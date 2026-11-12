<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, string>|null $data
 * @property list<int|string>|null $target_user_ids
 */
class PushCampaign extends Model
{
    use BelongsToApp;

    protected $fillable = [
        'app_id', 'title', 'body', 'image_url', 'data', 'notification_category_id', 'target_type',
        'segment_id', 'target_user_ids', 'scheduled_at', 'status', 'is_test', 'parent_campaign_id',
        'source_type', 'source_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'target_user_ids' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => CampaignStatus::class,
            'target_type' => CampaignTarget::class,
            'is_test' => 'boolean',
        ];
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /** @return BelongsTo<NotificationCategory, $this> */
    public function notificationCategory(): BelongsTo
    {
        return $this->belongsTo(NotificationCategory::class);
    }

    /** @return HasMany<PushDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(PushDelivery::class);
    }

    /** @return BelongsTo<PushCampaign, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PushCampaign::class, 'parent_campaign_id');
    }

    public function openRate(): ?float
    {
        return $this->success_count > 0 ? round($this->open_count / $this->success_count * 100, 1) : null;
    }
}
