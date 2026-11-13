<?php

namespace App\Http\Controllers\Api;

use App\Models\AppUser;
use App\Models\PlaybackSession;
use App\Models\Video;
use App\Services\Video\Access\VideoAccessGate;
use App\Services\Video\PlaybackSessionManager;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoThumbnails;
use App\Support\Api\ApiException;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags 動画
 */
class VideoController extends ApiController
{
    public function __construct(
        private readonly PlaybackSessionManager $sessions,
        private readonly VideoAccessGate $gate,
        private readonly VideoProviderManager $providers,
        private readonly VideoThumbnails $thumbnails,
    ) {}

    /**
     * 動画の一覧
     *
     * 再生可能で公開期間内の動画（並び順 → 新しい順）。メンバー限定の動画も一覧には含め、`playable` で再生できるかを返す。
     * `category`・`tag` で絞り込める。
     *
     * @unauthenticated
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'string', 'max:64'],
            'tag' => ['nullable', 'string', 'max:64'],
        ]);
        $user = $this->optionalAppUser($request);

        $query = Video::query()->listed()->orderBy('sort_order')->orderByDesc('id');
        if ($category = $request->string('category')->value()) {
            $query->where('category', $category);
        }
        if ($tag = $request->string('tag')->value()) {
            $query->whereJsonContains('tags', $tag);
        }

        return ApiResponse::paginated($query->paginate($request->integer('per_page', 20)), fn (Video $v) => $this->toArray($v, $user));
    }

    /**
     * 動画の詳細
     *
     * @unauthenticated
     */
    public function show(Request $request, int $video): JsonResponse
    {
        $model = Video::query()->listed()->find($video);
        if ($model === null) {
            return ApiResponse::error('video_not_available', 'The video is not available.', 404);
        }

        return ApiResponse::ok($this->toArray($model, $this->optionalAppUser($request)));
    }

    /**
     * 再生を開始する
     *
     * 視聴権限と同時視聴数を確認し、視聴セッションと署名付きの HLS / DASH の URL を返す。
     * 以後は `heartbeat_interval` 秒ごとに heartbeat を呼ぶ。未ログインで公開動画を再生する場合は `installation_id` が必須。
     *
     * 上限を超えた場合: アプリの設定が「拒否」なら `409 concurrent_limit_exceeded`。「最も古いセッションを停止」なら再生でき、
     * 停止されたセッションは次の heartbeat で `stop`（reason=kicked）になる。
     *
     * @unauthenticated
     */
    public function play(Request $request, int $video): JsonResponse
    {
        $data = $request->validate(['installation_id' => ['nullable', 'uuid']]);
        $app = $this->currentApp();
        $user = $this->optionalAppUser($request);

        $model = Video::query()->find($video);
        if ($model === null) {
            return ApiResponse::error('video_not_available', 'The video is not available.', 404);
        }
        $decision = $this->gate->check($app, $model, $user);
        if (! $decision->allowed) {
            return ApiResponse::error((string) $decision->code, (string) $decision->message, $decision->status);
        }

        $provider = $this->providers->for($app);
        try {
            $result = $this->sessions->start(
                $app,
                $model,
                $user,
                PlaybackSessionManager::viewerKey($user, $data['installation_id'] ?? null),
                fn (CarbonImmutable $expiresAt) => $provider->playbackUrls($app, $model, $expiresAt),
            );
        } catch (VideoProviderException) {
            throw new ApiException('video_provider_not_configured', 'Video playback is not configured for this app.', 503);
        }

        return ApiResponse::ok([
            'session_id' => $result['session']->id,
            'hls_url' => $result['urls']->hlsUrl,
            'dash_url' => $result['urls']->dashUrl,
            'token' => $result['urls']->token,
            'expires_at' => $result['urls']->expiresAt->toIso8601ZuluString(),
            'heartbeat_interval' => $this->sessions->heartbeatInterval(),
            'heartbeat_timeout' => $this->sessions->heartbeatTimeout(),
        ], 201);
    }

    /**
     * ハートビート
     *
     * 再生中は `heartbeat_interval`（30）秒ごとに呼ぶ。`action` が `stop` なら再生を止める（`reason`: kicked = 別の端末で再生が始まった / expired = ハートビートが途絶えた / stopped = 終了済み）。
     *
     * @unauthenticated
     */
    public function heartbeat(Request $request, string $session): JsonResponse
    {
        $model = $this->ownedSession($request, $session);

        return ApiResponse::ok($this->sessions->heartbeat($this->currentApp(), $model));
    }

    /**
     * 再生を終了する
     *
     * @unauthenticated
     */
    public function stop(Request $request, string $session): JsonResponse
    {
        $model = $this->ownedSession($request, $session);
        $this->sessions->stop($this->currentApp(), $model);

        return ApiResponse::ok(['stopped' => true]);
    }

    /**
     * セッションを開始した視聴者本人（ユーザー、または同じ installation_id の端末）だけが操作できる。
     */
    private function ownedSession(Request $request, string $id): PlaybackSession
    {
        $data = $request->validate(['installation_id' => ['nullable', 'uuid']]);
        $session = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? PlaybackSession::query()->find($id) : null;
        $user = $this->optionalAppUser($request);

        $viewer = null;
        try {
            $viewer = PlaybackSessionManager::viewerKey($user, $data['installation_id'] ?? null);
        } catch (ApiException) {
            // 視聴者を特定できない
        }
        if ($session === null || $viewer === null || ! hash_equals($session->viewer_key, $viewer)) {
            throw new ApiException('session_not_found', 'The playback session was not found.', 404);
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Video $video, ?AppUser $user): array
    {
        $decision = $this->gate->check($this->currentApp(), $video, $user);

        return array_merge($video->toApiArray(), [
            // 管理画面で指定したサムネイル、なければ動画プロバイダが自動で作ったもの（署名付き）
            'thumbnail_url' => $this->thumbnails->url($this->currentApp(), $video),
            'playable' => $decision->allowed,
            // 再生できない理由: login_required（ログインが必要）/ entitlement_required（有料プランが必要）
            'locked_reason' => $decision->allowed ? null : $decision->code,
        ]);
    }
}
