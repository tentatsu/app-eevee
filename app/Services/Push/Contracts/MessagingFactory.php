<?php

namespace App\Services\Push\Contracts;

use App\Models\App;
use Kreait\Firebase\Contract\Messaging;

/**
 * アプリごとの Firebase 認証情報から Messaging のインスタンスを作る。
 */
interface MessagingFactory
{
    public function for(App $app): Messaging;
}
