@extends('layouts.system')
@section('title', $account->exists ? $account->name : '共用の動画アカウントを追加')
@section('content')
<h1>{{ $account->exists ? $account->name : '共用の動画アカウントを追加' }}</h1>
<div class="card">
    <form method="post" action="{{ $account->exists ? route('system.provider-accounts.update', $account) : route('system.provider-accounts.store') }}">
        @csrf @if ($account->exists) @method('put') @endif
        <div class="form-row"><label>名前（管理用）</label><input type="text" name="name" value="{{ old('name', $account->name) }}" placeholder="Oneframe 共用 Cloudflare" required></div>
        <div class="form-row"><label>Cloudflare Account ID</label><input type="text" name="account_id" value="{{ old('account_id', $account->setting('account_id')) }}" required></div>
        <div class="form-row"><label>API Token（権限: Media › Stream › Edit）</label>
            <input type="password" name="api_token" autocomplete="new-password" placeholder="{{ $account->setting('api_token') ? '設定済み（変更する場合のみ入力）' : '' }}" @required(! $account->exists)></div>
        <button class="btn btn-primary">保存</button>
    </form>
</div>

@if ($account->exists)
<h2>自動設定</h2>
<div class="card">
    <table style="margin-bottom:12px">
        @foreach (['signing_key_id' => '署名鍵', 'webhook_secret' => 'Webhook', 'customer_subdomain' => 'カスタマーサブドメイン'] as $key => $label)
            <tr><th style="width:200px">{{ $label }}</th><td>{!! $account->setting($key) ? '<span class="badge badge-ok">設定済み</span>'.($key !== 'webhook_secret' ? ' <span class="mono">'.e($account->setting($key)).'</span>' : '') : '<span class="badge badge-muted">未設定</span>' !!}</td></tr>
        @endforeach
        <tr><th>Webhook の送信先（共用）</th><td class="mono">{{ route('webhooks.cloudflare.shared', ['token' => $account->webhook_token]) }}</td></tr>
    </table>
    @if (session('webhook_conflict'))
        <div class="alert alert-warn">
            この Cloudflare アカウントの Webhook には、別の送信先（<span class="mono">{{ session('webhook_conflict') }}</span>）が設定されています。上書きすると、その送信先には通知が届かなくなります。
            <form method="post" action="{{ route('system.provider-accounts.provision', $account) }}" style="margin-top:8px">@csrf<input type="hidden" name="overwrite_webhook" value="1"><button class="btn btn-danger">上書きして自動設定する</button></form>
        </div>
    @endif
    <form method="post" action="{{ route('system.provider-accounts.provision', $account) }}">@csrf<button class="btn btn-primary">Cloudflare を自動設定</button></form>
    <p class="hint muted">署名鍵を作成し、Webhook の送信先を上の共用の URL に設定します。届いた通知は、動画の ID からアプリを探して振り分けます。</p>
</div>

<h2>このアカウントを使っているアプリ</h2>
<div class="card">
    <table>
        <tr><th>組織</th><th>アプリ</th><th>app_key</th></tr>
        @forelse ($apps as $app)
            <tr><td><a href="{{ route('system.organizations.show', $app->organization->slug) }}">{{ $app->organization->name }}</a></td><td>{{ $app->name }}</td><td class="mono">{{ $app->app_key }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">まだありません（組織の詳細画面から割り当てます）。</td></tr>
        @endforelse
    </table>
    @if ($apps->isEmpty())
        <form method="post" action="{{ route('system.provider-accounts.destroy', $account) }}" style="margin-top:10px" onsubmit="return confirm('削除しますか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">このアカウントを削除</button></form>
    @endif
</div>
@endif
@endsection
