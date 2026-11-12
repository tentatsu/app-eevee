@extends('layouts.base')
@section('title', $organization->name.' ログイン')
@section('body')
<div class="login-box">
    <div class="card">
        <h1>{{ $organization->name }}</h1>
        <p class="muted">管理画面にログイン</p>
        <x-flash />
        <form method="post" action="{{ route('org.login', $organization) }}">
            @csrf
            <div class="form-row"><label>メールアドレス</label><input type="email" name="email" value="{{ old('email') }}" required autofocus></div>
            <div class="form-row"><label>パスワード</label><input type="password" name="password" required></div>
            <div class="form-row"><label><input type="checkbox" name="remember" value="1"> ログイン状態を保持</label></div>
            <button class="btn btn-primary">ログイン</button>
        </form>
    </div>
</div>
@endsection
