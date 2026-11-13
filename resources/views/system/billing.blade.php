@extends('layouts.system')
@section('title', '課金（Stripe）')
@section('content')
<h1>課金（Stripe Connect）</h1>
<p class="muted">Oneframe の Stripe アカウント（プラットフォーム）の設定です。各組織は「売上の受け取り」から自分の連結アカウント（Express）を作り、アプリのユーザーの支払いは手数料を差し引いて組織に送金されます。</p>

<div class="card">
    <form method="post" action="{{ route('system.billing.update') }}">
        @csrf @method('put')
        <div class="form-row"><label>シークレットキー（sk_test_… / sk_live_…、または制限付きキー rk_…）</label>
            <input type="password" name="secret_key" autocomplete="new-password" placeholder="{{ $account->setting('secret_key') ? '設定済み（'.($platform->isTestMode() ? 'テストモード' : '本番').'）・変更する場合のみ入力' : '' }}"></div>
        <div class="form-row"><label>Webhook の署名シークレット（whsec_…）</label>
            <input type="password" name="webhook_secret" autocomplete="new-password" placeholder="{{ $account->setting('webhook_secret') ? '設定済み・変更する場合のみ入力' : '' }}"></div>
        <div class="row">
            <div class="form-row"><label>手数料（%・既定値）</label><input type="number" name="fee_percent" step="0.01" min="0" max="50" value="{{ old('fee_percent', $account->setting('fee_percent') ?? config('eevee.billing.default_fee_percent')) }}" style="max-width:120px" required></div>
            <div class="form-row"><label>連結アカウントの国</label><input type="text" name="country" value="{{ old('country', $account->setting('country') ?? 'JP') }}" maxlength="2" style="max-width:80px" required></div>
        </div>
        <button class="btn btn-primary">保存</button>
    </form>
</div>

<h2>Webhook の設定</h2>
<div class="card">
    <p>Stripe のダッシュボード（開発者 › Webhook）で、次の送信先を追加し、表示された署名シークレットを上に保存してください。</p>
    <table>
        <tr><th style="width:200px">送信先 URL</th><td class="mono">{{ route('webhooks.stripe') }}</td></tr>
        <tr><th>イベント（自分のアカウント）</th><td class="mono">checkout.session.completed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed</td></tr>
        <tr><th>イベント（連結アカウント・任意）</th><td class="mono">account.updated <span class="muted">— 「連結アカウント」の Webhook を別に作る場合。作らなくても、組織の画面を開いたときに状態を取得します</span></td></tr>
    </table>
</div>

<h2>連結アカウントがある組織</h2>
<div class="card">
    <table>
        <tr><th>組織</th><th>状態</th><th>手数料（空欄で既定値）</th></tr>
        @forelse ($organizations as $org)
            <tr>
                <td><a href="{{ route('system.organizations.show', $org->slug) }}">{{ $org->name }}</a><div class="mono muted">{{ $org->stripe_account_id }}</div></td>
                <td>{!! $org->stripe_charges_enabled ? '<span class="badge badge-ok">決済可</span>' : '<span class="badge badge-muted">登録中</span>' !!}</td>
                <td>
                    <form method="post" action="{{ route('system.organizations.billing', $org->slug) }}" class="row">
                        @csrf @method('put')
                        <input type="number" name="application_fee_percent" step="0.01" min="0" max="50" value="{{ $org->application_fee_percent }}" placeholder="{{ $platform->feePercent() }}" style="max-width:100px"> %
                        <button class="btn btn-sm">保存</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">まだありません。</td></tr>
        @endforelse
    </table>
</div>
@endsection
