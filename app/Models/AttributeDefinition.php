<?php

namespace App\Models;

use App\Enums\AttributeType;
use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;

/**
 * アプリユーザーの属性の定義。セグメント条件や属性更新APIのバリデーションに使う。
 */
class AttributeDefinition extends Model
{
    use BelongsToApp;

    /** 属性キーの書式 */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    protected $fillable = ['app_id', 'key', 'type', 'label', 'app_writable', 'sort_order'];

    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'app_writable' => 'boolean',
        ];
    }
}
