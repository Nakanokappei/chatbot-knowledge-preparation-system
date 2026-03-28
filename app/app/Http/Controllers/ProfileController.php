<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * User profile settings — name, email, password change, and API token management.
 */
class ProfileController extends Controller
{
    /**
     * Available token abilities mapped to their API endpoints.
     */
    public const TOKEN_ABILITIES = [
        'retrieve',
        'chat',
        'datasets:read',
        'datasets:write',
        'pipeline-jobs:read',
        'pipeline-jobs:write',
    ];

    /**
     * Allowed expiration options in days (0 = never expires).
     */
    public const EXPIRATION_OPTIONS = [30, 60, 90, 365, 0];

    /**
     * Show the profile edit form with the current user's details.
     */
    public function edit()
    {
        return view('dashboard.profile', ['user' => auth()->user()]);
    }

    /**
     * Update the user's name and email address.
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($request->only('name', 'email'));

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change the user's password (no current password required while logged in).
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        auth()->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', 'Password changed.');
    }

    /**
     * Return the current user's API tokens as JSON (called via AJAX).
     */
    public function tokens()
    {
        $tokens = auth()->user()->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
            ]);

        return response()->json($tokens);
    }

    /**
     * Create a new personal access token with selected abilities and expiration.
     *
     * The plain-text token is returned only once in the response; it cannot
     * be retrieved later because only the hash is stored in the database.
     */
    public function createToken(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'required|array|min:1',
            'abilities.*' => 'in:' . implode(',', self::TOKEN_ABILITIES),
            'expiration' => 'required|in:' . implode(',', self::EXPIRATION_OPTIONS),
        ]);

        // Calculate expiration date (0 = never)
        $expiresAt = (int) $request->expiration > 0
            ? now()->addDays((int) $request->expiration)
            : null;

        $token = auth()->user()->createToken(
            $request->name,
            $request->abilities,
            $expiresAt,
        );

        return response()->json([
            'plainTextToken' => $token->plainTextToken,
        ]);
    }

    /**
     * Revoke (delete) a specific personal access token owned by the current user.
     */
    public function revokeToken(int $tokenId)
    {
        $deleted = auth()->user()->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        return response()->json(['message' => 'Token revoked.']);
    }
}
