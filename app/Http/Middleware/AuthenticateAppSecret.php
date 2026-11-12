<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * サーバー間API: Authorization: Bearer {app_secret} で認証する。
 */
class AuthenticateAppSecret
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $app = $this->tenant->app();
        $secret = $request->bearerToken();

        if ($app === null || $secret === null || $secret === '' || ! $app->verifySecret($secret)) {
            throw new ApiException('invalid_app_secret', 'The app secret is missing or invalid.', 401);
        }

        return $next($request);
    }
}
