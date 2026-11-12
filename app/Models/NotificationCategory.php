<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;

/**
 * 通知カテゴリ（例: お知らせ、キャンペーン）。ユーザーはカテゴリごとに受信のオン・オフを切り替えられる。
 */
class NotificationCategory extends Model
{
    use BelongsToApp;

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    protected $fillable = ['app_id', 'key', 'label', 'default_enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['default_enabled' => 'boolean'];
    }
}
