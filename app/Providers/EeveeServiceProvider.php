<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス（FCM / 動画プロバイダ）のクライアントの束縛。テストではフェイクに差し替える。
 */
class EeveeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
