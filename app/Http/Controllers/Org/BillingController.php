<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripePlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 組織の売上の受け取り（Stripe Connect の連結アカウント）。オーナー・管理者だけが操作できる。
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly StripePlatform $platform) {}

    public function show(Organization $organization): View
    {
        $error = null;
        if ($organization->stripe_account_id !== null && ! $organization->stripe_charges_enabled && $this->platform->isConfigured()) {
            try {
                $this->billing->refreshAccount($organization);
            } catch (BillingException $e) {
                $error = 'Stripe から状態を取得できませんでした。';
            }
        }

        return view('org.billing', [
            'organization' => $organization,
            'configured' => $this->platform->isConfigured(),
            'testMode' => $this->platform->isTestMode(),
            'feePercent' => $this->platform->isConfigured() ? $this->billing->feePercent($organization) : null,
            'error' => $error,
        ]);
    }

    /**
     * Stripe のオンボーディング（本人確認・口座登録）へ移動する。リンクの期限切れ（refresh_url）でもここに戻る。
     */
    public function connect(Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $first = $organization->stripe_account_id === null;
        try {
            $url = $this->billing->onboardingUrl(
                $organization,
                route('org.billing.return', $organization),
                route('org.billing.refresh', $organization),
            );
        } catch (BillingException $e) {
            return redirect()->route('org.billing.show', $organization)->withErrors(['stripe' => 'Stripe に接続できませんでした: '.$e->getMessage()]);
        }
        if ($first) {
            $audit->record('org.billing.connected', $organization, ['stripe_account_id' => $organization->stripe_account_id], 'Stripe の連結アカウントを作成');
        }

        return redirect()->away($url);
    }

    public function return(Organization $organization): RedirectResponse
    {
        try {
            $this->billing->refreshAccount($organization);
        } catch (BillingException) {
            // 表示時にもう一度取得する
        }

        return redirect()->route('org.billing.show', $organization)->with('status', $organization->stripe_charges_enabled ? '決済を受け付けられるようになりました。' : 'Stripe での登録内容を確認中です。続きがある場合は「登録を続ける」を押してください。');
    }

    public function dashboard(Organization $organization): RedirectResponse
    {
        if ($organization->stripe_account_id === null) {
            return redirect()->route('org.billing.show', $organization);
        }
        try {
            return redirect()->away($this->billing->dashboardUrl($organization));
        } catch (BillingException $e) {
            return redirect()->route('org.billing.show', $organization)->withErrors(['stripe' => 'Stripe のダッシュボードを開けませんでした（登録が終わっていない可能性があります）。']);
        }
    }
}
