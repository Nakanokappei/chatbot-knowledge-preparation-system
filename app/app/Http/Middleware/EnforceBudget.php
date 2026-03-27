<?php

namespace App\Http\Middleware;

use App\Services\CostTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Budget enforcement middleware.
 *
 * CTO-defined tiers:
 * - 80%: warning header (X-Budget-Warning)
 * - 100%: chat API blocked
 * - 120%: all API blocked except export
 */
class EnforceBudget
{
    /**
     * Check the workspace's token budget and block or warn as appropriate.
     * Unauthenticated requests pass through without budget checks.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Skip budget enforcement for unauthenticated requests
        if (! $user) {
            return $next($request);
        }

        $workspace = $user->workspace;
        $budget = $workspace->monthly_token_budget ?? 1_000_000;
        $costService = new CostTrackingService();
        $status = $costService->checkBudgetStatus($user->workspace_id, $budget);

        $path = $request->path();
        $isExport = str_contains($path, 'export');
        $isChat = str_contains($path, 'chat');

        // Hard limit (120%): block everything except export
        if ($status === 'hard_limit' && ! $isExport) {
            return response()->json([
                'error' => 'Monthly token budget exceeded (120%). Only export is available.',
                'budget_status' => $status,
            ], 429)->withHeaders(['X-Budget-Status' => $status]);
        }

        // Exceeded (100%): block chat API
        if ($status === 'exceeded' && $isChat) {
            return response()->json([
                'error' => 'Monthly token budget exceeded. Chat API is disabled.',
                'budget_status' => $status,
            ], 429)->withHeaders(['X-Budget-Status' => $status]);
        }

        $response = $next($request);

        // Warning (80%): add header
        if ($status === 'warning') {
            $response->headers->set('X-Budget-Warning', 'Token budget at 80%+');
            $response->headers->set('X-Budget-Status', $status);
        }

        return $response;
    }
}
