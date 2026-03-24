<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap rate limiters and other application services.
     */
    public function boot(): void
    {
        // Tenant-based rate limiting for Retrieval API
        RateLimiter::for('api-retrieve', function (Request $request) {
            $tenant = $request->user()?->tenant;
            $limit = $tenant?->retrieve_rate_limit ?? 60;
            return Limit::perMinute($limit)->by($request->user()?->tenant_id ?? $request->ip());
        });

        // Tenant-based rate limiting for Chat API
        RateLimiter::for('api-chat', function (Request $request) {
            $tenant = $request->user()?->tenant;
            $limit = $tenant?->chat_rate_limit ?? 20;
            return Limit::perMinute($limit)->by($request->user()?->tenant_id ?? $request->ip());
        });
    }
}
