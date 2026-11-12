<?php

namespace App\Http\Middleware;

use App\Services\IpAccess\IpAllowlistService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 組織の管理画面（ログイン画面を含む）を IP 許可リストで制限する。
 */
class RestrictOrganizationIp
{
    public function __construct(private readonly IpAllowlistService $allowlist, private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->organization();
        abort_if($organization === null, 404);

        if (! $this->allowlist->isAllowed($organization, $request->ip())) {
            $this->allowlist->logDenied($organization, $request->ip(), $request->path());
            abort(403, 'このIPアドレスからはアクセスできません。');
        }

        return $next($request);
    }
}
