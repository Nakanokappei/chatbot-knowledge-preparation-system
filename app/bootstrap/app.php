<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Set locale from session before anything else
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);

        // Set PostgreSQL RLS workspace scope after authentication
        $middleware->appendToGroup('web', \App\Http\Middleware\SetWorkspaceScope::class);

        // Enable Sanctum session-based auth for same-origin requests (used by the sandbox)
        $middleware->prependToGroup('api', \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);

        // API metrics tracking
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackApiMetrics::class);

        // Set PostgreSQL RLS workspace scope for API requests after Sanctum auth
        $middleware->appendToGroup('api', \App\Http\Middleware\SetWorkspaceScope::class);

        // Role-based access control and Sanctum ability aliases
        $middleware->alias([
            'budget' => \App\Http\Middleware\EnforceBudget::class,
            'owner' => \App\Http\Middleware\RequireOwner::class,
            'system_admin' => \App\Http\Middleware\RequireSystemAdmin::class,
            'ability' => \App\Http\Middleware\CheckTokenAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
