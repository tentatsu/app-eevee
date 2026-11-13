# アプリへの組み込み手順（client-integration）

iOS / Android アプリから app-eevee を使うための手順です。API の詳細（リクエスト・レスポンスの項目）は OpenAPI の仕様書 `docs/openapi.json`（管理画面にログインした状態で `/docs/api` でも閲覧可）を参照してください。

## 0. 前提

| 値 | どこで分かるか | アプリに埋め込んでよいか |
|---|---|---|
| `app_key`（例: `ak_xxxxxxxx`） | 管理画面 → アプリ → 概要 | よい（公開ID） |
| `app_secret`（例: `as_xxxx`） | 管理画面 → アプリ設定 → 再発行（その場で一度だけ表示） | **だめ**。顧客のサーバーだけで使う |
| ベースURL | `https://{app-eevee のドメイン}/api/v1/apps/{app_key}` | — |

- リクエスト・レスポンスはすべて JSON。`Accept: application/json` を付けてください。
- 成功: `{"data": ..., "meta": {...}}`、失敗: `{"error": {"code": "...", "message": "...", "details": {...}}}`。アプリの分岐は `error.code` で行ってください（主なコードは末尾の一覧）。
- 日時はすべて UTC（ISO 8601、例: `2026-11-12T03:00:00Z`）。表示時に端末のタイムゾーンへ変換してください。
- ログインが必要な API は `Authorization: Bearer {token}`。

## 1. Firebase（FCM）の設定

app-eevee は Firebase の機能のうち **Cloud Messaging（FCM）だけ**を使います（Firebase Auth / Firestore は使いません）。

### 1-1. Firebase プロジェクト（運用担当）
1. Firebase コンソールでプロジェクトを作り、iOS アプリと Android アプリを登録する。
2. プロジェクトの設定 → サービスアカウント → 「新しい秘密鍵を生成」で JSON をダウンロードし、app-eevee の管理画面 → アプリ設定 → Firebase に登録する（暗号化して保存されます）。

### 1-2. iOS
1. Apple Developer で APNs 認証キー（.p8）を作り、Firebase コンソール → プロジェクトの設定 → Cloud Messaging → Apple アプリの構成 にアップロードする。
2. Xcode で Signing & Capabilities に **Push Notifications** と **Background Modes → Remote notifications** を追加する。
3. `GoogleService-Info.plist` をプロジェクトに追加し、Firebase iOS SDK の `FirebaseMessaging` を導入する。
4. 起動時に `FirebaseApp.configure()`、`UNUserNotificationCenter.current().requestAuthorization(...)` で許可を求め、`application.registerForRemoteNotifications()` を呼ぶ。
5. `Messaging.messaging().delegate` の `messaging(_:didReceiveRegistrationToken:)` で FCM トークンを受け取ったら、**手順 2 の端末登録 API** を呼ぶ。
6. 画像付き通知を表示する場合は Notification Service Extension を追加する（app-eevee は画像付きのとき `mutable-content: 1` を付けて送ります）。

### 1-3. Android
1. `google-services.json` を `app/` に置き、Gradle に `com.google.gms.google-services` プラグインと `firebase-messaging` を追加する。
2. Android 13 以上は `POST_NOTIFICATIONS` の実行時許可を求める。
3. `FirebaseMessagingService` を継承したサービスで `onNewToken(token)` を受け取り、**手順 2 の端末登録 API** を呼ぶ。起動時は `FirebaseMessaging.getInstance().token` で現在のトークンを取得する。
4. 通知チャンネル（例: `default`）を作成しておく。

## 2. 端末の登録（起動時・トークン更新時）

アプリの初回起動時に UUID を生成して保存し（**installation_id**。アンインストールまで変えない）、起動のたびとトークンが変わったときに呼びます。ログインしていなくても登録できます。

```http
POST /api/v1/apps/{app_key}/devices
Content-Type: application/json

{
  "installation_id": "3f1c2a9e-8b7d-4c1e-9f0a-1234567890ab",
  "platform": "ios",                 // ios | android
  "fcm_token": "fcm-registration-token",
  "app_version": "1.2.0",
  "os_version": "18.1",
  "locale": "ja-JP",
  "timezone": "Asia/Tokyo",
  "push_permission": "authorized"    // not_determined | authorized | provisional | denied
}
```

- ログイン中ならユーザーのトークンを付けて呼ぶと、端末がそのユーザーに紐づきます。
- 通知の許可状態が変わったら `push_permission` を更新してください（`denied` の端末には送りません）。
- `app_version` と最終アクティブ日時はセグメント（ターゲティング）の条件に使われます。

## 3. ログインと端末の紐づけ

### 3-1. builtin 方式（app-eevee で認証）

```http
POST /api/v1/apps/{app_key}/auth/register   {"email": "...", "password": "...", "name": "...", "installation_id": "..."}
POST /api/v1/apps/{app_key}/auth/login      {"email": "...", "password": "...", "installation_id": "..."}
→ {"data": {"token": "1|xxxx", "token_type": "Bearer", "expires_at": "...", "user": {...}}}
```

- `installation_id` を渡すと、その端末がユーザーに紐づきます（渡さなかった場合は `POST /me/devices/link`）。
- トークンは Keychain / EncryptedSharedPreferences などに保存してください（有効期限 365 日）。
- パスワード再設定: `POST /auth/password/forgot {"email"}` → メールで届いた再設定コードを入力してもらい `POST /auth/password/reset {"email", "token", "password"}`。成功すると全端末のトークンが無効になります。

### 3-2. external 方式（顧客の既存バックエンドで認証）

1. アプリは顧客のバックエンドでログインする。
2. 顧客のバックエンドが app-eevee のサーバー間API（`Authorization: Bearer {app_secret}`）を呼ぶ:
   ```http
   PUT  /api/v1/server/apps/{app_key}/users/{external_user_id}          {"name": "...", "attributes": {"purchase_count": 3}}
   POST /api/v1/server/apps/{app_key}/users/{external_user_id}/tokens   {"installation_id": "..."}
   → {"data": {"token": "...", "expires_at": "...", ...}}
   ```
3. 顧客のバックエンドが受け取った短命のトークン（既定 60 分）をアプリに返す。アプリはそれを app-eevee の API に使う。期限が切れたら（401 `unauthenticated`）、顧客のバックエンド経由で取り直す。

### 3-3. ログアウト

```http
POST /api/v1/apps/{app_key}/auth/logout   {"installation_id": "..."}   (Authorization: Bearer {token})
```

`installation_id` を渡すと端末とユーザーの紐づけが外れ、そのユーザー向けのプッシュが届かなくなります（端末自体は未ログインの端末として残ります）。

## 4. 属性と通知設定

- アプリから自分の属性を更新（管理画面で「アプリから更新可」にした属性だけ）:
  `PATCH /me/attributes {"attributes": {"birth_month": 4, "gender": "female"}}`（`null` で削除）
- 購入回数などサーバーが持つ値は、顧客のバックエンドから `POST /api/v1/server/apps/{app_key}/users/attributes` で一括更新します（最大 1000 件）。
- 通知カテゴリ: `GET /notification-categories`、自分の設定 `GET /me/notification-preferences`、更新 `PUT /me/notification-preferences {"preferences": {"campaign": false}}`。

## 5. プッシュ通知の受信と開封記録

通知の data には管理画面で設定した値（例: `type=notice`, `id=123`）と、**`eevee_campaign_id`** が入っています。

1. ユーザーが通知をタップしたら、data を見て画面を開く（ディープリンク）。例: `type=notice` ならお知らせ詳細へ。
2. 同時に開封を記録する（何度呼んでも 1 回として数えます）:
   ```http
   POST /api/v1/apps/{app_key}/push/opened
   {"campaign_id": 123, "installation_id": "..."}
   ```
- iOS: `userNotificationCenter(_:didReceive:withCompletionHandler:)` の `response.notification.request.content.userInfo`。
- Android: 通知タップで起動した Activity の `intent.extras`（バックグラウンド時）または `onMessageReceived` の `remoteMessage.data`。

## 6. お知らせ

```http
GET  /notices?page=1&per_page=20      一覧（ピン留め → 新しい順。トークンを付けるとセグメント向けと is_read も含む）
GET  /notices/{id}                    詳細（body: Markdown, body_html: 安全な HTML）
POST /notices/{id}/read               既読（ログイン必須）
GET  /notices/unread-count            未読数（ログイン必須。バッジ表示に）
```

## 7. 動画の再生とハートビート

```
[一覧] GET /videos                     → playable が false なら locked_reason を見て案内する
   │                                        login_required → ログインを促す / entitlement_required → 有料プランを案内（8 章）
   │
[再生] POST /videos/{id}/play          → session_id, hls_url, heartbeat_interval(30)
   │     未ログインで公開動画を見る場合は {"installation_id": "..."} を送る
   │     409 concurrent_limit_exceeded → 「他の端末で再生中です」と表示
   │     401 login_required           → ログイン画面へ
   │
[再生中] 30 秒ごとに POST /playback-sessions/{session_id}/heartbeat
   │     → {"action": "continue"} なら続行
   │     → {"action": "stop", "reason": "kicked"}  他の端末で再生が始まった → 再生を止めて案内を表示
   │     → {"action": "stop", "reason": "expired"} 90 秒以上ハートビートがなかった → 再度 play から
   │
[終了] POST /playback-sessions/{session_id}/stop   （画面を閉じた・最後まで再生した）
```

- `hls_url` を AVPlayer（iOS）/ ExoPlayer（Android, Media3）にそのまま渡します。URL には署名付きトークンが入っており、有効期限（`expires_at` = 動画の長さ＋余裕時間）を過ぎると再生できません。期限切れや再生エラーのときは `play` からやり直してください。
- 未ログインの視聴では、heartbeat / stop にも `{"installation_id": "..."}` を送ってください（開始した端末以外からは操作できません）。
- アプリがバックグラウンドに入って再生を止めたら `stop` を呼ぶか、ハートビートを止めてください（90 秒後に自動で期限切れになり、同時視聴数の枠が空きます）。
- 同時視聴数の上限（初期値 2）と、上限を超えたときの挙動（新しい再生を拒否 / 最も古い再生を停止）はアプリごとに管理画面で設定します。

## 8. 有料プラン（課金・Stripe）

組織が「売上の受け取り」で Stripe に登録し、アプリの「課金プラン」でプランを作ると使えます。購入は Stripe の購入画面（Checkout）で行うため、アプリはカード情報を扱いません。

```
[プラン] GET /billing/plans            → id, name, price_label（例: 500円 / 月）, entitlement, subscribed
   │
[購入] POST /billing/checkout {"plan_id": 1}   （ログイン必須）→ {"url": "https://checkout.stripe.com/..."}
   │     url を Custom Tabs（Android）/ SFSafariViewController（iOS）で開く
   │     購入後は完了ページ（success_url を指定すればそこ）に戻る → アプリに戻ってもらう
   │
[確認] GET /me/entitlements            → {"entitlements": ["premium"], "subscriptions": [...]}
   │     反映は Webhook 経由なので数秒かかることがある。アプリに戻ったとき（onResume）に取り直す
   │
[管理] POST /billing/portal            → {"url": ...}  解約・カードの変更・領収書（Stripe の画面）
```

- 動画の `required_entitlement`（例: `premium`）と、自分の `entitlements` を照らし合わせて「有料」バッジを出せます。再生できるかどうかは `playable` / `locked_reason` を見てください（判定はサーバーで行います）。
- 解約しても、支払い済みの期間の終わり（`current_period_end`）までは見られます。
- **ストアの規約に注意**: アプリ内で見るデジタルコンテンツ（動画の見放題など）を、アプリ内から外部の決済に誘導して販売することは、App Store / Google Play の規約で原則として認められていません（アプリ内課金が必要）。地域・条件によって例外があります（日本のスマホ新法など）。Stripe の購入画面は、Web サイトでの販売、物販・サービスの予約、規約上認められた地域・条件での利用を想定してください。ストアの審査の可否は、各アプリで確認してください。

## 9. エラーコード一覧（主なもの）

| HTTP | code | 意味・アプリの対応 |
|---|---|---|
| 404 | `app_not_found` | app_key が間違っている（組織が停止された場合も） |
| 401 | `unauthenticated` | トークンがない・無効・期限切れ → ログインし直す |
| 401 | `invalid_credentials` | メールアドレスかパスワードが違う |
| 409 | `auth_mode_mismatch` | アプリの認証方式（builtin / external）と違う API を呼んだ |
| 422 | `validation_failed` | 入力エラー。`details.fields` に項目ごとのメッセージ |
| 422 | `invalid_reset_token` | 再設定コードが違う・期限切れ |
| 404 | `device_not_found` | 端末が未登録（先に端末登録 API を呼ぶ） |
| 404 | `delivery_not_found` | 開封記録の対象が見つからない（無視してよい） |
| 404 | `notice_not_found` | お知らせが非公開・対象外 |
| 404 | `video_not_available` | 動画が非公開・変換中・期間外 |
| 401 | `login_required` | メンバー限定・有料の動画（ログインが必要） |
| 403 | `entitlement_required` | 有料の動画（プランの購入が必要） |
| 404 | `plan_not_found` | プランが販売停止・存在しない |
| 409 | `already_subscribed` | 同じ権利のプランを購読中 |
| 404 | `no_subscription` | 購読がない（管理画面の URL を作れない） |
| 503 | `billing_unavailable` | 決済の準備ができていない（運用担当に連絡） |
| 422 | `installation_id_required` | 未ログインの再生で installation_id がない |
| 409 | `concurrent_limit_exceeded` | 同時視聴数の上限（`details.max_concurrent_streams`） |
| 404 | `session_not_found` | 視聴セッションが見つからない（別の視聴者のセッションなど） |
| 503 | `video_provider_not_configured` | 動画の配信設定が未完了（運用担当に連絡） |
| 429 | `rate_limited` | リクエストが多すぎる。`Retry-After` 秒待って再試行 |
| 500 | `server_error` | サーバーの障害。時間をおいて再試行 |
