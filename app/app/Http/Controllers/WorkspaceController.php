<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Workspace settings controller — manage workspace name and view members/invitations.
 */
class WorkspaceController extends Controller
{
    /** Show the workspace settings page with members and pending invitations. */
    public function edit()
    {
        $workspace = auth()->user()->workspace;
        $members = $workspace->users()->orderBy('name')->get();

        // Auto-delete expired invitations (168 hours / 7 days)
        Invitation::where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $pendingInvitations = Invitation::where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();

        return view('settings.workspace', compact('workspace', 'members', 'pendingInvitations'));
    }

    /** Update the workspace name. */
    public function update(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);

        $workspace = auth()->user()->workspace;
        $workspace->update(['name' => $request->name]);

        return back()->with('success', __('ui.workspace_updated'));
    }

    /** Update a member's role within the workspace. */
    public function updateRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:owner,member']);

        $workspace = auth()->user()->workspace;

        // Ensure the target user belongs to the same workspace
        if ($user->workspace_id !== $workspace->id) {
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
