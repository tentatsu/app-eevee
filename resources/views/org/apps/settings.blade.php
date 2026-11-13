@extends('layouts.org')
@section('title', 'アプリ設定')
@section('content')
<h1>アプリ設定</h1>

@if (session('app_secret'))
    <div class="alert alert-warn">
        <strong>app_secret（今だけ表示）:</strong> <span class="mono">{{ session('app_secret') }}</span>
        <div class="muted">サーバー間APIの <span class="mono">Authorization: Bearer {app_secret}</span> に使います。DBにはハッシュだけを保存しているため、再表示はできません。</div>
    </div>
@endif

<h2>基本設定</h2>
<div class="card">
    <form method="post" action="{{ route('org.apps.update', [$organization, $app]) }}">
        @csrf @method('put')
        <div class="form-row"><label>名前</label><input type="text" name="name" value="{{ old('name', $app->name) }}" required></div>
        <div class="form-row"><label>アプリユーザーの認証方式</label>
            <select name="auth_mode">
                @foreach (\App\Enums\AuthMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected(old('auth_mode', $app->auth_mode->value) === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row"><label>external 方式のアプリ用トークンの有効期限（分）</label><input type="number" name="external_token_ttl_minutes" value="{{ old('external_token_ttl_minutes', $app->external_token_ttl_minutes) }}" min="5"></div>
        <div class="form-row"><label>表示タイムゾーン（空欄なら組織の設定: {{ $organization->timezone }}）</label><input type="text" name="timezone" value="{{ old('timezone', $app->timezone) }}" placeholder="Asia/Tokyo"></div>
        <h3>動画</h3>
        <div class="form-row"><label>同時視聴数の上限</label><input type="number" name="max_concurrent_streams" value="{{ old('max_concurrent_streams', $app->max_concurrent_streams) }}" min="1"></div>
        <div class="form-row"><label>上限を超えたとき</label>
            <select name="concurrency_policy">
                @foreach (\App\Enums\ConcurrencyPolicy::cases() as $p)
                    <option value="{{ $p->value }}" @selected(old('concurrency_policy', $app->concurrency_policy->value) === $p->value)>{{ $p->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row"><label>再生トークンの有効期限に足す余裕時間（分）</label><input type="number" name="playback_token_grace_minutes" value="{{ old('playback_token_grace_minutes', $app->playback_token_grace_minutes) }}" min="0"></div>
        <h3>プッシュ</h3>
        <div class="form-row"><label>送信レート（1秒あたりのチャンク数の上限。1チャンク=最大500端末）</label><input type="number" name="push_rate_per_second" value="{{ old('push_rate_per_second', $app->push_rate_per_second) }}" min="1"></div>
        <div class="form-row"><label>端末ごとの送信結果の保持期間（日）</label><input type="number" name="delivery_log_retention_days" value="{{ old('delivery_log_retention_days', $app->delivery_log_retention_days) }}" min="1"></div>
        <button class="btn btn-primary">保存</button>
    </form>
</div>

<h2>API の認証情報</h2>
<div class="card">
    <p>app_key: <span class="mono">{{ $app->app_key }}</span></p>
    <p class="muted">app_secret の最終発行: {{ eevee_dt($app->app_secret_rotated_at) }}</p>
    <form method="post" action="{{ route('org.apps.secret', [$organization, $app]) }}" onsubmit="return confirm('app_secret を再発行しますか？古い値はすぐに使えなくなります。')">
        @csrf <button class="btn btn-danger">app_secret を再発行</button>
    </form>
</div>

<h2>Firebase（FCM）</h2>
<div class="card">
    @if ($app->hasFirebaseCredentials())
        <p><span class="badge badge-ok">設定済み</span> project_id: <span class="mono">{{ $app->firebase_credentials['project_id'] ?? '' }}</span> / client_email: <span class="mono">{{ $app->firebase_credentials['client_email'] ?? '' }}</span></p>
    @else
        <p><span class="badge badge-muted">未設定</span></p>
    @endif
    <form method="post" action="{{ route('org.apps.firebase', [$organization, $app]) }}" enctype="multipart/form-data">
        @csrf @method('put')
        <div class="form-row"><label>サービスアカウントの JSON ファイル</label><input type="file" name="service_account_file" accept="application/json"></div>
        <div class="form-row"><label>または JSON を貼り付け</label><textarea name="service_account_json" rows="4" placeholder='{"type": "service_account", ...}'></textarea>
            <div class="hint">Firebase コンソール → プロジェクトの設定 → サービスアカウント → 新しい秘密鍵の生成。暗号化して保存し、画面には再表示しません。</div></div>
        <button class="btn btn-primary">保存</button>
    </form>
    @if ($app->hasFirebaseCredentials())
        <form method="post" action="{{ route('org.apps.firebase', [$organization, $app]) }}" style="margin-top:8px" onsubmit="return confirm('削除しますか？')">
            @csrf @method('put')<input type="hidden" name="clear" value="1"><button class="btn btn-sm btn-danger">認証情報を削除</button>
        </form>
    @endif
</div>

<h2>動画プロバイダ</h2>
<div class="card">
    <p class="muted">Cloudflare Stream の場合は、<strong>Account ID と API Token（権限: Media › Stream › Edit）を保存してから「Cloudflare を自動設定」</strong>を押してください。署名鍵の作成と Webhook の設定をサーバーで行い、秘密情報は暗号化して保存します（画面には表示しません）。</p>
    <table style="margin-bottom:12px">
        @foreach (['account_id' => 'Account ID', 'api_token' => 'API Token', 'signing_key_id' => '署名鍵', 'webhook_secret' => 'Webhook', 'customer_subdomain' => 'カスタマーサブドメイン'] as $key => $label)
            <tr><th style="width:200px">{{ $label }}</th><td>{!! $app->videoSetting($key) ? '<span class="badge badge-ok">設定済み</span>'.(in_array($key, ['account_id', 'signing_key_id', 'customer_subdomain']) ? ' <span class="mono">'.e($app->videoSetting($key)).'</span>' : '') : '<span class="badge badge-muted">未設定</span>' !!}</td></tr>
        @endforeach
        <tr><th>Webhook の送信先</th><td class="mono">{{ route('webhooks.cloudflare', ['app_key' => $app->app_key]) }}</td></tr>
    </table>

    @if (session('webhook_conflict'))
        <div class="alert alert-warn">
            この Cloudflare アカウントの Webhook には、別の送信先（<span class="mono">{{ session('webhook_conflict') }}</span>）が設定されています。Webhook はアカウントに1つだけなので、上書きするとその送信先には通知が届かなくなります。
            <form method="post" action="{{ route('org.apps.video-provider.provision', [$organization, $app]) }}" style="margin-top:8px">
                @csrf <input type="hidden" name="overwrite_webhook" value="1">
                <button class="btn btn-danger">上書きして自動設定する</button>
            </form>
        </div>
    @endif
    <form method="post" action="{{ route('org.apps.video-provider', [$organization, $app]) }}" class="row" style="margin-bottom:10px">
        @csrf @method('put')
        <input type="hidden" name="video_provider" value="cloudflare_stream">
        <input type="text" name="account_id" value="{{ old('account_id', $app->videoSetting('account_id')) }}" placeholder="Account ID" style="max-width:280px">
        <input type="password" name="api_token" autocomplete="new-password" placeholder="{{ $app->videoSetting('api_token') ? 'API Token（設定済み。変更する場合のみ入力）' : 'API Token' }}" style="max-width:320px">
        <button class="btn">保存</button>
    </form>
    <form method="post" action="{{ route('org.apps.video-provider.provision', [$organization, $app]) }}" style="margin-bottom:16px">
        @csrf
        <button class="btn btn-primary" @disabled(! $app->videoSetting('account_id') || ! $app->videoSetting('api_token'))>Cloudflare を自動設定</button>
    </form>

    <details>
        <summary>個別に入力する</summary>
    <form method="post" action="{{ route('org.apps.video-provider', [$organization, $app]) }}">
        @csrf @method('put')
        <div class="form-row"><label>プロバイダ</label>
            <select name="video_provider">
                @foreach (array_keys((array) config('eevee.video.providers')) ?: ['cloudflare_stream'] as $p)
                    <option value="{{ $p }}" @selected($app->video_provider === $p)>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-row"><label>Cloudflare Account ID</label><input type="text" name="account_id" value="{{ old('account_id', $app->videoSetting('account_id')) }}"></div>
        <div class="form-row"><label>カスタマーサブドメイン（例: customer-xxxx.cloudflarestream.com）</label><input type="text" name="customer_subdomain" value="{{ old('customer_subdomain', $app->videoSetting('customer_subdomain')) }}"></div>
        @foreach (['api_token' => 'API Token（権限: Media › Stream › Edit）', 'signing_key_id' => '署名鍵の Key ID', 'signing_private_key' => '署名鍵の秘密鍵（PEM。Cloudflare が返す base64 のままでも可）', 'webhook_secret' => 'Webhook の署名シークレット'] as $key => $label)
            <div class="form-row">
                <label>{{ $label }}</label>
                @if ($key === 'signing_key_id')
                    <input type="text" name="{{ $key }}" value="{{ old($key, $app->videoSetting($key)) }}">
                @elseif ($key === 'signing_private_key')
                    <textarea name="{{ $key }}" rows="3" placeholder="{{ $app->videoSetting($key) ? '設定済み（変更する場合のみ入力）' : '' }}"></textarea>
                @else
                    <input type="password" name="{{ $key }}" autocomplete="new-password" placeholder="{{ $app->videoSetting($key) ? '設定済み（変更する場合のみ入力）' : '' }}">
                @endif
                @if ($key !== 'signing_key_id' && $app->videoSetting($key))
                    <div class="hint"><label style="font-weight:normal"><input type="checkbox" name="clear_{{ $key }}" value="1"> 削除する</label></div>
                @endif
            </div>
        @endforeach
        <button class="btn btn-primary">保存</button>
    </form>
    </details>
</div>
@endsection
