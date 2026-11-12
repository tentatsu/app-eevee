<?php

namespace App\Services\Push;

use App\Models\App;
use App\Services\Push\Contracts\MessagingFactory;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use RuntimeException;

/**
 * kreait/firebase-php でアプリごとの Messaging を動的に作る（FCM HTTP v1）。
 * 同じプロセス内では認証情報が変わらない限り使い回す。
 */
class KreaitMessagingFactory implements MessagingFactory
{
    /** @var array<string, Messaging> */
    private array $instances = [];

    public function for(App $app): Messaging
    {
        $credentials = $app->firebase_credentials;
        if (! $app->hasFirebaseCredentials() || $credentials === null) {
            throw new RuntimeException("Firebase credentials are not configured for app {$app->id}.");
        }

        $cacheKey = $app->id.':'.hash('sha256', (string) json_encode($credentials));

        return $this->instances[$cacheKey] ??= (new Factory)
            ->withServiceAccount($credentials)
            ->createMessaging();
    }
}
