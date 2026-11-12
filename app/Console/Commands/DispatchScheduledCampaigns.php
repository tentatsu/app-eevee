<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\PushCampaign;
use App\Services\Push\CampaignDispatcher;
use Illuminate\Console\Command;
use Throwable;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'eevee:push:dispatch-scheduled';

    protected $description = '送信日時を過ぎた予約キャンペーンを送信する（スケジューラーで毎分実行）';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        $campaigns = PushCampaign::withoutGlobalScopes()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                $dispatcher->queue($campaign);
                $this->info("campaign #{$campaign->id} queued");
            } catch (Throwable $e) {
                // 別のプロセスが先に処理した場合など
                $this->warn("campaign #{$campaign->id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
