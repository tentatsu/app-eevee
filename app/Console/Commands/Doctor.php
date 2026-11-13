<?php

namespace App\Console\Commands;

use App\Support\Ip\IpRange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * 本番環境（Laravel Cloud など）の設定と、依存サービスへの接続を確認する。
 *
 * 特に視聴セッションの同時視聴数の制御は Redis 互換ストア（Valkey）の Lua スクリプト（cjson）を使うため、
 * ここで実際に同じ命令を実行して確かめる。
 */
class Doctor extends Command
{
    protected $signature = 'eevee:doctor';

    protected $description = '本番環境の設定と、DB・Redis（Valkey）・キュー・スケジューラーへの接続を確認する';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $this->checkApp();
        $this->checkDatabase();
        $this->checkRedis();
        $this->checkCache();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkProxiesAndMail();

        $this->table(['項目', '結果', '詳細'], $this->rows);

        if ($this->failed) {
            $this->error('NG の項目があります。docs/deployment.md を参照してください。');

            return self::FAILURE;
        }
        $this->info('すべての必須項目は OK です。');

        return self::SUCCESS;
    }

    private function checkApp(): void
    {
        $production = app()->isProduction();
        $this->row('APP_KEY', config('app.key') ? 'OK' : 'NG', config('app.key') ? '設定済み' : '未設定（php artisan key:generate）');
        $this->row('APP_DEBUG', $production && config('app.debug') ? 'NG' : 'OK', $production && config('app.debug') ? '本番では false にする' : var_export((bool) config('app.debug'), true));

        $url = (string) config('app.url');
        $isHttps = str_starts_with($url, 'https://');
        $this->row('APP_URL', $production && ! $isHttps ? 'WARN' : 'OK', $url.($production && ! $isHttps ? '（招待メールと Webhook の URL に使う。https の独自ドメインにする）' : ''));
    }

    private function checkDatabase(): void
    {
        try {
            $version = DB::selectOne('SELECT VERSION() AS v');
            $this->row('データベース', 'OK', DB::getDriverName().' '.($version->v ?? ''));
            DB::table('migrations')->count();
        } catch (Throwable $e) {
            $this->row('データベース', 'NG', Str::limit($e->getMessage(), 150));
        }
    }

    private function checkRedis(): void
    {
        $connection = (string) config('eevee.video.redis_connection', 'default');
        try {
            $redis = Redis::connection($connection);
            $redis->ping();
            $info = $redis->command('info', ['server']);
            $server = is_array($info) ? ($info['valkey_version'] ?? null ? 'Valkey '.$info['valkey_version'] : 'Redis '.($info['redis_version'] ?? '?')) : '?';
            $this->row('Redis / Valkey 接続', 'OK', $server.'（接続名: '.$connection.'）');
        } catch (Throwable $e) {
            $this->row('Redis / Valkey 接続', 'NG', Str::limit($e->getMessage(), 150));

            return;
        }

        // 視聴セッションと同じ命令（EVAL + cjson、ZADD XX、ZSCORE）が使えるか
        $key = 'eevee:doctor:'.Str::random(8);
        try {
            $raw = $redis->eval(<<<'LUA'
redis.call('ZADD', KEYS[1], 1, 'a')
redis.call('PEXPIRE', KEYS[1], 60000)
return cjson.encode({ok = 1, count = redis.call('ZCARD', KEYS[1])})
LUA, 1, $key);
            $decoded = json_decode(is_string($raw) ? $raw : '', true);
            $redis->zadd($key, 'XX', 2, 'a');
            $score = $redis->zscore($key, 'a');
            $redis->del($key);

            $ok = is_array($decoded) && ($decoded['ok'] ?? 0) === 1 && (float) $score === 2.0;
            $this->row('Lua スクリプト（同時視聴数の制御）', $ok ? 'OK' : 'NG', $ok ? 'EVAL / cjson / ZADD XX が使える' : '結果が想定と違う: '.json_encode([$raw, $score]));
        } catch (Throwable $e) {
            $this->row('Lua スクリプト（同時視聴数の制御）', 'NG', Str::limit($e->getMessage(), 150));
        }
    }

    private function checkCache(): void
    {
        $store = (string) config('cache.default');
        $shared = ! in_array($store, ['array', 'file'], true);
        try {
            $lock = Cache::lock('eevee:doctor:lock', 5);
            $acquired = $lock->get();
            $lock->release();
            $this->row('キャッシュ', $shared ? 'OK' : 'WARN', $store.($shared ? '' : '（複数台では共有されない。レート制限と onOneServer のために redis か database にする）').($acquired ? '' : ' / ロックを取得できない'));
        } catch (Throwable $e) {
            $this->row('キャッシュ', 'NG', Str::limit($e->getMessage(), 150));
        }
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');
        $pushQueue = (string) config('eevee.push.queue');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);

        $this->row('キュー', $connection === 'sync' && app()->isProduction() ? 'NG' : 'OK', $connection.' / プッシュ用のキュー名: '.$pushQueue.'（ワーカーが '.$pushQueue.' と default を処理すること）');
        if ($retryAfter > 0) {
            $this->row('キューの retry_after', $retryAfter > 600 ? 'OK' : 'NG', $retryAfter.' 秒（最長のジョブの timeout 600 秒より長くする）');
        }
        $this->row('Horizon', 'OK', config('eevee.horizon.enabled') ? '使う（php artisan horizon で起動）' : '使わない（通常のキューワーカーで処理）');
    }

    private function checkScheduler(): void
    {
        try {
            $last = Cache::get('eevee:scheduler:last_run');
        } catch (Throwable $e) {
            $this->row('スケジューラー', 'NG', 'キャッシュを読めない: '.Str::limit($e->getMessage(), 120));

            return;
        }
        if (! is_string($last)) {
            $this->row('スケジューラー', 'WARN', 'まだ実行の記録がない（予約配信と視聴セッションの期限切れ処理に必要）');

            return;
        }
        $minutes = (int) now()->diffInMinutes($last, true);
        $this->row('スケジューラー', $minutes <= 2 ? 'OK' : 'WARN', '最終実行: '.$last.($minutes > 2 ? '（'.$minutes.'分前。止まっているか、アプリが休止している）' : ''));
    }

    private function checkProxiesAndMail(): void
    {
        $proxies = trim((string) config('eevee.trusted_proxies'));
        $valid = $proxies === '*' || $proxies === '**' || ($proxies !== '' && IpRange::parseList($proxies) !== []);
        $this->row('TRUSTED_PROXIES', $proxies === '' && app()->isProduction() ? 'WARN' : 'OK', $proxies === '' ? '未設定（プロキシの背後では IP 制限が正しく動かない。Laravel Cloud では *）' : $proxies.($valid ? '' : '（形式が不正）'));

        $mailer = (string) config('mail.default');
        $this->row('メール', in_array($mailer, ['log', 'array'], true) && app()->isProduction() ? 'WARN' : 'OK', $mailer.' / 送信元: '.config('mail.from.address'));
        $this->row('ログ（セキュリティ）', 'OK', implode(',', (array) config('logging.channels.security.channels')));
    }

    private function row(string $name, string $status, string $detail): void
    {
        if ($status === 'NG') {
            $this->failed = true;
        }
        $this->rows[] = [$name, $status, $detail];
    }
}
