@extends('layouts.base')
@section('title', 'システム管理 ログイン')
@section('body')
<div class="login-box">
    <div class="card">
        <h1>システム管理</h1>
        <x-flash />
        <form method="post" action="{{ route('system.login') }}">
            @csrf
            <div class="form-row"><label>メールアドレス</label><input type="email" name="email" value="{{ old('email') }}" required autofocus></div>
            <div class="form-row"><label>パスワード</label><input type="password" name="password" required></div>
            <div class="form-row"><label><input type="checkbox" name="remember" value="1"> ログイン状態を保持</label></div>
            <button class="btn btn-primary">ログイン</button>
        </form>
    </div>
</div>
@endsection
