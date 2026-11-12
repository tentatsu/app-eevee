@extends('layouts.org')
@section('title', 'アプリを作成')
@section('content')
<h1>アプリを作成</h1>
<div class="card">
    <form method="post" action="{{ route('org.apps.store', $organization) }}">
        @csrf
        <div class="form-row"><label>名前</label><input type="text" name="name" value="{{ old('name') }}" required></div>
        <div class="form-row"><label>アプリユーザーの認証方式</label>
            <select name="auth_mode">
                @foreach (\App\Enums\AuthMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected(old('auth_mode') === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-primary">作成</button>
    </form>
</div>
@endsection
