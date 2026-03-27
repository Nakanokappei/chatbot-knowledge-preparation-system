<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Tenant settings controller — manage tenant name and view members/invitations.
 */
class TenantController extends Controller
{
    /** Show the tenant settings page with members and pending invitations. */
    public function edit()
    {
        $tenant = auth()->user()->tenant;
        $members = $tenant->users()->orderBy('name')->get();

        // Auto-delete expired invitations (168 hours / 7 days)
        Invitation::where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $pendingInvitations = Invitation::where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();

        return view('settings.tenant', compact('tenant', 'members', 'pendingInvitations'));
    }

    /** Update the tenant name. */
    public function update(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $tenant = auth()->user()->tenant;
        $tenant->update(['name' => $request->name]);

        return back()->with('success', __('ui.tenant_updated'));
    }

    /** Update a member's role within the tenant. */
    public function updateRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:owner,member']);

        $tenant = auth()->user()->tenant;

        // Ensure the target user belongs to the same tenant
        if ($user->tenant_id !== $tenant->id) {
            abort(403);
        }

        // Prevent demoting yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', __('ui.cannot_change_own_role'));
        }

        $user->update(['role' => $request->role]);

        return back()->with('success', __('ui.role_updated'));
    }
}
