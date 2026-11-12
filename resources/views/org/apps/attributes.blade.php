@extends('layouts.org')
@section('title', '属性の定義')
@section('content')
<h1>属性の定義</h1>
<p class="muted">アプリユーザーの属性（JSON）のキーと型を定義します。セグメントの条件と属性更新APIのバリデーションに使います。「アプリから更新可」にした属性だけ、アプリが自分で更新できます。</p>
<div class="card">
    <table>
        <tr><th>キー</th><th>型</th><th>表示名</th><th>アプリから更新可</th><th>並び順</th><th></th></tr>
        @forelse ($definitions as $def)
            <tr>
                <td class="mono">{{ $def->key }}</td>
                <td>{{ $def->type->label() }}（{{ $def->type->value }}）</td>
                @can('manage-apps')
                    <td colspan="3">
                        <form method="post" action="{{ route('org.apps.attributes.update', [$organization, $app, $def]) }}" class="row">
                            @csrf @method('put')
                            <input type="text" name="label" value="{{ $def->label }}" style="max-width:200px">
                            <label><input type="checkbox" name="app_writable" value="1" @checked($def->app_writable)> 可</label>
                            <input type="number" name="sort_order" value="{{ $def->sort_order }}" style="max-width:80px">
                            <button class="btn btn-sm">更新</button>
                        </form>
                    </td>
                    <td><form method="post" action="{{ route('org.apps.attributes.destroy', [$organization, $app, $def]) }}" onsubmit="return confirm('削除しますか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">削除</button></form></td>
                @else
                    <td>{{ $def->label }}</td><td>{{ $def->app_writable ? '可' : '—' }}</td><td>{{ $def->sort_order }}</td><td></td>
                @endcan
            </tr>
        @empty
            <tr><td colspan="6" class="muted">まだ属性がありません。</td></tr>
        @endforelse
    </table>
</div>
@can('manage-apps')
<h2>属性を追加</h2>
<div class="card">
    <form method="post" action="{{ route('org.apps.attributes.store', [$organization, $app]) }}" class="row">
        @csrf
        <input type="text" name="key" placeholder="キー（例: birth_month）" required style="max-width:200px" value="{{ old('key') }}">
        <select name="type" style="width:auto">
            @foreach (\App\Enums\AttributeType::cases() as $t)<option value="{{ $t->value }}">{{ $t->label() }}（{{ $t->value }}）</option>@endforeach
        </select>
        <input type="text" name="label" placeholder="表示名" required style="max-width:200px" value="{{ old('label') }}">
        <label><input type="checkbox" name="app_writable" value="1"> アプリから更新可</label>
        <button class="btn btn-primary">追加</button>
    </form>
    <p class="hint muted">キーは英小文字で始まる英小文字・数字・アンダースコア。キーと型は後から変更できません。</p>
</div>
@endcan
@endsection
