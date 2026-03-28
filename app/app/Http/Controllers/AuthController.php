<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Authentication controller for Phase 3 multi-workspace support.
 *
 * Simple session-based login/logout. No registration UI — users are
 * created via tinker or seeder (invite-only model).
 */
class AuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLogin(): View
    {
        // If no users exist and setup passphrase is configured, redirect to setup
        if (\App\Models\User::count() === 0 && !empty(config('app.setup_passphrase'))) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    /**
     * Authenticate the user and start a session.
     *
     * Detailed diagnostic logging is emitted on both success and failure to
     * help trace intermittent login issues caused by RLS pool pollution,
     * session cookie mismatch, proxy header misconfiguration, or HTTPS/HTTP
     * scheme inconsistencies behind the ALB.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Capture request-time context before session regeneration
        $baseContext = [
            'email_hash'     => hash('sha256', strtolower(trim($request->input('email')))),
            'ip'             => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'session_id_pre' => $request->session()->getId(),
            'x_forwarded_proto' => $request->header('X-Forwarded-Proto'),
            'is_secure'      => $request->isSecure(),
            'app_url'        => config('app.url'),
            'session_domain' => config('session.domain'),
            'secure_cookie'  => config('session.secure'),
            'remember'       => $request->boolean('remember'),
        ];

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            // Regenerate session to prevent session fixation
            $request->session()->regenerate();

            Log::info('auth.login_success', array_merge($baseContext, [
                'user_id'         => auth()->id(),
                'role'            => auth()->user()->role,
                'workspace_id'    => auth()->user()->workspace_id,
                'session_id_post' => $request->session()->getId(),
                'url_intended'    => $request->session()->get('url.intended'),
            ]));

            // System admins are always redirected to the admin dashboard
            if (auth()->user()->isSystemAdmin()) {
                return redirect()->route('admin.index');
            }

            // If the intended URL points to an admin route, clear it.
            // This prevents a 403 when an owner visited /admin while unauthenticated —
            // the auth middleware stores url.intended = /admin, then redirect()->intended()
            // would land the owner on a system_admin-only route after login.
            $intended     = $request->session()->get('url.intended', '');
            $intendedPath = parse_url($intended, PHP_URL_PATH) ?? '';
            if (str_starts_with($intendedPath, '/admin')) {
                $request->session()->forget('url.intended');
                Log::info('auth.intended_url_cleared', [
                    'reason'       => 'admin_route_not_accessible_to_role',
                    'role'         => auth()->user()->role,
                    'url_intended' => $intended,
                ]);
            }

            return redirect()->intended(route('dashboard'));
        }

        // Distinguish "user not found" from "wrong password" without revealing
        // which case occurred to the user (but record it in logs for diagnostics)
        $userExists = User::where('email', $request->input('email'))->exists();

        Log::warning('auth.login_failure', array_merge($baseContext, [
            'reason' => $userExists ? 'wrong_password' : 'user_not_found',
        ]));

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
