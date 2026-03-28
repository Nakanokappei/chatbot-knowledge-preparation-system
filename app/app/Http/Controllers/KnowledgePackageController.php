<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePackage;
use App\Models\KnowledgePackageItem;
use App\Models\KnowledgeUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manages Knowledge Packages — versioned collections of approved KUs.
 *
 * CTO rules:
 * - Only approved KUs can be added to a package
 * - Published packages are immutable
 * - New version clones items into a fresh draft
 * - Export JSON excludes embeddings
 */
class KnowledgePackageController extends Controller
{
    /**
     * List all packages for the current workspace.
     */
    public function index()
    {
        $workspaceId = auth()->user()->workspace_id;

        $packages = KnowledgePackage::where('workspace_id', $workspaceId)
            ->orderByDesc('updated_at')
            ->get();

        return view('dashboard.datasets.index', compact('packages'));
    }

    /**
     * Show the create form with available approved Knowledge Units.
     */
    public function create(Request $request)
    {
        $workspaceId = auth()->user()->workspace_id;

        // Load approved KUs grouped by pipeline job for selection
        $approvedKUs = KnowledgeUnit::where('workspace_id', $workspaceId)
            ->where('review_status', 'approved')
            ->orderBy('pipeline_job_id')
            ->orderBy('topic')
            ->get();

        return view('dashboard.datasets.create', compact('approvedKUs'));
    }

    /**
     * Store a new package from selected approved KUs.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'knowledge_unit_ids' => 'required|array|min:1',
            'knowledge_unit_ids.*' => 'exists:knowledge_units,id',
        ]);

        $workspaceId = auth()->user()->workspace_id;

        // Verify all selected KUs are approved and belong to this workspace
        $selectedKUs = KnowledgeUnit::where('workspace_id', $workspaceId)
            ->where('review_status', 'approved')
            ->whereIn('id', $request->knowledge_unit_ids)
            ->get();

        // Ensure the count matches — some IDs may have been non-approved or wrong workspace
        if ($selectedKUs->count() !== count($request->knowledge_unit_ids)) {
            return back()->withErrors(['knowledge_unit_ids' => 'All selected units must be approved.']);
        }

        $package = DB::transaction(function () use ($request, $workspaceId, $selectedKUs) {
            // Create the package
            $package = KnowledgePackage::create([
                'workspace_id' => $workspaceId,
                'name' => $request->name,
                'description' => $request->description,
                'version' => 1,
                'status' => 'draft',
                'ku_count' => $selectedKUs->count(),
                'created_by' => auth()->id(),
            ]);

            // Add each KU as an item, recording its current version
            foreach ($selectedKUs->values() as $index => $ku) {
                KnowledgePackageItem::create([
                    'knowledge_dataset_id' => $package->id,
                    'knowledge_unit_id' => $ku->id,
                    'sort_order' => $index,
                    'included_version' => $ku->version,
                ]);
            }

            return $package;
        });

        return redirect()
            ->route('kp.show', $package)
            ->with('success', "Package \"{$package->name}\" created with {$package->ku_count} units.");
    }

    /**
     * Display package detail with its Knowledge Units.
     */
    public function show(KnowledgePackage $package)
    {
        $package->load(['items.knowledgeUnit', 'creator']);

        return view('dashboard.datasets.show', compact('package'));
    }

    /**
     * Submit a draft package for owner publication authorization (member workflow).
     */
    public function submitForReview(KnowledgePackage $package)
    {
        if (! $package->isSubmittable()) {
            return back()->withErrors(['status' => __('ui.only_drafts_submittable')]);
        }

        $package->update(['status' => 'pending_review']);

        return back()->with('success', __('ui.review_submitted'));
    }

    /**
     * Publish a package — owner-only authorization step.
     *
     * Owners can publish from both draft (shortcut) and pending_review states.
     * Members must go through submitForReview first.
     */
    public function publish(KnowledgePackage $package)
    {
        // Owners can publish from draft or pending_review
        if (! in_array($package->status, ['draft', 'pending_review'])) {
            return back()->withErrors(['status' => 'Only draft or publication-requested packages can be published.']);
        }

        // Non-owners cannot publish directly — they must submit for review
        if (! auth()->user()->isOwner() && ! auth()->user()->isSystemAdmin()) {
            return back()->withErrors(['status' => __('ui.owner_approval_required')]);
        }

        // Demote any existing published package with the same name to archived
        KnowledgePackage::where('workspace_id', $package->workspace_id)
            ->where('name', $package->name)
            ->where('status', 'published')
            ->update(['status' => 'archived']);

        $package->update(['status' => 'published']);

        return back()->with('success', "Package \"{$package->name}\" v{$package->version} is now published.");
    }

    /**
     * Reject a publication request and revert to draft (owner only).
     */
    public function rejectReview(KnowledgePackage $package)
    {
        if (! $package->isApprovable()) {
            return back()->withErrors(['status' => 'Only publication-requested packages can be rejected.']);
        }

        $package->update(['status' => 'draft']);

        return back()->with('success', __('ui.review_rejected'));
    }

    /**
     * Create a new version by cloning items from a published package.
     */
    public function newVersion(KnowledgePackage $package)
    {
        // Only published packages can spawn new versions
        if ($package->status !== 'published') {
            return back()->withErrors(['status' => 'Can only create new version from a published package.']);
        }

        $newPackage = DB::transaction(function () use ($package) {
            // Clone the package with incremented version
            $newPackage = KnowledgePackage::create([
                'workspace_id' => $package->workspace_id,
                'name' => $package->name,
                'description' => $package->description,
                'version' => $package->version + 1,
                'status' => 'draft',
                'source_job_ids' => $package->source_job_ids,
                'ku_count' => $package->ku_count,
                'created_by' => auth()->id(),
            ]);

            // Clone all items, refreshing included_version to current KU version
            foreach ($package->items()->with('knowledgeUnit')->get() as $item) {
                KnowledgePackageItem::create([
                    'knowledge_dataset_id' => $newPackage->id,
                    'knowledge_unit_id' => $item->knowledge_unit_id,
                    'sort_order' => $item->sort_order,
                    'included_version' => $item->knowledgeUnit->version,
                ]);
            }

            return $newPackage;
        });

        return redirect()
            ->route('kp.show', $newPackage)
            ->with('success', "New version v{$newPackage->version} created as draft.");
    }

    /**
     * Export a published package as JSON (no embeddings per CTO directive).
     */
    public function export(KnowledgePackage $package)
    {
        $package->load(['items.knowledgeUnit']);

        $export = [
            'package_id' => $package->id,
            'name' => $package->name,
            'version' => $package->version,
            'status' => $package->status,
            'exported_at' => now()->toIso8601String(),
            'knowledge_units' => $package->items->map(function ($item) {
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

        $filename = str_replace(' ', '_', $package->name) . "_v{$package->version}.json";

        return response()->json($export)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Show the RAG chat interface for a published package.
     */
    public function chat(KnowledgePackage $package)
    {
        // Chat is restricted to published packages only
        if (! $package->isPublished()) {
            return redirect()->route('kp.show', $package)
                ->withErrors(['status' => 'Chat is only available for published packages.']);
        }

        return view('dashboard.datasets.chat', compact('package'));
    }

    /**
     * Show the retrieval quality evaluation page for a package.
     */
    public function evaluation(KnowledgePackage $package)
    {
        return view('dashboard.datasets.evaluation', compact('package'));
    }
}
