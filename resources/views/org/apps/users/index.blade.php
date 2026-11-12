@extends('layouts.org')
@section('title', 'アプリユーザー')
@section('content')
<h1>アプリユーザー</h1>
<form method="get" class="row" style="margin-bottom:12px">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="ID / メール / 外部ユーザーID / 名前" style="max-width:320px">
    <button class="btn">検索</button>
</form>
<div class="card">
    <table>
        <tr><th>ID</th><th>メール / 外部ユーザーID</th><th>名前</th><th>端末</th><th>最終ログイン</th><th>登録日時</th></tr>
        @forelse ($users as $u)
            <tr>
                <td><a href="{{ route('org.apps.users.show', [$organization, $app, $u]) }}">{{ $u->id }}</a></td>
                <td>{{ $u->email ?? '' }} <span class="mono muted">{{ $u->external_user_id }}</span></td>
                <td>{{ $u->name }}</td>
                <td>{{ $u->devices_count }}</td>
                <td>{{ eevee_dt($u->last_login_at) }}</td>
                <td>{{ eevee_dt($u->created_at) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">ユーザーがいません。</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $users->links() }}</div>
</div>
@endsection
