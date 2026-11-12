<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 端末ごとの送信結果。(push_campaign_id, device_id) のユニーク制約で二重送信を防ぐ。
 */
class PushDelivery extends Model
{
    protected $fillable = [
        'push_campaign_id', 'device_id', 'status', 'attempts', 'error_code', 'error_message',
        'message_id', 'sent_at', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PushCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PushCampaign::class, 'push_campaign_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
