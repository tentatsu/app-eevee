@extends('layouts.system')
@section('title', 'IP許可リスト')
@section('content')
<h1>システム管理画面のIP許可リスト</h1>
<div class="card">
    <p>.env の <span class="mono">SYSTEM_IP_ALLOWLIST</span> とここで設定したリストを合算して判定します。両方とも空なら制限なし。</p>
    <p>.env の設定: @forelse ($envRanges as $r)<span class="badge mono">{{ $r }}</span> @empty<span class="muted">なし</span>@endforelse</p>
    <x-ip-allowlist-form :action="route('system.ip-allowlist.update')" :input="$input" :current-ip="$currentIp" />
</div>
@endsection
