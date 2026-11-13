@extends('layouts.system')
@section('title', '利用量')
@section('content')
<div class="row" style="justify-content:space-between">
    <h1>利用量（{{ $start->format('Y年n月') }}）</h1>
    <form method="get"><select name="month" onchange="this.form.submit()" style="width:auto">
        @foreach ($months as $m)<option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $start->format('Y-m'))>{{ $m->format('Y年n月') }}</option>@endforeach
    </select></form>
</div>
<p class="muted">共用のアカウントの費用を顧客に請求するときの目安です。動画の保存時間は現時点の値、視聴時間とプッシュの送信数はその月の値です。視聴時間は app-eevee のハートビートから計算した値で、Cloudflare の請求（配信時間）とは一致しないことがあります。</p>
<div class="card">
    <table>
        <tr><th>組織</th><th>アプリ</th><th>動画アカウント</th><th>動画の本数</th><th>保存時間（分）</th><th>再生回数</th><th>視聴時間（分）</th><th>プッシュ送信数</th></tr>
        @foreach ($rows as $r)
            <tr>
                <td>{{ $r['app']->organization?->name }}</td>
                <td>{{ $r['app']->name }}</td>
                <td>{!! $r['app']->videoProviderAccount ? '<span class="badge">共用: '.e($r['app']->videoProviderAccount->name).'</span>' : '<span class="badge badge-muted">アプリ専用</span>' !!}</td>
                <td>{{ number_format($r['videos']) }}</td>
                <td>{{ number_format($r['stored_minutes']) }}</td>
                <td>{{ number_format($r['plays']) }}</td>
                <td>{{ number_format($r['watched_minutes']) }}</td>
                <td>{{ number_format($r['pushes']) }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endsection
