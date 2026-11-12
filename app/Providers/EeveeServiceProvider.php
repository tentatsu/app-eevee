<?php

namespace App\Providers;

use App\Services\Push\Contracts\MessagingFactory;
use App\Services\Push\Contracts\PushSender;
use App\Services\Push\FakePushSender;
use App\Services\Push\FcmPushSender;
use App\Services\Push\KreaitMessagingFactory;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス（FCM / 動画プロバイダ）のクライアントの束縛。テストではフェイクに差し替える。
 */
class EeveeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessagingFactory::class, KreaitMessagingFactory::class);
        $this->app->singleton(PushSender::class, function ($app) {
            return config('eevee.push.fake')
                ? new FakePushSender(log: true)
                : new FcmPushSender($app->make(MessagingFactory::class));
        });
    }
}
