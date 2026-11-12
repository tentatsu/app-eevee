<?php

namespace App\Http\Middleware;

use App\Models\AppUser;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum で認証したユーザーが URL のアプリのユーザーであることを保証する（任意認証のエンドポイント用にも使う）。
 *
 * 使い方: app.user（必須） / app.user:optional（トークンがあれば検証）
 */
class EnsureAppUserToken
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $hasToken = $request->bearerToken() !== null;
        $user = $hasToken ? auth('sanctum')->user() : null;

        if ($user !== null && (! $user instanceof AppUser || $user->app_id !== $this->tenant->appId())) {
            $user = null;
        }

        if ($user === null && ($mode === 'required' || $hasToken)) {
            throw new ApiException('unauthenticated', 'A valid user token is required.', 401);
        }

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
