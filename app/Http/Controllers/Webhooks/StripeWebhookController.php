<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeClient;
use App\Services\Billing\StripePlatform;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe の Webhook（プラットフォームのアカウントに1つ）。
 *
 * 購読のイベントは、ペイロードをそのまま信じず、Stripe から最新の購読を取得して保存する（順序の入れ替わり・再送に強い）。
 * account.updated は連結アカウントのイベント（Connect 用の Webhook を作った場合だけ届く）。
 */
class StripeWebhookController extends Controller
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
    ];

    public function __invoke(Request $request, StripePlatform $platform, BillingService $billing): JsonResponse
    {
        $secret = $platform->webhookSecret();
        $payload = $request->getContent();
        if ($secret === null || ! StripeClient::verifySignature($payload, (string) $request->header('Stripe-Signature'), $secret)) {
            Log::channel('security')->warning('Stripe webhook signature verification failed', ['ip' => $request->ip()]);

            return ApiResponse::error('invalid_signature', 'The webhook signature is invalid.', 401);
        }

        $event = json_decode($payload, true);
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        $object = is_array($event) && is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        try {
            $subscriptionId = match (true) {
                in_array($type, self::SUBSCRIPTION_EVENTS, true) => $object['id'] ?? null,
                $type === 'checkout.session.completed', $type === 'invoice.paid', $type === 'invoice.payment_failed' => $object['subscription'] ?? ($object['parent']['subscription_details']['subscription'] ?? null),
                default => null,
            };
            if (is_string($subscriptionId) && $subscriptionId !== '') {
                $billing->syncSubscription($subscriptionId);

                return ApiResponse::ok(['received' => true, 'updated' => true]);
            }

            if ($type === 'account.updated' && is_string($object['id'] ?? null)) {
                $org = Organization::query()->where('stripe_account_id', $object['id'])->first();
                if ($org !== null) {
                    $billing->applyAccount($org, $object);

                    return ApiResponse::ok(['received' => true, 'updated' => true]);
                }
            }
        } catch (BillingException $e) {
            // Stripe に再送してもらう
            Log::warning('Stripe webhook processing failed', ['type' => $type, 'error' => $e->getMessage()]);

            return ApiResponse::error('processing_failed', 'Failed to process the event.', 500);
        }

        return ApiResponse::ok(['received' => true, 'updated' => false]);
    }
}
