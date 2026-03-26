<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
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
}
