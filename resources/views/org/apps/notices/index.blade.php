@extends('layouts.org')
@section('title', 'お知らせ')
@section('content')
<div class="row" style="justify-content:space-between"><h1>お知らせ</h1>
@can('edit-content')<a class="btn btn-primary" href="{{ route('org.apps.notices.create', [$organization, $app]) }}">お知らせを作成</a>@endcan
</div>
<div class="card">
    <table>
        <tr><th>タイトル</th><th>状態</th><th>対象</th><th>公開期間</th><th>既読</th></tr>
        @forelse ($notices as $n)
            <tr>
                <td>@if($n->is_pinned)📌 @endif<a href="{{ route('org.apps.notices.edit', [$organization, $app, $n]) }}">{{ $n->title }}</a></td>
                <td><span @class(['badge', 'badge-ok' => $n->isVisible(), 'badge-muted' => ! $n->isVisible()])>{{ $n->status->label() }}{{ $n->status->value === 'published' && ! $n->isVisible() ? '（期間外）' : '' }}</span></td>
                <td>{{ $n->target_type->label() }}</td>
                <td>{{ eevee_dt($n->publish_start_at) }} 〜 {{ eevee_dt($n->publish_end_at) }}</td>
                <td>{{ number_format($n->reads_count) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">まだお知らせがありません。</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $notices->links() }}</div>
</div>
@endsection
