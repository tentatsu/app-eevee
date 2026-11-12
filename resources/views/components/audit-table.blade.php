@props(['logs', 'showOrg' => false])
<table>
    <tr><th>日時</th>@if($showOrg)<th>組織</th>@endif<th>操作者</th><th>操作</th><th>対象</th><th>内容</th><th>IP</th></tr>
    @foreach ($logs as $log)
        <tr>
            <td style="white-space:nowrap">{{ eevee_dt($log->created_at, 'Y-m-d H:i:s') }}</td>
            @if($showOrg)<td>{{ $log->organization?->slug ?? '—' }}</td>@endif
            <td>{{ $log->actor_name ?? $log->actor_type }}<div class="muted">{{ $log->actor_type }}</div></td>
            <td class="mono">{{ $log->action }}</td>
            <td class="mono">{{ $log->subject_type }}{{ $log->subject_id ? '#'.$log->subject_id : '' }}</td>
            <td>{{ $log->summary }}
                @if ($log->changes)
                    <details><summary class="muted">変更内容</summary><pre class="mono" style="white-space:pre-wrap">{{ json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                @endif
            </td>
            <td class="mono">{{ $log->ip_address }}</td>
        </tr>
    @endforeach
</table>
<div class="pagination">{{ $logs->links() }}</div>
