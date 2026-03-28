<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Sanctum token abilities only when the request is authenticated
 * via a personal access token (not a session cookie).
 *
 * Session-authenticated requests (browser UI via /web-api/*) bypass
 * the ability check since they always have full access.
 */
class CheckTokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        // Session-based auth (web UI) — skip ability check
        if (! $request->user()?->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return $next($request);
        }

        // Token-based auth — verify the token has at least one of the required abilities
        $token = $request->user()->currentAccessToken();
        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This token does not have the required ability: ' . implode(', ', $abilities),
        ], 403);
    }
}
