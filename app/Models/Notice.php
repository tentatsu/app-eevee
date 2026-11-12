<?php

namespace App\Models;

use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Notice extends Model
{
    use BelongsToApp;

    protected $fillable = [
        'app_id', 'title', 'body', 'thumbnail_url', 'publish_start_at', 'publish_end_at',
        'target_type', 'segment_id', 'is_pinned', 'status',
    ];

    protected function casts(): array
    {
        return [
            'publish_start_at' => 'datetime',
            'publish_end_at' => 'datetime',
            'is_pinned' => 'boolean',
            'status' => NoticeStatus::class,
            'target_type' => NoticeTarget::class,
        ];
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /** @return HasMany<NoticeRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(NoticeRead::class);
    }

    /**
     * 公開中（状態が公開、かつ公開期間内）。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', NoticeStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_start_at')->orWhere('publish_start_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('publish_end_at')->orWhere('publish_end_at', '>', $now));
    }

    public function isVisible(): bool
    {
        $now = now();

        return $this->status === NoticeStatus::Published
            && ($this->publish_start_at === null || $this->publish_start_at->lte($now))
            && ($this->publish_end_at === null || $this->publish_end_at->gt($now));
    }

    public function bodyHtml(): string
    {
        return (string) Str::markdown($this->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
