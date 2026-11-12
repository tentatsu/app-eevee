@extends('layouts.org')
@section('title', $video->exists ? $video->title : '動画を追加')
@section('content')
<h1>{{ $video->exists ? $video->title : '動画を追加' }}
    @if ($video->exists)<span class="badge">{{ $video->status->label() }}</span>@endif</h1>

@if ($video->exists && in_array($video->status->value, ['pending_upload', 'error']))
    @can('edit-content')
    <div class="card" id="uploader"
         data-upload-url="{{ session('upload_url') }}"
         data-renew-url="{{ route('org.apps.videos.upload-url', [$organization, $app, $video]) }}"
         data-done-url="{{ route('org.apps.videos.uploaded', [$organization, $app, $video]) }}">
        <h2 style="margin-top:0">動画ファイルのアップロード</h2>
        <p class="muted">ファイルはブラウザから Cloudflare Stream に直接送られます（app-eevee のサーバーは経由しません）。この方式（Direct Creator Upload の基本アップロード）は 200MB までです。それより大きいファイルは tus 方式が必要です（docs/manual-verification.md 参照）。</p>
        @if ($video->status_message)<div class="alert alert-error">{{ $video->status_message }}</div>@endif
        <input type="file" accept="video/*" id="video-file">
        <button type="button" class="btn btn-primary" id="upload-btn">アップロード</button>
        <div id="upload-progress" class="muted" style="margin-top:8px"></div>
    </div>
    @push('scripts')
    <script>
    (function () {
      const box = document.getElementById('uploader');
      const csrf = document.querySelector('meta[name=csrf-token]').content;
      const post = (url) => fetch(url, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}}).then(r => r.json());
      document.getElementById('upload-btn').addEventListener('click', async () => {
        const file = document.getElementById('video-file').files[0];
        const out = document.getElementById('upload-progress');
        if (!file) { out.textContent = 'ファイルを選んでください。'; return; }
        let url = box.dataset.uploadUrl;
        if (!url) { url = (await post(box.dataset.renewUrl)).upload_url; }
        const form = new FormData(); form.append('file', file);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.upload.onprogress = e => { if (e.lengthComputable) out.textContent = `アップロード中… ${Math.round(e.loaded / e.total * 100)}%`; };
        xhr.onload = async () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            await post(box.dataset.doneUrl);
            out.textContent = 'アップロードが完了しました。変換が終わると「再生可能」になります（Webhook で自動更新）。';
            setTimeout(() => location.reload(), 2000);
          } else {
            out.textContent = 'アップロードに失敗しました（HTTP ' + xhr.status + '）。もう一度お試しください。';
            box.dataset.uploadUrl = '';
          }
        };
        xhr.onerror = () => { out.textContent = '通信エラーが発生しました。'; box.dataset.uploadUrl = ''; };
        xhr.send(form);
      });
    })();
    </script>
    @endpush
    @endcan
@endif

@if ($video->exists)
<div class="stats" style="margin-bottom:18px">
    <div class="stat"><div class="v">{{ number_format($stats['plays'] ?? 0) }}</div><div class="l">再生回数</div></div>
    <div class="stat"><div class="v">{{ number_format(intdiv($stats['watched_seconds'] ?? 0, 60)) }} 分</div><div class="l">視聴時間</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['viewers'] ?? 0) }}</div><div class="l">視聴者数</div></div>
    <div class="stat"><div class="v">{{ number_format($stats['limit_events'] ?? 0) }}</div><div class="l">同時視聴の超過件数</div></div>
    <div class="stat"><div class="v">{{ $video->duration_seconds ? gmdate('H:i:s', $video->duration_seconds) : '—' }}</div><div class="l">長さ</div></div>
</div>
<div class="row" style="margin-bottom:14px">
    <span class="muted mono">{{ $video->provider }} / {{ $video->provider_video_id }}</span>
    <form method="post" action="{{ route('org.apps.videos.refresh', [$organization, $app, $video]) }}">@csrf<button class="btn btn-sm">プロバイダから状態を取得</button></form>
</div>
@endif

<div class="card">
    <form method="post" action="{{ $video->exists ? route('org.apps.videos.update', [$organization, $app, $video]) : route('org.apps.videos.store', [$organization, $app]) }}">
        @csrf @if ($video->exists) @method('put') @endif
        <div class="form-row"><label>タイトル</label><input type="text" name="title" value="{{ old('title', $video->title) }}" required></div>
        <div class="form-row"><label>説明</label><textarea name="description" rows="4">{{ old('description', $video->description) }}</textarea></div>
        <div class="form-row"><label>サムネイル画像の URL（空欄なら変換後に Cloudflare のサムネイルを使います）</label><input type="url" name="thumbnail_url" value="{{ old('thumbnail_url', $video->thumbnail_url) }}"></div>
        <div class="form-row"><label>公開範囲</label>
            <select name="visibility" style="max-width:320px">
                @foreach (\App\Enums\VideoVisibility::cases() as $v)<option value="{{ $v->value }}" @selected(old('visibility', $video->visibility?->value) === $v->value)>{{ $v->label() }}</option>@endforeach
            </select></div>
        <div class="row">
            <div class="form-row"><label>公開開始（{{ $app->displayTimezone() }}）</label><input type="datetime-local" name="publish_start_at" value="{{ old('publish_start_at', eevee_input_dt($video->publish_start_at)) }}"></div>
            <div class="form-row"><label>公開終了</label><input type="datetime-local" name="publish_end_at" value="{{ old('publish_end_at', eevee_input_dt($video->publish_end_at)) }}"></div>
        </div>
        <div class="row">
            <div class="form-row"><label>並び順（小さいほど先頭）</label><input type="number" name="sort_order" value="{{ old('sort_order', $video->sort_order) }}" style="max-width:140px"></div>
            <div class="form-row"><label>カテゴリ</label><input type="text" name="category" value="{{ old('category', $video->category) }}" style="max-width:200px"></div>
            <div class="form-row"><label>タグ（カンマ区切り）</label><input type="text" name="tags" value="{{ old('tags', implode(',', $video->tags ?? [])) }}" style="max-width:300px"></div>
        </div>
        @can('edit-content')<button class="btn btn-primary">{{ $video->exists ? '保存' : '保存してアップロードへ進む' }}</button>@endcan
    </form>
    @if ($video->exists)
        @can('edit-content')
        <form method="post" action="{{ route('org.apps.videos.destroy', [$organization, $app, $video]) }}" style="margin-top:10px" onsubmit="return confirm('Cloudflare 側の動画も削除します。よろしいですか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">削除</button></form>
        @endcan
    @endif
</div>

@if ($video->exists && isset($recent) && $recent->isNotEmpty())
<h2>最近の視聴セッション</h2>
<div class="card"><table>
    <tr><th>開始</th><th>視聴者</th><th>視聴秒数</th><th>終了</th><th>終了理由</th></tr>
    @foreach ($recent as $s)
        <tr><td>{{ eevee_dt($s->started_at, 'm-d H:i:s') }}</td><td class="mono">{{ $s->viewer_key }}</td><td>{{ $s->watched_seconds }}</td><td>{{ eevee_dt($s->ended_at, 'm-d H:i:s') }}</td><td>{{ $s->end_reason ?? '再生中' }}</td></tr>
    @endforeach
</table></div>
@endif
@endsection
