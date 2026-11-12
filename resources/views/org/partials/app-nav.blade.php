<h4>{{ $app->name }}</h4>
<a href="{{ route('org.apps.show', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.show')])>概要</a>
@foreach ([
    'segments' => 'セグメント',
    'campaigns' => 'プッシュ配信',
    'notices' => 'お知らせ',
    'videos' => '動画',
] as $key => $label)
    @if (Route::has("org.apps.$key.index"))
        <a href="{{ route("org.apps.$key.index", [$org, $app]) }}" @class(['active' => request()->routeIs("org.apps.$key.*")])>{{ $label }}</a>
    @endif
@endforeach
<a href="{{ route('org.apps.users.index', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.users.*')])>アプリユーザー</a>
<a href="{{ route('org.apps.devices.index', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.devices.*')])>端末</a>
<a href="{{ route('org.apps.attributes.index', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.attributes.*')])>属性の定義</a>
<a href="{{ route('org.apps.categories.index', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.categories.*')])>通知カテゴリ</a>
@can('manage-apps')
    <a href="{{ route('org.apps.settings', [$org, $app]) }}" @class(['active' => request()->routeIs('org.apps.settings')])>アプリ設定</a>
@endcan
