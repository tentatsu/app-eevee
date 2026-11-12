@extends('layouts.org')
@section('title', $notice->exists ? 'お知らせの編集' : 'お知らせの作成')
@section('content')
<div class="row" style="justify-content:space-between">
    <h1>{{ $notice->exists ? 'お知らせの編集' : 'お知らせの作成' }}</h1>
    @if ($notice->exists)
        @can('edit-content')
            <form method="post" action="{{ route('org.apps.notices.push', [$organization, $app, $notice]) }}">@csrf<button class="btn">このお知らせをプッシュ通知する</button></form>
        @endcan
    @endif
</div>
<div class="card">
    <form method="post" action="{{ $notice->exists ? route('org.apps.notices.update', [$organization, $app, $notice]) : route('org.apps.notices.store', [$organization, $app]) }}">
        @csrf @if ($notice->exists) @method('put') @endif
        <div class="form-row"><label>タイトル</label><input type="text" name="title" value="{{ old('title', $notice->title) }}" required></div>
        <div class="form-row"><label>本文（Markdown）</label><textarea name="body" rows="12" required>{{ old('body', $notice->body) }}</textarea>
            <div class="hint">アプリには Markdown と、変換した HTML（生の HTML はエスケープ）の両方を返します。</div></div>
        <div class="form-row"><label>サムネイル画像の URL</label><input type="url" name="thumbnail_url" value="{{ old('thumbnail_url', $notice->thumbnail_url) }}"></div>
        <div class="row">
            <div class="form-row"><label>公開開始（{{ $app->displayTimezone() }}）</label><input type="datetime-local" name="publish_start_at" value="{{ old('publish_start_at', eevee_input_dt($notice->publish_start_at)) }}"></div>
            <div class="form-row"><label>公開終了</label><input type="datetime-local" name="publish_end_at" value="{{ old('publish_end_at', eevee_input_dt($notice->publish_end_at)) }}"></div>
        </div>
        <div class="form-row"><label>対象</label>
            @foreach (\App\Enums\NoticeTarget::cases() as $t)
                <label style="display:inline;font-weight:normal;margin-right:12px"><input type="radio" name="target_type" value="{{ $t->value }}" @checked(old('target_type', $notice->target_type?->value) === $t->value)> {{ $t->label() }}</label>
            @endforeach
            <select name="segment_id" style="max-width:300px;margin-top:6px"><option value="">（セグメントを選択）</option>
                @foreach ($segments as $s)<option value="{{ $s->id }}" @selected((string) old('segment_id', $notice->segment_id) === (string) $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            <div class="hint">セグメント向けのお知らせは、ログインしていて条件に一致するユーザーにだけ表示されます。</div>
        </div>
        <div class="form-row"><label><input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $notice->is_pinned))> ピン留め（一覧の先頭に表示）</label></div>
        <div class="form-row"><label>状態</label>
            <select name="status" style="max-width:200px">
                @foreach (\App\Enums\NoticeStatus::cases() as $s)<option value="{{ $s->value }}" @selected(old('status', $notice->status?->value) === $s->value)>{{ $s->label() }}</option>@endforeach
            </select></div>
        @can('edit-content')<button class="btn btn-primary">保存</button>@endcan
    </form>
    @if ($notice->exists)
        @can('edit-content')
        <form method="post" action="{{ route('org.apps.notices.destroy', [$organization, $app, $notice]) }}" style="margin-top:10px" onsubmit="return confirm('削除しますか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">削除</button></form>
        @endcan
        <h2>プレビュー</h2>
        <div class="card" style="background:#fafafa">{!! $notice->bodyHtml() !!}</div>
    @endif
</div>
@endsection
