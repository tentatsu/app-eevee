@extends('layouts.base')

@section('body')
@php($org = $currentOrganization)
<div class="topbar">
    <a class="brand" href="{{ route('org.dashboard', $org) }}">{{ $org->name }}</a>
    @isset($currentApp)
        <span>›</span><a href="{{ route('org.apps.show', [$org, $currentApp]) }}">{{ $currentApp->name }}</a>
    @endisset
    <span class="spacer"></span>
    <span>{{ $currentAdmin->name }} <span class="badge">{{ $currentRole->label() }}</span></span>
    <form method="post" action="{{ route('org.logout', $org) }}">@csrf<button class="btn btn-sm">ログアウト</button></form>
</div>
<div class="layout">
    <nav class="sidebar">
        <a href="{{ route('org.dashboard', $org) }}" @class(['active' => request()->routeIs('org.dashboard')])>ダッシュボード</a>
        @if (Route::has('org.apps.index'))
            <a href="{{ route('org.apps.index', $org) }}" @class(['active' => request()->routeIs('org.apps.index', 'org.apps.create')])>アプリ</a>
        @endif
        @isset($currentApp)
            @include('org.partials.app-nav', ['org' => $org, 'app' => $currentApp])
        @endisset
        <h4>組織</h4>
        @can('manage-organization')
            <a href="{{ route('org.members.index', $org) }}" @class(['active' => request()->routeIs('org.members.*')])>メンバー</a>
            <a href="{{ route('org.ip-allowlist.edit', $org) }}" @class(['active' => request()->routeIs('org.ip-allowlist.*')])>IP制限</a>
        @endcan
        @can('view-audit-logs')
            <a href="{{ route('org.audit-logs', $org) }}" @class(['active' => request()->routeIs('org.audit-logs')])>監査ログ</a>
        @endcan
    </nav>
    <main class="main">
        <x-flash />
        @yield('content')
    </main>
</div>
@endsection
