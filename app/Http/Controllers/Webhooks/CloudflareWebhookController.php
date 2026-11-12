<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoSynchronizer;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Stream の Webhook（変換完了・エラーなど）。署名を検証して動画の状態と長さを更新する。
 */
class CloudflareWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, VideoProviderManager $providers, VideoSynchronizer $sync): JsonResponse
    {
        $app = $tenant->app();
        if ($app === null || $app->video_provider !== 'cloudflare_stream') {
            return ApiResponse::error('not_found', 'Not found.', 404);
        }
        $provider = $providers->for($app);

        if (! $provider->verifyWebhook($app, $request)) {
            Log::channel('security')->warning('Cloudflare webhook signature verification failed', ['app_id' => $app->id, 'ip' => $request->ip()]);

            return ApiResponse::error('invalid_signature', 'The webhook signature is invalid.', 401);
        }

        $event = $provider->parseWebhook($app, $request);
        if ($event === null) {
            return ApiResponse::ok(['received' => true, 'updated' => false]);
        }

        $video = Video::query()->where('provider', $app->video_provider)->where('provider_video_id', $event->providerVideoId)->first();
        if ($video === null) {
            return ApiResponse::ok(['received' => true, 'updated' => false]);
        }

        $sync->apply($app, $video, $event);

        return ApiResponse::ok(['received' => true, 'updated' => true, 'status' => $video->status->value]);
    }
}
