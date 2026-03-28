<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Embedding;
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
     * Guard: system admins have no workspace; redirect to admin dashboard.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (auth()->check() && auth()->user()->isSystemAdmin()) {
                return redirect()->route('admin.index');
            }
            return $next($request);
        });
    }

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

        $workspaceId = auth()->user()->workspace_id;
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

        // Strip UTF-8 BOM (EF BB BF) if present — fgetcsv does not handle BOM
        $bom = file_get_contents($csvPath, false, null, 0, 3);
        if ($bom === "\xEF\xBB\xBF") {
            $content = substr(file_get_contents($csvPath), 3);
            file_put_contents($csvPath, $content);
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

        // Abort if any rows have mismatched column counts
        if (!empty($invalidLines)) {
            $lineNos = implode(', ', $invalidLines);
            $suffix = count($invalidLines) >= 10 ? ' (and more)' : '';
            return redirect()->route('dashboard')
                ->with('error', "Column count mismatch on lines: {$lineNos}{$suffix}. Expected {$headerCount} columns.");
        }

        // A header-only CSV is not useful — require at least one data row
        if ($totalDataRows === 0) {
            return redirect()->route('dashboard')
                ->with('error', 'CSV must contain at least one data row.');
        }

        // Sample rows for LLM description generation
        // Pick 5 rows at equal intervals: first, 2/5, 3/5, 4/5, last
        $sampleTargets = [];
        if ($totalDataRows <= 5) {
            $sampleTargets = range(0, $totalDataRows - 1);
        } else {
            $sampleTargets = [
                0,
                (int) floor($totalDataRows * 2 / 5),
                (int) floor($totalDataRows * 3 / 5),
                (int) floor($totalDataRows * 4 / 5),
                $totalDataRows - 1,
            ];
            $sampleTargets = array_unique($sampleTargets);
            sort($sampleTargets);
        }

        $sampleRows = [];
        $handle = fopen($csvPath, 'r');
        fgetcsv($handle, 0, $delimiter, '"', '"'); // Skip header
        $rowIdx = 0;
        $targetIdx = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false && $targetIdx < count($sampleTargets)) {
            if ($rowIdx === $sampleTargets[$targetIdx]) {
                $sampleRows[] = $row;
                $targetIdx++;
            }
            $rowIdx++;
        }
        fclose($handle);

        // Auto-generate dataset metadata using the workspace's default LLM model
        $defaultModel = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
        $llmMetadata = $this->generateDatasetMetadata(
            $file->getClientOriginalName(), $headerCols, $sampleRows,
            $defaultModel?->model_id,
        );

        // Prefer the LLM-generated name over the raw filename
        if (!empty($llmMetadata['dataset_name'])) {
            $datasetName = $llmMetadata['dataset_name'];
        }

        // Store the converted UTF-8 CSV and the original raw file
        $storedFilename = 'workspace' . $workspaceId . '_' . time() . '_' . $file->getClientOriginalName();
        $rawFilename = $storedFilename . '.raw';
        // Always save the original raw file for re-encoding
        $file->storeAs('csv-uploads', $rawFilename, 'csv');
        if ($detectedEncoding !== 'UTF-8') {
            // Store the converted UTF-8 version
            $this->csvDisk()->put(
                'csv-uploads/' . $storedFilename,
                file_get_contents($csvPath)
            );
            unlink($csvPath);
        } else {
            // UTF-8: duplicate raw file content as the working CSV
            // (use put+get instead of copy to avoid S3 ACL requirements)
            $this->csvDisk()->put(
                'csv-uploads/' . $storedFilename,
                $this->csvDisk()->get('csv-uploads/' . $rawFilename),
            );
        }
        $storedPath = 'csv-uploads/' . $storedFilename;

        // Create dataset in configuring status
        $dataset = Dataset::create([
            'workspace_id' => $workspaceId,
            'name' => $datasetName,
            'source_type' => 'csv',
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => 0,
            'schema_json' => [
                'status' => 'configuring',
                'stored_path' => $storedPath,
                'raw_path' => 'csv-uploads/' . $rawFilename,
                'detected_encoding' => $detectedEncoding,
                'delimiter' => $delimiter,
                'columns' => $headerCols,
                'total_lines' => $totalDataRows,
                'dataset_description' => $llmMetadata['dataset_description'] ?? '',
                'column_descriptions' => $llmMetadata['column_descriptions'] ?? [],
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

        if (!$storedPath || !$this->csvDisk()->exists($storedPath)) {
            abort(404, 'CSV file not found. Please re-upload.');
        }

        $delimiter = $schema['delimiter'] ?? ',';
        $columns = $schema['columns'] ?? [];

        // Read first 5 data rows using fgetcsv for RFC 4180 compliance
        $tempPath = $this->downloadToTemp($storedPath);
        $handle = fopen($tempPath, 'r');
        fgetcsv($handle, 0, $delimiter, '"', '"'); // Skip header row
        $previewRows = [];
        $count = 0;
        while ($count < 5 && ($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $previewRows[] = $row;
            $count++;
        }
        fclose($handle);
        unlink($tempPath);

        $workspaceId = auth()->user()->workspace_id;

        $llmModels = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get();

        $embeddingModels = EmbeddingModel::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get();

        // Detect reconfigure mode: dataset already has embeddings from a previous run
        $isReconfigure = Embedding::where('dataset_id', $dataset->id)->exists();

        // Check if a pipeline is already running in this workspace
        $hasRunningPipeline = \App\Models\PipelineJob::where('workspace_id', $workspaceId)
            ->whereIn('status', ['submitted', 'processing', 'preprocess', 'embedding', 'clustering', 'cluster_analysis', 'knowledge_unit_generation'])
            ->exists();

        return view('dataset.configure', [
            'dataset' => $dataset,
            'columns' => $columns,
            'previewRows' => $previewRows,
            'detectedEncoding' => $schema['detected_encoding'] ?? 'UTF-8',
            'totalLines' => $schema['total_lines'] ?? 0,
            'llmModels' => $llmModels,
            'embeddingModels' => $embeddingModels,
            'isReconfigure' => $isReconfigure,
            'hasRunningPipeline' => $hasRunningPipeline,
        ]);
    }

    /**
     * Re-encode the CSV with a user-specified encoding and update columns.
     *
     * Reads the original raw file, converts it to UTF-8 using the specified
     * encoding, re-detects headers and preview rows, then updates schema_json.
     * Returns the new column list and preview data as JSON.
     */
    public function reEncode(Request $request, Dataset $dataset)
    {
        $this->authorizeDataset($dataset);
        $schema = $dataset->schema_json;

        $encoding = $request->input('encoding', 'UTF-8');
        $rawPath = $schema['raw_path'] ?? null;

        if (!$rawPath || !$this->csvDisk()->exists($rawPath)) {
            return response()->json(['error' => 'Raw file not found'], 404);
        }

        // Read raw file and convert to UTF-8 with the specified encoding
        $rawContent = $this->csvDisk()->get($rawPath);
        $converted = ($encoding !== 'UTF-8')
            ? mb_convert_encoding($rawContent, 'UTF-8', $encoding)
            : $rawContent;

        // Strip UTF-8 BOM if present after conversion
        if (str_starts_with($converted, "\xEF\xBB\xBF")) {
            $converted = substr($converted, 3);
        }

        // Write the re-encoded CSV over the working copy
        $storedPath = $schema['stored_path'] ?? null;
        if ($storedPath) {
            $this->csvDisk()->put($storedPath, $converted);
        }

        // Re-read headers and preview rows from the converted content
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_re_');
        file_put_contents($tmpFile, $converted);
        $delimiter = $schema['delimiter'] ?? ',';

        $handle = fopen($tmpFile, 'r');
        $headerCols = fgetcsv($handle, 0, $delimiter, '"', '"') ?: [];

        $previewRows = [];
        $count = 0;
        while ($count < 5 && ($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $previewRows[] = $row;
            $count++;
        }
        fclose($handle);
        unlink($tmpFile);

        // Update schema_json with new columns and encoding
        $schema['columns'] = $headerCols;
        $schema['detected_encoding'] = $encoding;
        $dataset->update(['schema_json' => $schema]);

        return response()->json([
            'columns' => $headerCols,
            'previewRows' => $previewRows,
            'encoding' => $encoding,
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

        if (!$storedPath || !$this->csvDisk()->exists($storedPath)) {
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
        $tempPath = $this->downloadToTemp($storedPath);
        $handle = fopen($tempPath, 'r');

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
        unlink($tempPath);

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

        if (!$storedPath || !$this->csvDisk()->exists($storedPath)) {
            return redirect()->route('dashboard')
                ->with('error', 'CSV file not found. Please re-upload.');
        }

        $delimiter = $schema['delimiter'] ?? ',';
        $allColumns = $schema['columns'] ?? [];
        $workspaceId = auth()->user()->workspace_id;

        $hasHeader = $request->boolean('has_header');
        $selectedColumns = $request->input('selected_columns');
        $columnLabels = $request->input('column_labels');

        // Test mode: limit to N rows for quick pipeline validation
        $testMode = $request->input('test_mode');
        $maxRows = $testMode ? (int) $testMode : null;

        // Determine header names
        if ($hasHeader) {
            $headers = $allColumns;
        } else {
            $headers = array_map(
                fn($i) => "Column " . ($i + 1),
                range(0, count($allColumns) - 1)
            );
        }

        // Open CSV with fgetcsv for RFC 4180 compliance (download from S3 if needed)
        $tempPath = $this->downloadToTemp($storedPath);
        $handle = fopen($tempPath, 'r');
        if (!$handle) {
            unlink($tempPath);
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
                // Enforce test mode row limit
                if ($maxRows && ($rowNo - 1) >= $maxRows) break;

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
                    'workspace_id' => $workspaceId,
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
            unlink($tempPath);

            // Insert remaining rows
            if (!empty($batch)) {
                DatasetRow::insert($batch);
            }

            // Collect user-edited descriptions from the form
            $datasetDescription = $request->input('dataset_description', '');
            $columnDescriptions = $request->input('column_descriptions', []);

            // Update dataset metadata, preserving stored_path from upload step
            $dataset->update([
                'row_count' => $rowNo - 1,
                'schema_json' => [
                    'status' => 'ready',
                    'stored_path' => $storedPath,
                    'detected_encoding' => $schema['detected_encoding'] ?? 'UTF-8',
                    'total_lines' => $schema['total_lines'] ?? 0,
                    'columns' => $allColumns,
                    'selected_columns' => $selectedColumns,
                    'column_labels' => $columnLabels,
                    'has_header' => $hasHeader,
                    'delimiter' => $delimiter,
                    'dataset_description' => $datasetDescription,
                    'column_descriptions' => $columnDescriptions,
                    'primary_filter_label' => $request->input('primary_filter_label', ''),
                ],
            ]);

            // Save knowledge structure mapping
            $knowledgeMapping = [];
            foreach (['question', 'symptoms', 'root_cause', 'resolution', 'primary_filter', 'category'] as $field) {
                $source = $request->input("km_{$field}_source", '_none');
                $knowledgeMapping[$field] = $source;
            }
            $dataset->update(['knowledge_mapping_json' => $knowledgeMapping]);

            // Check if a pipeline is already running in this workspace
            $hasRunningPipeline = \App\Models\PipelineJob::where('workspace_id', $workspaceId)
                ->whereIn('status', ['submitted', 'processing', 'preprocess', 'embedding', 'clustering', 'cluster_analysis', 'knowledge_unit_generation'])
                ->exists();

            // Dispatch pipeline (queued if another is running)
            $pipelineJob = \App\Models\PipelineJob::create([
                'workspace_id' => $workspaceId,
                'dataset_id' => $dataset->id,
                'status' => $hasRunningPipeline ? 'queued' : 'submitted',
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
            $pipelineConfig['column_names'] = $allColumns;
            $pipelineConfig['llm_fallback'] = $request->boolean('llm_fallback', true);
            $pipelineConfig['dataset_description'] = $datasetDescription;
            $pipelineConfig['column_descriptions'] = $columnDescriptions;

            // Persist pipeline config snapshot for reproducibility
            $pipelineJob->update(['pipeline_config_snapshot_json' => $pipelineConfig]);

            // Only send to SQS if not queued (queued jobs are dispatched by the worker)
            if (!$hasRunningPipeline) {
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
                            'workspace_id' => $workspaceId,
                            'dataset_id' => $dataset->id,
                            'step' => 'preprocess',
                            'pipeline_config' => $pipelineConfig,
                        ]),
                    ]);

                    Log::info("Pipeline job {$pipelineJob->id} dispatched to SQS");
                } else {
                    Log::warning("SQS not configured — job {$pipelineJob->id} created but not dispatched");
                }
            } else {
                Log::info("Pipeline job {$pipelineJob->id} queued (another pipeline is running)");
            }

            DB::commit();

            // Keep CSV file for reconfiguration (stored in persistent volume)

            $successMessage = $hasRunningPipeline
                ? __('ui.pipeline_queued')
                : "Dataset '{$dataset->name}' created ({$dataset->row_count} rows). Pipeline Job #{$pipelineJob->id} dispatched.";

            return redirect()->route('dashboard')
                ->with('success', $successMessage);

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
        // Check for BOM signatures first — mb_detect_encoding often misdetects BOM files
        if (str_starts_with($sample, "\xEF\xBB\xBF")) return 'UTF-8';
        if (str_starts_with($sample, "\xFF\xFE")) return 'UTF-16LE';
        if (str_starts_with($sample, "\xFE\xFF")) return 'UTF-16BE';

        // Try mb_detect_encoding with strict mode
        $encodings = ['ASCII', 'UTF-8', 'Shift_JIS', 'EUC-JP', 'ISO-2022-JP'];
        $detected = mb_detect_encoding($sample, $encodings, true);
        if ($detected) return $detected;

        // Fallback: if all fail, check if it validates as UTF-8
        if (mb_check_encoding($sample, 'UTF-8')) return 'UTF-8';

        return 'ISO-8859-1';
    }

    /**
     * Authorization guard: ensure the dataset belongs to the current workspace.
     */
    private function authorizeDataset(Dataset $dataset): void
    {
        if ($dataset->workspace_id !== auth()->user()->workspace_id) {
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

        // Guard: prevent deletion while pipeline is running
        $hasRunningJobs = \App\Models\PipelineJob::where('dataset_id', $dataset->id)
            ->whereNotIn('status', ['completed', 'failed'])
            ->exists();
        if ($hasRunningJobs) {
            return redirect()->route('workspace.index')
                ->with('error', __('ui.cannot_delete_running'));
        }

        // Guard: prevent deletion of datasets with active embeddings
        $embeddingCount = \App\Models\Embedding::where('dataset_id', $dataset->id)->count();
        if ($embeddingCount > 0) {
            return redirect()->route('workspace.index')
                ->with('error', __('ui.cannot_delete_has_embeddings'));
        }

        $name = $dataset->name;
        $workspaceId = auth()->user()->workspace_id;

        // Delete the stored CSV and raw files from persistent volume
        foreach (['stored_path', 'raw_path'] as $pathKey) {
            $path = $dataset->schema_json[$pathKey] ?? null;
            if ($path && $this->csvDisk()->exists($path)) {
                $this->csvDisk()->delete($path);
            }
        }

        // Delete related pipeline jobs first (they reference dataset_id)
        \App\Models\PipelineJob::where('dataset_id', $dataset->id)->delete();

        // Delete rows (cascade should handle this, but be explicit)
        DatasetRow::where('dataset_id', $dataset->id)->delete();
        $dataset->delete();

        // Check if all datasets are now gone and orphaned jobs remain
        $remainingDatasets = Dataset::where('workspace_id', $workspaceId)->count();
        if ($remainingDatasets === 0) {
            $orphanedJobs = \App\Models\PipelineJob::where('workspace_id', $workspaceId)
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

    /**
     * API endpoint to generate dataset/column descriptions via LLM on demand.
     */
    public function generateDescriptionsApi(Request $request, Dataset $dataset)
    {
        $this->authorizeDataset($dataset);

        $schema = $dataset->schema_json;
        $storedPath = $schema['stored_path'] ?? null;

        if (!$storedPath || !$this->csvDisk()->exists($storedPath)) {
            return response()->json(['error' => 'CSV file not found'], 404);
        }

        // Read sample rows from the CSV
        $delimiter = $schema['delimiter'] ?? ',';
        $headers = $schema['columns'] ?? [];
        $tempPath = $this->downloadToTemp($storedPath);
        $handle = fopen($tempPath, 'r');
        fgetcsv($handle, 0, $delimiter, '"', '"'); // Skip header

        $totalLines = $schema['total_lines'] ?? 100;
        $samplePositions = [1, (int)($totalLines * 0.25), (int)($totalLines * 0.5), (int)($totalLines * 0.75), $totalLines];
        $sampleRows = [];
        $lineNo = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '"')) !== false) {
            $lineNo++;
            if (in_array($lineNo, $samplePositions)) {
                $sampleRows[] = $row;
            }
            if ($lineNo > max($samplePositions)) break;
        }
        fclose($handle);
        unlink($tempPath);

        // Use specified model or default
        $modelId = $request->input('model_id');
        if ($modelId) {
            // Temporarily override the env for BedrockService
            $originalModel = env('BEDROCK_LLM_MODEL');
            putenv("BEDROCK_LLM_MODEL=$modelId");
        }

        $metadata = $this->generateDatasetMetadata(
            $dataset->original_filename ?? 'dataset.csv',
            $headers,
            $sampleRows,
            $modelId,
        );

        if ($modelId && isset($originalModel)) {
            putenv("BEDROCK_LLM_MODEL=$originalModel");
        }

        if (empty($metadata)) {
            return response()->json(['error' => 'LLM failed to generate descriptions. Check model access.'], 500);
        }

        // Save to schema_json
        $schema['dataset_description'] = $metadata['dataset_description'] ?? '';
        $schema['column_descriptions'] = $metadata['column_descriptions'] ?? [];
        $dataset->update(['schema_json' => $schema]);

        if (!empty($metadata['dataset_name'])) {
            $dataset->update(['name' => $metadata['dataset_name']]);
        }

        return response()->json([
            'dataset_description' => $metadata['dataset_description'] ?? '',
            'column_descriptions' => $metadata['column_descriptions'] ?? [],
            'dataset_name' => $metadata['dataset_name'] ?? null,
        ]);
    }

    /**
     * Get the configured CSV storage disk (local or S3).
     */
    private function csvDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('csv');
    }

    /**
     * Download a file from the CSV disk to a local temp path for fopen/fgetcsv.
     * Returns the temp file path. Caller is responsible for unlinking.
     */
    private function downloadToTemp(string $diskPath): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempPath, $this->csvDisk()->get($diskPath));
        return $tempPath;
    }

    /**
     * Generate dataset name, description, and column descriptions using Bedrock LLM.
     *
     * Sends sample rows from the CSV to a language model which infers what the
     * dataset contains and what each column represents. Returns an associative
     * array with keys: dataset_name, dataset_description, column_descriptions.
     * On failure returns an empty array so the caller can fall back gracefully.
     */
    private function generateDatasetMetadata(string $filename, array $headers, array $sampleRows, ?string $modelId = null): array
    {
        try {
            // Format sample rows for the prompt
            $rowsText = '';
            foreach ($sampleRows as $rowIndex => $row) {
                $values = array_map(fn($cellValue) => mb_substr((string) $cellValue, 0, 200), $row);
                $rowsText .= "Row " . ($rowIndex + 1) . ": " . json_encode($values, JSON_UNESCAPED_UNICODE) . "\n";
            }

            $headerJson = json_encode($headers, JSON_UNESCAPED_UNICODE);

            $prompt = <<<PROMPT
Analyze this CSV dataset and generate metadata.

Filename: {$filename}
Headers: {$headerJson}

Sample rows (from evenly spaced positions in the file):
{$rowsText}
Respond ONLY with a JSON object (no markdown, no explanation):
{
  "dataset_name": "Short descriptive name for this dataset (2-5 words, in the language of the data)",
  "dataset_description": "1-2 sentence description of what this dataset contains and its purpose (in the language of the data)",
  "column_descriptions": {
    "column_name": "brief description of what this column contains"
  }
}

IMPORTANT:
- Include ALL columns in column_descriptions
- Respond in the same language as the data content
- Keep descriptions concise but informative
PROMPT;

            $modelId = $modelId ?? env('BEDROCK_LLM_MODEL', 'jp.anthropic.claude-haiku-4-5-20251001-v1:0');
            $bedrock = new \App\Services\BedrockService();
            $parsed = $bedrock->invokeJson($modelId, $prompt);

            if ($parsed) {
                Log::info('LLM generated dataset metadata', [
                    'name' => $parsed['dataset_name'] ?? null,
                    'columns_described' => count($parsed['column_descriptions'] ?? []),
                ]);
                return $parsed;
            }

            Log::warning('LLM dataset metadata response was not valid JSON');
            return [];

        } catch (\Exception $e) {
            Log::warning('Failed to generate dataset metadata via LLM', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
