<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide service registration and bootstrapping.
 *
 * Configures workspace-based rate limiters for the Retrieval and Chat
 * APIs, using per-workspace limits stored in the workspaces table.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services (none at this time).
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap rate limiters and other application services.
     */
    public function boot(): void
    {
        // Workspace-based rate limiting for Retrieval API
        RateLimiter::for('api-retrieve', function (Request $request) {
            $workspace = $request->user()?->workspace;
            $limit = $workspace?->retrieve_rate_limit ?? 60;
            return Limit::perMinute($limit)->by($request->user()?->workspace_id ?? $request->ip());
        });

        // Workspace-based rate limiting for Chat API
        RateLimiter::for('api-chat', function (Request $request) {
            $workspace = $request->user()?->workspace;
            $limit = $workspace?->chat_rate_limit ?? 20;
            return Limit::perMinute($limit)->by($request->user()?->workspace_id ?? $request->ip());
        });

        // -----------------------------------------------------------------
        // Brute-force protection for credential-carrying public endpoints
        // -----------------------------------------------------------------
        // Login attempts: limit per (email, ip) pair so a slow attacker
        // rotating IPs can't probe one account without being throttled,
        // and a shared-NAT user isn't blocked by a neighbour's typos.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $key   = $email !== '' ? $email . '|' . $request->ip() : $request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Password-reset requests: per-IP so attackers can't enumerate users
        // or flood the inbox of a known victim.
        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Reset-token submissions: guard the /reset-password POST against
        // brute-forcing the random token itself.
        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Invitation-token registration: throttle both the GET render and
        // the POST submission on /invitation/{token} to slow token-guessing.
        RateLimiter::for('invitation', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
