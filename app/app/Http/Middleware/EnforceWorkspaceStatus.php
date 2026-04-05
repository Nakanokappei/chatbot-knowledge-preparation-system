<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce workspace lifecycle status restrictions.
 *
 * Three states:
 *   active    — full access, no restrictions
 *   frozen    — read-only + export allowed; all write operations blocked
 *   suspended — no access at all; users redirected to status page
 *
 * System administrators bypass all restrictions.
 */
class EnforceWorkspaceStatus
{
    /**
     * Routes that are always allowed regardless of workspace status.
     * Checked by route name prefix.
     */
    private const ALWAYS_ALLOWED_ROUTES = [
        'logout',
        'login',
        'locale.switch',
        'workspace.status',
    ];

    /**
     * Routes allowed in frozen state even for POST/PUT/DELETE methods.
     * Primarily export downloads that use GET but also profile updates.
     */
    private const FROZEN_ALLOWED_ROUTES = [
        'kp.export',
        'kp.export-faq',
        'workspace.export',
        'workspace.export-rows',
        'dashboard.knowledge-units.export',
        'profile.edit',
        'profile.update',
        'profile.password',
    ];

    /**
     * Check workspace status and block or redirect as appropriate.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip for unauthenticated requests and system administrators
        if (!$user || $user->isSystemAdmin()) {
            return $next($request);
        }

        $workspace = $user->workspace;
        if (!$workspace) {
            return $next($request);
        }

        $status = $workspace->status ?? 'active';

        // Active workspaces have no restrictions
        if ($status === 'active') {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';

        // Always allow certain routes (logout, login, locale)
        foreach (self::ALWAYS_ALLOWED_ROUTES as $allowed) {
            if ($routeName === $allowed || str_starts_with($routeName, $allowed . '.')) {
                return $next($request);
            }
        }

        // Suspended: block everything, redirect to status page
        if ($status === 'suspended') {
            if ($routeName === 'workspace.status') {
                return $next($request);
            }
            return redirect()->route('workspace.status');
        }

        // Frozen: allow GET requests (read-only browsing)
        if ($status === 'frozen') {
            if ($request->isMethod('GET')) {
                return $next($request);
            }

            // Allow specific write routes (export downloads, profile updates)
            foreach (self::FROZEN_ALLOWED_ROUTES as $allowed) {
                if ($routeName === $allowed) {
                    return $next($request);
                }
            }

            // Block all other write operations
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => __('ui.workspace_frozen_error'),
                ], 403);
            }

            return redirect()->back()->withErrors([
                'workspace' => __('ui.workspace_frozen_error'),
            ]);
        }

        return $next($request);
    }
}
