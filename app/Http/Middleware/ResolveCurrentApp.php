<?php

namespace App\Http\Middleware;

use App\Models\App;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の URL の {app}（ID）を、現在の組織に属するアプリに限定して解決する。
 */
class ResolveCurrentApp
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $param = $route?->parameter('app');
        if ($route === null || $param === null) {
            return $next($request);
        }

        $orgId = $this->tenant->organizationId();
        abort_if($orgId === null, 404);

        $app = $param instanceof App ? $param : null;
        if ($app === null && ctype_digit((string) $param)) {
            $app = App::query()->where('organization_id', $orgId)->find((int) $param);
        }
        abort_if($app === null || $app->organization_id !== $orgId, 404);

        $route->setParameter('app', $app);
        $this->tenant->setApp($app);
        View::share('currentApp', $app);

        return $next($request);
    }
}
