# 実サービス（FCM / Cloudflare Stream）での動作確認手順

自動テストは FCM・Cloudflare をモック／フェイクにしていて、実際の通信はしません。本番前に、以下の手順で実際のサービスと接続して確認してください。

## 0. 準備

```bash
cp .env.example .env    # まだなら
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

`.env` で次の値を確認する。

| キー | 値 | 説明 |
|---|---|---|
| `EEVEE_PUSH_FAKE` | `false` | true だと FCM に送らずログに出すだけ |
| `EEVEE_VIDEO_FAKE` | `false` | true だと Cloudflare を呼ばずダミーを返す |
| `QUEUE_CONNECTION` | `redis` | プッシュはキューで送るため、Horizon を起動しておく（compose の `horizon` サービス、または `sail artisan horizon`） |
| `APP_URL` | 外部から到達できる URL | Cloudflare の Webhook を受けるため（ローカルなら cloudflared / ngrok などでトンネルを張る） |

Firebase と Cloudflare の認証情報は `.env` ではなく、**管理画面 → アプリ設定** でアプリごとに登録します（DB に暗号化して保存）。

## 1. FCM（プッシュ通知）

### 1-1. 認証情報の登録
1. Firebase コンソール → プロジェクトの設定 → サービスアカウント → 「新しい秘密鍵を生成」で JSON を取得。
2. 管理画面（例: `/oneframe/login` → owner でログイン）→ アプリ → アプリ設定 → Firebase に JSON を登録。「設定済み project_id: ...」と表示されることを確認。

### 1-2. 実機の登録
1. `docs/client-integration.md` の手順で組み込んだアプリ（または検証用アプリ）を実機で起動し、端末登録 API を呼ぶ。
   API だけで試す場合は、実機の FCM トークンを使って:
   ```bash
   curl -X POST https://{host}/api/v1/apps/{app_key}/devices -H 'Content-Type: application/json' \
     -d '{"installation_id":"'$(uuidgen | tr A-Z a-z)'","platform":"ios","fcm_token":"<実機のFCMトークン>","push_permission":"authorized"}'
   ```
2. 管理画面 → 端末 で、その端末を「テスト端末」に登録する（ラベルを付けておくと分かりやすい）。

### 1-3. 確認項目
| 確認すること | 手順 | 期待結果 |
|---|---|---|
| テスト配信 | プッシュ配信 → 作成 → 「テスト配信」 | テスト端末だけに届く。キャンペーン詳細の「テスト配信の結果」で成功 1 |
| 本配信 | 「今すぐ送信」 | 対象数・成功数が表示され、状態が「送信完了」になる。Horizon（`/horizon`、システム管理者でログイン）で `push` キューのジョブが処理されている |
| 画像付き | 画像URL（https）を指定して送信 | Android は画像付きで表示。iOS は Notification Service Extension 実装時に画像付き |
| ディープリンク | data に `type=notice` `id={お知らせID}` | タップでお知らせ詳細が開く（アプリ側の実装） |
| 開封記録 | 通知をタップ（アプリが `POST /push/opened` を呼ぶ） | キャンペーンの開封数・開封率が増える |
| 無効トークン | アプリをアンインストールしてから送信 | その端末が「無効」（UNREGISTERED）になり、以後の対象数から外れる |
| 予約配信 | 送信日時を数分後にして「予約する」 | スケジューラー（compose の `scheduler` サービス、または `sail artisan schedule:work`）で時刻に送信される |
| 通知カテゴリ | ユーザーがカテゴリをオフにしてから、そのカテゴリを指定して送信 | そのユーザーの端末には送られない |

うまくいかないとき: `storage/logs/laravel.log` と、キャンペーン詳細の「端末ごとの送信結果」のエラーコード（例: `SENDER_ID_MISMATCH` は Firebase プロジェクトの取り違え、`AuthenticationError` はサービスアカウントの権限不足）を確認する。

## 2. Cloudflare Stream（動画）

### 2-1. Cloudflare 側の準備
1. **Account ID**: Cloudflare ダッシュボードの右側に表示。
2. **API Token**: My Profile → API Tokens → Create Token。権限は `Account / Stream / Edit`。
3. **署名鍵**（再生トークン用）:
   ```bash
   curl -X POST "https://api.cloudflare.com/client/v4/accounts/<ACCOUNT_ID>/stream/keys" -H "Authorization: Bearer <API_TOKEN>"
   ```
   レスポンスの `result.id`（Key ID）と `result.pem`（base64 のまま貼ってよい）を控える。**pem は再取得できない**ので安全に保管する。
4. **Webhook**:
   ```bash
   curl -X PUT "https://api.cloudflare.com/client/v4/accounts/<ACCOUNT_ID>/stream/webhook" \
     -H "Authorization: Bearer <API_TOKEN>" -H "Content-Type: application/json" \
     -d '{"notificationUrl": "https://{host}/webhooks/cloudflare/{app_key}"}'
   ```
   レスポンスの `result.secret` が Webhook の署名シークレット。※ Webhook の URL はアカウントに 1 つです。1 つの Cloudflare アカウントを複数アプリで共有する場合は、代表のアプリの URL を登録し、残りは「プロバイダから状態を取得」ボタンで反映してください（アプリごとに Cloudflare アカウントを分けるのが推奨）。
5. **カスタマーサブドメイン**: Stream の動画詳細の埋め込みコードなどに出る `customer-xxxx.cloudflarestream.com`。

### 2-2. app-eevee への登録
管理画面 → アプリ設定 → 動画プロバイダ に、Account ID / カスタマーサブドメイン / API Token / Key ID / 秘密鍵 / Webhook シークレットを入力して保存。

### 2-3. 確認項目
| 確認すること | 手順 | 期待結果 |
|---|---|---|
| アップロード | 動画 → 動画を追加 → 保存 → ファイルを選んでアップロード | ブラウザから Cloudflare に直接送られ（開発者ツールの Network で `upload.videodelivery.net` などへの POST）、状態が「変換中」になる |
| 署名必須 | Cloudflare ダッシュボードで動画を開く | 「Require Signed URLs」が有効 |
| Webhook | 変換完了まで待つ | 状態が「再生可能」になり、長さ（秒）とサムネイルが入る。届かない場合は「プロバイダから状態を取得」 |
| 再生 | `POST /api/v1/apps/{app_key}/videos/{id}/play`（メンバー限定ならユーザーのトークン付き） | `hls_url` を Safari / VLC / AVPlayer で開くと再生できる |
| 署名なしの拒否 | `https://customer-xxxx.cloudflarestream.com/{uid}/manifest/video.m3u8`（トークンなし） | 401/403 で再生できない |
| 期限切れ | 余裕時間を 0 分、短い動画で再生し、`expires_at` を過ぎてから URL を開く | 再生できない |
| 同時視聴（拒否） | 上限 2・拒否で、同じユーザーで 3 回 play | 3 回目が `409 concurrent_limit_exceeded`。管理画面の「同時視聴の超過件数」が増える |
| 同時視聴（最古を停止） | 設定を「最も古いセッションを停止」にして 3 回 play | 3 回目は成功し、1 回目のセッションの heartbeat が `stop`（kicked） |
| 期限切れ | play 後、heartbeat を 90 秒以上送らない | heartbeat が `stop`（expired）。新しい play ができる |
| 統計 | 数回再生・heartbeat | 動画一覧・詳細に再生回数・視聴時間が出る |

### 2-4. 既知の制限
- ブラウザからのアップロードは Direct Creator Upload の基本方式（multipart POST）で、**200MB まで**。それを超えるファイルは tus（再開可能アップロード、`Upload-Length` 付きで `direct_upload` を呼ぶ方式）が必要で、今回は未対応。
- kick された再生は、アプリが heartbeat の `stop` に従って止める前提（渡済みの HLS URL はトークンの期限まで有効）。

## 3. メール（招待・パスワード再設定）

Sail には Mailpit が入っています。`http://localhost:8025` を開き、
- システム管理画面で組織の管理者を招待 → 招待メールが届き、リンクから参加できる
- builtin のアプリで `POST /auth/password/forgot` → 再設定コードのメールが届く

ことを確認する。本番では `.env` の `MAIL_*` を SMTP / SES などに変更する。
