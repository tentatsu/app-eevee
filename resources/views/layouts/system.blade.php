@extends('layouts.base')

@section('body')
<div class="topbar">
    <a class="brand" href="{{ route('system.dashboard') }}">{{ config('app.name') }} / システム管理</a>
    <span class="spacer"></span>
    <span>{{ auth('system')->user()?->name }}</span>
    <form method="post" action="{{ route('system.logout') }}">@csrf<button class="btn btn-sm">ログアウト</button></form>
</div>
<div class="layout">
    <nav class="sidebar">
        <x-nav-link :href="route('system.dashboard')" icon="building" :active="request()->routeIs('system.dashboard', 'system.organizations.*')">組織</x-nav-link>
        <x-nav-link :href="route('system.ip-allowlist.edit')" icon="shield" :active="request()->routeIs('system.ip-allowlist.*')">IP許可リスト</x-nav-link>
        <x-nav-link :href="route('system.usage')" icon="chart" :active="request()->routeIs('system.usage')">利用量</x-nav-link>
        <x-nav-link :href="route('system.audit-logs')" icon="doc" :active="request()->routeIs('system.audit-logs')">監査ログ</x-nav-link>
        <div class="nav-section">共用のアカウント</div>
        <x-nav-link :href="route('system.billing.edit')" icon="card" :active="request()->routeIs('system.billing.*')">課金（Stripe）</x-nav-link>
        <x-nav-link :href="route('system.provider-accounts.index')" icon="video" :active="request()->routeIs('system.provider-accounts.*')">動画（Cloudflare）</x-nav-link>
        <div class="nav-section">ツール</div>
        @if (config('eevee.horizon.enabled'))
            <x-nav-link :href="url('/horizon')" icon="queue">Horizon（キュー）</x-nav-link>
        @endif
        <x-nav-link :href="url('/docs/api')" icon="list">API仕様書</x-nav-link>
    </nav>
    <main class="main">
        <x-flash />
        @yield('content')
    </main>
</div>
@endsection
