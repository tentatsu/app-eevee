@extends('layouts.org')
@section('title', '通知カテゴリ')
@section('content')
<h1>通知カテゴリ</h1>
<p class="muted">ユーザーがカテゴリごとに通知のオン・オフを切り替えられます。プッシュ配信でカテゴリを指定すると、オフにしたユーザーには送りません。</p>
<div class="card">
    <table>
        <tr><th>キー</th><th>表示名 / 初期値 / 並び順</th><th></th></tr>
        @forelse ($categories as $cat)
            <tr>
                <td class="mono">{{ $cat->key }}</td>
                <td>
                    @can('manage-apps')
                        <form method="post" action="{{ route('org.apps.categories.update', [$organization, $app, $cat]) }}" class="row">
                            @csrf @method('put')
                            <input type="text" name="label" value="{{ $cat->label }}" style="max-width:200px">
                            <label><input type="checkbox" name="default_enabled" value="1" @checked($cat->default_enabled)> 初期値オン</label>
                            <input type="number" name="sort_order" value="{{ $cat->sort_order }}" style="max-width:80px">
                            <button class="btn btn-sm">更新</button>
                        </form>
                    @else
                        {{ $cat->label }} / {{ $cat->default_enabled ? 'オン' : 'オフ' }}
                    @endcan
                </td>
                <td>@can('manage-apps')<form method="post" action="{{ route('org.apps.categories.destroy', [$organization, $app, $cat]) }}" onsubmit="return confirm('削除しますか？')">@csrf @method('delete')<button class="btn btn-sm btn-danger">削除</button></form>@endcan</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">まだカテゴリがありません。</td></tr>
        @endforelse
    </table>
</div>
@can('manage-apps')
<div class="card">
    <form method="post" action="{{ route('org.apps.categories.store', [$organization, $app]) }}" class="row">
        @csrf
        <input type="text" name="key" placeholder="キー（例: campaign）" required style="max-width:200px">
        <input type="text" name="label" placeholder="表示名（例: キャンペーン）" required style="max-width:200px">
        <label><input type="checkbox" name="default_enabled" value="1" checked> 初期値オン</label>
        <button class="btn btn-primary">追加</button>
    </form>
</div>
@endcan
@endsection
