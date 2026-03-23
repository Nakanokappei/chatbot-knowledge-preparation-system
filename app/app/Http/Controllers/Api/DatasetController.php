<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\DatasetRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Handle dataset upload and listing.
 *
 * Datasets are the entry point of the pipeline: a user uploads a CSV/TSV
 * containing support log entries, which are parsed into dataset_rows.
 */
class DatasetController extends Controller
{
    /**
     * List all datasets for the current tenant.
     */
    public function index(): JsonResponse
    {
        $datasets = Dataset::orderByDesc('created_at')->paginate(20);
        return response()->json($datasets);
    }

    /**
     * Show a single dataset with row count.
     */
    public function show(Dataset $dataset): JsonResponse
    {
        return response()->json($dataset);
    }

    /**
     * Upload a CSV file and parse it into dataset_rows.
     *
     * The raw file is stored in S3 following the standard path structure:
     * s3://bucket/{tenant_id}/datasets/{dataset_id}/raw/
     *
     * Phase 0 constraint: maximum 1000 rows per upload.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,tsv|max:10240', // 10MB max
            'name' => 'required|string|max:255',
            'text_column' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $tenantId = auth()->user()->tenant_id;
        $textColumn = $request->input('text_column', 'text');

        // Parse the CSV to extract rows
        $rows = $this->parseCsvFile($file->getRealPath(), $textColumn);

        // Phase 0 row limit
        if (count($rows) > 1000) {
            return response()->json([
                'error' => 'Row limit exceeded. Phase 0 supports up to 1000 rows.',
                'row_count' => count($rows),
            ], 422);
        }

        return DB::transaction(function () use ($request, $file, $tenantId, $rows) {
            // Create the dataset record
            $dataset = Dataset::create([
                'tenant_id' => $tenantId,
                'name' => $request->input('name'),
                'source_type' => 'csv',
                'original_filename' => $file->getClientOriginalName(),
                'row_count' => count($rows),
            ]);

            // Upload raw file to S3 (Design Principle 3: Intermediate Data on S3)
            $s3Path = "{$tenantId}/datasets/{$dataset->id}/raw/{$file->getClientOriginalName()}";
            Storage::disk('s3')->put($s3Path, file_get_contents($file->getRealPath()));
            $dataset->update(['s3_raw_path' => $s3Path]);

            // Bulk insert dataset rows
            $rowRecords = [];
            foreach ($rows as $index => $text) {
                $rowRecords[] = [
                    'dataset_id' => $dataset->id,
                    'tenant_id' => $tenantId,
                    'row_no' => $index + 1,
                    'raw_text' => $text,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insert in chunks to avoid memory issues
            foreach (array_chunk($rowRecords, 500) as $chunk) {
                DatasetRow::insert($chunk);
            }

            return response()->json([
                'message' => 'Dataset uploaded successfully.',
                'dataset' => $dataset->fresh(),
            ], 201);
        });
    }

    /**
     * Parse a CSV/TSV file and extract the text column values.
     *
     * @return array<int, string> Array of text values from the specified column.
     */
    private function parseCsvFile(string $filePath, string $textColumn): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return $rows;
        }

        // Detect delimiter (tab or comma)
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) ? "\t" : ',';

        // Read header row to find the target column index
        $header = fgetcsv($handle, 0, $delimiter);
        $columnIndex = array_search($textColumn, $header);

        // If the specified column is not found, use the first column
        if ($columnIndex === false) {
            $columnIndex = 0;
        }

        // Read data rows
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $text = trim($row[$columnIndex] ?? '');
            if ($text !== '') {
                $rows[] = $text;
            }
        }

        fclose($handle);
        return $rows;
    }
}
