@extends('layouts.org')
@section('title', 'メンバー')
@section('content')
<h1>メンバー</h1>
<div class="card">
    <table>
        <tr><th>名前</th><th>メールアドレス</th><th>ロール</th><th></th></tr>
        @foreach ($members as $member)
            <tr>
                <td>{{ $member->adminUser->name }}</td>
                <td>{{ $member->adminUser->email }}</td>
                <td>
                    <form method="post" action="{{ route('org.members.update', [$organization, $member->id]) }}" class="row">
                        @csrf @method('put')
                        <select name="role" style="width:auto">
                            @foreach (\App\Enums\OrgRole::options() as $value => $label)
                                <option value="{{ $value }}" @selected($member->role->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm">変更</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="{{ route('org.members.destroy', [$organization, $member->id]) }}" onsubmit="return confirm('このメンバーを組織から外しますか？')">
                        @csrf @method('delete')
                        <button class="btn btn-sm btn-danger">削除</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
</div>

<h2>招待する</h2>
<div class="card">
    <form method="post" action="{{ route('org.members.invite', $organization) }}" class="row">
        @csrf
        <input type="email" name="email" placeholder="email@example.com" required style="max-width:300px">
        <select name="role" style="width:auto">
            @foreach (\App\Enums\OrgRole::options() as $value => $label)
                <option value="{{ $value }}" @selected($value === 'editor')>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary">招待メールを送る</button>
    </form>
    <p class="hint muted">owner: 全権限 / admin: アプリ設定・配信・コンテンツ管理 / editor: お知らせ・動画・プッシュの作成と送信 / viewer: 閲覧のみ</p>
</div>

@if ($invitations->isNotEmpty())
    <h2>招待中</h2>
    <div class="card">
        <table>
            <tr><th>メールアドレス</th><th>ロール</th><th>送信日時</th><th>有効期限</th><th></th></tr>
            @foreach ($invitations as $inv)
                <tr>
                    <td>{{ $inv->email }}</td>
                    <td>{{ $inv->role->label() }}</td>
                    <td>{{ eevee_dt($inv->created_at) }}</td>
                    <td>{{ eevee_dt($inv->expires_at) }}@if ($inv->expires_at->isPast()) <span class="badge badge-danger">期限切れ</span>@endif</td>
                    <td class="row">
                        <form method="post" action="{{ route('org.members.invitations.resend', [$organization, $inv->id]) }}">
                            @csrf<button class="btn btn-sm">再送</button>
                        </form>
                        <form method="post" action="{{ route('org.members.invitations.destroy', [$organization, $inv->id]) }}" onsubmit="return confirm('招待を取り消しますか？')">
                            @csrf @method('delete')<button class="btn btn-sm">取り消し</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
@endsection
