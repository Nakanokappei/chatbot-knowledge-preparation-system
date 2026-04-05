<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeUnit;
use App\Models\Workspace;
use App\Services\CostTrackingService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin usage controller — aggregate and per-workspace usage views.
 *
 * System admins can view usage across all workspaces or drill into
 * a specific workspace's usage data with full cost visibility.
 */
class AdminUsageController extends Controller
{
    /**
     * Aggregate usage across all workspaces.
     */
    public function index(): View
    {
        $workspaces = Workspace::orderBy('name')->get();
        $thirtyDaysAgo = now()->subDays(30);

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

        $byEndpoint = DB::table('token_usage')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('endpoint')
            ->selectRaw('endpoint, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        $byModel = DB::table('token_usage')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('model_id')
            ->selectRaw('model_id, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        return view('dashboard.usage', [
            'monthly' => [
                'tokens' => (int) $monthly->tokens,
                'cost' => (float) $monthly->cost,
                'requests' => (int) $monthly->requests,
            ],
            'dailyTrend' => $dailyTrend,
            'byEndpoint' => $byEndpoint,
            'byModel' => $byModel,
            'isAdminView' => true,
            'workspaceName' => __('ui.all_workspaces'),
        ]);
    }

    /**
     * Usage for a specific workspace.
     */
    public function show(Workspace $workspace): View
    {
        $costService = new CostTrackingService();
        $monthly = $costService->getMonthlyUsage($workspace->id);
        $thirtyDaysAgo = now()->subDays(30);

        $dailyTrend = DB::table('daily_cost_summary')
            ->where('workspace_id', $workspace->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get(['date', 'embedding_cost', 'chat_cost', 'pipeline_cost', 'total_cost', 'total_tokens', 'request_count', 'chat_answers', 'upvotes', 'downvotes']);

        $byEndpoint = DB::table('token_usage')
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('endpoint')
            ->selectRaw('endpoint, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        $byModel = DB::table('token_usage')
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('model_id')
            ->selectRaw('model_id, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        // Chat analytics for this workspace (reuse UsageController logic)
        $chatAnalytics = (new UsageController())->buildChatAnalyticsPublic($workspace->id, $thirtyDaysAgo);

        // Top KUs for this workspace
        $topKUs = KnowledgeUnit::where('workspace_id', $workspace->id)
            ->where('usage_count', '>', 0)
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get(['id', 'topic', 'intent', 'usage_count']);

        return view('dashboard.usage', [
            'monthly' => $monthly,
            'dailyTrend' => $dailyTrend,
            'byEndpoint' => $byEndpoint,
            'byModel' => $byModel,
            'chatAnalytics' => $chatAnalytics,
            'topKUs' => $topKUs,
            'isAdminView' => true,
            'workspaceName' => $workspace->name,
        ]);
    }
}
