<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function index()
    {
        return view('profile.index', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:30',
        ]);

        // Same rule as the API's own profile endpoint: the sign-in address
        // changing is a security event, a new phone number is not. Logging
        // every contact edit would bury the one line that matters.
        $addressChanged = $data['email'] !== $user->email;
        $wasEmail = $user->email;

        $user->update($data);

        if ($addressChanged) {
            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Changed their own sign-in address from {$wasEmail} to {$user->email}, from the web dashboard",
                actor: $user,
                request: $request,
            );
        }

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        ActivityLog::record(
            event: ActivityLog::PASSWORD_CHANGED,
            description: 'Changed their own password from the web dashboard',
            actor: $user,
            request: $request,
        );

        return back()->with('success', 'Password changed successfully.');
    }
}
