@extends('layouts.system')
@section('title', $organization->name)
@section('content')
<div class="row" style="justify-content:space-between">
    <h1>{{ $organization->name }} <span class="mono muted">/{{ $organization->slug }}</span></h1>
    <div class="row">
        <a class="btn" href="{{ route('system.organizations.edit', $organization) }}">編集</a>
        @if ($organization->isActive())
            <form method="post" action="{{ route('system.organizations.suspend', $organization) }}" onsubmit="return confirm('組織を停止しますか？')">@csrf<button class="btn btn-danger">停止</button></form>
        @else
            <form method="post" action="{{ route('system.organizations.resume', $organization) }}">@csrf<button class="btn">再開</button></form>
        @endif
    </div>
</div>
<p>状態: <span @class(['badge', 'badge-danger' => ! $organization->isActive()])>{{ $organization->status->label() }}</span>
   ／ タイムゾーン: {{ $organization->timezone }} ／ ログインURL: <a class="mono" href="{{ url($organization->slug.'/login') }}">{{ url($organization->slug.'/login') }}</a></p>

<h2>メンバー</h2>
<div class="card">
    <table>
        <tr><th>名前</th><th>メールアドレス</th><th>ロール</th></tr>
        @forelse ($members as $m)
            <tr><td>{{ $m->adminUser->name }}</td><td>{{ $m->adminUser->email }}</td><td>{{ $m->role->label() }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">まだメンバーがいません。</td></tr>
        @endforelse
    </table>
    @if ($invitations->isNotEmpty())
        <h3 style="margin-top:16px">招待中</h3>
        <table>
            <tr><th>メールアドレス</th><th>ロール</th><th>送信日時</th><th>有効期限</th><th></th></tr>
            @foreach ($invitations as $i)
                <tr>
                    <td>{{ $i->email }}</td>
                    <td>{{ $i->role->label() }}</td>
                    <td>{{ eevee_dt($i->created_at, 'Y-m-d H:i', $organization->timezone) }}</td>
                    <td>{{ eevee_dt($i->expires_at, 'Y-m-d H:i', $organization->timezone) }}@if ($i->expires_at->isPast()) <span class="badge badge-danger">期限切れ</span>@endif</td>
                    <td class="row">
                        <form method="post" action="{{ route('system.organizations.invitations.resend', [$organization, $i->id]) }}">
                            @csrf<button class="btn btn-sm">再送</button>
                        </form>
                        <form method="post" action="{{ route('system.organizations.invitations.destroy', [$organization, $i->id]) }}" onsubmit="return confirm('招待を取り消しますか？')">
                            @csrf @method('delete')<button class="btn btn-sm">取り消し</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
    <form method="post" action="{{ route('system.organizations.invite', $organization) }}" class="row" style="margin-top:12px">
        @csrf
        <input type="email" name="email" placeholder="email@example.com" required style="max-width:300px">
        <select name="role" style="width:auto">
            @foreach (\App\Enums\OrgRole::options() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary">管理者を招待</button>
    </form>
</div>

<h2>アプリ</h2>
<div class="card">
    <table>
        <tr><th>アプリ</th><th>app_key</th><th>動画アカウント</th></tr>
        @forelse ($apps as $app)
            <tr>
                <td>{{ $app->name }}</td>
                <td class="mono">{{ $app->app_key }}</td>
                <td>
                    <form method="post" action="{{ route('system.apps.video-account', $app->id) }}" class="row">
                        @csrf @method('put')
                        <select name="video_provider_account_id" style="width:auto">
                            <option value="">アプリ専用（顧客の Cloudflare）</option>
                            @foreach ($sharedAccounts as $sa)
                                <option value="{{ $sa->id }}" @selected($app->video_provider_account_id === $sa->id)>共用: {{ $sa->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm">変更</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">アプリがありません。</td></tr>
        @endforelse
    </table>
</div>

<h2>IP制限</h2>
<div class="card">
    @if ($ipEntries->isEmpty())
        <p class="muted">制限なし</p>
    @else
        <p>@foreach ($ipEntries as $e)<span class="badge mono">{{ $e->cidr }}</span> @endforeach</p>
        <form method="post" action="{{ route('system.organizations.ip-allowlist.clear', $organization) }}" onsubmit="return confirm('この組織のIP制限を解除しますか？')">
            @csrf @method('delete')<button class="btn btn-danger">IP制限を解除</button>
        </form>
    @endif
</div>

<h2>組織の削除</h2>
<div class="card">
    <form method="post" action="{{ route('system.organizations.destroy', $organization) }}" class="row">
        @csrf @method('delete')
        <input type="text" name="confirm_slug" placeholder="確認のため slug を入力" style="max-width:260px">
        <button class="btn btn-danger">削除する</button>
    </form>
    <p class="hint muted">削除した組織の管理画面・APIは利用できなくなります（データは論理削除）。</p>
</div>
@endsection
