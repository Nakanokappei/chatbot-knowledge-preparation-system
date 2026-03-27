<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setup controller — handles first-run system admin creation.
 *
 * Accessible only when no users exist and SETUP_PASSPHRASE is configured.
 * Generates a system_admin invitation and presents a mailto: link.
 */
class SetupController extends Controller
{
    /** Show the setup form if setup mode is active. */
    public function show()
    {
        if (!$this->isSetupMode()) {
            return redirect()->route('login');
        }

        return view('auth.setup');
    }

    /** Validate passphrase and generate a system_admin invitation link. */
    public function createAdmin(Request $request)
    {
        if (!$this->isSetupMode()) {
            return redirect()->route('login');
        }

        $request->validate([
            'email'      => 'required|email',
            'passphrase' => 'required|string',
        ]);

        // Reject incorrect passphrase
        if ($request->passphrase !== config('app.setup_passphrase')) {
            return back()
                ->withInput()
                ->withErrors(['passphrase' => 'Incorrect passphrase.']);
        }

        // Create a system_admin invitation with no workspace binding
        $token = Str::random(64);
        DB::table('invitations')->insert([
            'workspace_id' => null,
            'invited_by'   => null,
            'email'        => $request->email,
            'token'        => $token,
            'role'         => 'system_admin',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $registerUrl = route('invitation.register', ['token' => $token]);

        // Build a short mailto: URL — keep body brief to avoid browser URL length limits
        $subject  = 'KPS System Admin Setup';
        $body     = 'Register: ' . $registerUrl;
        $mailtoUrl = 'mailto:' . rawurlencode($request->email)
            . '?subject=' . rawurlencode($subject)
            . '&body='    . rawurlencode($body);

        return back()->with([
            'mailto_url'   => $mailtoUrl,
            'register_url' => $registerUrl,
            'invite_email' => $request->email,
        ]);
    }

    /** Setup mode is active when no users exist and a passphrase is configured. */
    private function isSetupMode(): bool
    {
        return User::count() === 0 && !empty(config('app.setup_passphrase'));
    }
}
