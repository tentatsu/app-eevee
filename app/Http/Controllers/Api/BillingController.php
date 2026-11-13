<?php

namespace App\Http\Controllers\Api;

use App\Models\BillingPlan;
use App\Models\Subscription;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Support\Api\ApiException;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags 課金
 */
class BillingController extends ApiController
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * 購入できるプランの一覧
     *
     * 組織が Stripe での決済を受け付けられない間は空になる。ログインしていれば、各プランを購読中か（`subscribed`）も返す。
     *
     * @unauthenticated
     */
    public function plans(Request $request): JsonResponse
    {
        $app = $this->currentApp();
        $user = $this->optionalAppUser($request);
        if (! $app->organization->stripe_charges_enabled) {
            return ApiResponse::ok([]);
        }
        $owned = $user?->entitlements() ?? [];
        $plans = BillingPlan::query()->where('is_active', true)->whereNotNull('stripe_price_id')->orderBy('sort_order')->orderBy('amount')->get()
            ->map(fn (BillingPlan $p) => $p->toApiArray() + ['subscribed' => in_array($p->entitlement, $owned, true)])->values();

        return ApiResponse::ok($plans);
    }

    /**
     * 自分の権利と購読
     *
     * `entitlements` は今有効な権利（例: `["premium"]`）。動画の `required_entitlement` と照らし合わせて使う。
     */
    public function entitlements(Request $request): JsonResponse
    {
        $user = $this->appUser($request);
        $subscriptions = Subscription::query()->where('app_user_id', $user->id)->latest('id')->limit(20)->get();

        return ApiResponse::ok([
            'entitlements' => $user->entitlements(),
            'subscriptions' => $subscriptions->map(fn (Subscription $s) => $s->toApiArray())->values(),
        ]);
    }

    /**
     * 購入画面（Stripe Checkout）の URL を作る
     *
     * 返った `url` をブラウザ（Custom Tabs / SFSafariViewController）で開く。購入が終わると `success_url`（省略時は完了ページ）に戻る。
     * 権利の反映は Webhook 経由なので、戻ったら `GET /me/entitlements` で確認する（数秒かかることがある）。
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'success_url' => ['nullable', 'url:https,http', 'max:2000'],
            'cancel_url' => ['nullable', 'url:https,http', 'max:2000'],
        ]);
        $app = $this->currentApp();
        $user = $this->appUser($request);
        $plan = BillingPlan::query()->where('is_active', true)->find($data['plan_id']);
        if ($plan === null) {
            throw new ApiException('plan_not_found', 'The plan was not found.', 404);
        }
        if ($user->hasEntitlement($plan->entitlement)) {
            throw new ApiException('already_subscribed', 'You already have this entitlement.', 409);
        }

        try {
            $url = $this->billing->checkoutUrl(
                $app,
                $user,
                $plan,
                $data['success_url'] ?? route('billing.complete', ['app_key' => $app->app_key]),
                $data['cancel_url'] ?? route('billing.canceled', ['app_key' => $app->app_key]),
            );
        } catch (BillingException) {
            throw new ApiException('billing_unavailable', 'Payments are not available for this app right now.', 503);
        }

        return ApiResponse::ok(['url' => $url], 201);
    }

    /**
     * 購読の管理画面（解約・カードの変更・領収書）の URL を作る
     */
    public function portal(Request $request): JsonResponse
    {
        $data = $request->validate(['return_url' => ['nullable', 'url:https,http', 'max:2000']]);
        $app = $this->currentApp();
        $user = $this->appUser($request);
        if ($user->stripe_customer_id === null) {
            throw new ApiException('no_subscription', 'You have no subscriptions.', 404);
        }

        try {
            $url = $this->billing->portalUrl($app, $user, $data['return_url'] ?? route('billing.complete', ['app_key' => $app->app_key]));
        } catch (BillingException) {
            throw new ApiException('billing_unavailable', 'Payments are not available for this app right now.', 503);
        }

        return ApiResponse::ok(['url' => $url], 201);
    }
}
