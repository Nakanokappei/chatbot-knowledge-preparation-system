<?php

namespace App\Http\Controllers;

use App\Models\Dataset;
use App\Models\Embedding;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Models\PipelineJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Controller for the embedding-centric main view.
 *
 * The primary UI: left sidebar lists embeddings, main area shows
 * knowledge units for the selected embedding.
 */
class EmbeddingController extends Controller
{
    /**
     * Main workspace view — sidebar + KU list.
     *
     * If no embedding_id is specified, selects the most recent one.
     */
    public function index(Request $request, ?int $embeddingId = null): View
    {
        $tenantId = auth()->user()->tenant_id;

        // Datasets with their embeddings for tree view
        $datasets = Dataset::where('tenant_id', $tenantId)
            ->where('row_count', '>', 0)
            ->with(['embeddings' => function ($embeddingQuery) use ($tenantId) {
                // Exclude embeddings whose pipeline is still in progress
                $embeddingQuery->where('tenant_id', $tenantId)
                  ->whereDoesntHave('pipelineJobs', function ($jobQuery) {
                      $jobQuery->whereNotIn('status', ['completed', 'failed']);
                  })
                  ->withCount('knowledgeUnits')
                  ->orderByDesc('created_at');
            }])
            ->orderByDesc('created_at')
            ->get();

        // Select current embedding: explicit, or first available across all datasets
        $current = null;
        $knowledgeUnits = collect();

        // Try to load the explicitly requested embedding
        if ($embeddingId) {
            $current = Embedding::where('tenant_id', $tenantId)->find($embeddingId);
        }
        if (!$current) {
            // Auto-select: first embedding of the first dataset
            foreach ($datasets as $dataset) {
                if ($dataset->embeddings->isNotEmpty()) {
                    $current = $dataset->embeddings->first();
                    break;
                }
            }
        }

        $embeddingJob = null;
        if ($current) {
            // Load the latest pipeline job for this embedding (for header info)
            $embeddingJob = PipelineJob::where('embedding_id', $current->id)
                ->orderByDesc('created_at')
                ->first();

            // Only show KUs when the pipeline has fully completed
            if ($embeddingJob && $embeddingJob->status === 'completed') {
                $knowledgeUnits = KnowledgeUnit::where('embedding_id', $current->id)
                    ->orderByDesc('row_count')
                    ->get();
            }
        }

        // Pipeline data (for integrated pipeline section)
        $pipelineView = $request->query('pipeline');

        // Pipeline and embedding selections are mutually exclusive
        if ($pipelineView) {
            $current = null;
            $knowledgeUnits = collect();
            $embeddingJob = null;
        }
        $pipelineFilter = $request->query('pf', 'all');

        $allJobs = PipelineJob::with('dataset:id,name')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Compute stats before any filtering (use filter() to avoid mutating)
        $jobStats = [
            'total' => $allJobs->count(),
            'completed' => $allJobs->filter(fn($job) => $job->status === 'completed')->count(),
            'processing' => $allJobs->filter(fn($job) => !in_array($job->status, ['completed', 'failed', 'cancelled']))->count(),
            'failed' => $allJobs->filter(fn($job) => in_array($job->status, ['failed', 'cancelled']))->count(),
        ];

        $filteredJobs = match ($pipelineFilter) {
            'completed' => $allJobs->filter(fn($job) => $job->status === 'completed'),
            'processing' => $allJobs->filter(fn($job) => !in_array($job->status, ['completed', 'failed', 'cancelled'])),
            'failed' => $allJobs->filter(fn($job) => in_array($job->status, ['failed', 'cancelled'])),
            default => $allJobs,
        };

        $llmModels = LlmModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('workspace.index', [
            'datasets' => $datasets,
            'current' => $current,
            'knowledgeUnits' => $knowledgeUnits,
            'embeddingJob' => $embeddingJob,
            'pipelineView' => $pipelineView,
            'pipelineFilter' => $pipelineFilter,
            'jobs' => $filteredJobs,
            'jobStats' => $jobStats,
            'llmModels' => $llmModels,
        ]);
    }

    /**
     * Show a single KU detail (edit, review, versions).
     */
    public function showKnowledgeUnit(int $embeddingId, int $kuId): View
    {
        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $knowledgeUnit = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->findOrFail($kuId);

        $embeddings = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->with('dataset:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('workspace.ku_detail', [
            'embeddings' => $embeddings,
            'current' => $embedding,
            'ku' => $knowledgeUnit,
        ]);
    }

    /**
     * Bulk approve all draft KUs for an embedding.
     */
    public function bulkApprove(int $embeddingId)
    {
        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $updated = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->where('review_status', 'draft')
            ->update([
                'review_status' => 'approved',
                'edited_by_user_id' => auth()->id(),
                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        return redirect()->route('workspace.index', ['embeddingId' => $embeddingId])
            ->with('success', "{$updated} KUs approved.");
    }

    /**
     * Bulk update review_status for selected knowledge units.
     *
     * Accepts an array of KU IDs and a target status from the dropdown.
     */
    public function bulkUpdateStatus(Request $request, int $embeddingId)
    {
        $request->validate([
            'ku_ids' => 'required|array|min:1',
            'ku_ids.*' => 'integer|exists:knowledge_units,id',
            'new_status' => 'required|in:draft,reviewed,approved,rejected',
        ]);

        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $updated = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->whereIn('id', $request->input('ku_ids'))
            ->update([
                'review_status' => $request->input('new_status'),
                'edited_by_user_id' => auth()->id(),
                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        $statusLabels = [
            'draft' => 'Draft', 'reviewed' => 'Reviewed',
            'approved' => 'Approved', 'rejected' => 'Rejected',
        ];
        $label = $statusLabels[$request->input('new_status')] ?? $request->input('new_status');

        return redirect()->route('workspace.index', ['embeddingId' => $embeddingId])
            ->with('success', "{$updated} KU(s) marked as {$label}.");
    }

    /**
     * Rename an embedding.
     */
    public function rename(Request $request, int $embeddingId)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $old = $embedding->name;
        $embedding->update(['name' => $request->input('name')]);

        return redirect()->route('workspace.embedding', ['embeddingId' => $embeddingId])
            ->with('success', "Renamed: {$old} → {$embedding->name}");
    }

    /**
     * Export approved KUs for an embedding as CSV or JSON.
     * Format is determined by the ?format= query parameter (default: json).
     */
    public function export(Request $request, int $embeddingId)
    {
        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $columns = [
            'id', 'topic', 'intent', 'summary', 'keywords_json',
            'question', 'symptoms', 'root_cause', 'resolution_summary',
            'primary_filter', 'category', 'language', 'row_count', 'review_status',
        ];
        $kus = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->where('review_status', 'approved')
            ->orderBy('topic')
            ->get($columns);

        $format = $request->query('format', 'json');
        $baseFilename = Str::slug($embedding->name) . '_clusters';

        if ($format === 'csv') {
            return $this->exportAsCsv($kus, $columns, $baseFilename);
        }

        return $this->exportAsJson($kus, $embedding->name, $baseFilename);
    }

    /**
     * Build a CSV download response from the given KU collection.
     * Outputs UTF-8 with BOM so Excel opens it correctly.
     */
    private function exportAsCsv($kus, array $columns, string $baseFilename)
    {
        $csvHeader = $columns;
        $rows = [];
        foreach ($kus as $ku) {
            $row = [];
            foreach ($columns as $col) {
                $value = $ku->{$col};
                // Flatten JSON array fields to semicolon-separated string
                if ($col === 'keywords_json' && is_array($value)) {
                    $value = implode('; ', $value);
                }
                $row[] = $value ?? '';
            }
            $rows[] = $row;
        }

        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $csvHeader);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseFilename}.csv\"",
        ]);
    }

    /**
     * Build a JSON download response from the given KU collection.
     */
    private function exportAsJson($kus, string $embeddingName, string $baseFilename)
    {
        return response()->json([
            'embedding' => $embeddingName,
            'exported_at' => now()->toIso8601String(),
            'total' => $kus->count(),
            'knowledge_units' => $kus,
        ])->header('Content-Disposition', "attachment; filename=\"{$baseFilename}.json\"");
    }

    /**
     * Export original dataset rows with their assigned cluster name appended.
     *
     * Joins dataset_rows → cluster_memberships → clusters to produce a CSV
     * containing all original columns plus a "cluster_topic" column. Rows not
     * assigned to any cluster get an empty cluster_topic value.
     */
    public function exportWithClusters(int $embeddingId)
    {
        $tenantId = auth()->user()->tenant_id;
        $embedding = Embedding::where('tenant_id', $tenantId)
            ->findOrFail($embeddingId);

        // Fetch all original rows with their cluster topic name via raw SQL
        // (bypasses RLS by using the app-level tenant filter)
        $rows = \DB::select("
            SELECT dr.row_no, dr.metadata_json, c.topic_name AS cluster_topic
            FROM dataset_rows dr
            LEFT JOIN cluster_memberships cm ON cm.dataset_row_id = dr.id
            LEFT JOIN clusters c ON c.id = cm.cluster_id
                AND c.pipeline_job_id = (
                    SELECT pj.id FROM pipeline_jobs pj
                    WHERE pj.embedding_id = ?
                    ORDER BY pj.created_at DESC LIMIT 1
                )
            WHERE dr.dataset_id = ? AND dr.tenant_id = ?
            ORDER BY dr.row_no
        ", [$embeddingId, $embedding->dataset_id, $tenantId]);

        if (empty($rows)) {
            return back()->with('error', 'No data rows found.');
        }

        // Determine CSV columns from the first row's metadata keys
        $firstMeta = json_decode($rows[0]->metadata_json, true) ?? [];
        $originalColumns = array_keys($firstMeta);
        $csvHeader = array_merge($originalColumns, ['cluster_topic']);

        // Build CSV output
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($handle, $csvHeader);

        foreach ($rows as $row) {
            $meta = json_decode($row->metadata_json, true) ?? [];
            $csvRow = [];
            foreach ($originalColumns as $col) {
                $csvRow[] = $meta[$col] ?? '';
            }
            $csvRow[] = $row->cluster_topic ?? '';
            fputcsv($handle, $csvRow);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        $filename = Str::slug($embedding->name) . '_rows_with_clusters.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Delete an embedding and all its related data (KUs, clusters, etc.).
     */
    public function destroy(int $embeddingId)
    {
        $tenantId = auth()->user()->tenant_id;
        $embedding = Embedding::where('tenant_id', $tenantId)
            ->findOrFail($embeddingId);

        $name = $embedding->name;
        $datasetId = $embedding->dataset_id;

        // Find the next embedding to select after deletion
        // Get all embeddings for the same dataset, ordered
        $siblings = Embedding::where('tenant_id', $tenantId)
            ->where('dataset_id', $datasetId)
            ->orderBy('created_at', 'desc')
            ->pluck('id')
            ->toArray();

        $currentIdx = array_search($embeddingId, $siblings);
        $nextId = null;

        // Select the next sibling to redirect to after deletion
        if ($currentIdx !== false && count($siblings) > 1) {
            // Try the next sibling, or fall back to the previous one
            if (isset($siblings[$currentIdx + 1])) {
                $nextId = $siblings[$currentIdx + 1];
            } elseif ($currentIdx > 0) {
                $nextId = $siblings[$currentIdx - 1];
            }
        }

        // Delete related KUs and the embedding
        KnowledgeUnit::where('embedding_id', $embeddingId)->delete();
        $embedding->delete();

        // Redirect to the next sibling embedding, or workspace root
        if ($nextId) {
            return redirect()->route('workspace.embedding', $nextId)
                ->with('success', "Deleted: {$name}");
        }

        return redirect()->route('workspace.index')
            ->with('success', "Deleted: {$name}");
    }

    /**
     * Delete all orphaned pipeline jobs (failed/submitted jobs with no matching dataset).
     */
    public function cleanupJobs(): \Illuminate\Http\RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $deleted = PipelineJob::where('tenant_id', $tenantId)
            ->whereIn('status', ['failed', 'submitted'])
            ->delete();

        return redirect()->route('workspace.index', ['pipeline' => 'jobs', 'pf' => 'all'])
            ->with('success', "Cleaned up {$deleted} job(s).");
    }
}
