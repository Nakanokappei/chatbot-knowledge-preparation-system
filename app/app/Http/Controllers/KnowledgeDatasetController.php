<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeDataset;
use App\Models\KnowledgeDatasetItem;
use App\Models\KnowledgeUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manages Knowledge Datasets — versioned collections of approved KUs.
 *
 * CTO rules:
 * - Only approved KUs can be added to a dataset
 * - Published datasets are immutable
 * - New version clones items into a fresh draft
 * - Export JSON excludes embeddings
 */
class KnowledgeDatasetController extends Controller
{
    /**
     * List all datasets for the current tenant.
     */
    public function index()
    {
        $tenantId = auth()->user()->tenant_id;

        $datasets = KnowledgeDataset::where('tenant_id', $tenantId)
            ->orderByDesc('updated_at')
            ->get();

        return view('dashboard.datasets.index', compact('datasets'));
    }

    /**
     * Show the create form with available approved Knowledge Units.
     */
    public function create(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        // Load approved KUs grouped by pipeline job for selection
        $approvedKUs = KnowledgeUnit::where('tenant_id', $tenantId)
            ->where('review_status', 'approved')
            ->orderBy('pipeline_job_id')
            ->orderBy('topic')
            ->get();

        return view('dashboard.datasets.create', compact('approvedKUs'));
    }

    /**
     * Store a new dataset from selected approved KUs.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'knowledge_unit_ids' => 'required|array|min:1',
            'knowledge_unit_ids.*' => 'exists:knowledge_units,id',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Verify all selected KUs are approved and belong to this tenant
        $selectedKUs = KnowledgeUnit::where('tenant_id', $tenantId)
            ->where('review_status', 'approved')
            ->whereIn('id', $request->knowledge_unit_ids)
            ->get();

        // Ensure the count matches — some IDs may have been non-approved or wrong tenant
        if ($selectedKUs->count() !== count($request->knowledge_unit_ids)) {
            return back()->withErrors(['knowledge_unit_ids' => 'All selected units must be approved.']);
        }

        $dataset = DB::transaction(function () use ($request, $tenantId, $selectedKUs) {
            // Create the dataset
            $dataset = KnowledgeDataset::create([
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'description' => $request->description,
                'version' => 1,
                'status' => 'draft',
                'ku_count' => $selectedKUs->count(),
                'created_by' => auth()->id(),
            ]);

            // Add each KU as an item, recording its current version
            foreach ($selectedKUs->values() as $index => $ku) {
                KnowledgeDatasetItem::create([
                    'knowledge_dataset_id' => $dataset->id,
                    'knowledge_unit_id' => $ku->id,
                    'sort_order' => $index,
                    'included_version' => $ku->version,
                ]);
            }

            return $dataset;
        });

        return redirect()
            ->route('kd.show', $dataset)
            ->with('success', "Dataset \"{$dataset->name}\" created with {$dataset->ku_count} units.");
    }

    /**
     * Display dataset detail with its Knowledge Units.
     */
    public function show(KnowledgeDataset $dataset)
    {
        $dataset->load(['items.knowledgeUnit', 'creator']);

        return view('dashboard.datasets.show', compact('dataset'));
    }

    /**
     * Publish a draft dataset — makes it available for retrieval/chat.
     */
    public function publish(KnowledgeDataset $dataset)
    {
        if (! $dataset->isEditable()) {
            return back()->withErrors(['status' => 'Only draft datasets can be published.']);
        }

        // Demote any existing published dataset with the same name to archived
        KnowledgeDataset::where('tenant_id', $dataset->tenant_id)
            ->where('name', $dataset->name)
            ->where('status', 'published')
            ->update(['status' => 'archived']);

        $dataset->update(['status' => 'published']);

        return back()->with('success', "Dataset \"{$dataset->name}\" v{$dataset->version} is now published.");
    }

    /**
     * Create a new version by cloning items from a published dataset.
     */
    public function newVersion(KnowledgeDataset $dataset)
    {
        // Only published datasets can spawn new versions
        if ($dataset->status !== 'published') {
            return back()->withErrors(['status' => 'Can only create new version from a published dataset.']);
        }

        $newDataset = DB::transaction(function () use ($dataset) {
            // Clone the dataset with incremented version
            $newDataset = KnowledgeDataset::create([
                'tenant_id' => $dataset->tenant_id,
                'name' => $dataset->name,
                'description' => $dataset->description,
                'version' => $dataset->version + 1,
                'status' => 'draft',
                'source_job_ids' => $dataset->source_job_ids,
                'ku_count' => $dataset->ku_count,
                'created_by' => auth()->id(),
            ]);

            // Clone all items, refreshing included_version to current KU version
            foreach ($dataset->items()->with('knowledgeUnit')->get() as $item) {
                KnowledgeDatasetItem::create([
                    'knowledge_dataset_id' => $newDataset->id,
                    'knowledge_unit_id' => $item->knowledge_unit_id,
                    'sort_order' => $item->sort_order,
                    'included_version' => $item->knowledgeUnit->version,
                ]);
            }

            return $newDataset;
        });

        return redirect()
            ->route('kd.show', $newDataset)
            ->with('success', "New version v{$newDataset->version} created as draft.");
    }

    /**
     * Export a published dataset as JSON (no embeddings per CTO directive).
     */
    public function export(KnowledgeDataset $dataset)
    {
        $dataset->load(['items.knowledgeUnit']);

        $export = [
            'dataset_id' => $dataset->id,
            'name' => $dataset->name,
            'version' => $dataset->version,
            'status' => $dataset->status,
            'exported_at' => now()->toIso8601String(),
            'knowledge_units' => $dataset->items->map(function ($item) {
                $ku = $item->knowledgeUnit;
                return [
                    'id' => $ku->id,
                    'topic' => $ku->topic,
                    'intent' => $ku->intent,
                    'summary' => $ku->summary,
                    'cause_summary' => $ku->cause_summary,
                    'resolution_summary' => $ku->resolution_summary,
                    'keywords' => $ku->keywords_json,
                    'typical_cases' => $ku->typical_cases_json,
                    'confidence' => (float) $ku->confidence,
                    'row_count' => $ku->row_count,
                    'version' => $item->included_version,
                ];
            })->values(),
        ];

        $filename = str_replace(' ', '_', $dataset->name) . "_v{$dataset->version}.json";

        return response()->json($export)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Show the RAG chat interface for a published dataset.
     */
    public function chat(KnowledgeDataset $dataset)
    {
        // Chat is restricted to published datasets only
        if (! $dataset->isPublished()) {
            return redirect()->route('kd.show', $dataset)
                ->withErrors(['status' => 'Chat is only available for published datasets.']);
        }

        return view('dashboard.datasets.chat', compact('dataset'));
    }

    /**
     * Show the retrieval quality evaluation page for a dataset.
     */
    public function evaluation(KnowledgeDataset $dataset)
    {
        return view('dashboard.datasets.evaluation', compact('dataset'));
    }
}
