<?php

namespace App\Services\Push\Contracts;

use App\Models\App;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;

/**
 * プッシュ通知の送信クライアント。本番は FCM（FcmPushSender）、テストではフェイクに差し替える。
 */
interface PushSender
{
    /**
     * @return list<PushResult> 宛先（delivery_id）ごとの結果
     */
    public function send(App $app, PushMessage $message): array;
}
