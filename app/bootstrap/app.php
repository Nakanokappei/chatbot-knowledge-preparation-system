<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ALB proxy headers so Laravel generates HTTPS URLs
        $middleware->trustProxies(at: '*');

        // Emit hardening response headers (HSTS / CSP / X-Frame-Options / …)
        // on every request — web and API alike. Runs as a global middleware
        // so embed routes, health checks, and static endpoints are covered.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Set locale from session before anything else
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);

        // Set PostgreSQL RLS workspace scope after authentication
        $middleware->appendToGroup('web', \App\Http\Middleware\SetWorkspaceScope::class);

        // Enforce workspace lifecycle status (frozen / suspended)
        $middleware->appendToGroup('web', \App\Http\Middleware\EnforceWorkspaceStatus::class);

        // Emit an audit log entry for every request made by a system_admin user
        $middleware->appendToGroup('web', \App\Http\Middleware\LogSystemAdminAction::class);

        // Enable Sanctum session-based auth for same-origin requests (used by the sandbox)
        $middleware->prependToGroup('api', \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);

        // API metrics tracking
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackApiMetrics::class);

        // Set PostgreSQL RLS workspace scope for API requests after Sanctum auth
        $middleware->appendToGroup('api', \App\Http\Middleware\SetWorkspaceScope::class);

        // Exclude embed chat API from CSRF verification (no session/cookie auth)
        $middleware->validateCsrfTokens(except: [
            'embed/api/*',
        ]);

        // Role-based access control and Sanctum ability aliases
        $middleware->alias([
            'budget'             => \App\Http\Middleware\EnforceBudget::class,
            'owner'              => \App\Http\Middleware\RequireOwner::class,
            'system_admin'       => \App\Http\Middleware\RequireSystemAdmin::class,
            'ability'            => \App\Http\Middleware\CheckTokenAbility::class,
            'workspace_owner'    => \App\Http\Middleware\RequireWorkspaceOwner::class,
            'redirect_sysadmin'  => \App\Http\Middleware\RedirectSystemAdmin::class,
            'embed.apikey'       => \App\Http\Middleware\EmbedApiKeyAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log CSRF token mismatches with full request diagnostics.
        // These failures can be caused by: session cookie domain mismatch,
        // HTTP/HTTPS scheme inconsistency behind ALB, cookie SameSite policy,
        // expired sessions, or connection pool RLS state pollution.
        $exceptions->report(function (TokenMismatchException $e) {
            $request = app('request');

            // Attempt to read session ID safely — session may not be started
            try {
                $sessionId = $request->session()->getId();
            } catch (\Throwable) {
                $sessionId = null;
            }

            Log::warning('csrf.token_mismatch', [
                'url'            => $request->fullUrl(),
                'method'         => $request->method(),
                'ip'             => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'session_id'     => $sessionId,
                'origin'         => $request->header('Origin'),
                'referer'        => $request->header('Referer'),
                'x_forwarded_proto' => $request->header('X-Forwarded-Proto'),
                'is_secure'      => $request->isSecure(),
                'app_url'        => config('app.url'),
                'session_domain' => config('session.domain'),
                'secure_cookie'  => config('session.secure'),
                'auth_id'        => auth()->id(),
            ]);

            // Return false to allow Laravel's default CSRF response (419) to proceed
            return false;
        });
    })->create();
