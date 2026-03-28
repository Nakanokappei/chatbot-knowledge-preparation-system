<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect system administrators away from workspace-scoped pages.
 *
 * System admins have no workspace_id, so workspace-scoped controllers
 * would either error or expose cross-workspace data via RLS bypass.
 * This middleware redirects them to the admin dashboard before any
 * controller logic runs.
 *
 * Used on routes accessible by owner + member but not system_admin,
 * e.g. /dataset/*, /jobs/*, /knowledge-units/*, /api-guide.
 */
class RedirectSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->isSystemAdmin()) {
            return redirect()->route('admin.index');
        }

        return $next($request);
    }
}
