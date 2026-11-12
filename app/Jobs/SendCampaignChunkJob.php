<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\App;
use App\Models\PushCampaign;
use App\Services\Push\CampaignDispatcher;
use App\Services\Push\Contracts\PushSender;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 最大 500 件の配信ログ（端末）にまとめて送信する。
 *
 * - 冪等性: 送信前に「未送信（pending）」の行だけを行ロックして「送信中（sending）」にし、その行だけを送る。
 *   ジョブが重複して実行されても、同じ端末に二度送らない。
 * - 一時的な失敗（FCM の UNAVAILABLE など）は行を pending に戻して例外を投げ、指数バックオフで再試行する。
 * - 無効トークン（UNREGISTERED / INVALID_ARGUMENT）は端末の invalidated_at を記録し、以後の送信対象から外す。
 * - 送信レートはアプリごとに制御する（RateLimited ミドルウェア。待たされても再試行回数は消費しない）。
 */
class SendCampaignChunkJob implements ShouldQueue
{
    use Queueable;

    /** 例外による再試行の上限（レート制限による待機は数えない） */
    public int $maxExceptions;

    public int $timeout = 120;

    /** @var array<int, int> 今回送る行の試行回数（送信前の値 + 1） */
    private array $rowAttempts = [];

    /**
     * @param  list<int>  $deliveryIds
     */
    public function __construct(public int $campaignId, public array $deliveryIds)
    {
        $this->onQueue((string) config('eevee.push.queue', 'push'));
        $this->maxExceptions = (int) config('eevee.push.max_attempts', 5);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('eevee.push.backoff', [10, 30, 90, 270]);

        return $backoff;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('push-chunks')];
    }

    public function appId(): ?int
    {
        return PushCampaign::withoutGlobalScopes()->whereKey($this->campaignId)->value('app_id');
    }

    public function handle(PushSender $sender): void
    {
        $campaign = PushCampaign::withoutGlobalScopes()->find($this->campaignId);
        if ($campaign === null) {
            return;
        }
        $app = App::withoutGlobalScopes()->findOrFail($campaign->app_id);

        $claimed = $this->claim();
        if ($claimed === []) {
            CampaignDispatcher::completeIfDone($campaign->id);

            return;
        }

        $data = array_map('strval', $campaign->data ?? []);
        $data['eevee_campaign_id'] = (string) $campaign->id;

        $message = new PushMessage($campaign->title, $campaign->body, $campaign->image_url, $data, $claimed);

        try {
            $results = $sender->send($app, $message);
        } catch (Throwable $e) {
            $results = array_map(fn ($id) => new PushResult((int) $id, PushResult::RETRYABLE, errorCode: class_basename($e), errorMessage: mb_substr($e->getMessage(), 0, 500)), array_keys($claimed));
        }

        $retryable = $this->record($campaign, $results, array_keys($claimed));

        CampaignDispatcher::completeIfDone($campaign->id);

        if ($retryable > 0) {
            throw new RuntimeException("{$retryable} deliveries failed temporarily and will be retried.");
        }
    }

    /**
     * 未送信の行を送信中にして、delivery_id => トークン を返す。
     *
     * @return array<int, string>
     */
    private function claim(): array
    {
        return DB::transaction(function () {
            $rows = DB::table('push_deliveries as pd')
                ->join('devices as d', 'd.id', '=', 'pd.device_id')
                ->whereIn('pd.id', $this->deliveryIds)
                ->where('pd.push_campaign_id', $this->campaignId)
                ->where('pd.status', DeliveryStatus::Pending->value)
                ->lockForUpdate()
                ->get(['pd.id', 'pd.attempts', 'd.fcm_token', 'd.invalidated_at']);

            $claimed = [];
            $skipped = [];
            foreach ($rows as $row) {
                // 抽出後に無効化・トークン削除された端末には送らない
                if ($row->fcm_token === null || $row->invalidated_at !== null) {
                    $skipped[] = (int) $row->id;
                } else {
                    $claimed[(int) $row->id] = (string) $row->fcm_token;
                    $this->rowAttempts[(int) $row->id] = (int) $row->attempts + 1;
                }
            }

            if ($claimed !== []) {
                DB::table('push_deliveries')->whereIn('id', array_keys($claimed))->update([
                    'status' => DeliveryStatus::Sending->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);
            }
            if ($skipped !== []) {
                DB::table('push_deliveries')->whereIn('id', $skipped)->update([
                    'status' => DeliveryStatus::InvalidToken->value,
                    'error_code' => 'DEVICE_INVALIDATED',
                    'updated_at' => now(),
                ]);
                PushCampaign::withoutGlobalScopes()->whereKey($this->campaignId)->increment('invalid_token_count', count($skipped));
            }

            return $claimed;
        });
    }

    /**
     * 結果を保存し、再試行が必要な件数を返す。
     *
     * @param  list<PushResult>  $results
     * @param  list<int>  $claimedIds
     */
    private function record(PushCampaign $campaign, array $results, array $claimedIds): int
    {
        $max = $this->maxExceptions;
        $byStatus = [PushResult::SENT => 0, PushResult::INVALID_TOKEN => 0, PushResult::FAILED => 0];
        $retryable = 0;
        $seen = [];

        DB::transaction(function () use ($results, $max, &$byStatus, &$retryable, &$seen) {
            $now = now();
            foreach ($results as $r) {
                $seen[$r->deliveryId] = true;
                // 行ごとの試行回数が上限に達したら、一時的な失敗でも失敗として確定する
                $isLastAttempt = ($this->rowAttempts[$r->deliveryId] ?? 1) >= $max;
                $status = $r->status === PushResult::RETRYABLE && $isLastAttempt ? PushResult::FAILED : $r->status;
                $update = match ($status) {
                    PushResult::SENT => ['status' => DeliveryStatus::Sent->value, 'message_id' => $r->messageId, 'sent_at' => $now, 'error_code' => null, 'error_message' => null],
                    PushResult::INVALID_TOKEN => ['status' => DeliveryStatus::InvalidToken->value, 'error_code' => $r->errorCode, 'error_message' => $r->errorMessage],
                    PushResult::RETRYABLE => ['status' => DeliveryStatus::Pending->value, 'error_code' => $r->errorCode, 'error_message' => $r->errorMessage],
                    default => ['status' => DeliveryStatus::Failed->value, 'error_code' => $r->errorCode, 'error_message' => $r->errorMessage],
                };
                DB::table('push_deliveries')->where('id', $r->deliveryId)->where('status', DeliveryStatus::Sending->value)->update($update + ['updated_at' => $now]);

                if ($status === PushResult::RETRYABLE) {
                    $retryable++;
                } else {
                    $byStatus[$status]++;
                }
                if ($status === PushResult::INVALID_TOKEN) {
                    DB::table('devices')
                        ->whereIn('id', DB::table('push_deliveries')->where('id', $r->deliveryId)->select('device_id'))
                        ->whereNull('invalidated_at')
                        ->update(['invalidated_at' => $now, 'invalidated_reason' => $r->errorCode ?? 'INVALID_TOKEN', 'updated_at' => $now]);
                }
            }
        });

        // 送信クライアントが結果を返さなかった宛先は失敗扱い
        $missing = array_values(array_filter($claimedIds, fn ($id) => ! isset($seen[$id])));
        if ($missing !== []) {
            DB::table('push_deliveries')->whereIn('id', $missing)->where('status', DeliveryStatus::Sending->value)
                ->update(['status' => DeliveryStatus::Failed->value, 'error_code' => 'NO_RESULT', 'updated_at' => now()]);
            $byStatus[PushResult::FAILED] += count($missing);
        }

        $campaignQuery = fn () => PushCampaign::withoutGlobalScopes()->whereKey($campaign->id);
        if ($byStatus[PushResult::SENT] > 0) {
            $campaignQuery()->increment('success_count', $byStatus[PushResult::SENT]);
        }
        if ($byStatus[PushResult::FAILED] > 0) {
            $campaignQuery()->increment('failure_count', $byStatus[PushResult::FAILED]);
        }
        if ($byStatus[PushResult::INVALID_TOKEN] > 0) {
            $campaignQuery()->increment('invalid_token_count', $byStatus[PushResult::INVALID_TOKEN]);
        }

        return $retryable;
    }

    /**
     * 再試行の上限に達した: 残っている行を失敗にする。
     */
    public function failed(?Throwable $e): void
    {
        $count = DB::table('push_deliveries')
            ->whereIn('id', $this->deliveryIds)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Sending->value])
            ->update(['status' => DeliveryStatus::Failed->value, 'error_code' => 'MAX_ATTEMPTS', 'updated_at' => now()]);
        if ($count > 0) {
            PushCampaign::withoutGlobalScopes()->whereKey($this->campaignId)->increment('failure_count', $count);
        }
        CampaignDispatcher::completeIfDone($this->campaignId);
    }
}
