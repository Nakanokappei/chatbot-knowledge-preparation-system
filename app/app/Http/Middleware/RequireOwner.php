<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to tenant owner users only.
 *
 * Returns 403 for non-owner users attempting to access
 * administrative routes like settings, model management,
 * and tenant configuration.
 */
class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->isOwner()) {
            abort(403, 'Owner access required.');
        }

        return $next($request);
    }
}
