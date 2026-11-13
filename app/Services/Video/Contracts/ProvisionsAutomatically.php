<?php

namespace App\Services\Video\Contracts;

use App\Models\App;
use App\Services\Video\Data\ProvisionResult;

/**
 * 管理画面の「自動設定」に対応する動画プロバイダ。
 * 署名鍵や Webhook のシークレットをサーバーの中だけで作って保存する（人やログを経由させない）。
 */
interface ProvisionsAutomatically
{
    /**
     * @param  bool  $overwriteWebhook  別の URL が Webhook に設定済みでも上書きする
     */
    public function provision(App $app, string $webhookUrl, bool $overwriteWebhook): ProvisionResult;
}
