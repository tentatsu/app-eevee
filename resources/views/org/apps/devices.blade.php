@extends('layouts.org')
@section('title', '端末')
@section('content')
<h1>端末</h1>
<form method="get" class="row" style="margin-bottom:12px">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="installation_id / ユーザーID / テストラベル" style="max-width:320px">
    <label><input type="checkbox" name="test_only" value="1" @checked(request('test_only'))> テスト端末のみ</label>
    <button class="btn">検索</button>
</form>
<div class="card">
    <table>
        <tr><th>ID</th><th>installation_id</th><th>ユーザー</th><th>OS / アプリ</th><th>通知</th><th>最終アクティブ</th><th>状態</th><th>テスト端末</th></tr>
        @forelse ($devices as $d)
            <tr>
                <td>{{ $d->id }}</td>
                <td class="mono">{{ $d->installation_id }}</td>
                <td>@if ($d->app_user_id)<a href="{{ route('org.apps.users.show', [$organization, $app, $d->app_user_id]) }}">#{{ $d->app_user_id }}</a>@else<span class="muted">未ログイン</span>@endif</td>
                <td>{{ $d->platform->value }} {{ $d->os_version }} / {{ $d->app_version }}</td>
                <td>{{ $d->push_permission->value }}{{ $d->fcm_token ? '' : '（トークンなし）' }}</td>
                <td>{{ eevee_dt($d->last_active_at) }}</td>
                <td>{!! $d->invalidated_at ? '<span class="badge badge-danger">無効</span> <span class="muted">'.e($d->invalidated_reason).'</span>' : '<span class="badge badge-ok">有効</span>' !!}</td>
                <td>
                    @can('edit-content')
                        <form method="post" action="{{ route('org.apps.devices.test', [$organization, $app, $d]) }}" class="row">
                            @csrf @method('put')
                            <input type="hidden" name="is_test_device" value="{{ $d->is_test_device ? 0 : 1 }}">
                            @unless ($d->is_test_device)<input type="text" name="test_label" placeholder="ラベル" style="max-width:110px">@else<span class="badge">{{ $d->test_label ?: 'テスト' }}</span>@endunless
                            <button class="btn btn-sm">{{ $d->is_test_device ? '解除' : '登録' }}</button>
                        </form>
                    @else
                        {{ $d->is_test_device ? ($d->test_label ?: 'テスト') : '' }}
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">端末がありません。</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $devices->links() }}</div>
</div>
@endsection
