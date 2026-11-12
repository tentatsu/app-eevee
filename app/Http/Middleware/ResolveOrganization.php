<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * URL の {organization}（slug）から組織を特定し、テナントの文脈に設定する。
 * 存在しない・削除済みの組織は 404、停止中の組織は 403。
 */
class ResolveOrganization
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        abort_if($route === null, 404);

        $param = $route->parameter('organization');
        $organization = $param instanceof Organization
            ? $param
            : Organization::query()->where('slug', (string) $param)->first();

        abort_if($organization === null, 404);
        abort_unless($organization->isActive(), 403, 'この組織は現在停止されています。');

        $route->setParameter('organization', $organization);
        $this->tenant->setOrganization($organization);
        View::share('currentOrganization', $organization);

        return $next($request);
    }
}
