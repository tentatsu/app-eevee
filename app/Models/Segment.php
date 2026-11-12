<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Model;

/**
 * ターゲティング用のセグメント。conditions は JSON で保存し、実行時に SQL へ変換する。
 *
 * @property array<string, mixed> $conditions
 */
class Segment extends Model
{
    use BelongsToApp;

    protected $fillable = ['app_id', 'name', 'description', 'conditions'];

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }
}
