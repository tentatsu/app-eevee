@extends('layouts.system')
@section('title', $organization->exists ? '組織の編集' : '組織の作成')
@section('content')
<h1>{{ $organization->exists ? '組織の編集' : '組織の作成' }}</h1>
<div class="card">
    <form method="post" action="{{ $organization->exists ? route('system.organizations.update', $organization) : route('system.organizations.store') }}">
        @csrf
        @if ($organization->exists) @method('put') @endif
        <div class="form-row">
            <label>slug（URLに使う）</label>
            @if ($organization->exists)
                <div class="mono">{{ $organization->slug }}</div>
            @else
                <input type="text" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9-]{3,32}">
                <div class="hint">英小文字・数字・ハイフン、3〜32文字。作成後は変更できません。</div>
            @endif
        </div>
        <div class="form-row"><label>名前</label><input type="text" name="name" value="{{ old('name', $organization->name) }}" required></div>
        <div class="form-row"><label>タイムゾーン</label><input type="text" name="timezone" value="{{ old('timezone', $organization->timezone) }}" required></div>
        <button class="btn btn-primary">保存</button>
    </form>
</div>
@endsection
