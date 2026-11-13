<?php

namespace App\Services\Billing;

use App\Models\ProviderAccount;

/**
 * Oneframe（プラットフォーム）の Stripe アカウントの設定。システム管理者が登録する（provider_accounts の provider=stripe の1件）。
 *
 * - secret_key        : シークレットキー（sk_live_… / sk_test_…）。制限付きキー（rk_…）でもよい
 * - webhook_secret    : Webhook の署名シークレット（whsec_…）
 * - fee_percent       : プラットフォームの手数料（売上に対する %）。組織ごとに上書きできる
 * - country           : 連結アカウントを作るときの国（既定 JP）
 */
class StripePlatform
{
    public const PROVIDER = 'stripe';

    public function account(): ?ProviderAccount
    {
        return ProviderAccount::query()->where('provider', self::PROVIDER)->first();
    }

    public function accountOrNew(): ProviderAccount
    {
        return $this->account() ?? new ProviderAccount(['provider' => self::PROVIDER, 'name' => 'Stripe']);
    }

    public function secretKey(): ?string
    {
        return $this->account()?->setting('secret_key');
    }

    public function webhookSecret(): ?string
    {
        return $this->account()?->setting('webhook_secret');
    }

    public function feePercent(): float
    {
        $value = $this->account()?->setting('fee_percent');

        return $value !== null && is_numeric($value) ? (float) $value : (float) config('eevee.billing.default_fee_percent', 10);
    }

    public function country(): string
    {
        return $this->account()?->setting('country') ?? 'JP';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== null;
    }

    public function isTestMode(): bool
    {
        return str_contains((string) $this->secretKey(), '_test_');
    }
}
