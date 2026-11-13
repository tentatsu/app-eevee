# デプロイと運用

本番は **Laravel Cloud** を前提にしています（理由と注意点は `docs/decisions.md` の D-024）。VPS や AWS に置く場合は後半の「Laravel Cloud 以外の環境」を参照してください。

## Laravel Cloud

### 1. 作るリソース

| リソース | 設定 | 備考 |
|---|---|---|
| アプリケーション | GitHub のこのリポジトリを接続。PHP 8.3 以上 | |
| 環境（production） | ブランチを指定。push で自動デプロイ | ステージングを作る場合は環境を追加 |
| Laravel MySQL | 環境に接続（`DB_*` は Cloud が設定） | MySQL 8 |
| Laravel Valkey（キャッシュ） | 環境に接続（`REDIS_*` は Cloud が設定） | Redis 互換。キャッシュ・レート制限・ロック・視聴セッションに使う |
| キューのワーカー | 処理するキュー: **`push,default`** | Horizon は使わない（`EEVEE_HORIZON_ENABLED=false`） |
| スケジューラー | 有効にする | 予約配信・視聴セッションの期限切れ処理（毎分） |
| 独自ドメイン | 例: `api.example.com`。SSL は自動 | 1 つで足りる（管理画面・API・Webhook 共通） |

Object Storage（バケット）は、今の app-eevee ではファイルを保存しないので不要です。

### 2. ビルドコマンド・デプロイコマンド

ビルドコマンド:

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize
```

デプロイコマンド:

```bash
php artisan migrate --force
```

管理画面は Vite を使っていない（`public/css/eevee.css` をそのまま配信）ので、`npm run build` は不要です。

### 3. 環境変数

Cloud が自動で設定するもの（`APP_KEY`、`DB_*`、`REDIS_*` など）以外に、次を設定します。

```dotenv
APP_NAME="管理画面に表示する名前"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com          # 独自ドメイン。招待メールと Webhook の URL に使う
APP_LOCALE=ja

# Laravel Cloud はエッジネットワーク経由でしかアプリに届かないため、直前のプロキシを信頼する
TRUSTED_PROXIES=*

QUEUE_CONNECTION=redis                    # キューのワーカーの設定に合わせる
CACHE_STORE=redis                         # Valkey。複数台・ワーカー間でレート制限とロックを共有するため
SESSION_DRIVER=database
SESSION_ENCRYPT=true

REDIS_PREFIX=eevee-
# REDIS_SCHEME=tls                        # Valkey が TLS 接続を求める場合
# REDIS_CACHE_DB=0                        # DB 0 しか使えない場合

EEVEE_HORIZON_ENABLED=false
EEVEE_PUSH_QUEUE=push
LOG_SECURITY_CHANNELS=stderr              # セキュリティログを Cloud のログ画面に出す

MAIL_MAILER=smtp                          # Resend / Postmark / Amazon SES など
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"

SYSTEM_IP_ALLOWLIST=                      # システム管理画面を制限する場合
```

### 4. デプロイ後の確認

```bash
php artisan eevee:doctor                  # Cloud のコマンド実行画面から
php artisan eevee:system-admin:create you@example.com --name="Your Name"
```

`eevee:doctor` は、DB・Valkey への接続、**同時視聴数の制御に使う Lua スクリプト（EVAL / cjson / ZADD XX）が Valkey で動くか**、キューの `retry_after`、スケジューラーが動いているか、`TRUSTED_PROXIES` やメールの設定を確認します。NG があれば終了コードが 1 になります。

さらに、ブラウザで次を確認してください。
- 組織の管理画面 → IP制限 に表示される「あなたの現在のIP」が、実際のグローバル IP と一致する（一致しなければ `TRUSTED_PROXIES` を見直す）。
- テスト配信が届く（キューのワーカーが `push` キューを処理している）。

### 5. 休止（scale to zero）を使う場合の注意

- アクセスがないとアプリと DB が休止します。最初のリクエストは復帰を待つため遅くなります。
- **スケジューラーが休止中も毎分動くかは、プランと設定によります。** `eevee:doctor` の「スケジューラー」が WARN（最終実行が数分前）になる場合は、予約配信が遅れます。予約配信を使うアプリがある間は、アプリを休止させない設定にしてください。
- 視聴セッションの期限切れは、スケジューラーが止まっていても次の再生開始時・ハートビート時に処理されるので、同時視聴数の判定は正しく動きます（統計の反映が遅れるだけ）。

### 6. Horizon について

Laravel Cloud のマネージドキューは `queue:work` で動き、Horizon のダッシュボードには対応していません。app-eevee のジョブは標準のキュー機能だけで書いているので、Horizon がなくても動きます（`EEVEE_HORIZON_ENABLED=false` にすると、システム管理画面の Horizon へのリンクとスナップショットの定期実行がなくなります）。ローカル（Sail）では引き続き Horizon を使えます。

---

# Laravel Cloud 以外の環境

## 必要なプロセス

| プロセス | コマンド | 説明 |
|---|---|---|
| Web | PHP-FPM + Nginx（または `php artisan octane:start` など） | 管理画面・API |
| キュー | `php artisan horizon`（Horizon を使わない場合は `php artisan queue:work --queue=push,default --timeout=600`） | プッシュ配信（`push` キュー）・メール。supervisord などで常駐させる |
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
