@extends('layouts.org')
@section('title', 'アプリユーザー #'.$user->id)
@section('content')
<h1>アプリユーザー #{{ $user->id }}</h1>
<div class="card">
    <table>
        <tr><th style="width:200px">メール</th><td>{{ $user->email ?? '—' }}</td></tr>
        <tr><th>外部ユーザーID</th><td class="mono">{{ $user->external_user_id ?? '—' }}</td></tr>
        <tr><th>名前</th><td>{{ $user->name ?? '—' }}</td></tr>
        <tr><th>最終ログイン</th><td>{{ eevee_dt($user->last_login_at) }}</td></tr>
    </table>
</div>
<h2>属性</h2>
<div class="card">
    <table>
        <tr><th>キー</th><th>表示名</th><th>値</th></tr>
        @forelse ($user->custom_attributes ?? [] as $key => $value)
            <tr><td class="mono">{{ $key }}</td><td>{{ $definitions[$key]->label ?? '（定義なし）' }}</td><td class="mono">{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">属性がありません。</td></tr>
        @endforelse
    </table>
</div>
<h2>通知カテゴリ</h2>
<div class="card">
    @foreach ($categories as $cat)
        <span @class(['badge', 'badge-muted' => ! $user->isCategoryEnabled($cat)])>{{ $cat->label }}: {{ $user->isCategoryEnabled($cat) ? 'オン' : 'オフ' }}</span>
    @endforeach
</div>
<h2>端末</h2>
<div class="card">
    <table>
        <tr><th>installation_id</th><th>OS</th><th>アプリ</th><th>通知</th><th>最終アクティブ</th><th>状態</th></tr>
        @foreach ($user->devices as $d)
            <tr><td class="mono">{{ $d->installation_id }}</td><td>{{ $d->platform->value }} {{ $d->os_version }}</td><td>{{ $d->app_version }}</td><td>{{ $d->push_permission->value }}</td><td>{{ eevee_dt($d->last_active_at) }}</td><td>{!! $d->invalidated_at ? '<span class="badge badge-danger">無効</span>' : '<span class="badge badge-ok">有効</span>' !!}</td></tr>
        @endforeach
    </table>
</div>
@endsection
