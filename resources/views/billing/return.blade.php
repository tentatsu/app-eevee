@extends('layouts.base')
@section('title', $ok ? 'お手続きが完了しました' : 'お手続きを中止しました')
@section('body')
<div class="login-box card" style="text-align:center">
    <h1>{{ $ok ? 'お手続きが完了しました' : 'お手続きを中止しました' }}</h1>
    <p>{{ $ok ? 'この画面を閉じて、アプリに戻ってください。反映まで数秒かかることがあります。' : '料金は発生していません。この画面を閉じて、アプリに戻ってください。' }}</p>
</div>
@endsection
