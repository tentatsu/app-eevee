# デプロイと運用

## 必要なプロセス

| プロセス | コマンド | 説明 |
|---|---|---|
| Web | PHP-FPM + Nginx（または `php artisan octane:start` など） | 管理画面・API |
| キュー | `php artisan horizon` | プッシュ配信（`push` キュー）・メール。supervisord などで常駐させる |
| スケジューラー | cron で毎分 `php artisan schedule:run` | 予約配信、視聴セッションの期限切れ、配信ログの削除（毎日 03:30）、Horizon のスナップショット、期限切れトークンの削除 |

必要なミドルウェア: MySQL 8、Redis（キュー・キャッシュ・視聴セッション。phpredis 拡張）。

デプロイ時は `php artisan migrate --force`、`php artisan config:cache route:cache view:cache`、`php artisan horizon:terminate`（ワーカーの再起動）を実行する。

## ロードバランサー / Cloudflare の背後に置く場合（TrustProxies）

管理画面の IP 制限と監査ログは送信元 IP を使うため、プロキシの背後では **信頼するプロキシ** を正しく設定する必要があります。

`.env`:

```dotenv
# ロードバランサー（例: AWS ALB の VPC 内アドレス）
TRUSTED_PROXIES=10.0.0.0/8

# Cloudflare（プロキシ有効）→ ロードバランサー → app-eevee の場合は両方
TRUSTED_PROXIES=10.0.0.0/8,173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32
```

- 仕組み: 接続元がリストに含まれるプロキシの場合だけ `X-Forwarded-For` を信頼し、右から順にプロキシのアドレスを除いた最初の IP を送信元とします（`App\Http\Middleware\TrustProxies`、設定値は `config('eevee.trusted_proxies')`）。Cloudflare は `X-Forwarded-For` にクライアントの IP を入れて転送します。
- Cloudflare の IP 範囲は https://www.cloudflare.com/ips/ で公開されており、まれに変わります。定期的に確認してください。
- `TRUSTED_PROXIES=*` は「直前の接続元を常に信頼」する設定です。app-eevee にプロキシ経由でしか到達できない（セキュリティグループ等でロードバランサー以外からの接続を遮断している）場合に限って使ってください。そうでないと `X-Forwarded-For` の偽装で IP 制限を回避されます。
- 設定後の確認: 管理画面の「IP制限」ページに表示される「あなたの現在のIP」が、実際のグローバル IP と一致すること。監査ログの IP も同様。

## IP 制限で締め出された場合

```bash
php artisan eevee:ip-allowlist:clear {org_slug}   # 組織の管理画面の制限を解除
php artisan eevee:ip-allowlist:clear --system     # システム管理画面（DB 分）の制限を解除。.env の SYSTEM_IP_ALLOWLIST は手で編集
```

システム管理者は `/system` の組織詳細からも解除できます。拒否したアクセスは `storage/logs/security-YYYY-MM-DD.log` に記録されます。

## システム管理者の作成

```bash
php artisan eevee:system-admin:create you@example.com --name="Your Name"
```

## 秘密情報

- Firebase のサービスアカウント、Cloudflare の API Token・署名鍵・Webhook シークレットは `APP_KEY` で暗号化して DB に保存しています。**`APP_KEY` を変えると復号できなくなります**（ローテーションする場合は `APP_PREVIOUS_KEYS` を使う）。
- `app_secret` はハッシュで保存しているため、紛失したら管理画面で再発行します。
- ログに秘密情報が出ないよう、モデルのシリアライズから除外し（`$hidden`）、監査ログでもマスクしています。
