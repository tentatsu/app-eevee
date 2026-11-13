<?php

use App\Services\Video\Access\DefaultVideoAccessPolicy;
use App\Services\Video\Access\EntitlementVideoAccessPolicy;
use App\Services\Video\Cloudflare\CloudflareStreamProvider;

return [

    /*
    | 表示に使う既定のタイムゾーン。DB には常に UTC で保存する。
    */
    'default_timezone' => env('EEVEE_DEFAULT_TIMEZONE', 'Asia/Tokyo'),

    /*
    | X-Forwarded-For 等を信頼するプロキシ（カンマ区切り、または *）。
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    | システム管理画面の IP 許可リスト（.env 分）。DB（ip_allow_entries.organization_id IS NULL）と合算する。
    */
    'system_ip_allowlist' => env('SYSTEM_IP_ALLOWLIST', ''),

    'invitation_ttl_days' => 7,

    /*
    | Horizon を使うか。Laravel Cloud のマネージドキュー（queue:work で動く）では false にする。
    | false でもジョブ自体は通常のキューワーカーで動く（Horizon はダッシュボードと監視だけの役割）。
    */
    'horizon' => [
        'enabled' => (bool) env('EEVEE_HORIZON_ENABLED', true),
    ],

    'rate_limits' => [
        'app_per_minute' => (int) env('EEVEE_RATE_LIMIT_PER_APP', 6000),
        'ip_per_minute' => (int) env('EEVEE_RATE_LIMIT_PER_IP', 300),
        'auth_per_minute' => 10,
        'server_per_minute' => (int) env('EEVEE_RATE_LIMIT_SERVER_PER_APP', 1200),
    ],

    'app_users' => [
        'password_reset_ttl_minutes' => 60,
        'builtin_token_ttl_days' => 365,
    ],

    'push' => [
        'chunk_size' => (int) env('EEVEE_PUSH_CHUNK_SIZE', 500),
        'fake' => (bool) env('EEVEE_PUSH_FAKE', false),
        'max_attempts' => 5,
        // 指数バックオフ（秒）
        'backoff' => [10, 30, 90, 270],
        'queue' => env('EEVEE_PUSH_QUEUE', 'push'),
    ],

    'video' => [
        'fake' => (bool) env('EEVEE_VIDEO_FAKE', false),
        // EEVEE_VIDEO_FAKE=true のときに再生 API が返す HLS（公開のテスト用ストリーム）
        'fake_hls_url' => env('EEVEE_VIDEO_FAKE_HLS_URL', 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8'),
        'heartbeat_interval' => 30,
        'heartbeat_timeout' => (int) env('EEVEE_VIDEO_HEARTBEAT_TIMEOUT', 90),
        // 長さ不明の動画に使うトークン有効期限（秒）
        'default_duration_seconds' => 3600,
        'max_upload_duration_seconds' => 21600,
        'redis_connection' => env('EEVEE_VIDEO_REDIS_CONNECTION', 'default'),

        // アプリごとに選べる動画プロバイダ（apps.video_provider => クラス）
        'providers' => [
            'cloudflare_stream' => CloudflareStreamProvider::class,
        ],

        // 視聴権限の判定（順に評価し、1つでも拒否すれば再生不可）
        'access_policies' => [
            DefaultVideoAccessPolicy::class,
            EntitlementVideoAccessPolicy::class,
        ],
    ],

    /*
    | 課金（Stripe Connect）。プラットフォームのキーはシステム管理画面で登録する（DB に暗号化して保存）。
    */
    'billing' => [
        // プラットフォームの手数料（%）の既定値。システム管理画面で変更できる
        'default_fee_percent' => (float) env('EEVEE_BILLING_FEE_PERCENT', 10),
    ],

];
