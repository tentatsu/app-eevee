<?php

namespace App\Services\Push;

use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Enums\DeliveryStatus;
use App\Jobs\PrepareCampaignJob;
use App\Jobs\SendCampaignChunkJob;
use App\Models\App;
use App\Models\PushCampaign;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * キャンペーンの送信を開始する。
 *
 * 1. 対象の端末を抽出し、配信ログ（push_deliveries）に INSERT IGNORE する
 *    → (campaign, device) のユニーク制約により、同じ端末に同じキャンペーンを二重に送らない
 * 2. 未送信の配信ログを 500 件ずつのチャンクに分け、キューで送信する
 */
class CampaignDispatcher
{
    public function __construct(private readonly CampaignTargetResolver $targets) {}

    /**
     * 即時送信、または予約（scheduled_at が未来）する。
     */
    public function send(PushCampaign $campaign): void
    {
        if ($campaign->scheduled_at !== null && $campaign->scheduled_at->isFuture()) {
            $updated = PushCampaign::withoutGlobalScopes()->whereKey($campaign->id)
                ->whereIn('status', [CampaignStatus::Draft->value])
                ->update(['status' => CampaignStatus::Scheduled->value]);
            if ($updated === 0) {
                throw new RuntimeException('This campaign cannot be scheduled in its current status.');
            }
            $campaign->refresh();

            return;
        }

        $this->queue($campaign);
    }

    /**
     * 送信待ちにしてキューへ投入する（下書き・予約済みのキャンペーンだけ）。
     */
    public function queue(PushCampaign $campaign): void
    {
        $updated = PushCampaign::withoutGlobalScopes()->whereKey($campaign->id)
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])
            ->update(['status' => CampaignStatus::Queued->value]);
        if ($updated === 0) {
            throw new RuntimeException('This campaign has already been sent or is being sent.');
        }
        $campaign->refresh();

        PrepareCampaignJob::dispatch($campaign->id);
    }

    public function cancelSchedule(PushCampaign $campaign): bool
    {
        return PushCampaign::withoutGlobalScopes()->whereKey($campaign->id)
            ->where('status', CampaignStatus::Scheduled->value)
            ->update(['status' => CampaignStatus::Draft->value]) > 0;
    }

    /**
     * テスト配信: テスト端末だけに送る子キャンペーンを作って即時送信する。
     */
    public function sendTest(PushCampaign $campaign, ?string $createdBy): PushCampaign
    {
        $test = $campaign->replicate(['status', 'scheduled_at', 'target_count', 'success_count', 'failure_count', 'invalid_token_count', 'open_count', 'started_at', 'finished_at']);
        $test->forceFill([
            'is_test' => true,
            'parent_campaign_id' => $campaign->id,
            'target_type' => CampaignTarget::TestDevices,
            'segment_id' => null,
            'target_user_ids' => null,
            'notification_category_id' => null,
            'status' => CampaignStatus::Draft,
            'created_by' => $createdBy,
        ])->save();

        $this->queue($test);

        return $test;
    }

    /**
     * 対象端末の配信ログを作り、チャンクごとの送信ジョブを投入する（PrepareCampaignJob から呼ぶ）。
     */
    public function prepare(PushCampaign $campaign): void
    {
        $app = App::withoutGlobalScopes()->findOrFail($campaign->app_id);

        $started = PushCampaign::withoutGlobalScopes()->whereKey($campaign->id)
            ->where('status', CampaignStatus::Queued->value)
            ->update(['status' => CampaignStatus::Sending->value, 'started_at' => now()]);
        if ($started === 0) {
            return; // 既に処理中・処理済み（ジョブの重複実行）
        }

        $select = $this->targets->query($app, $campaign)
            ->selectRaw('?, d.id, ?, NOW(), NOW()', [$campaign->id, DeliveryStatus::Pending->value]);

        DB::insert(
            'INSERT IGNORE INTO push_deliveries (push_campaign_id, device_id, status, created_at, updated_at) '.$select->toSql(),
            $select->getBindings(),
        );

        $total = DB::table('push_deliveries')->where('push_campaign_id', $campaign->id)->count();
        PushCampaign::withoutGlobalScopes()->whereKey($campaign->id)->update(['target_count' => $total]);

        $chunkSize = max(1, (int) config('eevee.push.chunk_size', 500));
        DB::table('push_deliveries')
            ->where('push_campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->select('id')
            ->chunkById($chunkSize, function ($rows) use ($campaign) {
                SendCampaignChunkJob::dispatch($campaign->id, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
            });

        self::completeIfDone($campaign->id);
    }

    /**
     * 未送信の配信ログがなくなったらキャンペーンを完了にする。
     */
    public static function completeIfDone(int $campaignId): void
    {
        $remaining = DB::table('push_deliveries')
            ->where('push_campaign_id', $campaignId)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Sending->value])
            ->exists();

        if (! $remaining) {
            PushCampaign::withoutGlobalScopes()->whereKey($campaignId)
                ->where('status', CampaignStatus::Sending->value)
                ->update(['status' => CampaignStatus::Sent->value, 'finished_at' => now()]);
        }
    }
}
