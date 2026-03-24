<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeUnit;
use App\Models\KnowledgeUnitReview;
use App\Models\KnowledgeUnitVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Controller for Knowledge Unit detail view, editing, and review workflow.
 *
 * CTO directives:
 * - Edit tracking: edited_by_user_id, edited_at, edit_comment
 * - Version increment on each edit (not on approval)
 * - Approved KUs are locked (immutable)
 * - Review state: draft → reviewed → approved / rejected, rejected → draft
 * - Immutable audit log via knowledge_unit_reviews
 */
class KnowledgeUnitController extends Controller
{
    /**
     * Allowed review_status transitions.
     */
    private const STATUS_TRANSITIONS = [
        'draft' => ['reviewed'],
        'reviewed' => ['approved', 'rejected'],
        'rejected' => ['draft'],
        'approved' => [],  // locked
    ];

    /**
     * Display a single Knowledge Unit with edit form and review actions.
     */
    public function show(KnowledgeUnit $knowledgeUnit): View
    {
        $knowledgeUnit->load(['versions' => fn ($q) => $q->orderByDesc('version')]);

        // Determine which status transitions are available
        $allowedTransitions = self::STATUS_TRANSITIONS[$knowledgeUnit->review_status] ?? [];

        return view('dashboard.knowledge_units.show', [
            'ku' => $knowledgeUnit,
            'allowedTransitions' => $allowedTransitions,
        ]);
    }

    /**
     * Update a Knowledge Unit's editable fields.
     *
     * Increments version, creates a snapshot, and records edit metadata.
     * CTO rule: approved KUs cannot be edited.
     */
    public function update(Request $request, KnowledgeUnit $knowledgeUnit): RedirectResponse
    {
        // CTO rule: approved KUs are immutable
        if (!$knowledgeUnit->isEditable()) {
            return redirect()->route('knowledge-units.show', $knowledgeUnit)
                ->with('error', 'Approved Knowledge Units cannot be edited. Reject first to re-edit.');
        }

        $request->validate([
            'topic' => 'required|string|max:200',
            'intent' => 'required|string|max:200',
            'summary' => 'required|string|max:5000',
            'cause_summary' => 'nullable|string|max:5000',
            'resolution_summary' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
            'edit_comment' => 'nullable|string|max:500',
        ]);

        $userId = auth()->id();

        DB::transaction(function () use ($request, $knowledgeUnit, $userId) {
            // Increment version
            $newVersion = $knowledgeUnit->version + 1;

            // Update the KU fields
            $knowledgeUnit->update([
                'topic' => $request->input('topic'),
                'intent' => $request->input('intent'),
                'summary' => $request->input('summary'),
                'cause_summary' => $request->input('cause_summary', ''),
                'resolution_summary' => $request->input('resolution_summary', ''),
                'notes' => $request->input('notes'),
                'version' => $newVersion,
                'edited_by_user_id' => $userId,
                'edited_at' => now(),
                'edit_comment' => $request->input('edit_comment'),
            ]);

            // Create version snapshot
            KnowledgeUnitVersion::create([
                'knowledge_unit_id' => $knowledgeUnit->id,
                'version' => $newVersion,
                'snapshot_json' => [
                    'topic' => $knowledgeUnit->topic,
                    'intent' => $knowledgeUnit->intent,
                    'summary' => $knowledgeUnit->summary,
                    'cause_summary' => $knowledgeUnit->cause_summary,
                    'resolution_summary' => $knowledgeUnit->resolution_summary,
                    'notes' => $knowledgeUnit->notes,
                    'keywords' => $knowledgeUnit->keywords_json,
                    'row_count' => $knowledgeUnit->row_count,
                    'review_status' => $knowledgeUnit->review_status,
                    'edit_comment' => $request->input('edit_comment'),
                    'edited_by_user_id' => $userId,
                ],
            ]);
        });

        return redirect()->route('knowledge-units.show', $knowledgeUnit)
            ->with('success', "Knowledge Unit updated (v{$knowledgeUnit->version}).");
    }

    /**
     * Change the review status of a Knowledge Unit.
     *
     * Creates an immutable audit record in knowledge_unit_reviews.
     * CTO rule: version does NOT increment on status change.
     */
    public function review(Request $request, KnowledgeUnit $knowledgeUnit): RedirectResponse
    {
        $request->validate([
            'new_status' => 'required|string|in:draft,reviewed,approved,rejected',
            'review_comment' => 'nullable|string|max:500',
        ]);

        $newStatus = $request->input('new_status');
        $currentStatus = $knowledgeUnit->review_status;
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        // Enforce valid transitions
        if (!in_array($newStatus, $allowed)) {
            return redirect()->route('knowledge-units.show', $knowledgeUnit)
                ->with('error', "Cannot transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        $userId = auth()->id();

        DB::transaction(function () use ($knowledgeUnit, $newStatus, $request, $userId) {
            // Update the KU status
            $knowledgeUnit->update(['review_status' => $newStatus]);

            // Create immutable audit record
            KnowledgeUnitReview::create([
                'knowledge_unit_id' => $knowledgeUnit->id,
                'reviewer_user_id' => $userId,
                'review_status' => $newStatus,
                'review_comment' => $request->input('review_comment'),
                'created_at' => now(),
            ]);
        });

        $statusLabels = [
            'reviewed' => 'marked as Reviewed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'draft' => 'reverted to Draft',
        ];

        return redirect()->route('knowledge-units.show', $knowledgeUnit)
            ->with('success', "Knowledge Unit {$statusLabels[$newStatus]}.");
    }

    /**
     * Display version history for a Knowledge Unit.
     */
    public function versions(KnowledgeUnit $knowledgeUnit): View
    {
        $versions = KnowledgeUnitVersion::where('knowledge_unit_id', $knowledgeUnit->id)
            ->orderByDesc('version')
            ->get();

        $reviews = KnowledgeUnitReview::where('knowledge_unit_id', $knowledgeUnit->id)
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard.knowledge_units.versions', [
            'ku' => $knowledgeUnit,
            'versions' => $versions,
            'reviews' => $reviews,
        ]);
    }
}
