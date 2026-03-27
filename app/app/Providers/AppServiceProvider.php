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
    }
}
