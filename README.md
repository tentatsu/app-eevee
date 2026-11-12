# app-eevee

受託で開発するスマホアプリ（iOS / Android）に共通するバックエンド機能を、1つのシステムでまとめて提供する「アプリ開発の母体」です。
1つの app-eevee で複数の顧客組織・複数のアプリを管理するマルチテナント構成です。

## 機能

| 機能 | 概要 |
|---|---|
| 組織管理 | システム管理者（`/system`）が組織を作成・停止・削除し、組織の管理者を招待する。組織ごとのログイン画面 `/{org_slug}/login`。ロール owner / admin / editor / viewer |
| 管理画面の IP 制限 | 組織ごと・システム管理画面ごとの許可リスト（IPv4/IPv6/CIDR）。締め出し警告、システム管理者・artisan による解除、拒否ログ |
| 監査ログ | 誰が・いつ・どの組織で・何を・どの IP から |
| アプリ設定 | app_key / app_secret（ハッシュ保存）、Firebase・Cloudflare の認証情報（暗号化保存）、動画・プッシュの設定 |
| アプリユーザー認証 | builtin（メール＋パスワード、Sanctum）／ external（顧客バックエンドがサーバー間APIで登録し短命トークンを発行） |
| 属性 | 属性の定義（型付き）、アプリからの更新（許可したキーだけ）、サーバー間の一括更新 |
| 端末 | installation_id で upsert、ログインとの紐づけ、無効トークンの除外 |
| セグメント | AND / OR / 入れ子の条件 → SQL、送信前の対象数プレビュー（10万端末で 0.5 秒未満） |
| プッシュ配信 | FCM HTTP v1、500 件チャンクのキュー送信、レート制御・指数バックオフ、冪等性、テスト配信、予約、配信ログ、開封率 |
| お知らせ | Markdown、公開期間、全員／セグメント、ピン留め、既読・未読数、プッシュ連携 |
| 動画配信 | Cloudflare Stream（Direct Creator Upload、署名付き URL 必須、Webhook）、RS256 の再生トークン、ハートビート、同時視聴数の制御、統計 |

対象外（後から追加できる構造にしている）: 記事、DRM、課金、アプリ側の SDK。

## 技術スタック

Laravel 13 / PHP 8.3+（Sail は PHP 8.5）、MySQL 8、Redis、Laravel Horizon、Sanctum、kreait/firebase-php（FCM のみ）、Cloudflare Stream API、Blade（管理画面）、Scramble（OpenAPI）、Pest、Larastan（level 6）、Pint。

## セットアップ（Laravel Sail）

```bash
git clone <this repo> app-eevee && cd app-eevee
cp .env.example .env

# PHP がローカルにない場合は Docker で依存関係を入れる
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php85-composer:latest composer install

./vendor/bin/sail up -d                 # app / mysql / redis / mailpit / horizon / scheduler
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

ブラウザで開く:

| URL | 用途 | ログイン（パスワードはすべて `password`） |
|---|---|---|
| http://localhost/system/login | システム管理画面 | `system@example.com` |
| http://localhost/oneframe/login | 組織 oneframe の管理画面 | `owner@` / `admin@` / `editor@` / `viewer@oneframe.example.com` |
| http://localhost/demo-client/login | 組織 demo-client の管理画面 | `owner@demo-client.example.com` |
| http://localhost/horizon | キューの監視（システム管理者でログイン中のみ） | |
| http://localhost/docs/api | API 仕様書（管理者でログイン中のみ） | |
| http://localhost:8025 | Mailpit（招待メール・パスワード再設定メール） | |

- デモのアプリは `ak_demo_oneframe_app`（builtin）と `ak_demo_client_app`（external）。app_secret はシーダー実行時にコンソールへ表示されます（管理画面のアプリ設定で再発行もできます）。
- Firebase・Cloudflare を設定しなくても画面を試せるよう、`.env` で `EEVEE_PUSH_FAKE=true`（FCM に送らずログに出す）、`EEVEE_VIDEO_FAKE=true`（Cloudflare を呼ばない）にできます。
- キューは Horizon（compose の `horizon` サービス）、予約配信などはスケジューラー（`scheduler` サービス）が処理します。

## テスト・静的解析・整形

```bash
./vendor/bin/sail test                                   # Pest（MySQL の testing DB と Redis の DB 14 を使う）
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=2G   # Larastan level 6
./vendor/bin/sail pint                                   # Pint（--test で確認のみ）
./vendor/bin/sail artisan scramble:export                # OpenAPI を docs/openapi.json に出力
```

外部サービス（FCM・Cloudflare）は、インターフェース（`PushSender` / `MessagingFactory` / `VideoProvider`）経由で差し替えており、テストはモック・フェイク・`Http::fake()` で実際の通信なしに通ります。

性能確認（任意）:

```bash
./vendor/bin/sail artisan db:seed --class=PerformanceSeeder
./vendor/bin/sail artisan eevee:segments:benchmark ak_perf_benchmark_app
```

## URL 設計

| 種類 | URL | 認証 |
|---|---|---|
| 組織の管理画面 | `/{org_slug}/login`, `/{org_slug}/`, `/{org_slug}/apps/{app}/...` | セッション（組織ごとにログイン）＋組織の IP 制限 |
| システム管理画面 | `/system/login`, `/system/...` | セッション＋システムの IP 制限 |
| アプリ向けAPI | `/api/v1/apps/{app_key}/...` | 未ログインで使えるものは app_key のみ、ほかは Sanctum トークン |
| サーバー間API | `/api/v1/server/apps/{app_key}/...` | `Authorization: Bearer {app_secret}` |
| Webhook | `/webhooks/cloudflare/{app_key}` | 署名（HMAC-SHA256） |
| ヘルスチェック | `/health` | なし |

## Artisan コマンド

| コマンド | 説明 |
|---|---|
| `eevee:system-admin:create {email}` | システム管理者を作成 |
| `eevee:ip-allowlist:clear {org_slug}` / `--system` | IP 制限の緊急解除 |
| `eevee:push:dispatch-scheduled` | 予約配信の送信（毎分） |
| `eevee:push:prune-deliveries` | 保持期間を過ぎた送信結果の削除（毎日） |
| `eevee:playback:expire` | ハートビートが途絶えた視聴セッションの期限切れ処理（毎分） |
| `eevee:segments:benchmark {app_key}` | セグメント抽出の性能計測 |

## ドキュメント

| ファイル | 内容 |
|---|---|
| [docs/decisions.md](docs/decisions.md) | 設計判断の記録（判断・理由・代替案） |
| [docs/client-integration.md](docs/client-integration.md) | アプリへの組み込み手順（FCM 設定、端末登録、ログイン、開封記録、動画再生） |
| [docs/openapi.json](docs/openapi.json) | アプリ向けAPI・サーバー間APIの OpenAPI 3.1 仕様書 |
| [docs/manual-verification.md](docs/manual-verification.md) | 実際の FCM・Cloudflare での動作確認手順 |
| [docs/deployment.md](docs/deployment.md) | デプロイ、TrustProxies（ロードバランサー・Cloudflare の背後）、運用 |
| [docs/performance.md](docs/performance.md) | セグメント抽出の性能計測結果 |

## ディレクトリ構成（主なもの）

```
app/
  Enums/                     ロール・状態などの列挙型
  Http/Controllers/System/   システム管理画面
  Http/Controllers/Org/      組織の管理画面（Apps/ にアプリごとの機能）
  Http/Controllers/Api/      アプリ向けAPI（Server/ にサーバー間API）
  Http/Controllers/Webhooks/ Webhook
  Http/Middleware/           テナント解決・IP 制限・認証
  Jobs/                      プッシュ配信のジョブ
  Models/Concerns/           テナント分離（BelongsToOrganization / BelongsToApp）
  Services/                  Audit, IpAccess, AppUsers, Segments, Push, Notices, Video
  Support/Tenancy/           TenantContext・グローバルスコープ
docs/                        設計判断・組み込み手順・仕様書
tests/                       Pest（Feature / Unit）
```
