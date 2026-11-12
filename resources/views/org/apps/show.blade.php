@extends('layouts.org')
@section('title', $app->name)
@section('content')
<h1>{{ $app->name }}</h1>
<div class="stats" style="margin-bottom:18px">
    <div class="stat"><div class="v">{{ number_format($stats['users']) }}</div><div class="l">アプリユーザー</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['devices']) }}</div><div class="l">端末</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['pushable']) }}</div><div class="l">プッシュ送信可能な端末</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['campaigns']) }}</div><div class="l">プッシュキャンペーン</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['notices']) }}</div><div class="l">お知らせ</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['videos']) }}</div><div class="l">動画</div></div>
</div>
<div class="card">
    <table>
        <tr><th style="width:220px">app_key（公開ID）</th><td class="mono">{{ $app->app_key }}</td></tr>
        <tr><th>アプリ向けAPIのベースURL</th><td class="mono">{{ url('/api/v1/apps/'.$app->app_key) }}</td></tr>
        <tr><th>サーバー間APIのベースURL</th><td class="mono">{{ url('/api/v1/server/apps/'.$app->app_key) }}</td></tr>
        <tr><th>Cloudflare Webhook URL</th><td class="mono">{{ url('/webhooks/cloudflare/'.$app->app_key) }}</td></tr>
        <tr><th>認証方式</th><td>{{ $app->auth_mode->label() }}</td></tr>
        <tr><th>Firebase</th><td>{!! $app->hasFirebaseCredentials() ? '<span class="badge badge-ok">設定済み</span> '.e($app->firebase_credentials['project_id'] ?? '') : '<span class="badge badge-muted">未設定</span>' !!}</td></tr>
        <tr><th>動画プロバイダ</th><td>{{ $app->video_provider }} {!! $app->videoSetting('account_id') ? '<span class="badge badge-ok">設定済み</span>' : '<span class="badge badge-muted">未設定</span>' !!}</td></tr>
        <tr><th>表示タイムゾーン</th><td>{{ $app->displayTimezone() }}</td></tr>
    </table>
</div>
@endsection
