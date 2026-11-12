<?php

namespace App\Http\Middleware;

use App\Models\App;
use App\Models\Organization;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API の {app_key} からアプリを特定し、テナントの文脈に設定する。
 */
class ResolveAppByKey
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $key = $route?->parameter('app_key');
        $app = is_string($key) ? App::withoutGlobalScopes()->where('app_key', $key)->first() : null;

        if ($app === null) {
            throw new ApiException('app_not_found', 'The app_key is invalid.', 404);
        }
        $organization = Organization::query()->find($app->organization_id);
        if ($organization === null || ! $organization->isActive()) {
            throw new ApiException('app_not_found', 'The app_key is invalid.', 404);
        }

        $this->tenant->setApp($app);
        $request->attributes->set('eevee_app', $app);
        // コントローラーには app_key を渡さない（以降のルートパラメータを名前・位置どちらでも受け取れるように）
        $route->forgetParameter('app_key');

        return $next($request);
    }
}
