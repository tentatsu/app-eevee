<?php

namespace App\Models;

use App\Enums\AuthMode;
use App\Enums\ConcurrencyPolicy;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 組織が持つアプリ。アプリ向けAPI・プッシュ・動画などはすべてアプリ単位で分離される。
 *
 * @property array<string, mixed>|null $firebase_credentials
 * @property array<string, mixed>|null $video_provider_settings
 */
class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'auth_mode', 'timezone',
        'firebase_credentials', 'video_provider', 'video_provider_settings',
        'max_concurrent_streams', 'concurrency_policy', 'playback_token_grace_minutes',
        'push_rate_per_second', 'delivery_log_retention_days', 'external_token_ttl_minutes',
    ];

    /** DB の既定値と同じ（作成直後のモデルでも値を持たせる） */
    protected $attributes = [
        'auth_mode' => 'builtin',
        'video_provider' => 'cloudflare_stream',
        'max_concurrent_streams' => 2,
        'concurrency_policy' => 'reject',
        'playback_token_grace_minutes' => 10,
        'push_rate_per_second' => 20,
        'delivery_log_retention_days' => 90,
        'external_token_ttl_minutes' => 60,
    ];

    /** 秘密情報はシリアライズ（JSON/ログ）に含めない */
    protected $hidden = ['app_secret_hash', 'firebase_credentials', 'video_provider_settings'];

    protected function casts(): array
    {
        return [
            'auth_mode' => AuthMode::class,
            'concurrency_policy' => ConcurrencyPolicy::class,
            'firebase_credentials' => 'encrypted:array',
            'video_provider_settings' => 'encrypted:array',
            'max_concurrent_streams' => 'integer',
            'playback_token_grace_minutes' => 'integer',
            'push_rate_per_second' => 'integer',
            'delivery_log_retention_days' => 'integer',
            'external_token_ttl_minutes' => 'integer',
            'app_secret_rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (App $app) {
            if (empty($app->app_key)) {
                $app->app_key = self::generateAppKey();
            }
            if (empty($app->app_secret_hash)) {
                // 作成直後は誰も知らない秘密値で埋めておき、管理画面で再発行して取得する
                $app->app_secret_hash = hash('sha256', Str::random(64));
            }
        });
    }

    public static function generateAppKey(): string
    {
        do {
            $key = 'ak_'.Str::lower(Str::random(24));
        } while (self::withoutGlobalScopes()->where('app_key', $key)->exists());

        return $key;
    }

    /**
     * app_secret を再発行し、平文を一度だけ返す（DBにはSHA-256ハッシュのみを保存）。
     */
    public function regenerateSecret(): string
    {
        $secret = 'as_'.Str::random(48);
        $this->forceFill([
            'app_secret_hash' => hash('sha256', $secret),
            'app_secret_rotated_at' => now(),
        ])->save();

        return $secret;
    }

    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->app_secret_hash, hash('sha256', $secret));
    }

    public function displayTimezone(): string
    {
        return $this->timezone
            ?: $this->organization()->withoutGlobalScopes()->value('timezone')
            ?: (string) config('eevee.default_timezone');
    }

    public function hasFirebaseCredentials(): bool
    {
        return ! empty($this->firebase_credentials['project_id']) && ! empty($this->firebase_credentials['private_key']);
    }

    public function videoSetting(string $key): ?string
    {
        $value = $this->video_provider_settings[$key] ?? null;

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /** @return HasMany<AppUser, $this> */
    public function appUsers(): HasMany
    {
        return $this->hasMany(AppUser::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<AttributeDefinition, $this> */
    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(AttributeDefinition::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<NotificationCategory, $this> */
    public function notificationCategories(): HasMany
    {
        return $this->hasMany(NotificationCategory::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Segment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /** @return HasMany<PushCampaign, $this> */
    public function pushCampaigns(): HasMany
    {
        return $this->hasMany(PushCampaign::class);
    }

    /** @return HasMany<Notice, $this> */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    /** @return HasMany<Video, $this> */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
