<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
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
     *
     * Resolves the workspace from the authenticated user, or — for the public
     * embed chat API, which has no Sanctum/session user — from the embed
     * workspace bound by EmbedApiKeyAuth. Only requests with no workspace
     * context at all pass through unchecked.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = $user?->workspace_id
            ?? $request->attributes->get('embed_workspace_id');

        // No workspace context (truly anonymous, non-embed request) → skip
        if (! $workspaceId) {
            return $next($request);
        }

        $workspace = $user?->workspace ?? Workspace::find($workspaceId);
        $budget = $workspace->monthly_token_budget ?? 1_000_000;
        $costService = new CostTrackingService();
        $status = $costService->checkBudgetStatus($workspaceId, $budget);

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
