<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\PushCampaign;
use App\Services\Push\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * キャンペーンの対象端末を抽出し、チャンク送信ジョブを投入する。
 */
class PrepareCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $campaignId)
    {
        $this->onQueue((string) config('eevee.push.queue', 'push'));
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        $campaign = PushCampaign::withoutGlobalScopes()->find($this->campaignId);
        if ($campaign === null) {
            return;
        }
        $dispatcher->prepare($campaign);
    }

    public function failed(?Throwable $e): void
    {
        PushCampaign::withoutGlobalScopes()->whereKey($this->campaignId)
            ->whereIn('status', [CampaignStatus::Queued->value, CampaignStatus::Sending->value])
            ->update(['status' => CampaignStatus::Failed->value, 'finished_at' => now()]);
    }
}
