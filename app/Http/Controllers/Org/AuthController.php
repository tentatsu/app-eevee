<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(Organization $organization): View
    {
        return view('org.auth.login', ['organization' => $organization]);
    }

    public function login(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('admin');
        $user = AdminUser::query()->where('email', mb_strtolower($credentials['email']))->first();

        // 認証情報が正しくても、組織に所属していなければ同じエラーにする（組織の所属有無を漏らさない）
        if ($user === null
            || ! $guard->getProvider()->validateCredentials($user, ['password' => $credentials['password']])
            || ! $user->isMemberOf($organization)) {
            throw ValidationException::withMessages(['email' => 'メールアドレスまたはパスワードが正しくありません。']);
        }

        $previous = $guard->user();
        $orgs = ($previous instanceof AdminUser && $previous->id === $user->id)
            ? $request->session()->get(EnsureOrganizationMember::SESSION_KEY, [])
            : [];

        $guard->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put(EnsureOrganizationMember::SESSION_KEY, array_values(array_unique([...$orgs, $organization->id])));
        $user->forceFill(['last_login_at' => now()])->save();

        $audit->record('auth.login', $user, summary: 'ログイン', organization: $organization);

        return redirect()->intended(route('org.dashboard', $organization));
    }

    public function logout(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', $request->user('admin'), summary: 'ログアウト', organization: $organization);

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('org.login', $organization);
    }
}
