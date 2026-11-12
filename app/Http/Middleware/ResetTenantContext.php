<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * リクエストの前後でテナント文脈を必ず空にする（長寿命プロセスやテストで前のリクエストの文脈を持ち越さない）。
 */
class ResetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->clear();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenant->clear();
    }
}
