@extends('layouts.org')
@section('title', '監査ログ')
@section('content')
<h1>監査ログ</h1>
<form method="get" class="row" style="margin-bottom:12px">
    <input type="text" name="action" value="{{ request('action') }}" placeholder="操作で絞り込み（例: push.）" style="max-width:260px">
    <button class="btn">絞り込み</button>
</form>
<div class="card"><x-audit-table :logs="$logs" /></div>
@endsection
