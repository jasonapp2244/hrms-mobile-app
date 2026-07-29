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
            return redirect()->route(Auth::user()->homeRoute());
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

        // Only known roles may sign in: admin/HR reach the dashboard, employees
        // and managers reach the self-service portal. Any other account is rejected.
        if (! $user->hasAnyRole(['admin', 'hr', 'employee', 'manager'])) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'This account is not permitted to sign in.',
            ]);
        }

        if (! ($user->is_active ?? true)) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Contact your administrator.',
            ]);
        }

        $request->session()->regenerate();

        // Employees always land on their own portal; staff use intended() so deep
        // links still work after a session timeout.
        return $user->homeRoute() === 'employee.dashboard'
            ? redirect()->route('employee.dashboard')
            : redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
