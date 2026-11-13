<?php

namespace App\Services\Video\Contracts;

use App\Models\App;
use App\Models\Video;
use App\Services\Video\Data\DirectUpload;
use App\Services\Video\Data\PlaybackUrls;
use App\Services\Video\Data\ProviderVideo;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * 動画配信プロバイダ。アプリごとに apps.video_provider で選ぶ（config/eevee.php の video.providers）。
 *
 * 今回の実装は Cloudflare Stream（CloudflareStreamProvider）。DRM 対応のプロバイダ（Mux など）は
 * このインターフェースを実装して config に登録すれば、アプリごとに切り替えられる。
 */
interface VideoProvider
{
    /**
     * ブラウザから直接アップロードする URL を発行する。発行時点でプロバイダ側に動画の枠を作り、署名付き URL を必須にする。
     */
    public function createDirectUpload(App $app, string $title, int $maxDurationSeconds): DirectUpload;

    /**
     * プロバイダ側の動画の状態を取得する。
     */
    public function getVideo(App $app, string $providerVideoId): ProviderVideo;

    /**
     * 署名付きの再生だけを許可する設定にする（既に設定済みなら何もしない）。
     */
    public function requireSignedPlayback(App $app, string $providerVideoId): void;

    public function deleteVideo(App $app, string $providerVideoId): void;

    /**
     * 再生 URL（トークン付き）を作る。再生のたびにプロバイダの API を呼ばずに、サーバー側で署名する。
     */
    public function playbackUrls(App $app, Video $video, CarbonInterface $expiresAt): PlaybackUrls;

    /**
     * プロバイダが自動で作ったサムネイルの URL（署名付き）。作れなければ null。
     */
    public function thumbnailUrl(App $app, Video $video, CarbonInterface $expiresAt): ?string;

    /**
     * Webhook の署名を検証する。
     */
    public function verifyWebhook(App $app, Request $request): bool;

    /**
     * Webhook の内容を解析する。動画の状態変化でなければ null。
     */
    public function parseWebhook(App $app, Request $request): ?ProviderVideo;
}
