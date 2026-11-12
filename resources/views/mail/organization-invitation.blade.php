{{ $organization->name }} の管理画面に招待されました。

ロール: {{ $invitation->role->label() }}

次のURLを開いて、招待を受け入れてください（有効期限: {{ eevee_dt($invitation->expires_at, 'Y-m-d H:i', $organization->timezone) }}）。

{{ $acceptUrl }}

心当たりがない場合は、このメールを破棄してください。

--
{{ config('app.name') }}
