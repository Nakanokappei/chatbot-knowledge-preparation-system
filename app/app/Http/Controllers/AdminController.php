<?php

namespace App\Http\Controllers;

use App\Models\PipelineJob;
use App\Models\Workspace;
use App\Services\CostTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Pipeline jobs across all workspaces (most recent 50)
        $pipelineJobs = PipelineJob::with('dataset')
            ->latest()
            ->limit(50)
            ->get();

        // Determine which usage data to show
        if ($selectedWorkspaceId && $selectedWorkspaceId !== 'all') {
            $usageData = $this->getWorkspaceUsage((int) $selectedWorkspaceId);
            $selectedWorkspace = $workspaces->firstWhere('id', $selectedWorkspaceId);
        } else {
            $usageData = $this->getAggregateUsage();
            $selectedWorkspace = null;
        }

        return view('admin.index', compact(
            'workspaces', 'pipelineJobs', 'usageData',
            'selectedWorkspaceId', 'selectedWorkspace'
        ));
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

        return [
            'monthly' => $monthly,
            'dailyTrend' => $dailyTrend,
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

        return [
            'monthly' => [
                'tokens' => (int) $monthly->tokens,
                'cost' => (float) $monthly->cost,
                'requests' => (int) $monthly->requests,
            ],
            'dailyTrend' => $dailyTrend,
        ];
    }
}
