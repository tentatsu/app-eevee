@extends('layouts.org')
@section('title', 'プッシュ配信')
@section('content')
<div class="row" style="justify-content:space-between"><h1>プッシュ配信</h1>
@can('edit-content')<a class="btn btn-primary" href="{{ route('org.apps.campaigns.create', [$organization, $app]) }}">プッシュを作成</a>@endcan
</div>
<div class="card">
    <table>
        <tr><th>タイトル</th><th>対象</th><th>状態</th><th>送信日時</th><th>対象</th><th>成功</th><th>失敗</th><th>無効</th><th>開封（率）</th></tr>
        @forelse ($campaigns as $c)
            <tr>
                <td><a href="{{ route('org.apps.campaigns.show', [$organization, $app, $c]) }}">{{ $c->title }}</a></td>
                <td>{{ $c->target_type->label() }}</td>
                <td><span class="badge @if($c->status->value==='sent') badge-ok @elseif($c->status->value==='draft') badge-muted @endif">{{ $c->status->label() }}</span></td>
                <td>{{ eevee_dt($c->started_at ?? $c->scheduled_at) }}</td>
                <td>{{ number_format($c->target_count) }}</td>
                <td>{{ number_format($c->success_count) }}</td>
                <td>{{ number_format($c->failure_count) }}</td>
                <td>{{ number_format($c->invalid_token_count) }}</td>
                <td>{{ number_format($c->open_count) }}{{ $c->openRate() !== null ? '（'.$c->openRate().'%）' : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">まだプッシュがありません。</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $campaigns->links() }}</div>
</div>
@endsection
