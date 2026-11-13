<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Enums\VideoVisibility;
use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string>|null $tags
 */
class Video extends Model
{
    use BelongsToApp;

    protected $fillable = [
        'app_id', 'provider', 'provider_video_id', 'title', 'description', 'thumbnail_url',
        'duration_seconds', 'status', 'status_message', 'visibility', 'required_entitlement', 'publish_start_at',
        'publish_end_at', 'sort_order', 'category', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'visibility' => VideoVisibility::class,
            'publish_start_at' => 'datetime',
            'publish_end_at' => 'datetime',
            'tags' => 'array',
            'duration_seconds' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<PlaybackSession, $this> */
    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    /** @return HasMany<PlaybackLimitEvent, $this> */
    public function limitEvents(): HasMany
    {
        return $this->hasMany(PlaybackLimitEvent::class);
    }

    /**
     * 再生可能かつ公開期間内の動画。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeListed(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', VideoStatus::Ready->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_start_at')->orWhere('publish_start_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('publish_end_at')->orWhere('publish_end_at', '>', $now));
    }

    public function isWithinPublishWindow(): bool
    {
        $now = now();

        return ($this->publish_start_at === null || $this->publish_start_at->lte($now))
            && ($this->publish_end_at === null || $this->publish_end_at->gt($now));
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_url,
            'duration_seconds' => $this->duration_seconds,
            'visibility' => $this->visibility->value,
            'required_entitlement' => $this->required_entitlement,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'sort_order' => $this->sort_order,
            'publish_start_at' => $this->publish_start_at?->toIso8601ZuluString(),
            'publish_end_at' => $this->publish_end_at?->toIso8601ZuluString(),
        ];
    }
}
