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
            ->with(['embeddings' => function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orderByDesc('created_at');
            }])
            ->orderByDesc('created_at')
            ->get();

        // Select current embedding: explicit, or first available across all datasets
        $current = null;
        $knowledgeUnits = collect();

        if ($embeddingId) {
            $current = Embedding::where('tenant_id', $tenantId)->find($embeddingId);
        }
        if (!$current) {
            // Auto-select: first embedding of the first dataset
            foreach ($datasets as $ds) {
                if ($ds->embeddings->isNotEmpty()) {
                    $current = $ds->embeddings->first();
                    break;
                }
            }
        }

        $embeddingJob = null;
        if ($current) {
            $knowledgeUnits = KnowledgeUnit::where('embedding_id', $current->id)
                ->orderByDesc('row_count')
                ->get();

            // Load the latest pipeline job for this embedding (for header info)
            $embeddingJob = PipelineJob::where('embedding_id', $current->id)
                ->orderByDesc('created_at')
                ->first();
        }

        // Pipeline data (for integrated pipeline section)
        $pipelineView = $request->query('pipeline');
        $pipelineFilter = $request->query('pf', 'all');

        $allJobs = PipelineJob::with('dataset:id,name')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $jobStats = [
            'total' => $allJobs->count(),
            'completed' => $allJobs->where('status', 'completed')->count(),
            'processing' => $allJobs->whereNotIn('status', ['completed', 'failed', 'submitted'])->count(),
            'failed' => $allJobs->where('status', 'failed')->count(),
        ];

        $filteredJobs = match ($pipelineFilter) {
            'completed' => $allJobs->where('status', 'completed'),
            'processing' => $allJobs->whereNotIn('status', ['completed', 'failed', 'submitted']),
            'failed' => $allJobs->where('status', 'failed'),
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

        $ku = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->findOrFail($kuId);

        $embeddings = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->with('dataset:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('workspace.ku_detail', [
            'embeddings' => $embeddings,
            'current' => $embedding,
            'ku' => $ku,
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
     * Export approved KUs for an embedding as JSON.
     */
    public function export(int $embeddingId)
    {
        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $kus = KnowledgeUnit::where('embedding_id', $embeddingId)
            ->where('review_status', 'approved')
            ->orderBy('topic')
            ->get(['id', 'topic', 'intent', 'summary', 'keywords', 'language', 'row_count', 'review_status']);

        $filename = Str::slug($embedding->name) . '_approved_kus.json';

        return response()->json([
            'embedding' => $embedding->name,
            'exported_at' => now()->toIso8601String(),
            'total' => $kus->count(),
            'knowledge_units' => $kus,
        ])->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Delete an embedding and all its related data (KUs, clusters, etc.).
     */
    public function destroy(int $embeddingId)
    {
        $embedding = Embedding::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($embeddingId);

        $name = $embedding->name;

        // Delete related KUs
        KnowledgeUnit::where('embedding_id', $embeddingId)->delete();

        // Delete the embedding itself
        $embedding->delete();

        return redirect()->route('workspace.index')
            ->with('success', "Deleted: {$name}");
    }
}
