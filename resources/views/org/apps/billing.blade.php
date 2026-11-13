@extends('layouts.org')
@section('title', '課金プラン')
@section('content')
<h1>課金プラン</h1>
<p class="muted">月額・年額の購読プランです。プランを購入したユーザーは「権利」（例: <span class="mono">premium</span>）を得て、同じ権利が必要な動画を再生できます。セグメントの条件「課金: 〜の権利がある」で、購読者だけに配信することもできます。</p>
@if ($errors->has('stripe'))<div class="alert alert-error">{{ $errors->first('stripe') }}</div>@endif
@if (! $configured)
    <div class="alert alert-warn">課金機能が有効になっていません（システム管理者による Stripe の設定が必要です）。</div>
@elseif (! $organization->stripe_charges_enabled)
    <div class="alert alert-warn">組織の「売上の受け取り」で Stripe への登録が終わるまで、アプリにはプランが表示されません。@can('manage-organization') <a href="{{ route('org.billing.show', $organization) }}">登録する</a>@endcan</div>
@endif

<div class="card">
    <table>
        <tr><th>プラン</th><th>料金</th><th>権利</th><th>購読者</th><th></th></tr>
        @forelse ($plans as $plan)
            <tr>
                <td>
                    @can('manage-apps')
                        <form method="post" action="{{ route('org.apps.billing.plans.update', [$organization, $app, $plan->id]) }}" id="plan-{{ $plan->id }}" class="row">
                            @csrf @method('put')
                            <input type="text" name="name" value="{{ $plan->name }}" style="max-width:180px" required>
                            <input type="text" name="description" value="{{ $plan->description }}" placeholder="説明" style="max-width:220px">
                            <input type="number" name="sort_order" value="{{ $plan->sort_order }}" style="max-width:70px" title="並び順">
                            <label><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> 販売中</label>
                        </form>
                    @else
                        <strong>{{ $plan->name }}</strong> @unless ($plan->is_active)<span class="badge badge-muted">停止中</span>@endunless
                        <div class="muted">{{ $plan->description }}</div>
                    @endcan
                </td>
                <td>{{ $plan->priceLabel() }}</td>
                <td class="mono">{{ $plan->entitlement }}</td>
                <td>{{ number_format($counts[$plan->id] ?? 0) }}</td>
                <td>@can('manage-apps')<button class="btn btn-sm" form="plan-{{ $plan->id }}">更新</button>@endcan</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">まだプランがありません。</td></tr>
        @endforelse
    </table>
    <p class="hint muted">料金・期間は作成後に変えられません（Stripe の仕様）。変える場合は新しいプランを作り、古いプランの「販売中」を外してください。既存の購読者はそのまま継続します。</p>
</div>

@can('manage-apps')
@if ($configured)
<h2>プランを追加</h2>
<div class="card">
    <form method="post" action="{{ route('org.apps.billing.plans.store', [$organization, $app]) }}">
        @csrf
        <div class="row">
            <div class="form-row"><label>名前</label><input type="text" name="name" value="{{ old('name') }}" placeholder="プレミアム（月額）" required></div>
            <div class="form-row"><label>説明（任意）</label><input type="text" name="description" value="{{ old('description') }}" placeholder="すべての動画が見放題"></div>
        </div>
        <div class="row">
            <div class="form-row"><label>料金（円）</label><input type="number" name="amount" min="50" value="{{ old('amount', 500) }}" style="max-width:140px" required></div>
            <div class="form-row"><label>期間</label><select name="interval"><option value="month">月額</option><option value="year" @selected(old('interval') === 'year')>年額</option></select></div>
            <div class="form-row"><label>権利（英小文字）</label><input type="text" name="entitlement" value="{{ old('entitlement', 'premium') }}" pattern="[a-z][a-z0-9_]*" style="max-width:160px" required></div>
        </div>
        <button class="btn btn-primary">作成</button>
    </form>
</div>
@endif
@endcan

<h2>最近の購読</h2>
<div class="card">
    <table>
        <tr><th>ユーザー</th><th>プラン</th><th>状態</th><th>次回更新 / 終了</th></tr>
        @forelse ($subscriptions as $sub)
            <tr>
                <td>@if ($sub->appUser)<a href="{{ route('org.apps.users.show', [$organization, $app, $sub->appUser]) }}">{{ $sub->appUser->email ?? $sub->appUser->external_user_id }}</a>@else<span class="muted">（削除済み）</span>@endif</td>
                <td>{{ $sub->plan->name ?? $sub->entitlement }}</td>
                <td>
                    @php $label = ['active' => '有効', 'trialing' => 'お試し中', 'past_due' => '支払い待ち', 'canceled' => '解約済み', 'unpaid' => '未払い', 'incomplete' => '未完了', 'incomplete_expired' => '期限切れ', 'paused' => '一時停止'][$sub->status] ?? $sub->status; @endphp
                    <span class="badge {{ $sub->isEntitled() ? 'badge-ok' : 'badge-muted' }}">{{ $label }}</span>
                    @if ($sub->cancel_at_period_end && $sub->isEntitled())<span class="badge badge-muted">期間終了で解約</span>@endif
                </td>
                <td>{{ $sub->current_period_end?->timezone($organization->timezone)->format('Y-m-d H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">まだ購読がありません。</td></tr>
        @endforelse
    </table>
</div>
@endsection
