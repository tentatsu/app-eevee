<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;

/**
 * 同時視聴数の上限に達した記録（拒否 / 最古セッションの停止）。
 */
class PlaybackLimitEvent extends Model
{
    use BelongsToApp;

    public const UPDATED_AT = null;

    protected $fillable = ['app_id', 'video_id', 'app_user_id', 'viewer_key', 'policy', 'kicked_session_id'];
}
