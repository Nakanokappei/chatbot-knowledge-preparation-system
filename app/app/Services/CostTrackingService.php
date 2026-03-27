<?php

namespace App\Services;

use App\Models\LlmModel;
use Illuminate\Support\Facades\DB;

/**
 * Token cost tracking and budget enforcement.
 *
 * CTO budget tiers:
 * - 80%: warning
 * - 100%: chat API stops
 * - 120%: all API stops except export
 */
class CostTrackingService
{
    /**
     * Record token usage and calculate cost.
     */
    public function recordUsage(
        int $workspaceId,
        ?int $userId,
        string $endpoint,
        string $modelId,
        int $inputTokens,
        int $outputTokens
    ): float {
        // Look up model pricing
        $model = LlmModel::where('model_id', $modelId)->first();
        $inputPrice = $model?->input_price_per_1m ?? 1.00;
        $outputPrice = $model?->output_price_per_1m ?? 5.00;

        // Calculate cost
        $cost = ($inputTokens * $inputPrice / 1_000_000) + ($outputTokens * $outputPrice / 1_000_000);

        // Record per-request usage
        DB::table('token_usage')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'model_id' => $modelId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => $cost,
            'created_at' => now(),
        ]);

        // Upsert daily cost summary
        // Route cost to the appropriate daily summary column by endpoint type
        $costColumn = match (true) {
            str_contains($endpoint, 'chat') => 'chat_cost',
            str_contains($endpoint, 'embed') || str_contains($endpoint, 'retrieve') => 'embedding_cost',
            default => 'pipeline_cost',
        };

        DB::statement("
            INSERT INTO daily_cost_summary (workspace_id, date, {$costColumn}, total_cost, total_tokens, request_count, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
            ON CONFLICT (workspace_id, date)
            DO UPDATE SET
                {$costColumn} = daily_cost_summary.{$costColumn} + EXCLUDED.{$costColumn},
                total_cost = daily_cost_summary.total_cost + EXCLUDED.total_cost,
                total_tokens = daily_cost_summary.total_tokens + EXCLUDED.total_tokens,
                request_count = daily_cost_summary.request_count + 1,
                updated_at = NOW()
        ", [$workspaceId, now()->toDateString(), $cost, $cost, $inputTokens + $outputTokens]);

        return $cost;
    }

    /**
     * Get current month token usage for a workspace.
     */
    public function getMonthlyUsage(int $workspaceId): array
    {
        $usage = DB::table('daily_cost_summary')
            ->where('workspace_id', $workspaceId)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(total_tokens) as tokens, SUM(total_cost) as cost, SUM(request_count) as requests')
            ->first();

        return [
            'tokens' => (int) ($usage->tokens ?? 0),
            'cost' => (float) ($usage->cost ?? 0),
            'requests' => (int) ($usage->requests ?? 0),
        ];
    }

    /**
     * Check budget status for a workspace.
     *
     * Returns: ok, warning (80%), exceeded (100%), hard_limit (120%)
     */
    public function checkBudgetStatus(int $workspaceId, int $monthlyBudget): string
    {
        $usage = $this->getMonthlyUsage($workspaceId);
        $percentage = $monthlyBudget > 0 ? ($usage['tokens'] / $monthlyBudget) * 100 : 0;

        if ($percentage >= 120) {
            return 'hard_limit';  // All API stop except export
        }
        if ($percentage >= 100) {
            return 'exceeded';    // Chat API stop
        }
        if ($percentage >= 80) {
            return 'warning';     // Warning banner
        }

        return 'ok';
    }

    /**
     * Increment the daily chat_answers counter.
     */
    public function recordChatAnswer(int $workspaceId): void
    {
        DB::statement("
            INSERT INTO daily_cost_summary (workspace_id, date, chat_answers, created_at, updated_at)
            VALUES (?, ?, 1, NOW(), NOW())
            ON CONFLICT (workspace_id, date)
            DO UPDATE SET chat_answers = daily_cost_summary.chat_answers + 1, updated_at = NOW()
        ", [$workspaceId, now()->toDateString()]);
    }

    /**
     * Increment the daily upvote or downvote counter.
     */
    public function recordFeedback(int $workspaceId, string $vote): void
    {
        $column = $vote === 'up' ? 'upvotes' : 'downvotes';
        DB::statement("
            INSERT INTO daily_cost_summary (workspace_id, date, {$column}, created_at, updated_at)
            VALUES (?, ?, 1, NOW(), NOW())
            ON CONFLICT (workspace_id, date)
            DO UPDATE SET {$column} = daily_cost_summary.{$column} + 1, updated_at = NOW()
        ", [$workspaceId, now()->toDateString()]);
    }
}
