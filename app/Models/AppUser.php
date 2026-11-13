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
        'custom_attributes', 'notification_preferences', 'last_login_at', 'stripe_customer_id',
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

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @var list<string>|null 1リクエストの間だけ覚えておく（一覧で動画ごとに問い合わせない） */
    private ?array $entitlementCache = null;

    /** キャッシュを作ったリクエスト（別のリクエストでは使わない。テストや長寿命プロセスでモデルが使い回されても古い値を返さない） */
    private ?int $entitlementCacheRequest = null;

    public function hasEntitlement(string $entitlement): bool
    {
        return in_array($entitlement, $this->entitlements(), true);
    }

    /**
     * 今有効な権利（例: ["premium"]）。
     *
     * @return list<string>
     */
    public function entitlements(bool $fresh = false): array
    {
        $requestId = spl_object_id(request());
        if ($this->entitlementCache === null || $fresh || $this->entitlementCacheRequest !== $requestId) {
            /** @var list<string> $list */
            $list = Subscription::withoutGlobalScopes()->where('app_user_id', $this->id)->entitled()->distinct()->pluck('entitlement')->all();
            $this->entitlementCache = $list;
            $this->entitlementCacheRequest = $requestId;
        }

        return $this->entitlementCache;
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
