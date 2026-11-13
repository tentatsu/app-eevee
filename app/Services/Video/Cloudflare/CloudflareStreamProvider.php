<?php

namespace App\Services\Video\Cloudflare;

use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\Video;
use App\Services\Video\Contracts\ProvisionsAutomatically;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Data\DirectUpload;
use App\Services\Video\Data\PlaybackUrls;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Data\ProvisionResult;
use App\Services\Video\VideoProviderException;
use App\Services\Video\WebhookConflictException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Stream。
 *
 * - アップロード: Direct Creator Upload（POST /stream/direct_upload）。requireSignedURLs=true で作る
 * - 再生: 署名鍵（Key ID + RSA 秘密鍵）で JWT（RS256）をサーバー側で作り、
 *         https://{customer-xxx.cloudflarestream.com}/{token}/manifest/video.m3u8 を返す
 * - Webhook: Webhook-Signature: time=...,sig1=hex(HMAC-SHA256(secret, "{time}.{body}")) を検証
 */
class CloudflareStreamProvider implements ProvisionsAutomatically, VideoProvider
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    /** Webhook の時刻のずれの許容範囲（秒） */
    public const WEBHOOK_TOLERANCE = 300;

    public function createDirectUpload(App $app, string $title, int $maxDurationSeconds): DirectUpload
    {
        $res = $this->client($app)->post($this->path($app, '/direct_upload'), [
            'maxDurationSeconds' => $maxDurationSeconds,
            'requireSignedURLs' => true,
            // 冒頭は黒い画面が多いので、サムネイルは動画の 10% の位置から作る
            'thumbnailTimestampPct' => 0.1,
            'meta' => ['name' => $title, 'app_key' => $app->app_key],
        ]);
        $result = $this->result($res, 'direct_upload');

        if (! is_string($result['uploadURL'] ?? null) || ! is_string($result['uid'] ?? null)) {
            throw new VideoProviderException('Cloudflare did not return an upload URL.');
        }

        return new DirectUpload(
            $result['uploadURL'],
            $result['uid'],
            isset($result['expiry']) && is_string($result['expiry']) ? CarbonImmutable::parse($result['expiry']) : null,
        );
    }

    /**
     * 署名鍵の作成と Webhook の設定を行う（管理画面の「自動設定」）。
     * Account ID と API Token が保存済みであること。
     */
    public function provision(App $app, string $webhookUrl, bool $overwriteWebhook): ProvisionResult
    {
        $client = $this->client($app);
        $settings = [];
        $notes = [];

        // 1. Webhook（アカウントに1つ）。別の URL が設定済みなら、確認のうえで上書きする
        $current = $client->get($this->path($app, '/webhook'));
        $currentUrl = $current->successful() && is_array($current->json('result')) ? (string) ($current->json('result.notificationUrl') ?? '') : '';
        if ($currentUrl !== '' && $currentUrl !== $webhookUrl && ! $overwriteWebhook) {
            throw new WebhookConflictException($currentUrl);
        }
        $webhook = $this->result($client->put($this->path($app, '/webhook'), ['notificationUrl' => $webhookUrl]), 'webhook');
        if (! is_string($webhook['secret'] ?? null) || $webhook['secret'] === '') {
            throw new VideoProviderException('Cloudflare did not return a webhook secret.');
        }
        $settings['webhook_secret'] = $webhook['secret'];
        $notes[] = 'Webhook の送信先を '.$webhookUrl.' に設定しました。';

        // 2. 署名鍵（再生トークン用）。既に鍵があっても新しく作る（古い鍵で発行したトークンも期限までは有効）
        $key = $this->result($client->post($this->path($app, '/keys')), 'keys');
        if (! is_string($key['id'] ?? null) || ! is_string($key['pem'] ?? null)) {
            throw new VideoProviderException('Cloudflare did not return a signing key.');
        }
        $settings['signing_key_id'] = $key['id'];
        $settings['signing_private_key'] = $key['pem'];
        $notes[] = '再生トークン用の署名鍵を作成しました（Key ID: '.$key['id'].'）。';

        if ($app->videoSetting('customer_subdomain') === null) {
            $notes[] = 'カスタマーサブドメインは、最初の動画の変換が終わったときに自動で設定されます。';
        }

        return new ProvisionResult($settings, $notes);
    }

    public function getVideo(App $app, string $providerVideoId): ProviderVideo
    {
        $res = $this->client($app)->get($this->path($app, '/'.rawurlencode($providerVideoId)));

        return self::toProviderVideo($this->result($res, 'get'));
    }

    public function requireSignedPlayback(App $app, string $providerVideoId): void
    {
        $res = $this->client($app)->post($this->path($app, '/'.rawurlencode($providerVideoId)), [
            'uid' => $providerVideoId,
            'requireSignedURLs' => true,
        ]);
        $this->result($res, 'update');
    }

    public function deleteVideo(App $app, string $providerVideoId): void
    {
        $res = $this->client($app)->delete($this->path($app, '/'.rawurlencode($providerVideoId)));
        if ($res->status() === 404) {
            return; // 既に削除済み
        }
        if (! $res->successful()) {
            throw new VideoProviderException('Cloudflare API error on delete: HTTP '.$res->status());
        }
    }

    public function playbackUrls(App $app, Video $video, CarbonInterface $expiresAt): PlaybackUrls
    {
        $now = CarbonImmutable::now();
        $token = $this->signToken($app, $video, $expiresAt, $now->subMinute());
        $host = $this->host($app);

        return new PlaybackUrls(
            "https://{$host}/{$token}/manifest/video.m3u8",
            "https://{$host}/{$token}/manifest/video.mpd",
            $token,
            $expiresAt,
        );
    }

    /**
     * 署名付き URL 必須の動画は、サムネイルにもトークンが要る。
     * 同じ有効期限なら同じ URL になる（nbf を付けない）ので、アプリ側で画像をキャッシュできる。
     */
    public function thumbnailUrl(App $app, Video $video, CarbonInterface $expiresAt): ?string
    {
        if ($video->provider_video_id === null || $app->videoSetting('signing_key_id') === null || $app->videoSetting('signing_private_key') === null) {
            return null;
        }
        $token = $this->signToken($app, $video, $expiresAt, null);

        return "https://{$this->host($app)}/{$token}/thumbnails/thumbnail.jpg?height=480";
    }

    /**
     * Cloudflare の署名付き URL のトークン（JWT, RS256）。
     */
    private function signToken(App $app, Video $video, CarbonInterface $expiresAt, ?CarbonInterface $notBefore): string
    {
        $keyId = $app->videoSetting('signing_key_id');
        $privateKey = self::normalizePem($app->videoSetting('signing_private_key'));
        if ($keyId === null || $privateKey === null || $video->provider_video_id === null) {
            throw new VideoProviderException('Cloudflare signing key is not configured.');
        }

        $claims = [
            'sub' => $video->provider_video_id,
            'kid' => $keyId,
            'exp' => $expiresAt->getTimestamp(),
        ];
        if ($notBefore !== null) {
            $claims['nbf'] = $notBefore->getTimestamp();
        }

        return JWT::encode($claims, $privateKey, 'RS256', $keyId);
    }

    private function host(App $app): string
    {
        return $app->videoSetting('customer_subdomain') ?? 'videodelivery.net';
    }

    /**
     * プロバイダが作ったサムネイルの URL か（管理画面で指定した URL と区別するため）。
     */
    public static function isProviderThumbnail(?string $url): bool
    {
        $host = $url !== null ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && (preg_match('/^customer-[a-z0-9]+\.cloudflarestream\.com$/', $host) === 1 || $host === 'videodelivery.net');
    }

    public function verifyWebhook(App $app, Request $request): bool
    {
        $secret = $app->videoSetting('webhook_secret');
        $header = (string) $request->header('Webhook-Signature', '');
        if ($secret === null || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }
        $time = $parts['time'] ?? '';
        $sig = $parts['sig1'] ?? '';
        if (! ctype_digit($time) || $sig === '' || abs(time() - (int) $time) > self::WEBHOOK_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $time.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $sig);
    }

    public function parseWebhook(App $app, Request $request): ?ProviderVideo
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! is_string($payload['uid'] ?? null)) {
            return null;
        }

        return self::toProviderVideo($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function toProviderVideo(array $data): ProviderVideo
    {
        $state = is_array($data['status'] ?? null) ? ($data['status']['state'] ?? null) : null;
        $status = match (true) {
            ($data['readyToStream'] ?? false) === true || $state === 'ready' => VideoStatus::Ready,
            $state === 'error' => VideoStatus::Error,
            $state === 'pendingupload' || $state === null => VideoStatus::PendingUpload,
            default => VideoStatus::Processing,
        };
        $duration = $data['duration'] ?? null;
        $message = is_array($data['status'] ?? null) ? ($data['status']['errorReasonText'] ?? $data['status']['errorReasonCode'] ?? null) : null;

        return new ProviderVideo(
            (string) $data['uid'],
            $status,
            is_numeric($duration) && $duration > 0 ? (int) ceil((float) $duration) : null,
            is_string($data['thumbnail'] ?? null) ? $data['thumbnail'] : null,
            is_string($message) && $message !== '' ? $message : null,
            isset($data['requireSignedURLs']) ? (bool) $data['requireSignedURLs'] : null,
            self::playbackHost($data),
        );
    }

    /**
     * 再生 URL（playback.hls）から customer-xxxx.cloudflarestream.com を取り出す。
     *
     * @param  array<string, mixed>  $data
     */
    private static function playbackHost(array $data): ?string
    {
        $hls = is_array($data['playback'] ?? null) ? ($data['playback']['hls'] ?? null) : null;
        $host = is_string($hls) ? parse_url($hls, PHP_URL_HOST) : null;

        return is_string($host) && preg_match('/^customer-[a-z0-9]+\.cloudflarestream\.com$/', $host) === 1 ? $host : null;
    }

    /**
     * Cloudflare が返す秘密鍵（base64 でエンコードされた PEM）と、PEM そのものの両方を受け付ける。
     */
    public static function normalizePem(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }
        $decoded = base64_decode($value, true);

        return $decoded !== false && str_contains($decoded, '-----BEGIN') ? trim($decoded) : null;
    }

    private function client(App $app): PendingRequest
    {
        $token = $app->videoSetting('api_token');
        if ($token === null || $app->videoSetting('account_id') === null) {
            throw new VideoProviderException('Cloudflare Stream is not configured for this app.');
        }

        return Http::baseUrl(self::API_BASE)->withToken($token)->acceptJson()->timeout(20)->retry(2, 500, throw: false);
    }

    private function path(App $app, string $suffix): string
    {
        return '/accounts/'.rawurlencode((string) $app->videoSetting('account_id')).'/stream'.($suffix === '/' ? '' : $suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Response $res, string $operation): array
    {
        $json = $res->json();
        if (! $res->successful() || ! is_array($json) || ($json['success'] ?? false) !== true || ! is_array($json['result'] ?? null)) {
            $message = is_array($json) ? (string) json_encode($json['errors'] ?? []) : '';

            throw new VideoProviderException("Cloudflare API error on {$operation}: HTTP {$res->status()} ".mb_substr($message, 0, 300));
        }

        return $json['result'];
    }
}
