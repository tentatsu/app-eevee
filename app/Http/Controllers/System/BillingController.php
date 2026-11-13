<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\StripePlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * プラットフォーム（Oneframe）の Stripe の設定と、組織ごとの手数料。
 */
class BillingController extends Controller
{
    public function edit(StripePlatform $platform): View
    {
        $organizations = Organization::query()->whereNotNull('stripe_account_id')->orderBy('name')->get();

        return view('system.billing', ['account' => $platform->accountOrNew(), 'platform' => $platform, 'organizations' => $organizations]);
    }

    public function update(Request $request, StripePlatform $platform, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'secret_key' => ['nullable', 'string', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'regex:/^whsec_[A-Za-z0-9]+$/', 'max:255'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
        ], [
            'secret_key.regex' => 'sk_test_… / sk_live_…（または制限付きキー rk_…）の形式で入力してください。',
            'webhook_secret.regex' => 'whsec_… の形式で入力してください。',
        ]);

        $account = $platform->accountOrNew();
        $settings = $account->settings ?? [];
        foreach (['secret_key', 'webhook_secret'] as $key) {
            if (! empty($data[$key])) {
                $settings[$key] = $data[$key];
            }
        }
        $settings['fee_percent'] = (string) $data['fee_percent'];
        $settings['country'] = strtoupper($data['country']);
        $account->settings = $settings;
        $account->save();

        $audit->record('system.billing.updated', $account, [
            'fee_percent' => $settings['fee_percent'],
            'country' => $settings['country'],
            'secret_key_changed' => ! empty($data['secret_key']),
            'webhook_secret_changed' => ! empty($data['webhook_secret']),
        ], 'Stripe の設定を更新');

        return back()->with('status', '保存しました。');
    }

    public function updateOrganization(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['application_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:50']]);
        $before = $organization->application_fee_percent;
        $organization->forceFill(['application_fee_percent' => $data['application_fee_percent'] ?? null])->save();
        $audit->record('system.organization.fee_updated', $organization, ['before' => $before, 'after' => $organization->application_fee_percent], '手数料を変更: '.$organization->name, organization: $organization);

        return back()->with('status', '手数料を保存しました（新しい購読から適用されます）。');
    }
}
