<?php

namespace App\Http\Middleware;

use App\Services\Push\CampaignDispatcher;
use App\Support\ScheduleFrequency;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 定期処理の間隔を毎分より長くしている場合（Laravel Cloud の休止を使う場合）に、
 * アクセスがあったついでに、送信日時を過ぎた予約配信を確認する（最大1分に1回、レスポンスを返したあと）。
 */
class DispatchDueCampaigns
{
    public function __construct(private readonly CampaignDispatcher $dispatcher) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (ScheduleFrequency::isEveryMinute()) {
            return;
        }
        try {
            if (Cache::add('eevee:push:due-check', 1, 60)) {
                $this->dispatcher->dispatchDue();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch due campaigns on request', ['error' => $e->getMessage()]);
        }
    }
}
