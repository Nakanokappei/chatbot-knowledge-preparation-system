<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the PostgreSQL session variable for Row Level Security.
 *
 * On each authenticated request, this middleware sets app.workspace_id
 * so that RLS policies can filter rows at the database level.
 * This provides defense-in-depth alongside Eloquent's BelongsToWorkspace global scope.
 */
class SetWorkspaceScope
{
    /**
     * Set the PostgreSQL session variable for RLS before each request.
     * Only runs when the user is authenticated and has a workspace_id.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            if (auth()->user()->isSystemAdmin()) {
                // System admins bypass RLS — set a flag instead of workspace_id
                DB::statement("SET app.is_system_admin = 'true'");
            } elseif (auth()->user()->workspace_id) {
                // Regular users: propagate workspace_id for RLS policies
                DB::statement("SET app.workspace_id = '" . (int) auth()->user()->workspace_id . "'");
            }
        }

        return $next($request);
    }
}
