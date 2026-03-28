<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to workspace owner users only.
 *
 * - System admins are redirected to the admin dashboard (they have
 *   no workspace and should never reach workspace-owner pages).
 * - Workspace members (non-owners) receive a 403.
 * - Owners pass through.
 *
 * Used on routes such as /knowledge-packages and /usage that are
 * scoped to one workspace and require owner-level privileges.
 */
class RequireWorkspaceOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        // System admins belong to no workspace; send them to their own dashboard
        if (auth()->user()->isSystemAdmin()) {
            return redirect()->route('admin.index');
        }

        // Members may not access owner-only pages
        if (!auth()->user()->isOwner()) {
            abort(403, 'Owner access required.');
        }

        return $next($request);
    }
}
