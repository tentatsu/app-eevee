<?php

namespace App\Console\Commands;

use App\Services\Push\CampaignDispatcher;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'eevee:push:dispatch-scheduled';

    protected $description = '送信日時を過ぎた予約キャンペーンを送信する（スケジューラーで定期実行。間隔は EEVEE_SCHEDULE_FREQUENT）';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();
        $this->info("{$count} campaign(s) queued");

        return self::SUCCESS;
    }
}
