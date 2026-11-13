{{-- アプリごとのメニュー（アプリ名のブロックの中に、役割ごとのグループで並べる） --}}
<div class="app-block">
    <div class="app-block-head">
        <div class="app-avatar">{{ mb_substr($app->name, 0, 1) }}</div>
        <div class="app-block-title">
            <div class="app-block-name" title="{{ $app->name }}">{{ $app->name }}</div>
            <div class="app-block-key">{{ $app->app_key }}</div>
        </div>
    </div>
    @if (isset($orgApps) && $orgApps->count() > 1)
        <select class="app-switch" aria-label="アプリを切り替え" onchange="if (this.value) location.href = this.value">
            @foreach ($orgApps as $a)
                <option value="{{ route('org.apps.show', [$org, $a]) }}" @selected($a->id === $app->id)>{{ $a->name }}</option>
            @endforeach
        </select>
    @endif

    <x-nav-link :href="route('org.apps.show', [$org, $app])" icon="chart" :active="request()->routeIs('org.apps.show')">概要</x-nav-link>

    <div class="nav-group">
        <div class="nav-group-title">コンテンツ</div>
        <div class="nav-group-items">
            <x-nav-link :href="route('org.apps.notices.index', [$org, $app])" icon="megaphone" :sub="true" :active="request()->routeIs('org.apps.notices.*')">お知らせ</x-nav-link>
            <x-nav-link :href="route('org.apps.videos.index', [$org, $app])" icon="video" :sub="true" :active="request()->routeIs('org.apps.videos.*')">動画</x-nav-link>
        </div>
    </div>

    <div class="nav-group">
        <div class="nav-group-title">配信</div>
        <div class="nav-group-items">
            <x-nav-link :href="route('org.apps.campaigns.index', [$org, $app])" icon="send" :sub="true" :active="request()->routeIs('org.apps.campaigns.*')">プッシュ配信</x-nav-link>
            <x-nav-link :href="route('org.apps.segments.index', [$org, $app])" icon="filter" :sub="true" :active="request()->routeIs('org.apps.segments.*')">セグメント</x-nav-link>
        </div>
    </div>

    <div class="nav-group">
        <div class="nav-group-title">ユーザー</div>
        <div class="nav-group-items">
            <x-nav-link :href="route('org.apps.users.index', [$org, $app])" icon="users" :sub="true" :active="request()->routeIs('org.apps.users.*')">アプリユーザー</x-nav-link>
            <x-nav-link :href="route('org.apps.devices.index', [$org, $app])" icon="phone" :sub="true" :active="request()->routeIs('org.apps.devices.*')">端末</x-nav-link>
            <x-nav-link :href="route('org.apps.billing.index', [$org, $app])" icon="card" :sub="true" :active="request()->routeIs('org.apps.billing.*')">課金プラン</x-nav-link>
        </div>
    </div>

    <div class="nav-group">
        <div class="nav-group-title">設定</div>
        <div class="nav-group-items">
            <x-nav-link :href="route('org.apps.attributes.index', [$org, $app])" icon="tag" :sub="true" :active="request()->routeIs('org.apps.attributes.*')">属性の定義</x-nav-link>
            <x-nav-link :href="route('org.apps.categories.index', [$org, $app])" icon="bell" :sub="true" :active="request()->routeIs('org.apps.categories.*')">通知カテゴリ</x-nav-link>
            @can('manage-apps')
                <x-nav-link :href="route('org.apps.settings', [$org, $app])" icon="cog" :sub="true" :active="request()->routeIs('org.apps.settings')">アプリ設定</x-nav-link>
            @endcan
        </div>
    </div>
</div>
