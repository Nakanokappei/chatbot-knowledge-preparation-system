<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * User profile settings — name, email, password change.
 */
class ProfileController extends Controller
{
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
}
