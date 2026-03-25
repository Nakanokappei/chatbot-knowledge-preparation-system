<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\EmbeddingModel;
use App\Models\LlmModel;
use Aws\Sqs\SqsClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Two-step dataset wizard: upload CSV, then configure columns for embedding.
 *
 * CSV parsing follows RFC 4180:
 *  - Fields MAY be enclosed in double quotes
 *  - Double quotes inside quoted fields are escaped as ""
 *  - Quoted fields MAY contain CRLF (newlines), commas, and double quotes
 *  - Each record is one or more fields separated by the delimiter
 *
 * All CSV reading uses fgetcsv() which handles RFC 4180 correctly,
 * including multi-line quoted fields. We never split on "\n" to get rows.
 */
class DatasetWizardController extends Controller
{
    /**
     * Step 1: Accept CSV upload, validate column count consistency,
     * detect encoding, store file, create draft dataset.
     *
     * Uses fgetcsv() for RFC 4180 compliant parsing — correctly handles
     * quoted fields containing newlines, commas, and escaped quotes.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:51200',
            'dataset_name' => 'nullable|string|max:255',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $file = $request->file('csv_file');

        // Default name is the original filename without extension
        $datasetName = $request->input('dataset_name')
            ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // Detect encoding from first ~100KB of raw bytes
        $rawSample = file_get_contents($file->getRealPath(), false, null, 0, 100000);
        $detectedEncoding = $this->detectEncoding($rawSample);

        // If not UTF-8, convert the entire file and write to a temp location
        $csvPath = $file->getRealPath();
        if ($detectedEncoding !== 'UTF-8') {
            $rawContent = file_get_contents($file->getRealPath());
            $converted = mb_convert_encoding($rawContent, 'UTF-8', $detectedEncoding);
            $csvPath = tempnam(sys_get_temp_dir(), 'csv_utf8_');
            file_put_contents($csvPath, $converted);
        }

        // Detect delimiter from the first line
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return redirect()->route('dashboard')
                ->with('error', 'Failed to open CSV file.');
        }
        $firstLineRaw = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLineRaw, "\t") > substr_count($firstLineRaw, ','))
            ? "\t" : ',';

        // Read header row using fgetcsv (RFC 4180 compliant)
        $headerCols = fgetcsv($handle, 0, $delimiter, '"', '"');
        if (!$headerCols || count($headerCols) === 0) {
            fclose($handle);
            return redirect()->route('dashboard')
                ->with('error', 'CSV file has no valid header row.');
        }
        $headerCount = count($headerCols);

        // Validate column count for all data rows
        $invalidLines = [];
        $totalDataRows = 0;
        $lineNo = 2;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $totalDataRows++;
            if (count($row) !== $headerCount) {
                $invalidLines[] = $lineNo;
                if (count($invalidLines) >= 10) break;
            }
            $lineNo++;
        }
        fclose($handle);

        if (!empty($invalidLines)) {
            $lineNos = implode(', ', $invalidLines);
            $suffix = count($invalidLines) >= 10 ? ' (and more)' : '';
            return redirect()->route('dashboard')
                ->with('error', "Column count mismatch on lines: {$lineNos}{$suffix}. Expected {$headerCount} columns.");
        }

        if ($totalDataRows === 0) {
            return redirect()->route('dashboard')
                ->with('error', 'CSV must contain at least one data row.');
        }

        // Store the (possibly converted) CSV file
        $storedFilename = 'tenant' . $tenantId . '_' . time() . '_' . $file->getClientOriginalName();
        if ($detectedEncoding !== 'UTF-8') {
            // Store the converted UTF-8 version
            Storage::disk('local')->put(
                'csv-uploads/' . $storedFilename,
                file_get_contents($csvPath)
            );
            unlink($csvPath);
        } else {
            $file->storeAs('csv-uploads', $storedFilename, 'local');
        }
        $storedPath = 'csv-uploads/' . $storedFilename;

        // Create dataset in configuring status
        $dataset = Dataset::create([
            'tenant_id' => $tenantId,
            'name' => $datasetName,
            'source_type' => 'csv',
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => 0,
            'schema_json' => [
                'status' => 'configuring',
                'stored_path' => $storedPath,
                'detected_encoding' => $detectedEncoding,
                'delimiter' => $delimiter,
                'columns' => $headerCols,
                'total_lines' => $totalDataRows,
            ],
        ]);

        return redirect()->route('dataset.configure', $dataset);
    }

    /**
     * Step 2: Show configuration page with column selection and preview.
     *
     * Reads the first 5 data rows from the stored CSV using fgetcsv().
     */
    public function configure(Dataset $dataset): View
    {
        $this->authorizeDataset($dataset);

        $schema = $dataset->schema_json;
        $storedPath = $schema['stored_path'] ?? null;

        if (!$storedPath || !Storage::disk('local')->exists($storedPath)) {
            abort(404, 'CSV file not found. Please re-upload.');
        }

        $delimiter = $schema['delimiter'] ?? ',';
        $columns = $schema['columns'] ?? [];

        // Read first 5 data rows using fgetcsv for RFC 4180 compliance
        $fullPath = Storage::disk('local')->path($storedPath);
        $handle = fopen($fullPath, 'r');
        fgetcsv($handle, 0, $delimiter, '"', '"'); // Skip header row
        $previewRows = [];
        $count = 0;
        while ($count < 5 && ($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $previewRows[] = $row;
            $count++;
        }
        fclose($handle);

        $tenantId = auth()->user()->tenant_id;

        $llmModels = LlmModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get();

        $embeddingModels = EmbeddingModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get();

        return view('dataset.configure', [
            'dataset' => $dataset,
            'columns' => $columns,
            'previewRows' => $previewRows,
            'detectedEncoding' => $schema['detected_encoding'] ?? 'UTF-8',
            'totalLines' => $schema['total_lines'] ?? 0,
            'llmModels' => $llmModels,
            'embeddingModels' => $embeddingModels,
        ]);
    }

    /**
     * Preview API: returns embedding text for the first N rows based on
     * current column configuration. Called via AJAX from the configure page.
     */
    public function preview(Request $request, Dataset $dataset)
    {
        $this->authorizeDataset($dataset);

        $schema = $dataset->schema_json;
        $storedPath = $schema['stored_path'] ?? null;

        if (!$storedPath || !Storage::disk('local')->exists($storedPath)) {
            return response()->json(['error' => 'CSV not found'], 404);
        }

        $delimiter = $schema['delimiter'] ?? ',';
        $allColumns = $schema['columns'] ?? [];

        // Parameters from request
        $hasHeader = $request->boolean('has_header', true);
        $selectedColumns = $request->input('selected_columns', []);
        $columnLabels = $request->input('column_labels', []);

        // Determine header names
        if ($hasHeader) {
            $headers = $allColumns;
        } else {
            $headers = array_map(
                fn($i) => "Column " . ($i + 1),
                range(0, count($allColumns) - 1)
            );
        }

        // Read CSV with fgetcsv for correct RFC 4180 handling
        $fullPath = Storage::disk('local')->path($storedPath);
        $handle = fopen($fullPath, 'r');

        // If has_header, skip the first row; otherwise treat it as data
        if ($hasHeader) {
            fgetcsv($handle, 0, $delimiter, '"', '"');
        }

        $previews = [];
        $rowNo = 1;
        while ($rowNo <= 5 && ($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $text = $this->buildEmbeddingText($row, $selectedColumns, $columnLabels, $headers);
            $previews[] = [
                'row_no' => $rowNo,
                'text' => $text,
            ];
            $rowNo++;
        }
        fclose($handle);

        return response()->json(['previews' => $previews]);
    }

    /**
     * Finalize: parse CSV with fgetcsv, create dataset_rows, dispatch pipeline.
     */
    public function finalize(Request $request, Dataset $dataset): RedirectResponse
    {
        $this->authorizeDataset($dataset);

        $request->validate([
            'dataset_name' => 'nullable|string|max:255',
            'has_header' => 'required|boolean',
            'selected_columns' => 'required|array|min:1',
            'column_labels' => 'required|array',
            'llm_model_id' => 'nullable|string|max:100',
            'clustering_method' => 'nullable|in:hdbscan,kmeans,agglomerative,leiden',
        ]);

        // Update dataset name if changed
        if ($request->filled('dataset_name')) {
            $dataset->update(['name' => $request->input('dataset_name')]);
        }

        $schema = $dataset->schema_json;
        $storedPath = $schema['stored_path'] ?? null;

        if (!$storedPath || !Storage::disk('local')->exists($storedPath)) {
            return redirect()->route('dashboard')
                ->with('error', 'CSV file not found. Please re-upload.');
        }

        $delimiter = $schema['delimiter'] ?? ',';
        $allColumns = $schema['columns'] ?? [];
        $tenantId = auth()->user()->tenant_id;

        $hasHeader = $request->boolean('has_header');
        $selectedColumns = $request->input('selected_columns');
        $columnLabels = $request->input('column_labels');

        // Determine header names
        if ($hasHeader) {
            $headers = $allColumns;
        } else {
            $headers = array_map(
                fn($i) => "Column " . ($i + 1),
                range(0, count($allColumns) - 1)
            );
        }

        // Open CSV with fgetcsv for RFC 4180 compliance
        $fullPath = Storage::disk('local')->path($storedPath);
        $handle = fopen($fullPath, 'r');
        if (!$handle) {
            return redirect()->route('dataset.configure', $dataset)
                ->with('error', 'Failed to open stored CSV file.');
        }

        // Skip header if present
        if ($hasHeader) {
            fgetcsv($handle, 0, $delimiter, '"', '"');
        }

        // Insert rows in transaction
        DB::beginTransaction();
        try {
            $now = now();
            $rowNo = 1;
            $batch = [];

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
                $embeddingText = $this->buildEmbeddingText(
                    $row, $selectedColumns, $columnLabels, $headers
                );

                if (trim($embeddingText) === '') continue;

                // Build metadata: combine headers with row values
                $metaHeaders = array_slice($headers, 0, count($row));
                $metadata = count($metaHeaders) === count($row)
                    ? array_combine($metaHeaders, $row)
                    : $row;

                $batch[] = [
                    'dataset_id' => $dataset->id,
                    'tenant_id' => $tenantId,
                    'row_no' => $rowNo,
                    'raw_text' => $embeddingText,
                    'metadata_json' => json_encode($metadata),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $rowNo++;

                // Bulk insert in chunks of 500
                if (count($batch) >= 500) {
                    DatasetRow::insert($batch);
                    $batch = [];
                }
            }
            fclose($handle);

            // Insert remaining rows
            if (!empty($batch)) {
                DatasetRow::insert($batch);
            }

            // Update dataset metadata
            $dataset->update([
                'row_count' => $rowNo - 1,
                'schema_json' => [
                    'status' => 'ready',
                    'columns' => $allColumns,
                    'selected_columns' => $selectedColumns,
                    'column_labels' => $columnLabels,
                    'has_header' => $hasHeader,
                    'delimiter' => $delimiter,
                ],
            ]);

            // Save knowledge structure mapping
            $knowledgeMapping = [];
            foreach (['question', 'symptoms', 'root_cause', 'resolution', 'product', 'category'] as $field) {
                $source = $request->input("km_{$field}_source", '_none');
                $knowledgeMapping[$field] = $source;
            }
            $dataset->update(['knowledge_mapping_json' => $knowledgeMapping]);

            // Dispatch pipeline
            $pipelineJob = \App\Models\PipelineJob::create([
                'tenant_id' => $tenantId,
                'dataset_id' => $dataset->id,
                'status' => 'submitted',
                'progress' => 0,
            ]);

            // Build pipeline config
            $pipelineConfig = ['phase' => '2'];
            $llmModelId = $request->input('llm_model_id');
            if ($llmModelId) {
                $pipelineConfig['llm_model_id'] = $llmModelId;
            }
            $clusteringMethod = $request->input('clustering_method', 'hdbscan');
            $pipelineConfig['clustering_method'] = $clusteringMethod;

            // Collect clustering parameters from request
            $clusteringParams = $request->only([
                'hdbscan_min_cluster_size', 'hdbscan_min_samples',
                'kmeans_n_clusters',
                'agglomerative_n_clusters', 'agglomerative_linkage',
                'leiden_n_neighbors', 'leiden_resolution',
            ]);
            $pipelineConfig['clustering_params'] = $clusteringParams;
            $pipelineConfig['knowledge_mapping'] = $knowledgeMapping;

            // Send to SQS
            $sqsUrl = env('SQS_QUEUE_URL');
            if (!$sqsUrl) {
                // Build URL from prefix + queue name as fallback
                $prefix = env('SQS_PREFIX', '');
                $queue = env('SQS_QUEUE', 'ckps-pipeline-dev');
                if ($prefix) {
                    $sqsUrl = $prefix . '/' . $queue;
                }
            }

            if ($sqsUrl) {
                $sqs = new SqsClient([
                    'region' => env('SQS_REGION', 'ap-northeast-1'),
                    'version' => 'latest',
                ]);

                $sqs->sendMessage([
                    'QueueUrl' => $sqsUrl,
                    'MessageBody' => json_encode([
                        'job_id' => $pipelineJob->id,
                        'tenant_id' => $tenantId,
                        'dataset_id' => $dataset->id,
                        'step' => 'preprocess',
                        'pipeline_config' => $pipelineConfig,
                    ]),
                ]);

                Log::info("Pipeline job {$pipelineJob->id} dispatched to SQS");
            } else {
                Log::warning("SQS not configured — job {$pipelineJob->id} created but not dispatched");
            }

            DB::commit();

            // Clean up temporary CSV file
            Storage::disk('local')->delete($storedPath);

            return redirect()->route('dashboard')
                ->with('success', "Dataset '{$dataset->name}' created ({$dataset->row_count} rows). Pipeline Job #{$pipelineJob->id} dispatched.");

        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            DB::rollBack();
            Log::error('Dataset finalize failed', ['error' => $e->getMessage()]);
            return redirect()->route('dataset.configure', $dataset)
                ->with('error', 'Failed: ' . $e->getMessage());
        }
    }

    /**
     * Build embedding text from a CSV row based on selected columns and labels.
     *
     * Example output: "subject: Battery Temperature Issue\nbody: My device overheats..."
     */
    private function buildEmbeddingText(
        array $row,
        array $selectedColumns,
        array $columnLabels,
        array $headers
    ): string {
        $parts = [];
        foreach ($selectedColumns as $colIndex) {
            $colIndex = (int) $colIndex;
            $value = trim($row[$colIndex] ?? '');
            if ($value === '') continue;

            $label = $columnLabels[$colIndex]
                ?? ($headers[$colIndex] ?? "col{$colIndex}");
            $parts[] = "{$label}: {$value}";
        }
        return implode("\n", $parts);
    }

    /**
     * Detect encoding from a byte string sample.
     *
     * Checks common Japanese encodings first, then falls back to Latin/ASCII.
     */
    private function detectEncoding(string $sample): string
    {
        $encodings = [
            'UTF-8', 'Shift_JIS', 'EUC-JP', 'ISO-2022-JP',
            'ISO-8859-1', 'ASCII',
        ];
        $detected = mb_detect_encoding($sample, $encodings, true);
        return $detected ?: 'UTF-8';
    }

    /**
     * Authorization guard: ensure the dataset belongs to the current tenant.
     */
    private function authorizeDataset(Dataset $dataset): void
    {
        if ($dataset->tenant_id !== auth()->user()->tenant_id) {
            abort(403);
        }
    }

    /**
     * Delete a dataset that has no embeddings.
     *
     * Only allowed when the dataset has zero embeddings to prevent
     * accidental deletion of data that is actively in use.
     */
    public function destroy(Dataset $dataset): RedirectResponse
    {
        $this->authorizeDataset($dataset);

        // Safety check: only allow deletion if no embeddings reference this dataset
        $embeddingCount = \App\Models\Embedding::where('dataset_id', $dataset->id)->count();
        if ($embeddingCount > 0) {
            return redirect()->route('workspace.index')
                ->with('error', 'Cannot delete a dataset that has embeddings.');
        }

        $name = $dataset->name;
        $tenantId = auth()->user()->tenant_id;

        // Delete related pipeline jobs first (they reference dataset_id)
        \App\Models\PipelineJob::where('dataset_id', $dataset->id)->delete();

        // Delete rows (cascade should handle this, but be explicit)
        DatasetRow::where('dataset_id', $dataset->id)->delete();
        $dataset->delete();

        // Check if all datasets are now gone and orphaned jobs remain
        $remainingDatasets = Dataset::where('tenant_id', $tenantId)->count();
        if ($remainingDatasets === 0) {
            $orphanedJobs = \App\Models\PipelineJob::where('tenant_id', $tenantId)
                ->whereIn('status', ['failed', 'submitted'])
                ->count();

            if ($orphanedJobs > 0) {
                return redirect()->route('workspace.index')
                    ->with('success', "Dataset \"{$name}\" deleted.")
                    ->with('confirm_cleanup', $orphanedJobs);
            }
        }

        return redirect()->route('workspace.index')
            ->with('success', "Dataset \"{$name}\" deleted.");
    }
}
