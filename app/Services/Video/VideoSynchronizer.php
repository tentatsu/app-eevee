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
        if ($video->thumbnail_url === null && $remote->thumbnailUrl !== null) {
            $video->thumbnail_url = $remote->thumbnailUrl;
        }
        $video->save();

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
