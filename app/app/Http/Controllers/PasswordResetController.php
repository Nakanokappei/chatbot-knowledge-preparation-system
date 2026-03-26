<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Password reset controller — handles the forgot-password flow:
 *   1. User requests a reset link via email
 *   2. System sends a tokenized link (valid for 60 minutes)
 *   3. User clicks the link and sets a new password
 */
class PasswordResetController extends Controller
{
    /** Show the "forgot password" form where the user enters their email. */
    public function showForgotForm(Request $request)
    {
        return view('auth.forgot-password', [
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Generate a reset token and send it via email.
     * Always shows a success message to prevent email enumeration.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // Remove any existing token for this email
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Generate a new token
            $token = Str::random(64);
            DB::table('password_reset_tokens')->insert([
                'email' => $request->email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            // Build the reset URL
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $request->email]);

            // Send email with reset link
            Mail::raw(
                __('ui.password_reset_email_body', ['url' => $resetUrl, 'app' => __('ui.app_name')]),
                function ($message) use ($request) {
                    $message->to($request->email)
                            ->subject(__('ui.password_reset_subject'));
                }
            );
        }

        // Always show success to prevent email enumeration
        return back()->with('status', __('ui.password_reset_link_sent'));
    }

    /** Show the password reset form (accessed via the emailed link). */
    public function showResetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /** Validate the token and update the user's password. */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // Look up the reset record
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record) {
            return back()->withErrors(['email' => __('ui.password_reset_invalid_token')]);
        }

        // Verify token matches and is not expired (60 minutes)
        if (! Hash::check($request->token, $record->token)) {
            return back()->withErrors(['email' => __('ui.password_reset_invalid_token')]);
        }

        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return back()->withErrors(['email' => __('ui.password_reset_expired')]);
        }

        // Update the password
        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return back()->withErrors(['email' => __('ui.password_reset_invalid_token')]);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Clean up the used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return redirect()->route('login')->with('status', __('ui.password_reset_success'));
    }
}
