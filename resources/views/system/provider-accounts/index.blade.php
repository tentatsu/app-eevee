@extends('layouts.system')
@section('title', '共用の動画アカウント')
@section('content')
<div class="row" style="justify-content:space-between"><h1>共用の動画アカウント</h1><a class="btn btn-primary" href="{{ route('system.provider-accounts.create') }}">アカウントを追加</a></div>
<p class="muted">Oneframe が契約した Cloudflare Stream のアカウントを登録し、複数のアプリで共用します。アプリへの割り当ては、組織の詳細画面から行います。費用の目安は「利用量」画面で確認できます。</p>
<div class="card">
    <table>
        <tr><th>名前</th><th>Account ID</th><th>状態</th><th>使用中のアプリ</th></tr>
        @forelse ($accounts as $a)
            <tr>
                <td><a href="{{ route('system.provider-accounts.edit', $a) }}">{{ $a->name }}</a></td>
                <td class="mono">{{ $a->setting('account_id') }}</td>
                <td>{!! $a->setting('signing_key_id') && $a->setting('webhook_secret') ? '<span class="badge badge-ok">設定済み</span>' : '<span class="badge badge-muted">自動設定がまだです</span>' !!}</td>
                <td>{{ $a->apps_count }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">まだありません。</td></tr>
        @endforelse
    </table>
</div>
@endsection
