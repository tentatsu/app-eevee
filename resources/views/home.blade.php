@extends('layouts.base')
@section('title', config('app.name'))
@section('body')
<div class="login-box card">
    <h1>{{ config('app.name') }}</h1>
    <p>組織の管理画面には、組織ごとのURL（例: <span class="mono">/oneframe/login</span>）からログインしてください。</p>
</div>
@endsection
