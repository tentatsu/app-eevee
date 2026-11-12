@extends('layouts.org')
@section('title', '動画')
@section('content')
<div class="row" style="justify-content:space-between"><h1>動画</h1>
@can('edit-content')<a class="btn btn-primary" href="{{ route('org.apps.videos.create', [$organization, $app]) }}">動画を追加</a>@endcan
</div>
<div class="card">
    <table>
        <tr><th>タイトル</th><th>状態</th><th>公開範囲</th><th>長さ</th><th>並び順</th><th>再生回数</th><th>視聴時間</th><th>同時視聴の超過</th></tr>
        @forelse ($videos as $v)
            @php($s = $stats[$v->id] ?? ['plays' => 0, 'watched_seconds' => 0, 'limit_events' => 0])
            <tr>
                <td><a href="{{ route('org.apps.videos.edit', [$organization, $app, $v]) }}">{{ $v->title }}</a>@if($v->category)<div class="muted">{{ $v->category }}</div>@endif</td>
                <td><span @class(['badge', 'badge-ok' => $v->status->value === 'ready', 'badge-danger' => $v->status->value === 'error', 'badge-muted' => in_array($v->status->value, ['pending_upload', 'processing'])])>{{ $v->status->label() }}</span></td>
                <td>{{ $v->visibility->label() }}</td>
                <td>{{ $v->duration_seconds ? gmdate($v->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $v->duration_seconds) : '—' }}</td>
                <td>{{ $v->sort_order }}</td>
                <td>{{ number_format($s['plays']) }}</td>
                <td>{{ number_format(intdiv($s['watched_seconds'], 60)) }} 分</td>
                <td>{{ number_format($s['limit_events']) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">まだ動画がありません。</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $videos->links() }}</div>
</div>
@endsection
