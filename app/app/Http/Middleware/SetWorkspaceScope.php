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
        // Always reset both RLS control variables before applying per-request auth state.
        // Laravel reuses PDO connections across requests (connection pool). Without explicit
        // resets, a previous request's system-admin flag or workspace_id would persist on the
        // pooled connection and bleed into the next user's request — causing cross-user data
        // exposure and intermittent auth state errors.
        DB::statement("SET app.is_system_admin = 'false'");
        DB::statement("SET app.workspace_id = ''");

        if (auth()->check()) {
            if (auth()->user()->isSystemAdmin()) {
                // System admins bypass workspace-scoped RLS
                DB::statement("SET app.is_system_admin = 'true'");
            } elseif (auth()->user()->workspace_id) {
                // Regular users: scope all DB queries to their workspace
                DB::statement("SET app.workspace_id = '" . (int) auth()->user()->workspace_id . "'");
            }
        }

        return $next($request);
    }
}
