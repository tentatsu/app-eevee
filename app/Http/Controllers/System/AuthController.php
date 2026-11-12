<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\SystemAdmin;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('system')->check()) {
            return redirect()->route('system.dashboard');
        }

        return view('system.auth.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('system')->attempt(['email' => mb_strtolower($credentials['email']), 'password' => $credentials['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'メールアドレスまたはパスワードが正しくありません。']);
        }
        $request->session()->regenerate();

        /** @var SystemAdmin $admin */
        $admin = Auth::guard('system')->user();
        $admin->forceFill(['last_login_at' => now()])->save();
        $audit->record('system.auth.login', $admin, summary: 'システム管理画面にログイン');

        return redirect()->intended(route('system.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('system')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('system.login');
    }
}
