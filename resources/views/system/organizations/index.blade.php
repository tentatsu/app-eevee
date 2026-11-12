@extends('layouts.system')
@section('title', '組織')
@section('content')
<div class="row" style="justify-content:space-between"><h1>組織</h1><a class="btn btn-primary" href="{{ route('system.organizations.create') }}">組織を作成</a></div>
<div class="card">
    <table>
        <tr><th>slug</th><th>名前</th><th>状態</th><th>アプリ</th><th>メンバー</th><th>ログインURL</th></tr>
        @foreach ($organizations as $o)
            <tr>
                <td><a href="{{ route('system.organizations.show', $o) }}" class="mono">{{ $o->slug }}</a></td>
                <td>{{ $o->name }}</td>
                <td><span @class(['badge', 'badge-danger' => ! $o->isActive()])>{{ $o->status->label() }}</span></td>
                <td>{{ $o->apps_count }}</td>
                <td>{{ $o->members_count }}</td>
                <td class="mono"><a href="{{ url($o->slug.'/login') }}">/{{ $o->slug }}/login</a></td>
            </tr>
        @endforeach
    </table>
</div>
@endsection
