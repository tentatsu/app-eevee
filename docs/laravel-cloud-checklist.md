# Laravel Cloud 導入チェックリスト

app-eevee を Laravel Cloud に載せたときに必要だった調整と、運用してわかったことのまとめです。ほかの Laravel のシステムを Laravel Cloud に載せるときにも使えるよう、app-eevee 固有の話は分けて書いています。

- 対象: Laravel 11 以降（app-eevee は Laravel 13 / PHP 8.3 以上）
- 確認した時期: 2026年11月（Laravel Cloud は仕様・料金の変更が多いので、作業前に公式ドキュメントも見てください）

---

## 0. 先に知っておくこと（ハマりどころ）

| # | 内容 | どう困るか |
|---|---|---|
| 1 | **Laravel Cloud の Redis（Upstash 製）は提供終了に向かっている。** 新規作成はできず、2027年8月末で終了予定。代わりに **Laravel Valkey**（Redis 互換）を使う | キャッシュに Redis を選ぼうとして作れない |
| 2 | **Horizon のダッシュボードは使えない。** ワーカーは `queue:work` で動く | Horizon 前提の設定（キュー名・ワーカー数）が効かない |
| 3 | **スケジューラーの実行時刻になると、休止中の環境が起こされる。** 毎分の処理があると一度も休止しない | 休止（hibernation）を有効にしても料金が下がらない（app-eevee では約240時間ずっと動いていた） |
| 4 | **キューのジョブでは休止中の環境は起きない** | 「予約時刻に遅延ジョブを入れる」作りだと、休止中は処理されない |
| 5 | **アプリにはエッジのネットワーク経由でしか届かない** | `TRUSTED_PROXIES` を設定しないと、アクセス元 IP がプロキシの IP になる（IP 制限・レート制限が誤動作） |
| 6 | **ファイルに書いたログは Cloud の画面で見られない** | 独自のログチャンネル（`daily` など）が見えない |
| 7 | **環境変数を変えただけでは反映されない。** 再デプロイが必要 | 設定を変えたのに動作が変わらない |
| 8 | **`*.laravel.cloud` のアドレスからはメールを送れない。** Resend 連携で、自分のドメインを認証して使う | 送信元のアドレスに困る |

---

## 1. コードの調整

### 1-1. Redis / Valkey の接続設定（`config/database.php`）

- [ ] **キーの接頭辞を固定する。** Laravel の初期設定は `APP_NAME` から接頭辞を作るので、表示名を変えるとキャッシュ・キュー・ロックのキーが全部変わる。
- [ ] **TLS を切り替えられるようにする（`REDIS_SCHEME`）。** マネージドの Redis / Valkey には TLS 必須のものがある（Laravel Cloud の Valkey では不要だった）。
- [ ] **キャッシュの DB 番号を切り替えられるようにする（`REDIS_CACHE_DB`）。** DB 0 しか使えないサービスがある（Laravel Cloud の Valkey では不要だった）。

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        // 表示名（APP_NAME）を変えてもキーが変わらないよう、固定値を既定にする
        'prefix' => env('REDIS_PREFIX', 'myapp-'),
        'persistent' => env('REDIS_PERSISTENT', false),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'scheme' => env('REDIS_SCHEME'),          // TLS が必要なら tls
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'scheme' => env('REDIS_SCHEME'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),  // DB 0 しか使えないなら 0
    ],
],
```

- [ ] **Lua スクリプト（`EVAL`）や特殊な命令を使うなら、Valkey で動くか本番で確かめる。** app-eevee は同時視聴の制御で `EVAL` と `cjson`、`ZADD XX` を使っており、Valkey 9 で動いた。確認は後述の確認コマンドに入れておく。

### 1-2. キュー（`config/queue.php`）

- [ ] **`retry_after` を、いちばん長いジョブの `timeout` より長くする。** 初期値は 90 秒。これより長く動くジョブがあると、処理中に別のワーカーがもう一度実行してしまう。

```php
'redis' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('REDIS_QUEUE', 'default'),
    // 最長のジョブの timeout（例: 600 秒）より長くする
    'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 660),
    'block_for' => null,
    'after_commit' => false,
],
```

- [ ] **Horizon を任意にする。** ローカルでは使い、Laravel Cloud では使わない、を設定で切り替える。Horizon 専用の機能（`horizon:snapshot` のスケジュール、ダッシュボードへのリンク）は設定で外す。ジョブは標準のキュー機能だけで書く。

```php
// config/app.php などに
'horizon_enabled' => (bool) env('HORIZON_ENABLED', true),

// routes/console.php
if (config('app.horizon_enabled')) {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
```

- [ ] **独自のキュー名を使うなら、ワーカーの設定に全部書く。** Horizon の設定ファイルには頼れない（app-eevee は `push,default`）。

### 1-3. プロキシ（`bootstrap/app.php`）

- [ ] **プロキシを信頼する。** Laravel Cloud では `*`（直前のプロキシを信頼）。環境変数で変えられるようにしておくと、VPS や AWS（ロードバランサーの CIDR）でも使い回せる。

```php
->withMiddleware(function (Middleware $middleware) {
    $proxies = env('TRUSTED_PROXIES');
    if ($proxies) {
        $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
    }
})
```

確認: IP 制限の画面などで「あなたの現在の IP」を表示し、実際のグローバル IP と一致するか見る。

### 1-4. ログ（`config/logging.php`）

- [ ] **独自のログチャンネルは、出力先を環境変数で切り替える。** ローカル・VPS はファイル、Laravel Cloud は `stderr`（Cloud のログ画面に出る）。

```php
'security' => [
    'driver' => 'stack',
    'channels' => explode(',', (string) env('LOG_SECURITY_CHANNELS', 'security_file')),
    'ignore_exceptions' => false,
],

'security_file' => [
    'driver' => 'daily',
    'path' => storage_path('logs/security.log'),
    'level' => 'info',
    'days' => 90,
],
```

### 1-5. メール

- [ ] **Resend 連携を使うなら、`resend/resend-php` を入れる。** Laravel Cloud の環境に Resend を接続すると `MAIL_*` と API キーは自動で設定されるが、パッケージがないと送れない。

```bash
composer require resend/resend-php
```

- [ ] **送信元のドメインを Resend で認証する**（DNS に DKIM などのレコードを追加）。`*.laravel.cloud` からは送れない。

### 1-6. スケジューラー（休止を使う場合は必須）

- [ ] **定期処理の間隔を環境変数で変えられるようにする。** 毎分のままだと休止できない。本番は毎時（`0 * * * *`）などにする。
- [ ] **間隔を長くしたぶんの遅れを、アクセスのついでの処理で補う**（必要な場合）。app-eevee は予約配信を、Web のリクエストのあと（最大1分に1回）にも確認している。
- [ ] **スケジューラーが動いているかを記録する。** 確認コマンドで「直近の実行予定より後に記録があるか」を見る。
- [ ] **`onOneServer()` を使うなら、キャッシュは共有のストア（`redis` か `database`）にする。**

```php
// routes/console.php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

$frequent = (string) env('SCHEDULE_FREQUENT', '* * * * *');  // 本番（休止あり）は "0 * * * *"

Schedule::command('app:dispatch-scheduled')->cron($frequent)->withoutOverlapping()->onOneServer();
Schedule::command('app:prune')->dailyAt('03:30')->onOneServer();

// 確認コマンド用の記録
Schedule::call(fn () => Cache::forever('app:scheduler:last_run', now()->toIso8601String()))
    ->cron($frequent)->name('app:scheduler-heartbeat')->onOneServer();
```

本当は `env()` を `config/*.php` の中だけで呼ぶほうがよい（`config:cache` 後も確実に読める）。app-eevee では `config('eevee.schedule.frequent')` にしている。

```php
// アクセスのついでに処理するミドルウェア（レスポンスを返したあとに動く）
class DispatchDueTasks
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (config('app.schedule_frequent') === '* * * * *') {
            return; // 毎分動くなら不要
        }
        try {
            if (Cache::add('app:due-check', 1, 60)) {   // 最大1分に1回
                app(TaskDispatcher::class)->dispatchDue();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch due tasks', ['error' => $e->getMessage()]);
        }
    }
}

// bootstrap/app.php
$middleware->append(DispatchDueTasks::class);
```

### 1-7. 本番の確認コマンドを作る（おすすめ）

- [ ] **設定と接続をまとめて確認する artisan コマンドを用意する。** Laravel Cloud のコマンド画面（または API）から実行し、デプロイのたびに確かめられる。NG があれば終了コード 1 にする。

確認している項目（app-eevee の `eevee:doctor`）:

| 項目 | NG / WARN の条件 |
|---|---|
| `APP_KEY` / `APP_DEBUG` / `APP_URL` | 未設定、本番で debug が true、https でない |
| データベース | 接続できない、`migrations` テーブルを読めない |
| Redis / Valkey | 接続できない。`INFO server` でサーバーの種類とバージョンを表示 |
| Lua スクリプト | 使う命令（`EVAL`・`cjson`・`ZADD XX` など）を実際に実行して結果が違う |
| キャッシュ | `array` / `file`（複数台で共有されない）、ロックを取れない |
| キュー | 本番で `sync`、`retry_after` が最長のジョブの timeout 以下 |
| スケジューラー | 記録がない、直近の実行予定より古い |
| `TRUSTED_PROXIES` | 本番で未設定、形式が不正 |
| メール | 本番で `log` / `array` |

ひな形（必要なところだけ残して使う）:

```php
<?php

namespace App\Console\Commands;

use Cron\CronExpression;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class Doctor extends Command
{
    protected $signature = 'app:doctor';

    protected $description = '本番環境の設定と、DB・Redis（Valkey）・キュー・スケジューラーを確認する';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $prod = app()->isProduction();

        // アプリ
        $this->row('APP_KEY', config('app.key') ? 'OK' : 'NG', config('app.key') ? '設定済み' : '未設定');
        $this->row('APP_DEBUG', $prod && config('app.debug') ? 'NG' : 'OK', var_export((bool) config('app.debug'), true));

        // DB
        try {
            $v = DB::selectOne('SELECT VERSION() AS v');
            DB::table('migrations')->count();
            $this->row('データベース', 'OK', DB::getDriverName().' '.($v->v ?? ''));
        } catch (Throwable $e) {
            $this->row('データベース', 'NG', Str::limit($e->getMessage(), 150));
        }

        // Redis / Valkey（Lua を使うなら実際に実行して確かめる）
        try {
            $redis = Redis::connection();
            $redis->ping();
            $info = $redis->command('info', ['server']);
            $server = isset($info['valkey_version']) ? 'Valkey '.$info['valkey_version'] : 'Redis '.($info['redis_version'] ?? '?');
            $key = 'doctor:'.Str::random(8);
            $raw = $redis->eval("redis.call('SET', KEYS[1], 1, 'EX', 60) return cjson.encode({ok = 1})", 1, $key);
            $redis->del($key);
            $ok = (json_decode((string) $raw, true)['ok'] ?? 0) === 1;
            $this->row('Redis / Valkey', $ok ? 'OK' : 'NG', $server.($ok ? '（EVAL / cjson が使える）' : '（Lua の結果が違う）'));
        } catch (Throwable $e) {
            $this->row('Redis / Valkey', 'NG', Str::limit($e->getMessage(), 150));
        }

        // キャッシュ（複数台・onOneServer のために共有ストア）
        $store = (string) config('cache.default');
        $this->row('キャッシュ', in_array($store, ['array', 'file'], true) && $prod ? 'WARN' : 'OK', $store);

        // キュー
        $conn = (string) config('queue.default');
        $retryAfter = (int) config("queue.connections.{$conn}.retry_after", 0);
        $longest = 600; // いちばん長いジョブの timeout
        $this->row('キュー', $conn === 'sync' && $prod ? 'NG' : 'OK', $conn);
        if ($retryAfter > 0) {
            $this->row('retry_after', $retryAfter > $longest ? 'OK' : 'NG', "{$retryAfter} 秒（{$longest} 秒より長くする）");
        }

        // スケジューラー（直近の実行予定より後に記録があるか）
        $last = Cache::get('app:scheduler:last_run');
        $expr = (string) config('app.schedule_frequent', '* * * * *');
        $prev = CarbonImmutable::instance((new CronExpression($expr))->getPreviousRunDate('now', 0, true, (string) config('app.timezone')));
        $ok = is_string($last) && CarbonImmutable::parse($last)->gte($prev->subMinutes(2));
        $this->row('スケジューラー', $ok ? 'OK' : 'WARN', '最終実行: '.($last ?? 'なし').' / 間隔: '.$expr);

        // プロキシ・メール
        $this->row('TRUSTED_PROXIES', $prod && ! env('TRUSTED_PROXIES') ? 'WARN' : 'OK', (string) env('TRUSTED_PROXIES', '未設定'));
        $mailer = (string) config('mail.default');
        $this->row('メール', in_array($mailer, ['log', 'array'], true) && $prod ? 'WARN' : 'OK', $mailer.' / '.config('mail.from.address'));

        $this->table(['項目', '結果', '詳細'], $this->rows);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function row(string $name, string $status, string $detail): void
    {
        $this->failed = $this->failed || $status === 'NG';
        $this->rows[] = [$name, $status, $detail];
    }
}
```

（`env('TRUSTED_PROXIES')` は `config:cache` 後に読めないので、実際は config 経由にする。）

---

## 2. Laravel Cloud 側の設定

### 2-1. リソース

| リソース | 設定 | メモ |
|---|---|---|
| アプリケーション | GitHub のリポジトリを接続。PHP 8.3 以上 | |
| 環境 | デプロイするブランチを選ぶ。push で自動デプロイ | |
| Laravel MySQL | 環境に接続（`DB_*` は自動） | Flex は休止できる（`suspend_seconds`） |
| Laravel Valkey | 環境に接続（`REDIS_*` は自動） | Redis（Upstash）は新規作成不可 |
| キューのワーカー | 処理するキューを全部書く（例: `push,default`）、`--timeout` は最長のジョブに合わせる | Horizon は使わない |
| スケジューラー | 有効にする | 休止を使うなら定期処理の間隔に注意（0 章 #3） |
| メール | Resend を環境に接続 | 送信元ドメインの認証が必要 |

### 2-2. ビルド・デプロイのコマンド

```bash
# ビルド
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
# Vite を使うなら
# npm ci --audit false && npm run build

# デプロイ
php artisan migrate --force
```

### 2-3. 環境変数

Laravel Cloud が自動で入れるもの（`APP_KEY`、`DB_*`、`REDIS_*`、Resend 接続時の `MAIL_*` など）以外:

```dotenv
APP_NAME="表示名"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com      # メールのリンクや Webhook の URL に使う
APP_LOCALE=ja

TRUSTED_PROXIES=*                     # 0 章 #5

QUEUE_CONNECTION=redis
CACHE_STORE=redis                     # Valkey。レート制限・ロック・onOneServer を共有
SESSION_DRIVER=database
SESSION_ENCRYPT=true

REDIS_PREFIX=myapp-                   # 1-1
# REDIS_SCHEME=tls                    # TLS が必要なサービスの場合
# REDIS_CACHE_DB=0                    # DB 0 しか使えないサービスの場合

HORIZON_ENABLED=false                 # 1-2
LOG_SECURITY_CHANNELS=stderr          # 1-4
SCHEDULE_FREQUENT="0 * * * *"         # 1-6（休止を使う場合）
```

- [ ] 変更したら**再デプロイする**（0 章 #7）。

### 2-4. 休止（scale to zero）

- [ ] アプリ・MySQL・Valkey の休止を有効にする（app-eevee はどれも 5 分の無通信で休止）。
- [ ] 定期処理の間隔を長くする（1-6）。
- [ ] 数日後に Usage を見て、MySQL と Valkey の **compute hours が経過時間より十分小さいか**確かめる。経過時間とほぼ同じなら、何かが起こし続けている。
- [ ] 休止中の最初のリクエストは、復帰を待つので数秒遅くなる。休止中はキューのワーカーも止まる。

---

## 3. デプロイ後の確認

- [ ] 確認コマンド（1-7）を実行し、すべて OK か見る。
- [ ] 最初の管理者を作る（app-eevee は `php artisan eevee:system-admin:create you@example.com`）。
- [ ] 「現在の IP」の表示が実際のグローバル IP と一致するか（`TRUSTED_PROXIES`）。
- [ ] メールが届くか（招待メール・パスワード再設定など）。
- [ ] キューの処理が動くか（テスト用のジョブ、app-eevee ならテスト配信）。
- [ ] `php artisan schedule:list` で、定期処理の間隔が意図どおりか。

---

## 4. Laravel Cloud の API を使う

API トークン（Laravel Cloud の画面で発行）があれば、画面を開かずに操作できる。ベース URL は `https://cloud.laravel.com/api`。

| やりたいこと | API |
|---|---|
| artisan を実行する | `POST /environments/{environment}/commands`（`{"command": "php artisan ..."}`）→ 結果は `GET /commands/{command}` の `output` |
| 環境変数を追加・更新する | `POST /environments/{environment}/variables`（`{"method": "set", "variables": [{"key": "...", "value": "..."}]}`） |
| 再デプロイする | `POST /environments/{environment}/deployments` |
| デプロイの状態を見る | `GET /environments/{environment}/deployments`（`status` が `deployment.succeeded` / `failed`） |
| 利用額を見る | `GET /usage`（リソースごとの金額と compute hours） |
| 環境を止める・起動する | `POST /environments/{environment}/stop` / `start`（起動時にデプロイも走る） |

- `tinker --execute` に PHP を渡すときは、シェルのクォートに注意する（`$` がシェルに展開される）。JSON にするときは、コマンド全体を JSON の文字列としてエスケープする。
- API の結果には `APP_KEY` などの秘密の値も含まれる。ログやチャットに出さない。

---

## 5. app-eevee 固有のもの（参考）

| 内容 | 場所 |
|---|---|
| 定期処理の間隔（`EEVEE_SCHEDULE_FREQUENT`）と、アクセスのついでの予約配信 | `app/Support/ScheduleFrequency.php`、`app/Http/Middleware/DispatchDueCampaigns.php` |
| 確認コマンド | `app/Console/Commands/Doctor.php`（`php artisan eevee:doctor`） |
| 同時視聴の制御（Valkey の Lua） | `app/Services/Video/PlaybackSessionManager.php` |
| デプロイ手順の詳細 | `docs/deployment.md` |
| 設計判断の理由 | `docs/decisions.md`（D-024 Laravel Cloud、D-028 休止） |
