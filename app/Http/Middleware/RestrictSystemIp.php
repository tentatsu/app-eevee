<?php

namespace App\Http\Middleware;

use App\Services\IpAccess\IpAllowlistService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * システム管理画面（/system）を専用の IP 許可リストで制限する。
 */
class RestrictSystemIp
{
    public function __construct(private readonly IpAllowlistService $allowlist) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->allowlist->isAllowed(null, $request->ip())) {
            $this->allowlist->logDenied(null, $request->ip(), $request->path());
            abort(403, 'このIPアドレスからはアクセスできません。');
        }

        return $next($request);
    }
}
