<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to system administrator users only.
 *
 * Returns 403 for non-system-admin users attempting to access
 * cross-workspace administrative routes.
 */
class RequireSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->isSystemAdmin()) {
            abort(403, 'System administrator access required.');
        }

        return $next($request);
    }
}
