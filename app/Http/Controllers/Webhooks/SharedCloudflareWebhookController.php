<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\ProviderAccount;
use App\Models\Video;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoSynchronizer;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 共用の Cloudflare アカウントの Webhook（アカウントに1つ）。動画の uid から、どのアプリの動画かを探して振り分ける。
 */
class SharedCloudflareWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, VideoProviderManager $providers, VideoSynchronizer $sync, TenantContext $tenant): JsonResponse
    {
        $account = ProviderAccount::query()->where('provider', 'cloudflare_stream')->where('webhook_token', $token)->first();
        if ($account === null) {
            return ApiResponse::error('not_found', 'Not found.', 404);
        }

        $asApp = $account->asApp();
        $provider = $providers->for($asApp);
        if (! $provider->verifyWebhook($asApp, $request)) {
            Log::channel('security')->warning('Shared Cloudflare webhook signature verification failed', ['account_id' => $account->id, 'ip' => $request->ip()]);

            return ApiResponse::error('invalid_signature', 'The webhook signature is invalid.', 401);
        }

        $event = $provider->parseWebhook($asApp, $request);
        if ($event === null) {
            return ApiResponse::ok(['received' => true, 'updated' => false]);
        }

        // このアカウントを割り当てたアプリの動画に限って探す
        $video = Video::withoutGlobalScopes()
            ->where('provider', 'cloudflare_stream')
            ->where('provider_video_id', $event->providerVideoId)
            ->whereIn('app_id', App::withoutGlobalScopes()->where('video_provider_account_id', $account->id)->select('id'))
            ->first();
        if ($video === null) {
            return ApiResponse::ok(['received' => true, 'updated' => false]);
        }

        $app = App::withoutGlobalScopes()->findOrFail($video->app_id);
        $tenant->run($app, fn () => $sync->apply($app, $video, $event));

        return ApiResponse::ok(['received' => true, 'updated' => true, 'status' => $video->status->value]);
    }
}
