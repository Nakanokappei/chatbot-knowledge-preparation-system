<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Invitation controller — handles the invite-a-colleague flow:
 *   1. Existing user sends an invitation from their profile page
 *   2. Invitee receives an email with a registration link
 *   3. Invitee clicks the link and creates their account
 *   4. New user is automatically placed in the inviter's workspace
 */
class InvitationController extends Controller
{
    /** Send an invitation email to a new user. */
    public function send(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'sometimes|in:owner,member',
        ]);

        $inviter = auth()->user();
        $email = $request->email;

        // Prevent inviting existing users
        if (User::where('email', $email)->exists()) {
            return back()->withErrors(['invite_email' => __('ui.invite_already_registered')]);
        }

        // Prevent duplicate pending invitations
        $existingInvitation = Invitation::where('email', $email)
            ->where('workspace_id', $inviter->workspace_id)
            ->whereNull('accepted_at')
            ->first();

        if ($existingInvitation && ! $existingInvitation->isExpired()) {
            return back()->withErrors(['invite_email' => __('ui.invite_already_sent')]);
        }

        // Create a new invitation with a unique token
        // System admin invitations have no workspace binding
        $role = $request->input('role', 'member');
        $token = Str::random(64);
        Invitation::create([
            'workspace_id' => ($role === 'system_admin') ? null : $inviter->workspace_id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'token' => $token,
            'role' => $role,
        ]);

        // Build the registration URL
        $registerUrl = route('invitation.register', ['token' => $token]);

        // TODO: Send invitation email via SES when configured
        // Mail::raw(
        //     __('ui.invite_email_body', [
        //         'name' => $inviter->name,
        //         'workspace' => $inviter->workspace->name ?? 'KPS',
        //         'url' => $registerUrl,
        //         'app' => __('ui.app_name'),
        //     ]),
        //     function ($message) use ($email, $inviter) {
        //         $message->to($email)
        //                 ->subject(__('ui.invite_email_subject', ['name' => $inviter->name]));
        //     }
        // );

        // Show the registration URL directly until email is configured
        return back()->with('invite_success', true)->with('invite_url', $registerUrl);
    }

    /** Show the registration form for an invited user. */
    public function showRegisterForm(string $token)
    {
        $invitation = Invitation::where('token', $token)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invitation->isExpired()) {
            abort(410, __('ui.invite_expired'));
        }

        return view('auth.register-invite', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    /** Register a new user from an invitation. */
    public function register(Request $request, string $token)
    {
        $invitation = Invitation::where('token', $token)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invitation->isExpired()) {
            abort(410, __('ui.invite_expired'));
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // System admins have no workspace binding; all other roles inherit the inviter's workspace
        $role = $invitation->role ?? 'member';
        $workspaceId = ($role === 'system_admin') ? null : $invitation->workspace_id;

        // Create the user in the inviter's workspace with the specified role
        $user = User::create([
            'name' => $request->name,
            'email' => $invitation->email,
            'password' => Hash::make($request->password),
            'workspace_id' => $workspaceId,
            'role' => $role,
        ]);

        // Mark the invitation as accepted
        $invitation->update(['accepted_at' => now()]);

        // Log the new user in
        auth()->login($user);
        $request->session()->regenerate();

        // System admins go to the admin dashboard; others to the regular dashboard
        return $user->isSystemAdmin()
            ? redirect()->route('admin.index')
            : redirect()->route('dashboard');
    }

    /** Cancel a pending invitation by deleting it. */
    public function cancel(Invitation $invitation)
    {
        // Ensure the invitation belongs to the current user's workspace
        if ($invitation->workspace_id !== auth()->user()->workspace_id) {
            abort(403);
        }

        $invitation->delete();

        return back()->with('success', __('ui.invitation_cancelled'));
    }
}
