@extends('layouts.system')
@section('title', '監査ログ')
@section('content')
<h1>監査ログ（全組織）</h1>
<div class="card"><x-audit-table :logs="$logs" :show-org="true" /></div>
@endsection
