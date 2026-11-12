@props(['action', 'input', 'currentIp'])
<form method="post" action="{{ $action }}">
    @csrf @method('put')
    @if (session('lockout_warning'))
        <div class="alert alert-warn">
            <strong>警告:</strong> 現在アクセスしているIPアドレス（<span class="mono">{{ $currentIp }}</span>）が許可リストに含まれていません。
            このまま保存すると、あなた自身がこの管理画面にアクセスできなくなります。
            <div style="margin-top:6px"><label><input type="checkbox" name="confirm_lockout" value="1"> 理解したうえで保存する</label></div>
        </div>
    @endif
    <div class="form-row">
        <label>許可するIPアドレス / CIDR（1行に1件、「#」以降はメモ）</label>
        <textarea name="entries" rows="10" placeholder="203.0.113.0/24 # 本社&#10;198.51.100.10 # VPN&#10;2001:db8::/32">{{ old('entries', $input) }}</textarea>
        <div class="hint">IPv4・IPv6・CIDR に対応。空にすると制限なしになります。あなたの現在のIP: <span class="mono">{{ $currentIp }}</span></div>
    </div>
    <button class="btn btn-primary">保存</button>
</form>
