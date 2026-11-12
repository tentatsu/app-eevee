<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Database\Factories\AppUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * アプリのエンドユーザー。
 *
 * @property array<string, mixed>|null $custom_attributes
 * @property array<string, bool>|null $notification_preferences
 */
class AppUser extends Authenticatable
{
    /** @use HasFactory<AppUserFactory> */
    use BelongsToApp, HasApiTokens, HasFactory;

    protected $fillable = [
        'app_id', 'email', 'password', 'external_user_id', 'name',
        'custom_attributes', 'notification_preferences', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'custom_attributes' => 'array',
            'notification_preferences' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function isCategoryEnabled(NotificationCategory $category): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return array_key_exists($category->key, $prefs) ? (bool) $prefs[$category->key] : $category->default_enabled;
    }

    /**
     * API で返すユーザー情報。
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'external_user_id' => $this->external_user_id,
            'name' => $this->name,
            'attributes' => (object) ($this->custom_attributes ?? []),
            'notification_preferences' => (object) ($this->notification_preferences ?? []),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
