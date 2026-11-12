@extends('layouts.org')
@section('title', 'IP制限')
@section('content')
<h1>管理画面のIP制限</h1>
<div class="card">
    <p>リストが空なら制限なし。1件以上あれば、リストに一致しないIPからの <span class="mono">/{{ $organization->slug }}/</span> 配下（ログイン画面を含む）へのアクセスは拒否されます。
    アプリ向けAPIとWebhookには適用されません。</p>
    <p class="muted">締め出された場合は、システム管理者に解除を依頼してください（<span class="mono">php artisan eevee:ip-allowlist:clear {{ $organization->slug }}</span> でも解除できます）。</p>
    <x-ip-allowlist-form :action="route('org.ip-allowlist.update', $organization)" :input="$input" :current-ip="$currentIp" />
</div>
@endsection
