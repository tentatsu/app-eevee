<?php

use App\Models\AdminUser;
use App\Models\AppUser;
use App\Models\SystemAdmin;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admin_users'),
    ],

    /*
    | admin   : 組織の管理画面（/{org_slug}/...）
    | system  : システム管理画面（/system/...）
    | sanctum : アプリ向けAPI（AppUser のトークン。Sanctum が自動登録する）
    */
    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],
        'system' => [
            'driver' => 'session',
            'provider' => 'system_admins',
        ],
    ],

    'providers' => [
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
        'system_admins' => [
            'driver' => 'eloquent',
            'model' => SystemAdmin::class,
        ],
        'app_users' => [
            'driver' => 'eloquent',
            'model' => AppUser::class,
        ],
    ],

    'passwords' => [
        'admin_users' => [
            'provider' => 'admin_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
