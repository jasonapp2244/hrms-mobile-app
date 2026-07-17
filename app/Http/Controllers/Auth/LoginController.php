<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        // Web dashboard login is for staff roles (admin + HR).
        // Employee access is delivered through the mobile app phase, so employee-only
        // accounts cannot sign in to the dashboard.
        if (! $user->hasAnyRole(['admin', 'hr'])) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'This account is not permitted to access the dashboard.',
            ]);
        }

        if (! ($user->is_active ?? true)) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Contact your administrator.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
