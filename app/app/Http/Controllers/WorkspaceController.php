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

    /**
     * Send a password reset email to a workspace member on behalf of the owner.
     * Uses the same token mechanism as the forgot-password flow.
     */
    public function sendPasswordReset(\App\Models\User $user): \Illuminate\Http\RedirectResponse
    {
        // Ensure the target user belongs to this workspace
        if ($user->workspace_id !== auth()->user()->workspace_id) {
            abort(403);
        }

        // Remove any existing token and generate a fresh one
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        $token = \Illuminate\Support\Str::random(64);
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email'      => $user->email,
            'token'      => \Illuminate\Support\Facades\Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

        \Illuminate\Support\Facades\Mail::raw(
            __('ui.password_reset_email_body', ['url' => $resetUrl, 'app' => __('ui.app_name')]),
            function ($message) use ($user) {
                $message->to($user->email)->subject(__('ui.password_reset_subject'));
            }
        );

        return back()->with('success', __('ui.password_reset_sent', ['name' => $user->name]));
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
