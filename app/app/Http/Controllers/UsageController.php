<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeUnit;
use App\Services\CostTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Usage dashboard — usage by workspace, endpoint, model, daily trend,
 * and chat analytics (sessions, response time, action breakdown, channels).
 *
 * Supports configurable date range via ?period= query parameter.
 */
class UsageController extends Controller
{
    /**
     * Determine the aggregation granularity based on the number of days.
     *
     * Returns 'day', 'week', or 'month'.
     */
    public static function resolveGranularity(int $days): string
    {
        if ($days <= 30) return 'day';
        if ($days <= 90) return 'week';
        return 'month';
    }

    /**
     * Build an aggregated daily_cost_summary query grouped by granularity.
     */
    public static function buildTrendQuery($query, string $granularity)
    {
        if ($granularity === 'day') {
            return $query->orderBy('date')
                ->get(['date', 'embedding_cost', 'chat_cost', 'pipeline_cost', 'total_cost', 'total_tokens', 'request_count', 'chat_answers', 'upvotes', 'downvotes']);
        }

        $truncExpr = $granularity === 'week'
            ? "DATE_TRUNC('week', date)::date"
            : "DATE_TRUNC('month', date)::date";

        return $query
            ->groupByRaw($truncExpr)
            ->selectRaw("{$truncExpr} as date,
                SUM(embedding_cost) as embedding_cost, SUM(chat_cost) as chat_cost,
                SUM(pipeline_cost) as pipeline_cost, SUM(total_cost) as total_cost,
                SUM(total_tokens) as total_tokens, SUM(request_count) as request_count,
                SUM(chat_answers) as chat_answers, SUM(upvotes) as upvotes, SUM(downvotes) as downvotes")
            ->orderByRaw($truncExpr)
            ->get();
    }

    /**
     * Resolve the selected period into start/end Carbon dates.
     *
     * Returns [period_key, start_date, end_date].
     */
    public static function resolvePeriod(?string $period): array
    {
        $period = $period ?: 'last_30';

        // All periods use Asia/Tokyo for consistency with chat analytics
        $now = Carbon::now('Asia/Tokyo');

        $map = [
            'today'     => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfDay()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(Carbon::MONDAY), $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->endOfDay()],
            'last_7'    => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_28'   => [$now->copy()->subDays(27)->startOfDay(), $now->copy()->endOfDay()],
            'last_30'   => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'last_90'   => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'last_12m'  => [$now->copy()->subMonths(12)->startOfDay(), $now->copy()->endOfDay()],
        ];

        if (!isset($map[$period])) {
            $period = 'last_30';
        }

        [$start, $end] = $map[$period];

        return [$period, $start, $end];
    }

    /**
     * Display the usage dashboard with configurable date range.
     */
    public function index(Request $request)
    {
        $workspaceId = auth()->user()->workspace_id;
        $costService = new CostTrackingService();

        // Resolve period from query parameter
        [$period, $startDate, $endDate] = self::resolvePeriod($request->input('period'));
        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();

        // Summary for the selected period
        $monthly = $costService->getMonthlyUsage($workspaceId, $startDateStr, $endDateStr);

        // Determine aggregation granularity based on period length
        $days = $startDate->diffInDays($endDate) + 1;
        $granularity = self::resolveGranularity($days);

        // Trend data aggregated by granularity (day/week/month)
        $dailyTrend = self::buildTrendQuery(
            DB::table('daily_cost_summary')
                ->where('workspace_id', $workspaceId)
                ->where('date', '>=', $startDateStr)
                ->where('date', '<=', $endDateStr),
            $granularity
        );

        // Cost by endpoint
        $byEndpoint = DB::table('token_usage')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->groupBy('endpoint')
            ->selectRaw('endpoint, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        // Cost by model
        $byModel = DB::table('token_usage')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->groupBy('model_id')
            ->selectRaw('model_id, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost, COUNT(*) as requests')
            ->orderByDesc('cost')
            ->get();

        // Top searched Knowledge Units
        $topKUs = KnowledgeUnit::where('workspace_id', $workspaceId)
            ->where('usage_count', '>', 0)
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get(['id', 'topic', 'intent', 'usage_count']);

        // Chat analytics for the selected period
        $chatAnalytics = $this->buildChatAnalytics($workspaceId, $startDate);

        return view('dashboard.usage', compact(
            'monthly', 'dailyTrend', 'byEndpoint', 'byModel', 'topKUs', 'chatAnalytics',
            'period', 'startDate', 'endDate', 'granularity'
        ));
    }

    /**
     * Public accessor for chat analytics — used by AdminUsageController.
     */
    public function buildChatAnalyticsPublic(int $workspaceId, $since): array
    {
        return $this->buildChatAnalytics($workspaceId, $since);
    }

    /**
     * Build chat analytics from chat_sessions and chat_turns tables.
     *
     * Returns summary stats, daily action trend, and channel breakdown
     * for the given workspace since the specified date.
     */
    private function buildChatAnalytics(int $workspaceId, $since): array
    {
        // Summary: session count, avg turns, avg response time, resolution rate
        $summary = DB::selectOne("
            SELECT
                COUNT(DISTINCT cs.id) AS total_sessions,
                COUNT(ct.id) FILTER (WHERE ct.role = 'assistant') AS total_assistant_turns,
                ROUND(AVG(ct.response_ms) FILTER (WHERE ct.role = 'assistant' AND ct.response_ms IS NOT NULL)) AS avg_response_ms,
                COUNT(ct.id) FILTER (WHERE ct.action IN ('answer', 'answer_broad')) AS answered,
                COUNT(ct.id) FILTER (WHERE ct.action IN ('answer', 'answer_broad', 'no_match', 'rejected')) AS total_actionable
            FROM chat_sessions cs
            LEFT JOIN chat_turns ct ON ct.session_id = cs.id
            WHERE cs.workspace_id = ? AND cs.created_at >= ?
        ", [$workspaceId, $since]);

        // Compute derived metrics safely
        $totalSessions = (int) ($summary->total_sessions ?? 0);
        $totalTurns = (int) ($summary->total_assistant_turns ?? 0);
        $avgTurns = $totalSessions > 0 ? round($totalTurns / $totalSessions, 1) : 0;
        $avgResponseMs = (int) ($summary->avg_response_ms ?? 0);
        $answered = (int) ($summary->answered ?? 0);
        $totalActionable = (int) ($summary->total_actionable ?? 0);
        $resolutionRate = $totalActionable > 0 ? round(($answered / $totalActionable) * 100, 1) : 0;

        // Daily action trend: grouped by date and action type
        $dailyActions = DB::select("
            SELECT
                DATE(ct.created_at AT TIME ZONE 'Asia/Tokyo') AS date,
                ct.action,
                COUNT(*) AS count
            FROM chat_turns ct
            JOIN chat_sessions cs ON cs.id = ct.session_id
            WHERE cs.workspace_id = ? AND ct.role = 'assistant' AND ct.created_at >= ?
            GROUP BY date, ct.action
            ORDER BY date
        ", [$workspaceId, $since]);

        // Daily session count for overlay line
        $dailySessions = DB::select("
            SELECT DATE(created_at AT TIME ZONE 'Asia/Tokyo') AS date, COUNT(*) AS count
            FROM chat_sessions
            WHERE workspace_id = ? AND created_at >= ?
            GROUP BY date ORDER BY date
        ", [$workspaceId, $since]);

        // Channel breakdown: workspace chat vs embed widget
        $channels = DB::select("
            SELECT
                CASE
                    WHEN cs.knowledge_package_id IS NOT NULL THEN 'embed'
                    ELSE 'workspace'
                END AS channel,
                COUNT(DISTINCT cs.id) AS sessions,
                COUNT(ct.id) FILTER (WHERE ct.role = 'assistant') AS turns,
                ROUND(AVG(ct.response_ms) FILTER (WHERE ct.role = 'assistant' AND ct.response_ms IS NOT NULL)) AS avg_ms,
                COUNT(ct.id) FILTER (WHERE ct.action IN ('answer', 'answer_broad')) AS answered,
                COUNT(ct.id) FILTER (WHERE ct.action IN ('answer', 'answer_broad', 'no_match', 'rejected')) AS total_actionable
            FROM chat_sessions cs
            LEFT JOIN chat_turns ct ON ct.session_id = cs.id
            WHERE cs.workspace_id = ? AND cs.created_at >= ?
            GROUP BY channel
        ", [$workspaceId, $since]);

        return [
            'total_sessions' => $totalSessions,
            'avg_turns' => $avgTurns,
            'avg_response_ms' => $avgResponseMs,
            'resolution_rate' => $resolutionRate,
            'daily_actions' => $dailyActions,
            'daily_sessions' => $dailySessions,
            'channels' => $channels,
        ];
    }
}
