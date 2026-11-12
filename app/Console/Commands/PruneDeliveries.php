<?php

namespace App\Console\Commands;

use App\Models\App;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneDeliveries extends Command
{
    protected $signature = 'eevee:push:prune-deliveries';

    protected $description = '保持期間（アプリごと、初期値90日）を過ぎた端末ごとの送信結果を削除する（キャンペーンの集計値は残る）';

    public function handle(): int
    {
        $total = 0;
        foreach (App::withoutGlobalScopes()->get(['id', 'delivery_log_retention_days']) as $app) {
            $before = now()->subDays(max(1, $app->delivery_log_retention_days));
            $campaignIds = DB::table('push_campaigns')->where('app_id', $app->id)->pluck('id');
            foreach ($campaignIds->chunk(500) as $ids) {
                do {
                    $deleted = DB::table('push_deliveries')
                        ->whereIn('push_campaign_id', $ids->all())
                        ->where('created_at', '<', $before)
                        ->limit(5000)
                        ->delete();
                    $total += $deleted;
                } while ($deleted > 0);
            }
        }
        $this->info("{$total} 件の送信結果を削除しました。");

        return self::SUCCESS;
    }
}
