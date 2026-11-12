<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 視聴セッションの統計用レコード。同時視聴数の判定は Redis 側で行う。
 */
class PlaybackSession extends Model
{
    use BelongsToApp, HasUuids;

    protected $fillable = [
        'id', 'app_id', 'video_id', 'app_user_id', 'viewer_key', 'started_at', 'last_heartbeat_at',
        'ended_at', 'end_reason', 'watched_seconds', 'token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'watched_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
