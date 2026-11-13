@extends('layouts.org')
@section('title', '売上の受け取り')
@section('content')
<h1>売上の受け取り（Stripe）</h1>
<p class="muted">アプリのユーザーが有料プランを購入すると、手数料を差し引いた金額がこの組織の Stripe アカウントに入金されます。Stripe との契約（本人確認・口座の登録）は、下のボタンから数分で行えます。</p>
@if ($errors->has('stripe'))<div class="alert alert-error">{{ $errors->first('stripe') }}</div>@endif
@if ($error)<div class="alert alert-warn">{{ $error }}</div>@endif

<div class="card">
    @if (! $configured)
        <p class="muted">まだ課金機能が有効になっていません（システム管理者による Stripe の設定が必要です）。</p>
    @else
        @if ($testMode)<div class="alert alert-warn">テストモードです。実際の支払い・入金は発生しません。</div>@endif
        <table style="margin-bottom:12px">
            <tr><th style="width:200px">状態</th><td>
                @if ($organization->stripe_charges_enabled)
                    <span class="badge badge-ok">決済を受け付けられます</span>
                @elseif ($organization->stripe_account_id)
                    <span class="badge badge-muted">登録の途中です</span>
                @else
                    <span class="badge badge-muted">未登録</span>
                @endif
            </td></tr>
            <tr><th>手数料</th><td>売上の {{ rtrim(rtrim(number_format((float) $feePercent, 2), '0'), '.') }}%（Stripe の決済手数料は別途かかります）</td></tr>
            @if ($organization->stripe_account_id)<tr><th>Stripe アカウント</th><td class="mono">{{ $organization->stripe_account_id }}</td></tr>@endif
        </table>
        <div class="row">
            @if (! $organization->stripe_charges_enabled)
                <form method="post" action="{{ route('org.billing.connect', $organization) }}">@csrf<button class="btn btn-primary">{{ $organization->stripe_account_id ? '登録を続ける' : 'Stripe に登録する' }}</button></form>
            @endif
            @if ($organization->stripe_details_submitted)
                <form method="post" action="{{ route('org.billing.dashboard', $organization) }}">@csrf<button class="btn">売上・入金を見る（Stripe）</button></form>
            @endif
        </div>
        @if ($organization->stripe_charges_enabled)
            <p class="hint muted">プランは、各アプリの「課金プラン」で作成します。</p>
        @endif
    @endif
</div>
@endsection
