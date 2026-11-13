<?php

namespace App\Services\Video;

use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\Video;
use App\Services\Video\Data\ProviderVideo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * プロバイダ側の状態（Webhook / API）を動画に反映する。
 */
class VideoSynchronizer
{
    public function __construct(private readonly VideoProviderManager $providers) {}

    public function apply(App $app, Video $video, ProviderVideo $remote): void
    {
        $video->status = $remote->status;
        $video->status_message = $remote->status === VideoStatus::Error ? $remote->message : null;
        if ($remote->durationSeconds !== null) {
            $video->duration_seconds = $remote->durationSeconds;
        }
        // サムネイルは保存しない（署名付き URL が必要なため、表示のたびに VideoThumbnails で作る）
        $video->save();

        // カスタマーサブドメインが未設定なら、動画の再生 URL から自動で設定する
        if ($remote->playbackHost !== null && $app->videoSetting('customer_subdomain') === null) {
            $settings = $app->video_provider_settings ?? [];
            $settings['customer_subdomain'] = $remote->playbackHost;
            $app->video_provider_settings = $settings;
            $app->save();
        }

        // 署名付き URL が必須になっていなければ設定する（アップロード時に指定しているが念のため）
        if ($remote->signedPlaybackRequired === false && $video->provider_video_id !== null) {
            try {
                $this->providers->for($app)->requireSignedPlayback($app, $video->provider_video_id);
            } catch (Throwable $e) {
                Log::warning('Failed to require signed URLs', ['video_id' => $video->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function refresh(App $app, Video $video): void
    {
        if ($video->provider_video_id === null) {
            return;
        }
        $this->apply($app, $video, $this->providers->for($app)->getVideo($app, $video->provider_video_id));
    }
}
