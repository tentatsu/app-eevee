@extends('layouts.org')
@section('title', $campaign->title)
@section('content')
<div class="row" style="justify-content:space-between">
    <h1>{{ $campaign->title }} <span class="badge">{{ $campaign->status->label() }}</span></h1>
    @can('edit-content')
    <div class="row">
        @if ($campaign->status->value === 'draft')
            <a class="btn" href="{{ route('org.apps.campaigns.edit', [$organization, $app, $campaign]) }}">編集</a>
            <form method="post" action="{{ route('org.apps.campaigns.test', [$organization, $app, $campaign]) }}">@csrf<button class="btn">テスト配信（{{ $testDeviceCount }}台）</button></form>
            <form method="post" action="{{ route('org.apps.campaigns.send', [$organization, $app, $campaign]) }}" onsubmit="return confirm('{{ $campaign->scheduled_at && $campaign->scheduled_at->isFuture() ? '予約しますか？' : '今すぐ送信しますか？' }}')">@csrf
                <button class="btn btn-primary">{{ $campaign->scheduled_at && $campaign->scheduled_at->isFuture() ? '予約する（'.eevee_dt($campaign->scheduled_at).'）' : '今すぐ送信' }}</button></form>
        @elseif ($campaign->status->value === 'scheduled')
            <form method="post" action="{{ route('org.apps.campaigns.test', [$organization, $app, $campaign]) }}">@csrf<button class="btn">テスト配信</button></form>
            <form method="post" action="{{ route('org.apps.campaigns.cancel', [$organization, $app, $campaign]) }}">@csrf<button class="btn btn-danger">予約を取り消す</button></form>
        @endif
    </div>
    @endcan
</div>

<div class="stats" style="margin-bottom:18px">
    @if ($estimate)
        <div class="stat"><div class="v">{{ number_format($estimate['devices']) }}</div><div class="l">送信予定の端末</div></div>
        <div class="stat"><div class="v">{{ number_format($estimate['users']) }}</div><div class="l">送信予定のユーザー</div></div>
    @else
        <div class="stat"><div class="v">{{ number_format($campaign->target_count) }}</div><div class="l">対象</div></div>
        <div class="stat"><div class="v">{{ number_format($campaign->success_count) }}</div><div class="l">成功</div></div>
        <div class="stat"><div class="v">{{ number_format($campaign->failure_count) }}</div><div class="l">失敗</div></div>
        <div class="stat"><div class="v">{{ number_format($campaign->invalid_token_count) }}</div><div class="l">無効トークン</div></div>
        <div class="stat"><div class="v">{{ number_format($campaign->open_count) }}</div><div class="l">開封</div></div>
        <div class="stat"><div class="v">{{ $campaign->openRate() !== null ? $campaign->openRate().'%' : '—' }}</div><div class="l">開封率（開封/成功）</div></div>
    @endif
</div>

<div class="card">
    <table>
        <tr><th style="width:180px">本文</th><td style="white-space:pre-wrap">{{ $campaign->body }}</td></tr>
        <tr><th>画像</th><td class="mono">{{ $campaign->image_url ?? '—' }}</td></tr>
        <tr><th>data</th><td class="mono">{{ $campaign->data ? json_encode($campaign->data, JSON_UNESCAPED_UNICODE) : '—' }}</td></tr>
        <tr><th>通知カテゴリ</th><td>{{ $campaign->notificationCategory?->label ?? '—' }}</td></tr>
        <tr><th>対象</th><td>{{ $campaign->target_type->label() }}
            @if ($campaign->segment) : {{ $campaign->segment->name }} @endif
            @if ($campaign->target_user_ids) : {{ implode(', ', $campaign->target_user_ids) }} @endif</td></tr>
        <tr><th>送信日時</th><td>予約: {{ eevee_dt($campaign->scheduled_at) }} ／ 開始: {{ eevee_dt($campaign->started_at) }} ／ 完了: {{ eevee_dt($campaign->finished_at) }}</td></tr>
        <tr><th>作成者</th><td>{{ $campaign->created_by }}</td></tr>
    </table>
</div>

@if ($tests->isNotEmpty())
<h2>テスト配信の結果</h2>
<div class="card"><table>
    <tr><th>日時</th><th>状態</th><th>対象</th><th>成功</th><th>失敗</th><th>無効</th></tr>
    @foreach ($tests as $t)
        <tr><td>{{ eevee_dt($t->started_at) }}</td><td>{{ $t->status->label() }}</td><td>{{ $t->target_count }}</td><td>{{ $t->success_count }}</td><td>{{ $t->failure_count }}</td><td>{{ $t->invalid_token_count }}</td></tr>
    @endforeach
</table></div>
@endif

@if ($deliveries->total() > 0)
<h2>端末ごとの送信結果</h2>
<div class="card">
    <table>
        <tr><th>端末</th><th>ユーザー</th><th>状態</th><th>試行</th><th>エラー</th><th>送信日時</th><th>開封日時</th></tr>
        @foreach ($deliveries as $d)
            <tr>
                <td class="mono">{{ $d->device?->installation_id }}</td>
                <td>{{ $d->device?->app_user_id ? '#'.$d->device->app_user_id : '—' }}</td>
                <td>{{ $d->status->value }}</td>
                <td>{{ $d->attempts }}</td>
                <td class="mono">{{ $d->error_code }}</td>
                <td>{{ eevee_dt($d->sent_at, 'm-d H:i:s') }}</td>
                <td>{{ eevee_dt($d->opened_at, 'm-d H:i:s') }}</td>
            </tr>
        @endforeach
    </table>
    <div class="pagination">{{ $deliveries->links() }}</div>
</div>
@endif
@endsection
