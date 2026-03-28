<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePackage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * API usage guide and interactive sandbox.
 *
 * Fetches the user's published datasets to populate the demo selectors.
 * The sandbox uses session authentication so no API token is required.
 * All queries are automatically scoped to the authenticated user's workspace.
 */
class ApiGuideController extends Controller
{
    /**
     * Guard: system admins have no workspace; redirect to admin dashboard.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (auth()->check() && auth()->user()->isSystemAdmin()) {
                return redirect()->route('admin.index');
            }
            return $next($request);
        });
    }

    /**
     * Display the API guide with the current user's workspace context.
     *
     * Passes only published datasets — non-published datasets cannot be
     * queried via the retrieve/chat API and would only cause confusing errors.
     */
    public function index(Request $request): View
    {
        $workspaceId = auth()->user()->workspace_id;

        // Published packages are queryable; others are not accessible via the API
        $packages = KnowledgePackage::where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('dashboard.api-guide', compact('packages'));
    }
}
