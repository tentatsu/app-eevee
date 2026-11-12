@extends('layouts.org')
@section('title', $campaign->exists ? 'プッシュの編集' : 'プッシュの作成')
@section('content')
<h1>{{ $campaign->exists ? 'プッシュの編集' : 'プッシュの作成' }}</h1>
@php
    $dataText = old('data', collect($campaign->data ?? [])->map(fn ($v, $k) => "$k=$v")->implode("\n"));
    $target = old('target_type', $campaign->target_type?->value ?? 'all');
@endphp
<div class="card">
    <form method="post" action="{{ $campaign->exists ? route('org.apps.campaigns.update', [$organization, $app, $campaign]) : route('org.apps.campaigns.store', [$organization, $app]) }}">
        @csrf @if ($campaign->exists) @method('put') @endif
        <div class="form-row"><label>タイトル</label><input type="text" name="title" value="{{ old('title', $campaign->title) }}" required maxlength="191"></div>
        <div class="form-row"><label>本文</label><textarea name="body" rows="4" required>{{ old('body', $campaign->body) }}</textarea></div>
        <div class="form-row"><label>画像URL（任意、https）</label><input type="url" name="image_url" value="{{ old('image_url', $campaign->image_url) }}"></div>
        <div class="form-row"><label>data（任意。1行に「キー=値」。ディープリンク用）</label><textarea name="data" rows="3" placeholder="type=notice&#10;id=123">{{ $dataText }}</textarea>
            <div class="hint">アプリには data に加えて <span class="mono">eevee_campaign_id</span> が届きます（開封記録APIに使います）。</div></div>
        <div class="form-row"><label>通知カテゴリ（任意。オフにしているユーザーには送りません）</label>
            <select name="notification_category_id"><option value="">指定しない</option>
                @foreach ($categories as $cat)<option value="{{ $cat->id }}" @selected((string) old('notification_category_id', $campaign->notification_category_id) === (string) $cat->id)>{{ $cat->label }}</option>@endforeach
            </select></div>
        <div class="form-row"><label>対象</label>
            @foreach (\App\Enums\CampaignTarget::cases() as $t)
                <label style="display:inline;font-weight:normal;margin-right:12px"><input type="radio" name="target_type" value="{{ $t->value }}" @checked($target === $t->value)> {{ $t->label() }}</label>
            @endforeach
        </div>
        <div class="form-row"><label>セグメント（対象がセグメントのとき）</label>
            <select name="segment_id"><option value="">選択してください</option>
                @foreach ($segments as $s)<option value="{{ $s->id }}" @selected((string) old('segment_id', $campaign->segment_id) === (string) $s->id)>{{ $s->name }}</option>@endforeach
            </select></div>
        <div class="form-row"><label>アプリユーザーID（対象がユーザーID指定のとき。カンマ・改行区切り）</label>
            <textarea name="target_user_ids" rows="2">{{ old('target_user_ids', implode(',', $campaign->target_user_ids ?? [])) }}</textarea></div>
        <div class="form-row"><label>送信日時（空欄なら「送信」ボタンで即時送信。{{ $app->displayTimezone() }}）</label>
            <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', eevee_input_dt($campaign->scheduled_at)) }}" style="max-width:260px"></div>
        <button class="btn btn-primary">下書きを保存</button>
    </form>
</div>
@endsection
