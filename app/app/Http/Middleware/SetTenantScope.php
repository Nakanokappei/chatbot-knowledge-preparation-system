<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the PostgreSQL session variable for Row Level Security.
 *
 * On each authenticated request, this middleware sets app.tenant_id
 * so that RLS policies can filter rows at the database level.
 * This provides defense-in-depth alongside Eloquent's BelongsToTenant global scope.
 */
class SetTenantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->tenant_id) {
            DB::statement("SET app.tenant_id = '" . (int) auth()->user()->tenant_id . "'");
        }

        return $next($request);
    }
}
