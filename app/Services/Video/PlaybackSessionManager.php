<?php

namespace App\Services\Video;

use App\Enums\ConcurrencyPolicy;
use App\Models\App;
use App\Models\AppUser;
use App\Models\PlaybackLimitEvent;
use App\Models\PlaybackSession;
use App\Models\Video;
use App\Services\Video\Data\PlaybackUrls;
use App\Support\Api\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * 視聴セッションと同時視聴数の制御。
 *
 * Redis（判定用）:
 *   eevee:playback:{app_id}:viewer:{viewer_key}  ZSET  member=session_id, score=最終ハートビート（ミリ秒）
 *   eevee:playback:session:{session_id}          HASH  state(active|kicked|stopped|expired) ほか
 * DB（統計用）: playback_sessions（視聴開始・終了・視聴秒数）、playback_limit_events（上限超過）
 *
 * 視聴者は、ログインしていればユーザー（u:{id}）、未ログインなら端末（d:{installation_id}）。
 * ハートビートが heartbeat_timeout 秒（初期値90秒）途絶えたセッションは期限切れとして数えない。
 */
class PlaybackSessionManager
{
    /**
     * 期限切れを取り除き、上限を判定し、セッションを追加する（原子的に実行する Lua）。
     * 戻り値: {"ok": 0|1, "kicked": [...], "expired": [...]}
     */
    private const START_SCRIPT = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local timeoutMs = tonumber(ARGV[2])
local max = tonumber(ARGV[3])
local policy = ARGV[4]
local sid = ARGV[5]
local ttl = tonumber(ARGV[6])
local expired = redis.call('ZRANGEBYSCORE', key, '-inf', now - timeoutMs)
if #expired > 0 then
  redis.call('ZREMRANGEBYSCORE', key, '-inf', now - timeoutMs)
end
local count = redis.call('ZCARD', key)
local kicked = {}
if count >= max then
  if policy ~= 'kick_oldest' then
    return cjson.encode({ok = 0, kicked = kicked, expired = expired})
  end
  kicked = redis.call('ZRANGE', key, 0, count - max)
  for _, k in ipairs(kicked) do
    redis.call('ZREM', key, k)
  end
end
redis.call('ZADD', key, now, sid)
redis.call('PEXPIRE', key, ttl)
return cjson.encode({ok = 1, kicked = kicked, expired = expired})
LUA;

    public function heartbeatTimeout(): int
    {
        return (int) config('eevee.video.heartbeat_timeout', 90);
    }

    public function heartbeatInterval(): int
    {
        return (int) config('eevee.video.heartbeat_interval', 30);
    }

    public static function viewerKey(?AppUser $user, ?string $installationId): string
    {
        if ($user !== null) {
            return 'u:'.$user->id;
        }
        if ($installationId === null || $installationId === '') {
            throw new ApiException('installation_id_required', 'installation_id is required when not logged in.', 422);
        }

        return 'd:'.strtolower($installationId);
    }

    /**
     * 視聴セッションを開始する。上限超過時はアプリの設定に従い、拒否（409）するか最も古いセッションを停止する。
     *
     * @param  callable(CarbonImmutable): PlaybackUrls  $makeUrls  セッション作成が確定してから再生 URL を作る
     * @return array{session: PlaybackSession, urls: PlaybackUrls, kicked: list<string>}
     */
    public function start(App $app, Video $video, ?AppUser $user, string $viewerKey, callable $makeUrls): array
    {
        $now = CarbonImmutable::now();
        $sessionId = (string) Str::uuid();
        $duration = $video->duration_seconds ?: (int) config('eevee.video.default_duration_seconds', 3600);
        $expiresAt = $now->addSeconds($duration)->addMinutes($app->playback_token_grace_minutes);

        $raw = $this->redis()->eval(self::START_SCRIPT, 1,
            $this->viewerSetKey($app, $viewerKey),
            (string) $now->valueOf(),
            (string) ($this->heartbeatTimeout() * 1000),
            (string) max(1, $app->max_concurrent_streams),
            $app->concurrency_policy->value,
            $sessionId,
            (string) (($duration + 3600) * 1000),
        );
        $result = json_decode(is_string($raw) ? $raw : '', true);
        $result = is_array($result) ? $result : [];
        // Lua の空テーブルは JSON の {} になるため、配列でなければ空として扱う
        $kicked = is_array($result['kicked'] ?? null) ? array_values(array_map('strval', $result['kicked'])) : [];
        $expired = is_array($result['expired'] ?? null) ? array_values(array_map('strval', $result['expired'])) : [];

        $this->finishInDb($expired, 'expired', null);

        if (($result['ok'] ?? 0) !== 1) {
            PlaybackLimitEvent::query()->create([
                'app_id' => $app->id, 'video_id' => $video->id, 'app_user_id' => $user?->id,
                'viewer_key' => $viewerKey, 'policy' => ConcurrencyPolicy::Reject->value,
            ]);

            throw new ApiException('concurrent_limit_exceeded', 'The maximum number of concurrent streams has been reached.', 409, [
                'max_concurrent_streams' => $app->max_concurrent_streams,
            ]);
        }

        foreach ($kicked as $kickedId) {
            $this->setState($kickedId, 'kicked');
            PlaybackLimitEvent::query()->create([
                'app_id' => $app->id, 'video_id' => $video->id, 'app_user_id' => $user?->id,
                'viewer_key' => $viewerKey, 'policy' => ConcurrencyPolicy::KickOldest->value, 'kicked_session_id' => $kickedId,
            ]);
        }
        $this->finishInDb($kicked, 'kicked', $now);

        try {
            $urls = $makeUrls($expiresAt);
        } catch (\Throwable $e) {
            $this->redis()->zrem($this->viewerSetKey($app, $viewerKey), $sessionId);

            throw $e;
        }

        $session = PlaybackSession::query()->create([
            'id' => $sessionId,
            'app_id' => $app->id,
            'video_id' => $video->id,
            'app_user_id' => $user?->id,
            'viewer_key' => $viewerKey,
            'started_at' => $now,
            'last_heartbeat_at' => $now,
            'token_expires_at' => $urls->expiresAt,
        ]);

        $this->redis()->hmset($this->sessionKey($sessionId), [
            'app_id' => $app->id, 'viewer' => $viewerKey, 'video_id' => $video->id, 'state' => 'active', 'last' => $now->valueOf(),
        ]);
        $this->redis()->expire($this->sessionKey($sessionId), $duration + 86400);

        return ['session' => $session, 'urls' => $urls, 'kicked' => $kicked];
    }

    /**
     * ハートビート。継続してよければ continue、停止すべきなら stop と理由を返す。
     *
     * @return array{action: 'continue'|'stop', reason: string|null}
     */
    public function heartbeat(App $app, PlaybackSession $session): array
    {
        $now = CarbonImmutable::now();
        $state = $this->redis()->hget($this->sessionKey($session->id), 'state');

        if ($session->ended_at !== null || in_array($state, ['kicked', 'stopped', 'expired'], true)) {
            return ['action' => 'stop', 'reason' => $session->end_reason ?? (is_string($state) ? $state : 'ended')];
        }

        $setKey = $this->viewerSetKey($app, $session->viewer_key);
        $score = $this->redis()->zscore($setKey, $session->id);
        $timeoutMs = $this->heartbeatTimeout() * 1000;
        if (! is_numeric($score) || (float) $score < $now->valueOf() - $timeoutMs) {
            $this->redis()->zrem($setKey, $session->id);
            $this->setState($session->id, 'expired');
            $this->finishInDb([$session->id], 'expired', null);

            return ['action' => 'stop', 'reason' => 'expired'];
        }

        $this->redis()->zadd($setKey, 'XX', $now->valueOf(), $session->id);
        $this->redis()->hset($this->sessionKey($session->id), 'last', $now->valueOf());

        DB::table('playback_sessions')->where('id', $session->id)->whereNull('ended_at')->update([
            'watched_seconds' => DB::raw('watched_seconds + '.$this->watchedDelta($session, $now)),
            'last_heartbeat_at' => $now,
            'updated_at' => $now,
        ]);

        return ['action' => 'continue', 'reason' => null];
    }

    /**
     * 再生の終了。
     */
    public function stop(App $app, PlaybackSession $session): void
    {
        $now = CarbonImmutable::now();
        $this->redis()->zrem($this->viewerSetKey($app, $session->viewer_key), $session->id);
        $this->setState($session->id, 'stopped');

        if ($session->ended_at === null) {
            DB::table('playback_sessions')->where('id', $session->id)->whereNull('ended_at')->update([
                'watched_seconds' => DB::raw('watched_seconds + '.$this->watchedDelta($session, $now)),
                'last_heartbeat_at' => $now,
                'ended_at' => $now,
                'end_reason' => 'stopped',
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * ハートビートが途絶えたセッションを期限切れにする（スケジューラーで毎分実行）。
     */
    public function expireStale(): int
    {
        $threshold = CarbonImmutable::now()->subSeconds($this->heartbeatTimeout());
        $count = 0;
        PlaybackSession::withoutGlobalScopes()
            ->whereNull('ended_at')
            ->where('last_heartbeat_at', '<', $threshold)
            ->select(['id', 'app_id', 'viewer_key'])
            ->chunkById(500, function ($sessions) use (&$count) {
                foreach ($sessions as $s) {
                    $this->redis()->zrem('eevee:playback:'.$s->app_id.':viewer:'.$s->viewer_key, $s->id);
                    $this->setState($s->id, 'expired');
                }
                $count += $this->finishInDb($sessions->pluck('id')->all(), 'expired', null);
            }, 'id');

        return $count;
    }

    /**
     * 現在の有効なセッション数（管理画面・テスト用）。
     */
    public function activeCount(App $app, string $viewerKey): int
    {
        $min = CarbonImmutable::now()->valueOf() - $this->heartbeatTimeout() * 1000;

        return (int) $this->redis()->zcount($this->viewerSetKey($app, $viewerKey), (string) $min, '+inf');
    }

    /**
     * 前回のハートビートからの経過秒数（通信の途絶を数えすぎないよう、ハートビート間隔の2倍で打ち切る）。
     */
    private function watchedDelta(PlaybackSession $session, CarbonImmutable $now): int
    {
        $last = CarbonImmutable::parse((string) (DB::table('playback_sessions')->where('id', $session->id)->value('last_heartbeat_at') ?? $session->last_heartbeat_at));

        return max(0, min($this->heartbeatInterval() * 2, (int) $last->diffInSeconds($now, true)));
    }

    /**
     * @param  list<string>  $sessionIds
     */
    private function finishInDb(array $sessionIds, string $reason, ?CarbonImmutable $endedAt): int
    {
        if ($sessionIds === []) {
            return 0;
        }

        // 期限切れは「最後のハートビートの時刻」で終了とする
        return DB::table('playback_sessions')->whereIn('id', $sessionIds)->whereNull('ended_at')->update([
            'ended_at' => $endedAt ?? DB::raw('last_heartbeat_at'),
            'end_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    private function setState(string $sessionId, string $state): void
    {
        $this->redis()->hset($this->sessionKey($sessionId), 'state', $state);
        $this->redis()->expire($this->sessionKey($sessionId), 86400);
    }

    private function viewerSetKey(App $app, string $viewerKey): string
    {
        return 'eevee:playback:'.$app->id.':viewer:'.$viewerKey;
    }

    private function sessionKey(string $sessionId): string
    {
        return 'eevee:playback:session:'.$sessionId;
    }

    private function redis(): PhpRedisConnection
    {
        // phpredis クライアント（REDIS_CLIENT=phpredis、Sail の既定）を前提にする
        return Redis::connection((string) config('eevee.video.redis_connection', 'default'));
    }
}
