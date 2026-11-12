@extends('layouts.base')

@section('body')
<div class="topbar">
    <a class="brand" href="{{ route('system.dashboard') }}">app-eevee / システム管理</a>
    <span class="spacer"></span>
    <span>{{ auth('system')->user()?->name }}</span>
    <form method="post" action="{{ route('system.logout') }}">@csrf<button class="btn btn-sm">ログアウト</button></form>
</div>
<div class="layout">
    <nav class="sidebar">
        <a href="{{ route('system.dashboard') }}" @class(['active' => request()->routeIs('system.dashboard', 'system.organizations.*')])>組織</a>
        <a href="{{ route('system.ip-allowlist.edit') }}" @class(['active' => request()->routeIs('system.ip-allowlist.*')])>IP許可リスト</a>
        <a href="{{ route('system.audit-logs') }}" @class(['active' => request()->routeIs('system.audit-logs')])>監査ログ</a>
        <a href="{{ url('/horizon') }}">Horizon（キュー）</a>
        <a href="{{ url('/docs/api') }}">API仕様書</a>
    </nav>
    <main class="main">
        <x-flash />
        @yield('content')
    </main>
</div>
@endsection
