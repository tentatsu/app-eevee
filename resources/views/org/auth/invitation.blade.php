@extends('layouts.base')
@section('title', '招待の受け入れ')
@section('body')
<div class="login-box">
    <div class="card">
        <h1>{{ $organization->name }} への招待</h1>
        <p><span class="mono">{{ $invitation->email }}</span> が <span class="badge">{{ $invitation->role->label() }}</span> として招待されています。</p>
        <x-flash />
        <form method="post" action="{{ route('org.invitations.accept', [$organization, $token]) }}">
            @csrf
            @if ($existing)
                <p class="muted">既にアカウントをお持ちです。パスワードを入力して参加してください。</p>
                <div class="form-row"><label>パスワード</label><input type="password" name="password" required></div>
            @else
                <div class="form-row"><label>お名前</label><input type="text" name="name" value="{{ old('name') }}" required></div>
                <div class="form-row"><label>パスワード（8文字以上）</label><input type="password" name="password" required></div>
                <div class="form-row"><label>パスワード（確認）</label><input type="password" name="password_confirmation" required></div>
            @endif
            <button class="btn btn-primary">参加する</button>
        </form>
    </div>
</div>
@endsection
