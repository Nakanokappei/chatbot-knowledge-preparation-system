<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit an audit log entry for every request made by a system_admin user.
 *
 * System admins have RLS bypass and can access all tenant data. Logging
 * every action provides an immutable audit trail for compliance and
 * incident investigation (CTO directive: Pre-PoC Security Stabilization).
 *
 * Log channel: the default (stderr → CloudWatch JSON on ECS).
 * Log event key: "admin.action"
 *
 * Required fields per CTO spec:
 *   timestamp, user_id, workspace_id, route, method,
 *   is_system_admin, ip_address, user_agent
 */
class LogSystemAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log authenticated system_admin users
        if (!auth()->check() || !auth()->user()->isSystemAdmin()) {
            return $response;
        }

        Log::info('admin.action', [
            'timestamp'       => now()->toIso8601ZuluString(),
            'user_id'         => auth()->id(),
            'workspace_id'    => $request->route('workspace') ?? null,
            'route'           => $request->route()?->getName(),
            'url'             => $request->path(),
            'method'          => $request->method(),
            'is_system_admin' => true,
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'http_status'     => $response->getStatusCode(),
        ]);

        return $response;
    }
}
