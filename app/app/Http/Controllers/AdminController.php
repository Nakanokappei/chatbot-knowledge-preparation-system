<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\PipelineJob;
use App\Models\Workspace;
use App\Services\CostTrackingService;
use App\Services\SystemMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * System administrator dashboard controller.
 *
 * Provides cross-workspace views: workspace list, pipeline overview,
 * usage statistics, and workspace creation.
 */
class AdminController extends Controller
{
    /**
     * Admin top page — workspace list sidebar + usage for selected workspace.
     *
     * The sidebar shows all workspaces and all pipeline jobs.
     * Main content shows usage for the selected workspace or aggregate.
     */
    public function index(Request $request): View
    {
        $workspaces = Workspace::orderBy('name')->get();
        $selectedWorkspaceId = $request->query('workspace');
        $pipelineView = $request->query('pipeline');
        $pipelineFilter = $request->query('pf', 'all');

        // All pipeline jobs across all workspaces, with workspace name for display
        $allJobs = PipelineJob::with(['dataset:id,name', 'workspace:id,name'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Count by status bucket (same logic as EmbeddingController)
        $jobStats = [
            'total'      => $allJobs->count(),
            'completed'  => $allJobs->filter(fn($j) => $j->status === 'completed')->count(),
            'processing' => $allJobs->filter(fn($j) => !in_array($j->status, ['completed', 'failed', 'cancelled']))->count(),
            'failed'     => $allJobs->filter(fn($j) => in_array($j->status, ['failed', 'cancelled']))->count(),
        ];

        $filteredJobs = match ($pipelineFilter) {
            'completed'  => $allJobs->filter(fn($j) => $j->status === 'completed'),
            'processing' => $allJobs->filter(fn($j) => !in_array($j->status, ['completed', 'failed', 'cancelled'])),
            'failed'     => $allJobs->filter(fn($j) => in_array($j->status, ['failed', 'cancelled'])),
            default      => $allJobs,
        };

        // Initialize workspace management data
        $workspaceInvitations = collect();

        // When pipeline view is active, suppress workspace usage panel
        if ($pipelineView) {
            $usageData = null;
            $selectedWorkspace = null;
            $selectedWorkspaceId = null;
        } elseif ($selectedWorkspaceId && $selectedWorkspaceId !== 'all') {
            $usageData = $this->getWorkspaceUsage((int) $selectedWorkspaceId);
            $selectedWorkspace = $workspaces->firstWhere('id', $selectedWorkspaceId);
            // Load members and pending invitations for workspace management panel
            if ($selectedWorkspace) {
                $selectedWorkspace->load('users');
                $workspaceInvitations = Invitation::where('workspace_id', $selectedWorkspaceId)
                    ->whereNull('accepted_at')
                    ->orderByDesc('created_at')
                    ->get();
            }
        } else {
            $usageData = $this->getAggregateUsage();
            $selectedWorkspace = null;
        }

        return view('admin.index', compact(
            'workspaces', 'filteredJobs', 'jobStats', 'usageData',
            'selectedWorkspaceId', 'selectedWorkspace',
            'pipelineView', 'pipelineFilter',
            'workspaceInvitations'
        ));
    }

    /**
     * System health dashboard: infrastructure and application metrics (past 24 hours).
     *
     * Fetches ECS CPU/memory and RDS connections from CloudWatch, and
     * chat latency, pipeline duration, error rate from the database.
     */
    public function systemHealth(): View
    {
        $metricsService = new SystemMetricsService();
        $metrics = $metricsService->getLast24Hours();

        return view('admin.system', compact('metrics'));
    }

    /**
     * Cancel a pipeline job from the admin dashboard.
     */
    public function cancelJob(PipelineJob $pipelineJob): RedirectResponse
    {
        if (!in_array($pipelineJob->status, ['completed', 'failed', 'cancelled'])) {
            $pipelineJob->update(['status' => 'cancelled']);
        }

        return redirect()->route('admin.index', ['pipeline' => 'jobs', 'pf' => request('pf', 'all')])
            ->with('success', __('ui.job_cancelled'));
    }

    /**
     * Show the workspace creation form.
     */
    public function createWorkspace(): View
    {
        return view('admin.workspaces.create');
    }

    /**
     * Create a new workspace.
     *
     * The Workspace model's booted() method auto-provisions default
     * LLM models when a workspace is created.
     */
    public function storeWorkspace(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Workspace::create([
            'name' => $request->name,
        ]);

        return redirect()->route('admin.index')
            ->with('success', __('ui.workspace_created'));
    }

    /**
     * Delete a workspace and all its associated data.
     *
     * Cascading deletes are incomplete in the schema, so we explicitly
     * delete related records in dependency order within a transaction.
     */
    public function destroyWorkspace(Request $request, Workspace $workspace): RedirectResponse
    {
        // Require confirmation by typing the workspace name
        $request->validate([
            'confirm_name' => 'required|string',
        ]);

        if ($request->confirm_name !== $workspace->name) {
            return redirect()->route('admin.index', ['workspace' => $workspace->id])
                ->withErrors(['confirm_name' => __('ui.workspace_delete_name_mismatch')]);
        }

        $workspaceName = $workspace->name;
        $wid = $workspace->id;

        DB::transaction(function () use ($wid) {
            // Delete in dependency order (children before parents)
            // Chat turns depend on chat sessions
            DB::table('chat_turns')
                ->whereIn('session_id', fn($q) => $q->select('id')->from('chat_sessions')->where('workspace_id', $wid))
                ->delete();
            DB::table('chat_sessions')->where('workspace_id', $wid)->delete();

            // Embed API keys depend on knowledge packages
            DB::table('embed_api_keys')->where('workspace_id', $wid)->delete();

            // Knowledge package items depend on knowledge packages
            DB::table('knowledge_package_items')
                ->whereIn('knowledge_package_id', fn($q) => $q->select('id')->from('knowledge_packages')->where('workspace_id', $wid))
                ->delete();
            DB::table('knowledge_packages')->where('workspace_id', $wid)->delete();

            // Feedback and knowledge units
            DB::table('answer_feedback')->where('workspace_id', $wid)->delete();
            DB::table('knowledge_units')->where('workspace_id', $wid)->delete();

            // Clusters depend on pipeline jobs
            DB::table('clusters')
                ->whereIn('pipeline_job_id', fn($q) => $q->select('id')->from('pipeline_jobs')->where('workspace_id', $wid))
                ->delete();
            DB::table('exports')->where('workspace_id', $wid)->delete();
            DB::table('pipeline_jobs')->where('workspace_id', $wid)->delete();

            // Dataset rows depend on datasets
            DB::table('dataset_rows')
                ->whereIn('dataset_id', fn($q) => $q->select('id')->from('datasets')->where('workspace_id', $wid))
                ->delete();
            DB::table('datasets')->where('workspace_id', $wid)->delete();

            // Cost tracking
            DB::table('token_usage')->where('workspace_id', $wid)->delete();
            DB::table('daily_cost_summary')->where('workspace_id', $wid)->delete();

            // Invitations and users
            DB::table('invitations')->where('workspace_id', $wid)->delete();

            // Nullify workspace-scoped model templates (SET NULL behavior)
            DB::table('llm_models')->where('workspace_id', $wid)->delete();
            DB::table('embedding_models')->where('workspace_id', $wid)->delete();
            DB::table('embeddings')->where('workspace_id', $wid)->delete();

            // Users in this workspace
            DB::table('users')->where('workspace_id', $wid)->delete();

            // Finally delete the workspace itself
            DB::table('workspaces')->where('id', $wid)->delete();
        });

        return redirect()->route('admin.index')
            ->with('success', __('ui.workspace_deleted', ['name' => $workspaceName]));
    }

    /**
     * Invite a user to a workspace (reuses the invitation token system).
     *
     * Creates an Invitation record with a unique token. The invited user
     * registers via the token URL, same as workspace-level invitations.
     */
    public function inviteToWorkspace(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:owner,member',
        ]);

        // Check if user already exists in this workspace
        $existingUser = \App\Models\User::where('email', $request->email)
            ->where('workspace_id', $workspace->id)
            ->first();
        if ($existingUser) {
            return redirect()->route('admin.index', ['workspace' => $workspace->id])
                ->withErrors(['email' => __('ui.user_already_in_workspace')]);
        }

        // Check for pending invitation with same email
        $pendingInvite = Invitation::where('workspace_id', $workspace->id)
            ->where('email', $request->email)
            ->whereNull('accepted_at')
            ->first();
        if ($pendingInvite) {
            return redirect()->route('admin.index', ['workspace' => $workspace->id])
                ->withErrors(['email' => __('ui.invitation_already_pending')]);
        }

        // Create invitation with token
        $invitation = Invitation::create([
            'workspace_id' => $workspace->id,
            'invited_by' => auth()->id(),
            'email' => $request->email,
            'token' => Str::random(64),
            'role' => $request->role,
        ]);

        $inviteUrl = route('invitation.register', $invitation->token);

        return redirect()->route('admin.index', ['workspace' => $workspace->id])
            ->with('success', __('ui.invitation_sent'))
            ->with('invite_url', $inviteUrl);
    }

    /**
     * Get usage data for a specific workspace.
     */
    private function getWorkspaceUsage(int $workspaceId): array
    {
        $costService = new CostTrackingService();
        $monthly = $costService->getMonthlyUsage($workspaceId);

        $dailyTrend = DB::table('daily_cost_summary')
            ->where('workspace_id', $workspaceId)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get(['date', 'embedding_cost', 'chat_cost', 'pipeline_cost', 'total_cost', 'total_tokens', 'request_count', 'chat_answers', 'upvotes', 'downvotes']);

        $pipelineDailyStats = $this->getPipelineDailyStats(workspaceId: $workspaceId);

        return [
            'monthly' => $monthly,
            'dailyTrend' => $dailyTrend,
            'pipelineDailyStats' => $pipelineDailyStats,
        ];
    }

    /**
     * Get aggregate usage across all workspaces.
     */
    private function getAggregateUsage(): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        // Aggregate monthly stats across all workspaces
        $monthly = DB::table('token_usage')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('
                COALESCE(SUM(input_tokens + output_tokens), 0) as tokens,
                COALESCE(SUM(estimated_cost), 0) as cost,
                COUNT(*) as requests
            ')
            ->first();

        $dailyTrend = DB::table('daily_cost_summary')
            ->where('date', '>=', $thirtyDaysAgo->toDateString())
            ->groupBy('date')
            ->selectRaw('
                date,
                SUM(embedding_cost) as embedding_cost,
                SUM(chat_cost) as chat_cost,
                SUM(pipeline_cost) as pipeline_cost,
                SUM(total_cost) as total_cost,
                SUM(total_tokens) as total_tokens,
                SUM(request_count) as request_count,
                SUM(chat_answers) as chat_answers,
                SUM(upvotes) as upvotes,
                SUM(downvotes) as downvotes
            ')
            ->orderBy('date')
            ->get();

        $pipelineDailyStats = $this->getPipelineDailyStats();

        return [
            'monthly' => [
                'tokens' => (int) $monthly->tokens,
                'cost' => (float) $monthly->cost,
                'requests' => (int) $monthly->requests,
            ],
            'dailyTrend' => $dailyTrend,
            'pipelineDailyStats' => $pipelineDailyStats,
        ];
    }

    /**
     * Count completed and failed pipeline jobs per day for the last 30 days.
     *
     * Used to render the stacked bar chart on the admin usage panel.
     * When workspace_id is given, scopes to that workspace; otherwise all.
     */
    private function getPipelineDailyStats(?int $workspaceId = null): \Illuminate\Support\Collection
    {
        $query = DB::table('pipeline_jobs')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("
                DATE(created_at AT TIME ZONE 'Asia/Tokyo') as date,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status IN ('failed', 'cancelled') THEN 1 ELSE 0 END) as failed
            ")
            ->groupBy(DB::raw("DATE(created_at AT TIME ZONE 'Asia/Tokyo')"))
            ->orderBy('date');

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return $query->get();
    }
}
