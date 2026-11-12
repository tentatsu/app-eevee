@extends('layouts.org')
@section('title', 'ダッシュボード')
@section('content')
<h1>ダッシュボード</h1>
<div class="card">
    <h2 style="margin-top:0">アプリ</h2>
    @if ($apps->isEmpty())
        <p class="muted">まだアプリがありません。</p>
    @else
        <table>
            <tr><th>名前</th><th>app_key</th><th>認証方式</th><th>ユーザー数</th><th>端末数</th></tr>
            @foreach ($apps as $app)
                <tr>
                    <td>@if (Route::has('org.apps.show'))<a href="{{ route('org.apps.show', [$organization, $app]) }}">{{ $app->name }}</a>@else{{ $app->name }}@endif</td>
                    <td class="mono">{{ $app->app_key }}</td>
                    <td>{{ $app->auth_mode->value }}</td>
                    <td>{{ number_format($app->app_users_count) }}</td>
                    <td>{{ number_format($app->devices_count) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
