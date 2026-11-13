<?php

namespace App\Services\Video;

use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\Video;
use App\Services\Video\Cloudflare\CloudflareStreamProvider;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * 動画のサムネイルの URL を決める。
 *
 * - 管理画面でサムネイルの URL を指定していれば、それを使う
 * - 指定がなければ、動画プロバイダが自動で作ったサムネイル（Cloudflare なら署名付き）を使う
 */
class VideoThumbnails
{
    public function __construct(private readonly VideoProviderManager $providers) {}

    public function url(App $app, Video $video): ?string
    {
        if ($video->thumbnail_url !== null && ! CloudflareStreamProvider::isProviderThumbnail($video->thumbnail_url)) {
            return $video->thumbnail_url;
        }
        if ($video->status !== VideoStatus::Ready || $video->provider_video_id === null) {
            return null;
        }

        try {
            // 期限を日単位でそろえ、同じ日のうちは同じ URL にする（アプリ側で画像をキャッシュできる）
            $expiresAt = CarbonImmutable::now()->startOfDay()->addDays(2);

            return $this->providers->for($app)->thumbnailUrl($app, $video, $expiresAt);
        } catch (Throwable) {
            return null;
        }
    }
}
