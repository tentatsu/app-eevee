<?php

namespace App\Services\Billing;

use App\Models\App;
use App\Models\AppUser;
use App\Models\BillingPlan;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Stripe Connect（Express）での課金。
 *
 * - 組織（顧客）ごとに連結アカウントを作り、Stripe のオンボーディング画面で本人確認・口座登録をしてもらう
 * - プラン（Product / Price）・顧客（Customer）・購読（Subscription）はプラットフォーム側に作り、
 *   売上は transfer_data[destination] で組織の連結アカウントに送る（application_fee_percent を差し引く）
 * - 購読の状態は Webhook で受け取り、その都度 Stripe から最新の購読を取得して保存する（イベントの順序に依存しない）
 */
class BillingService
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripePlatform $platform,
    ) {}

    /**
     * 連結アカウントを作り（未作成なら）、オンボーディングの URL を返す。
     */
    public function onboardingUrl(Organization $org, string $returnUrl, string $refreshUrl): string
    {
        if ($org->stripe_account_id === null) {
            $account = $this->stripe->post('/accounts', [
                'type' => 'express',
                'country' => $this->platform->country(),
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_profile' => ['name' => $org->name],
                'metadata' => ['organization_id' => $org->id, 'organization_slug' => $org->slug],
            ], 'eevee-account-org-'.$org->id);
            $org->forceFill(['stripe_account_id' => (string) $account['id']])->save();
        }

        $link = $this->stripe->post('/account_links', [
            'account' => $org->stripe_account_id,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ]);

        return (string) $link['url'];
    }

    /**
     * 連結アカウントの状態（決済を受け付けられるか）を Stripe から取得して保存する。
     */
    public function refreshAccount(Organization $org): void
    {
        if ($org->stripe_account_id === null) {
            return;
        }
        $account = $this->stripe->get('/accounts/'.$org->stripe_account_id);
        $this->applyAccount($org, $account);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public function applyAccount(Organization $org, array $account): void
    {
        $org->forceFill([
            'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
        ])->save();
    }

    /**
     * Stripe の Express ダッシュボード（売上・入金の確認）へのログイン URL。
     */
    public function dashboardUrl(Organization $org): string
    {
        $link = $this->stripe->post('/accounts/'.$org->stripe_account_id.'/login_links');

        return (string) $link['url'];
    }

    /**
     * プランの Product と Price を作る。金額・通貨・期間は作成後に変えられない（変えるときは新しいプランを作る）。
     */
    public function createPlan(App $app, BillingPlan $plan): void
    {
        $product = $this->stripe->post('/products', [
            'name' => $plan->name,
            'description' => $plan->description,
            'metadata' => ['app_id' => $app->id, 'app_key' => $app->app_key, 'entitlement' => $plan->entitlement],
        ], 'eevee-product-plan-'.$plan->id);
        $price = $this->stripe->post('/prices', [
            'product' => $product['id'],
            'currency' => $plan->currency,
            'unit_amount' => $plan->amount,
            'recurring' => ['interval' => $plan->interval],
            'metadata' => ['billing_plan_id' => $plan->id],
        ], 'eevee-price-plan-'.$plan->id);

        $plan->forceFill(['stripe_product_id' => (string) $product['id'], 'stripe_price_id' => (string) $price['id']])->save();
    }

    public function updatePlan(BillingPlan $plan): void
    {
        if ($plan->stripe_product_id === null) {
            return;
        }
        $this->stripe->post('/products/'.$plan->stripe_product_id, [
            'name' => $plan->name,
            'description' => $plan->description ?? '',
            'active' => $plan->is_active,
        ]);
    }

    /**
     * 購読の購入画面（Stripe Checkout）の URL。
     */
    public function checkoutUrl(App $app, AppUser $user, BillingPlan $plan, string $successUrl, string $cancelUrl): string
    {
        $org = $app->organization;
        if (! $org->stripe_charges_enabled || $org->stripe_account_id === null) {
            throw new BillingException('The organization cannot accept payments yet.');
        }
        if ($plan->stripe_price_id === null || ! $plan->is_active) {
            throw new BillingException('The plan is not available.');
        }

        $metadata = ['app_id' => $app->id, 'app_user_id' => $user->id, 'billing_plan_id' => $plan->id, 'entitlement' => $plan->entitlement];
        $session = $this->stripe->post('/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $this->customerId($app, $user),
            'client_reference_id' => (string) $user->id,
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => $metadata,
                'application_fee_percent' => $this->feePercent($org),
                'transfer_data' => ['destination' => $org->stripe_account_id],
                'on_behalf_of' => $org->stripe_account_id,
            ],
        ]);

        return (string) $session['url'];
    }

    /**
     * 購読の管理画面（Stripe のカスタマーポータル: 解約・カード変更・領収書）の URL。
     */
    public function portalUrl(App $app, AppUser $user, string $returnUrl): string
    {
        if ($user->stripe_customer_id === null) {
            throw new BillingException('The user has no subscriptions.');
        }
        $session = $this->stripe->post('/billing_portal/sessions', [
            'customer' => $user->stripe_customer_id,
            'return_url' => $returnUrl,
        ]);

        return (string) $session['url'];
    }

    /**
     * Stripe から購読を取得して保存する（Webhook から呼ぶ）。このシステムで作った購読でなければ null。
     */
    public function syncSubscription(string $subscriptionId): ?Subscription
    {
        $data = $this->stripe->get('/subscriptions/'.$subscriptionId);
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $appId = (int) ($meta['app_id'] ?? 0);
        $userId = (int) ($meta['app_user_id'] ?? 0);
        if ($appId === 0 || $userId === 0) {
            return null;
        }
        $user = AppUser::withoutGlobalScopes()->where('app_id', $appId)->find($userId);
        if ($user === null) {
            return null;
        }
        $planId = (int) ($meta['billing_plan_id'] ?? 0);
        $plan = $planId > 0 ? BillingPlan::withoutGlobalScopes()->where('app_id', $appId)->find($planId) : null;

        // API のバージョンにより、期間の終わりは購読そのもの、または購読のアイテムにある
        $periodEnd = $data['current_period_end'] ?? ($data['items']['data'][0]['current_period_end'] ?? null);

        /** @var Subscription */
        return Subscription::withoutGlobalScopes()->updateOrCreate(
            ['provider_subscription_id' => $subscriptionId],
            [
                'app_id' => $appId,
                'app_user_id' => $user->id,
                'billing_plan_id' => $plan?->id,
                'provider' => 'stripe',
                'status' => (string) ($data['status'] ?? 'incomplete'),
                'entitlement' => (string) ($meta['entitlement'] ?? $plan->entitlement ?? 'premium'),
                'current_period_end' => is_numeric($periodEnd) ? CarbonImmutable::createFromTimestamp((int) $periodEnd) : null,
                'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
                'canceled_at' => is_numeric($data['canceled_at'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $data['canceled_at']) : null,
            ],
        );
    }

    public function feePercent(Organization $org): float
    {
        return $org->application_fee_percent ?? $this->platform->feePercent();
    }

    private function customerId(App $app, AppUser $user): string
    {
        if ($user->stripe_customer_id !== null) {
            return $user->stripe_customer_id;
        }
        $customer = $this->stripe->post('/customers', [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['app_id' => $app->id, 'app_key' => $app->app_key, 'app_user_id' => $user->id],
        ], 'eevee-customer-user-'.$user->id);
        $user->forceFill(['stripe_customer_id' => (string) $customer['id']])->save();

        return $user->stripe_customer_id;
    }
}
