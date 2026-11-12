<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\Tenancy\CurrentMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中の管理者が URL の組織に所属し、かつその組織のログイン画面からログインしたことを確認する。
 *
 * - 所属していない組織 → 404
 * - 所属しているが、このセッションではその組織にログインしていない → その組織のログイン画面へ
 */
class EnsureOrganizationMember
{
    public const SESSION_KEY = 'eevee.admin_organizations';

    public function __construct(private readonly TenantContext $tenant, private readonly CurrentMembership $membership) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->organization();
        $user = $request->user('admin');
        abort_if($organization === null || ! $user instanceof AdminUser, 404);

        $role = $user->roleIn($organization);
        abort_if($role === null, 404);

        /** @var list<int> $authenticated */
        $authenticated = $request->session()->get(self::SESSION_KEY, []);
        if (! in_array($organization->id, $authenticated, true)) {
            return redirect()->route('org.login', $organization)
                ->with('status', 'この組織の管理画面を使うには、もう一度ログインしてください。');
        }

        $this->membership->set($user, $role);
        View::share('currentRole', $role);
        View::share('currentAdmin', $user);

        return $next($request);
    }
}
