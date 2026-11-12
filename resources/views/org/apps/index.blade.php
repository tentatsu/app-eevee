@extends('layouts.org')
@section('title', 'アプリ')
@section('content')
<div class="row" style="justify-content:space-between"><h1>アプリ</h1>
@can('manage-apps')<a class="btn btn-primary" href="{{ route('org.apps.create', $organization) }}">アプリを作成</a>@endcan
</div>
<div class="card">
    <table>
        <tr><th>名前</th><th>app_key</th><th>認証方式</th><th>ユーザー</th><th>端末</th></tr>
        @forelse ($apps as $app)
            <tr>
                <td><a href="{{ route('org.apps.show', [$organization, $app]) }}">{{ $app->name }}</a></td>
                <td class="mono">{{ $app->app_key }}</td>
                <td>{{ $app->auth_mode->value }}</td>
                <td>{{ number_format($app->app_users_count) }}</td>
                <td>{{ number_format($app->devices_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">まだアプリがありません。</td></tr>
        @endforelse
    </table>
</div>
@endsection
