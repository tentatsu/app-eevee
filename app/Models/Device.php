<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PushPermission;
use App\Models\Concerns\BelongsToApp;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 端末（FCMトークン）。ログインしていない端末も登録できる（app_user_id は nullable）。
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToApp, HasFactory;

    protected $fillable = [
        'app_id', 'app_user_id', 'installation_id', 'fcm_token', 'platform', 'app_version',
        'os_version', 'locale', 'timezone', 'push_permission', 'is_test_device', 'test_label',
        'last_active_at', 'invalidated_at', 'invalidated_reason',
    ];

    protected $hidden = ['fcm_token'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'push_permission' => PushPermission::class,
            'is_test_device' => 'boolean',
            'last_active_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Device $device) {
            if ($device->isDirty('app_version')) {
                $device->app_version_num = $device->app_version !== null ? self::versionToNumber($device->app_version) : null;
            }
        });
    }

    /**
     * "1.2.3" のようなバージョン文字列を比較可能な整数にする（各要素は 0〜999999）。
     */
    public static function versionToNumber(string $version): int
    {
        preg_match_all('/\d+/', $version, $m);
        $parts = array_map('intval', array_slice($m[0], 0, 3));
        $parts = array_pad($parts, 3, 0);
        [$major, $minor, $patch] = array_map(fn (int $p) => min($p, 999_999), $parts);

        return $major * 1_000_000_000_000 + $minor * 1_000_000 + $patch;
    }

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    /**
     * プッシュ送信が可能な端末（トークンあり、無効化されていない、通知が拒否されていない）。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNull('invalidated_at')
            ->whereNotNull('fcm_token')
            ->where('push_permission', '!=', PushPermission::Denied->value);
    }

    public function isPushable(): bool
    {
        return $this->invalidated_at === null && $this->fcm_token !== null && $this->push_permission !== PushPermission::Denied;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'installation_id' => $this->installation_id,
            'platform' => $this->platform->value,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'push_permission' => $this->push_permission->value,
            'app_user_id' => $this->app_user_id,
            'has_fcm_token' => $this->fcm_token !== null,
            'invalidated' => $this->invalidated_at !== null,
            'last_active_at' => $this->last_active_at?->toIso8601ZuluString(),
        ];
    }
}
