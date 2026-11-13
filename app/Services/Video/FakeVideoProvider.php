<?php

namespace App\Services\Video;

use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\Video;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Data\DirectUpload;
use App\Services\Video\Data\PlaybackUrls;
use App\Services\Video\Data\ProviderVideo;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 外部サービスを呼ばない動画プロバイダ（ローカル開発用: EEVEE_VIDEO_FAKE=true）。
 * アップロード直後に「再生可能」になったものとして扱う。
 */
class FakeVideoProvider implements VideoProvider
{
    public function createDirectUpload(App $app, string $title, int $maxDurationSeconds): DirectUpload
    {
        $uid = 'fake-'.Str::lower(Str::random(24));

        return new DirectUpload(url('/fake-upload/'.$uid), $uid, now()->addMinutes(30));
    }

    public function getVideo(App $app, string $providerVideoId): ProviderVideo
    {
        return new ProviderVideo($providerVideoId, VideoStatus::Ready, 120, null, null, true);
    }

    public function requireSignedPlayback(App $app, string $providerVideoId): void {}

    public function deleteVideo(App $app, string $providerVideoId): void {}

    public function playbackUrls(App $app, Video $video, CarbonInterface $expiresAt): PlaybackUrls
    {
        $token = 'fake.'.base64_encode($video->provider_video_id.'|'.$expiresAt->getTimestamp());

        // 実際に再生できる公開のテスト用 HLS を返す（サンプルアプリの動作確認用）
        return new PlaybackUrls((string) config('eevee.video.fake_hls_url'), null, $token, $expiresAt);
    }

    public function verifyWebhook(App $app, Request $request): bool
    {
        return true;
    }

    public function parseWebhook(App $app, Request $request): ?ProviderVideo
    {
        return null;
    }
}
