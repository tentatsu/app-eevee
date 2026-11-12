@extends('layouts.org')
@section('title', 'セグメント')
@section('content')
<div class="row" style="justify-content:space-between"><h1>セグメント</h1>
@can('edit-content')<a class="btn btn-primary" href="{{ route('org.apps.segments.create', [$organization, $app]) }}">セグメントを作成</a>@endcan
</div>
<div class="card">
    <table>
        <tr><th>名前</th><th>説明</th><th>更新日時</th></tr>
        @forelse ($segments as $s)
            <tr><td><a href="{{ route('org.apps.segments.edit', [$organization, $app, $s]) }}">{{ $s->name }}</a></td><td>{{ $s->description }}</td><td>{{ eevee_dt($s->updated_at) }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">まだセグメントがありません。</td></tr>
        @endforelse
    </table>
</div>
@endsection
