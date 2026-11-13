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
        <x-nav-link :href="route('org.dashboard', $org)" icon="home" :active="request()->routeIs('org.dashboard')">ダッシュボード</x-nav-link>
        <x-nav-link :href="route('org.apps.index', $org)" icon="apps" :active="request()->routeIs('org.apps.index', 'org.apps.create')">アプリ一覧</x-nav-link>

        @isset($currentApp)
            @include('org.partials.app-nav', ['org' => $org, 'app' => $currentApp])
        @endisset

        @if (Gate::check('manage-organization') || Gate::check('view-audit-logs'))
            <div class="nav-section">組織</div>
            @can('manage-organization')
                <x-nav-link :href="route('org.members.index', $org)" icon="team" :active="request()->routeIs('org.members.*')">メンバー</x-nav-link>
                <x-nav-link :href="route('org.ip-allowlist.edit', $org)" icon="shield" :active="request()->routeIs('org.ip-allowlist.*')">IP制限</x-nav-link>
            @endcan
            @can('view-audit-logs')
                <x-nav-link :href="route('org.audit-logs', $org)" icon="doc" :active="request()->routeIs('org.audit-logs')">監査ログ</x-nav-link>
            @endcan
        @endif
    </nav>
    <main class="main">
        <x-flash />
        @yield('content')
    </main>
</div>
@endsection
