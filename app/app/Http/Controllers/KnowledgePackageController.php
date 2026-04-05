<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePackage;
use App\Models\KnowledgePackageItem;
use App\Models\KnowledgeUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // Sort: published first (newest version), then draft/requested, archived last (oldest version)
        $packages = KnowledgePackage::where('workspace_id', $workspaceId)
            ->orderByRaw("CASE status
                WHEN 'published' THEN 0
                WHEN 'publication_requested' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'archived' THEN 3
                END")
            ->orderByRaw("CASE WHEN status = 'archived' THEN version END ASC")
            ->orderByRaw("CASE WHEN status != 'archived' THEN version END DESC")
            ->get();

        return view('dashboard.datasets.index', compact('packages'));
    }

    /**
     * Show the create form with available approved Knowledge Units.
     */
    public function create(Request $request)
    {
        $workspaceId = auth()->user()->workspace_id;

        // Load embeddings that have at least one approved KU.
        // PostgreSQL cannot reference subquery aliases in HAVING, so filter in PHP.
        $embeddings = \App\Models\Embedding::where('workspace_id', $workspaceId)
            ->where('status', 'ready')
            ->withCount(['knowledgeUnits as approved_ku_count' => fn($q) => $q->where('review_status', 'approved')])
            ->with('dataset:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn($e) => $e->approved_ku_count > 0)
            ->values();

        return view('dashboard.datasets.create', compact('embeddings'));
    }

    /**
     * Store a new package from all approved KUs in the selected embedding(s).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'embedding_ids' => 'required|array|min:1',
            'embedding_ids.*' => 'exists:embeddings,id',
        ]);

        $workspaceId = auth()->user()->workspace_id;

        // Collect all approved KUs from the selected embeddings
        $selectedKUs = KnowledgeUnit::where('workspace_id', $workspaceId)
            ->where('review_status', 'approved')
            ->whereIn('embedding_id', $request->embedding_ids)
            ->orderBy('embedding_id')
            ->orderBy('topic')
            ->get();

        if ($selectedKUs->isEmpty()) {
            return back()->withErrors(['embedding_ids' => __('ui.no_approved_kus_in_embedding')]);
        }

        $package = DB::transaction(function () use ($request, $workspaceId, $selectedKUs) {
            $package = KnowledgePackage::create([
                'workspace_id' => $workspaceId,
                'name' => $request->name,
                'description' => $request->description,
                'version' => 1,
                'status' => 'draft',
                'ku_count' => $selectedKUs->count(),
                'created_by' => auth()->id(),
            ]);

            foreach ($selectedKUs->values() as $index => $ku) {
                KnowledgePackageItem::create([
                    'knowledge_package_id' => $package->id,
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

        $package->update(['status' => 'publication_requested']);

        return back()->with('success', __('ui.review_submitted'));
    }

    /**
     * Publish a package — owner-only authorization step.
     *
     * Owners can publish from both draft (shortcut) and publication_requested states.
     * Members must go through submitForReview first.
     */
    public function publish(KnowledgePackage $package)
    {
        // Owners can publish from draft, publication_requested, or re-publish
        if (! in_array($package->status, ['draft', 'publication_requested', 'published'])) {
            return back()->withErrors(['status' => 'Cannot publish this package.']);
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
     * Refresh KU list: re-sync with current approved KUs from the same embeddings.
     *
     * Replaces all package items with the latest approved KUs from the
     * embeddings that the package's current KUs belong to.
     */
    public function refreshKUs(KnowledgePackage $package)
    {
        $workspaceId = auth()->user()->workspace_id;

        // Find which embeddings are currently represented in this package
        $embeddingIds = KnowledgeUnit::whereIn('id',
            $package->items()->pluck('knowledge_unit_id')
        )->pluck('embedding_id')->unique()->filter()->values();

        if ($embeddingIds->isEmpty()) {
            return back()->with('error', __('ui.no_embeddings_in_package'));
        }

        DB::transaction(function () use ($package, $workspaceId, $embeddingIds) {
            // Delete existing items
            $package->items()->delete();

            // Re-add all approved KUs from those embeddings
            $approvedKUs = KnowledgeUnit::where('workspace_id', $workspaceId)
                ->where('review_status', 'approved')
                ->whereIn('embedding_id', $embeddingIds)
                ->orderBy('embedding_id')
                ->orderBy('topic')
                ->get();

            foreach ($approvedKUs->values() as $index => $ku) {
                KnowledgePackageItem::create([
                    'knowledge_package_id' => $package->id,
                    'knowledge_unit_id' => $ku->id,
                    'sort_order' => $index,
                    'included_version' => $ku->version,
                ]);
            }

            $package->update(['ku_count' => $approvedKUs->count()]);
        });

        return back()->with('success', __('ui.package_kus_refreshed', ['count' => $package->ku_count]));
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

    /**
     * Update the embed appearance configuration stored in embed_config_json.
     *
     * Accepts title, greeting, placeholder, theme, color, icon_url, and openers.
     * Merges into existing config so partial updates are safe.
     */
    public function updateEmbedConfig(Request $request, KnowledgePackage $package): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'nullable|string|max:100',
            'greeting'    => 'nullable|string|max:500',
            'placeholder' => 'nullable|string|max:200',
            'theme'       => 'nullable|in:light,dark',
            'color'       => ['nullable', 'regex:/^#[0-9a-fA-F]{3,6}$/'],
            'icon_url'    => 'nullable|url|max:500',
            'openers'     => 'nullable|array|max:3',
            'openers.*'   => 'string|max:200',
        ]);

        // Filter out empty strings so we don't store blank openers
        if (isset($validated['openers'])) {
            $validated['openers'] = array_values(array_filter($validated['openers'], fn($v) => trim($v) !== ''));
        }

        // Merge with existing config to allow partial updates
        $existing = $package->embed_config_json ?? [];
        $package->update([
            'embed_config_json' => array_merge($existing, $validated),
        ]);

        return response()->json(['success' => true, 'config' => $package->embed_config_json]);
    }

    /**
     * Export the package as a frequency-sorted FAQ document in Markdown format.
     *
     * KUs are sorted by row_count descending (proxy for inquiry frequency).
     * Output includes question, answer, and supplementary details.
     */
    public function exportFaq(KnowledgePackage $package): StreamedResponse
    {
        $package->load(['items.knowledgeUnit']);

        // Sort KUs by row_count descending (most frequent inquiries first)
        $sortedItems = $package->items->sortByDesc(fn($item) => $item->knowledgeUnit->row_count ?? 0)->values();

        $filename = str_replace(' ', '_', $package->name) . "_v{$package->version}_FAQ.md";

        return response()->streamDownload(function () use ($package, $sortedItems) {
            echo "# FAQ — {$package->name} v{$package->version}\n";
            echo "Export date: " . now()->format('Y-m-d') . "\n\n";
            echo "---\n\n";

            foreach ($sortedItems as $index => $item) {
                $ku = $item->knowledgeUnit;
                $number = $index + 1;
                $rowCount = $ku->row_count ?? 0;

                // Topic header with inquiry count
                echo "## {$number}. {$ku->topic}";
                if ($rowCount > 0) {
                    echo " ({$rowCount} " . ($rowCount === 1 ? 'case' : 'cases') . ")";
                }
                echo "\n\n";

                // Question: prefer the explicit question field, fall back to topic + intent
                $question = $ku->question ?: "{$ku->topic} — {$ku->intent}";
                echo "**Q:** {$question}\n\n";

                // Answer: prefer resolution_summary, fall back to summary
                $answer = $ku->resolution_summary ?: $ku->summary ?: '(No answer available)';
                echo "**A:** {$answer}\n\n";

                // Supplementary details if available
                $supplements = [];
                if (!empty($ku->symptoms)) {
                    $supplements[] = "**Symptoms:** {$ku->symptoms}";
                }
                if (!empty($ku->cause_summary)) {
                    $supplements[] = "**Cause:** {$ku->cause_summary}";
                }
                if (!empty($supplements)) {
                    echo implode("\n\n", $supplements) . "\n\n";
                }

                echo "---\n\n";
            }
        }, $filename, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }
}
