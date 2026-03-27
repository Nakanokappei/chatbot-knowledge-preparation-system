<?php

namespace App\Http\Controllers;

use App\Services\CostTrackingService;
use Illuminate\Support\Facades\DB;

/**
 * Usage dashboard — monthly usage by tenant, endpoint, model, and daily trend.
 */
class UsageController extends Controller
{
    /**
     * Display the cost dashboard with monthly summary, daily trend,
     * and breakdowns by endpoint and model for the last 30 days.
     */
    public function index()
    {
        $tenantId = auth()->user()->tenant_id;
        $costService = new CostTrackingService();

        // Monthly summary
        $monthly = $costService->getMonthlyUsage($tenantId);

        // Daily trend (last 30 days)
        $dailyTrend = DB::table('daily_cost_summary')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get(['date', 'embedding_cost', 'chat_cost', 'pipeline_cost', 'total_cost', 'total_tokens', 'request_count', 'chat_answers', 'upvotes', 'downvotes']);

        $thirtyDaysAgo = now()->subDays(30);

        // Cost by endpoint
        $byEndpoint = DB::table('token_usage')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('endpoint')
            ->selectRaw('endpoint, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        // Cost by model
        $byModel = DB::table('token_usage')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->groupBy('model_id')
            ->selectRaw('model_id, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        return view('dashboard.usage', compact(
            'monthly', 'dailyTrend', 'byEndpoint', 'byModel'
        ));
    }
}
