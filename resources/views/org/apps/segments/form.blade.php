@extends('layouts.org')
@section('title', $segment->exists ? 'セグメントの編集' : 'セグメントの作成')
@section('content')
<h1>{{ $segment->exists ? 'セグメント: '.$segment->name : 'セグメントの作成' }}</h1>
<div class="card">
    <form method="post" action="{{ $segment->exists ? route('org.apps.segments.update', [$organization, $app, $segment]) : route('org.apps.segments.store', [$organization, $app]) }}">
        @csrf @if ($segment->exists) @method('put') @endif
        <div class="form-row"><label>名前</label><input type="text" name="name" value="{{ old('name', $segment->name) }}" required></div>
        <div class="form-row"><label>説明</label><input type="text" name="description" value="{{ old('description', $segment->description) }}"></div>
        <div class="form-row"><label>条件</label>
            <x-segment-builder :value="old('conditions') ? json_decode(old('conditions'), true) : $segment->conditions" :fields="$fields" :preview-url="route('org.apps.segments.preview', [$organization, $app])" />
            <div class="hint">日時はアプリの表示タイムゾーン（{{ $app->displayTimezone() }}）で入力します。属性の条件は、その属性の値を持つユーザーにだけ一致します（「≠」も値がないユーザーには一致しません）。未ログインの端末は属性の条件に一致しません。</div>
        </div>
        @can('edit-content')<button class="btn btn-primary">保存</button>@endcan
    </form>
    @if ($segment->exists)
        @can('edit-content')
        <form method="post" action="{{ route('org.apps.segments.destroy', [$organization, $app, $segment]) }}" style="margin-top:10px" onsubmit="return confirm('削除しますか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">削除</button></form>
        @endcan
    @endif
</div>
@endsection
