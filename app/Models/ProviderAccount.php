<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Oneframe が用意し、複数のアプリで共用する外部サービスのアカウント（システム管理者だけが管理する）。
 *
 * @property array<string, mixed>|null $settings
 */
class ProviderAccount extends Model
{
    protected $fillable = ['provider', 'name', 'settings'];

    protected $hidden = ['settings', 'webhook_token'];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProviderAccount $account) {
            $account->webhook_token ??= Str::random(40);
        });
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /** @return HasMany<App, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class, 'video_provider_account_id');
    }

    /**
     * 動画プロバイダの API を呼ぶための、このアカウントの設定を持った（保存しない）App。
     */
    public function asApp(): App
    {
        $app = new App;
        $app->video_provider = $this->provider;
        $app->video_provider_settings = $this->settings ?? [];

        return $app;
    }
}
