# 設計判断の記録（decisions）

仕様に書かれていない細部や、選択肢があった点について「判断内容・理由・代替案」を記録します。

---

## D-001 管理画面は Filament ではなく Blade（＋少量の素の JavaScript）で実装する

- **判断**: 管理画面は Laravel の Blade とコントローラーで実装した。Filament・Livewire は使っていない。
- **理由**
  - 仕様の URL 設計 `/{org_slug}/login`（組織ごとのログイン画面）を Filament で素直に満たせない。Filament のマルチテナンシーはパネル単位のログイン（`/admin/login` → テナント選択）が前提で、ログイン画面の手前にテナント（組織）を置くにはパネルのルーティングやログイン処理を大きく上書きする必要がある。
  - 組織の解決 → IP 制限 → 認証 → 所属確認 → アプリの解決、という順序のミドルウェアを確実に適用し、テストで保証したかった。素の Blade ならルートとミドルウェアがそのまま見えるため、テナント分離のテストが書きやすい。
  - 仕様では「Livewire＋Blade で実装してよい」とされているが、今回の画面（一覧・フォーム・プレビュー）はフォーム送信と小さな fetch で十分で、Livewire を入れる利点が小さかった。セグメントのプレビューなど動的な部分は素の JavaScript（ビルド不要）で実装した。
- **代替案**: Filament v4 のパネルを組織ごとに動的に登録する／`tenantRoutePrefix` を使い、ログインだけ独自ルートにする。Livewire コンポーネントでフォームを組む。将来、画面数が増えて CRUD の量産が必要になれば Filament の導入を再検討する（`/{org_slug}/login` の要件を緩められるなら導入は容易）。

## D-002 管理者のセッションは「ログインした組織」に紐づける

- **判断**: 1人の管理者は複数の組織に所属できるが、組織 A のログイン画面でログインしたセッションでは組織 A の管理画面しか使えない。組織 B に所属していても、B の画面を開くと B のログイン画面へリダイレクトする（B でもログインすれば両方使える）。所属していない組織は 404。
- **理由**: 仕様「組織Aでログインしたセッションで組織Bの管理画面にアクセスできないこと」を厳密に満たすため。また組織ごとの IP 制限はログイン画面にもかかるため、B のログインは必ず B の IP 制限を通る。
- **代替案**: 所属していればどの組織でも同じセッションで使える方式（組織切り替えメニュー）。利便性は高いが、上記の要件を満たさない。

## D-003 テナント分離はグローバルスコープ＋作成時の検査で行う

- **判断**: `TenantContext`（リクエストごとに生成）に現在の組織・アプリを保持し、`BelongsToOrganization` / `BelongsToApp` トレイトのグローバルスコープで全クエリに `organization_id` / `app_id` 条件を付ける。アプリが確定していない組織の画面でも、`app_id IN (組織のアプリ)` に限定する。作成・更新時に別のテナントの ID が指定されたら `TenantViolation` 例外にする。
- テナントの解決ミドルウェアはルートモデルバインディング（`SubstituteBindings`）より前に実行するよう優先順位を設定しているため、URL の子リソース（`/{org}/apps/{app}/notices/{notice}`）も自動的にスコープがかかり、他のアプリの ID を指定すると 404 になる。
- **代替案**: データベースを組織ごとに分ける（マルチDB型のテナンシー）。分離は強いが、受託の小〜中規模アプリでは運用コスト（マイグレーション・バックアップ・横断集計）が見合わない。
- **注意**: キューのジョブやコンソールではテナント文脈が空のためスコープはかからない。ジョブは対象の ID を明示して処理する。

## D-004 組織の削除は論理削除（ソフトデリート）

- **判断**: システム管理者による組織の削除は `deleted_at` を立てる論理削除。削除済みの slug は再利用できない（ユニーク制約のまま）。確認のため slug の入力を求める。
- **理由**: 誤削除からの復旧と、監査ログ・配信ログとの整合のため。
- **代替案**: 物理削除（cascade）。完全に消す運用が必要になったら、論理削除から一定期間後に物理削除するコマンドを追加する。

## D-005 IP 許可リストの保存形式と判定

- 組織の許可リストとシステム管理画面の許可リストは同じテーブル `ip_allow_entries` に保存する（`organization_id IS NULL` がシステム用）。システム用は `.env` の `SYSTEM_IP_ALLOWLIST` と合算する。
- 値は正規化して保存する（単一 IP は `/32`・`/128` を付け、IPv6 は短縮表記）。判定は Symfony の `IpUtils::checkIp`。
- 締め出し対策として、保存時に現在の IP がリストに含まれなければ警告を出し、チェックボックスで確認したうえで保存させる。システム管理者はどの組織の制限でも解除でき、`php artisan eevee:ip-allowlist:clear {org_slug}`（`--system` でシステム用）でも解除できる。
- 拒否は `storage/logs/security-*.log`（`security` チャンネル）に記録する。

## D-006 監査ログは明示的に記録する

- **判断**: Eloquent のオブザーバーで自動記録するのではなく、コントローラーで `AuditLogger::record()` を明示的に呼ぶ。
- **理由**: 「送信」「再発行」「解除」のようにモデルの変更と1対1にならない操作があり、要約文（summary）も操作ごとに書きたいため。秘密情報（secret / token / password / private_key / credential など）は記録時にマスクする。
- **代替案**: オブザーバーによる自動記録（漏れは減るが、ノイズが増え、意味のある操作名を付けにくい）。

## D-007 Larastan のキャスト推論

- `phpstan.neon` で `parseModelCastsMethod: true` を有効にしている。Laravel 11 以降の `casts()` メソッドに書いたキャストを Larastan が型として認識するために必要。

## D-008 app_secret はハッシュで保存し、サーバー間APIは Bearer 方式で認証する

- **判断**: `app_secret` は SHA-256 のハッシュだけを保存し、平文は発行・再発行の直後に一度だけ画面に表示する。サーバー間APIは `Authorization: Bearer {app_secret}` で認証する（HTTPS 前提）。
- **理由**: 仕様で「ハッシュ化して保存」とされている。HMAC 署名方式はサーバー側で秘密値の平文（または可逆な形）が必要になり、ハッシュ保存と両立しない。秘密値は48文字のランダム文字列で総当たりが現実的でないため、bcrypt ではなく SHA-256 で十分（定数時間比較 `hash_equals` を使う）。
- **代替案**: 秘密値を暗号化して保存し、`X-Eevee-Signature: HMAC-SHA256(timestamp + body)` で署名検証する方式（リプレイ対策・改ざん検知ができる）。ハッシュ保存の要件を外せるなら将来追加する。

## D-009 アプリユーザーの属性の保存形式と正規化

- 属性は `app_users.custom_attributes`（JSON）に保存する。カラム名を `attributes` にしなかったのは、Eloquent の内部プロパティ `$attributes` と衝突するため（API では `attributes` として返す）。
- 書き込み時に「属性の定義」の型で正規化する: `int` は整数、`decimal` は小数（4桁で丸め）、`bool` は true/false、`date` は UTC の `Y-m-d H:i:s` 文字列（タイムゾーン付きの ISO 8601 も受け付けて UTC に変換）。正規化しておくことで、セグメントの SQL で文字列比較・CAST がそのまま使える。
- 定義にないキーはエラー。`null` を指定したキーは削除。定義を削除してもユーザーに保存済みの値は残す（再定義すれば再び使える）。キーと型は作成後に変更できない（既存データとの不整合を防ぐ）。
- アプリからの更新は「アプリから更新可」の定義だけ。サーバー間APIの一括更新は全件を先に検証し、1件でも不正なら何も更新しない。

## D-010 アプリユーザーのトークン

- builtin 方式: Sanctum のトークンを有効期限 365 日で発行する（`config/eevee.php` の `app_users.builtin_token_ttl_days`）。パスワード再設定に成功すると全トークンを無効にする。
- external 方式: 顧客のバックエンドがサーバー間APIで `POST /users/{external_user_id}/tokens` を呼び、短命（初期値 60 分、アプリごとに設定可）のトークンをアプリに渡す。期限が切れたらアプリは顧客のバックエンド経由で再取得する。
- パスワード再設定はメールで「再設定コード」（10文字）を送り、アプリの画面で入力してもらう方式にした。モバイルアプリではメールのリンクからアプリに戻す（ユニバーサルリンク等）設定がアプリごとに必要で、母体として共通化しにくいため。コードは有効期限 60 分、レート制限（1分10回）あり。
- 認証方式が合わない API を呼ぶと `409 auth_mode_mismatch` を返す。

## D-011 通知カテゴリの受信設定はユーザー単位

- **判断**: カテゴリのオン・オフは `app_users.notification_preferences`（JSON）に保存する。未設定のカテゴリはカテゴリの初期値に従う。ログインしていない端末は常にカテゴリの初期値として扱う。
- **理由**: 仕様で「AppUser に持たせる」とされているため。
- **代替案**: 端末単位で持つ（未ログインでも設定できる）。必要になったら `devices.notification_preferences` を追加し、ユーザーの設定を優先する形で拡張できる。

## D-012 端末の登録

- `installation_id` をキーに upsert する。ユーザーのトークン付きで呼ぶとその端末をユーザーに紐づける。ログイン API・トークン発行 API に `installation_id` を渡しても紐づく。ログアウト API に `installation_id` を渡すと紐づけを外す。
- 同じ FCM トークンを持つ別の端末（再インストールで installation_id が変わった場合など）があれば、古い端末のトークンを外して無効化する（二重送信の防止）。
- 無効化された端末でも、新しい FCM トークンが登録されれば有効に戻す。
- バージョン比較のため `app_version` から比較用の整数 `app_version_num` を自動で作る（`major * 10^12 + minor * 10^6 + patch`）。

## D-013 API のレスポンス形式

- 成功: `{"data": ..., "meta": {...}}`（`meta` はページングなど必要なときだけ）
- 失敗: `{"error": {"code": "機械が読めるコード", "message": "説明", "details": {...}}}`。バリデーションエラーは `422 validation_failed` で `details.fields` に項目ごとのメッセージ。
- 主なコード: `app_not_found`, `unauthenticated`, `invalid_credentials`, `invalid_app_secret`, `forbidden`, `not_found`, `validation_failed`, `rate_limited`, `auth_mode_mismatch`, `user_not_found`, `device_not_found`, `invalid_reset_token`, `server_error`（動画・プッシュのコードは各フェーズで追加）。一覧は `docs/client-integration.md`。
- レート制限: アプリ単位（初期値 6000/分）と IP 単位（300/分）の両方。ログイン系はさらに IP＋メールアドレス単位で 10/分。サーバー間APIはアプリ単位で 1200/分。いずれも `.env` で変更できる。

## D-014 セグメントの属性検索は当面 JSON カラムで行う（EAV・生成カラムの検討）

- **判断**: 属性は `app_users.custom_attributes`（JSON）に保存し、セグメントの条件は `JSON_EXTRACT` ＋ `CAST` の SQL に変換する。条件の値はすべてバインド変数で渡す（SQL インジェクション対策）。
- **計測**: 10万端末で最大 0.4 秒（`docs/performance.md`）。目標の「数秒以内」を満たすため、当面この方式とする。
- **将来の選択肢**
  1. **生成カラム＋インデックス（MySQL の generated column）**: よく使う属性だけ `ALTER TABLE app_users ADD COLUMN attr_gender VARCHAR(32) AS (custom_attributes->>'$.gender') STORED, ADD INDEX (...)`。アプリごとに属性が異なるためマイグレーションを動的に発行する仕組みが必要で、列数に上限がある。少数の「よく使う属性」に限るなら最も手軽。MySQL 8 の multi-valued index / 関数インデックスでも同様のことができる。
  2. **EAV テーブル**（`app_user_attributes(app_user_id, key, value_string, value_number, value_date)` に `(key, value_*)` のインデックス）: 属性の追加にスキーマ変更が不要で、条件ごとにインデックスが効く。一方、条件の数だけ JOIN / EXISTS が増え、AND/OR の入れ子の SQL が複雑になる。書き込み量も増える。
  3. **検索エンジンへの同期**（OpenSearch など）: 数百万端末規模で検討。
  - 移行しやすいように、属性の型変換・SQL 化は `SegmentCompiler::expression()` に閉じ込めてある。生成カラムや EAV に変える場合はここを差し替える。

## D-015 セグメント条件の意味（未ログイン端末・値がない場合）

- セグメントは「端末（devices）に、紐づくユーザー（app_users、未ログインなら NULL）を LEFT JOIN した行」に対して評価する。プッシュの対象数・端末数はこの行数、ユーザー数は重複を除いたログインユーザー数。
- 属性の条件は「値が存在し、かつ条件を満たす」ときだけ真。`neq`・`not_in` も値がないユーザー・未ログインの端末には一致しない（「値がない」は `not_exists` で明示的に指定する）。SQL の NULL の性質をそのまま使っており、利用者にとって「性別≠女性」に未回答の人が含まれない方が自然と判断した。
- 通知カテゴリの条件は、ユーザーの設定がなければカテゴリの初期値で判定する（未ログイン端末も初期値）。
- お知らせの対象判定（ユーザー単位）は「ユーザーの有効な端末のどれか（端末がなければユーザーだけ）で条件を満たせば対象」とする。
- 日時の入力値はアプリの表示タイムゾーンとして解釈して UTC に変換する。`within_days`・`before_days` は実行時点を基準にする。
- 入れ子は 5 段まで、条件は 100 個まで。

## D-016 プッシュ配信の処理方式

- **流れ**: 送信ボタン → キャンペーンを `queued` にして `PrepareCampaignJob` を投入 → 対象端末を `INSERT IGNORE ... SELECT` で配信ログ（`push_deliveries`）に書き込む → 未送信の行を 500 件ずつ `SendCampaignChunkJob` に分けて投入 → FCM の `sendMulticast`（HTTP v1）で送信 → 結果を行ごとに保存し、キャンペーンの集計値を加算 → 未送信がなくなったら `sent`。
- **キュー**: Redis の `push` キュー（Horizon の `supervisor-push`）。準備ジョブとチャンク送信ジョブを分けたのは、10万件規模の抽出が1ジョブの時間内に終わるようにし、送信だけを並列化するため。
- **冪等性（二重送信の防止）**
  - `(push_campaign_id, device_id)` のユニーク制約。抽出は `INSERT IGNORE` なので、準備ジョブが重複実行されても行は増えない。
  - 送信前に「未送信（pending）」の行だけを `SELECT ... FOR UPDATE` で取り、「送信中（sending）」にしてから送る。ジョブが重複実行されても、既に送信中・送信済みの行は送らない。
  - キャンペーンの状態遷移（draft/scheduled → queued → sending → sent）は条件付き UPDATE で行い、送信ボタンの二度押しや予約処理との競合でも一度しか開始しない。
  - トレードオフ: FCM に送った直後にワーカーが落ちた場合、その行は「送信中」のまま残り、再送しない（重複送信より取りこぼしを選ぶ「最大1回」）。残った行は一覧で確認できる。
- **再試行**: FCM の一時的なエラー（UNAVAILABLE / INTERNAL / QUOTA_EXCEEDED / 接続失敗）は、その行だけを pending に戻して例外を投げ、10 → 30 → 90 → 270 秒の指数バックオフで最大 5 回まで再試行する。上限に達したら失敗として確定する。
- **送信レート**: アプリごとに「1秒あたりのチャンク数」（初期値 20 チャンク ≒ 1万通/秒）を `RateLimited` ジョブミドルウェアで制御する。レート制限で待たされた分は再試行回数に数えない（`maxExceptions` と `retryUntil` を使用）。
- **無効トークン**: `UNREGISTERED`（NotFound / SenderIdMismatch）と、トークンが不正な `INVALID_ARGUMENT` は端末の `invalidated_at` を記録し、以後の抽出対象から外す。アプリが新しいトークンを登録すれば有効に戻る。トークン以外の `INVALID_ARGUMENT`（ペイロード不正）は再試行しない失敗として記録する。
- **data**: 管理画面で入力した data に `eevee_campaign_id` を自動で加える。アプリは通知のタップ時にこの値と `installation_id` で開封記録APIを呼ぶ。キー `from`・`gcm.*`・`google.*`（FCM の予約語）と `eevee_*` は使えない。
- **テスト配信**: 元のキャンペーンを複製した子キャンペーン（`is_test = true`、対象はテスト端末）を作って即時送信する。元のキャンペーンの状態や集計には影響せず、何度でも送れる。
- **開封率**: 開封数 ÷ 成功数。
- **保持期間**: 端末ごとの送信結果はアプリごとの保持期間（初期値 90 日）を過ぎたら `eevee:push:prune-deliveries`（毎日 03:30）で削除する。キャンペーンの集計値（対象数・成功数・開封数など）は残る。
- **代替案**: FCM のトピック配信（全員向けは速いが、セグメント・開封の端末単位の記録・無効トークンの処理ができない）。

## D-017 お知らせ

- 一覧はピン留め → 公開開始日時（未設定なら作成日時）の新しい順。公開中＝状態が「公開」かつ公開期間内。
- 未ログインでも対象が「全員」のお知らせは見られる（`app_key` だけで呼べる）。セグメント向けはトークンを付けたときに、そのユーザーがセグメントに含まれる場合だけ返す。既読・未読数はログインが必要。
- 本文は Markdown で保存し、API は Markdown（`body`）と HTML（`body_html`）の両方を返す。HTML は生の HTML タグをエスケープし、`javascript:` などの危険なリンクを無効にする（CommonMark の `html_input=escape`, `allow_unsafe_links=false`）。アプリは WebView で `body_html` を表示するか、ネイティブの Markdown 表示に `body` を使う。
- セグメント向けの判定はお知らせごとに SQL を1本実行する。公開中のセグメント向けお知らせが数百件を超えるような運用になったら、配信時にユーザーとの対応表を作る方式に変える。
- 「このお知らせをプッシュ通知する」はプッシュの下書きを作り、編集画面に移動する（即時送信はしない）。data に `type=notice`・`id={お知らせのID}` を自動で入れ、対象（全員／セグメント）もお知らせに合わせる。

## D-018 動画プロバイダの抽象化

- `App\Services\Video\Contracts\VideoProvider` に、アップロード URL の発行・状態取得・署名付き再生の必須化・削除・再生 URL の生成・Webhook の検証と解析を定義した。実装は `CloudflareStreamProvider`。
- アプリごとに `apps.video_provider` でプロバイダを選び、`config/eevee.php` の `video.providers`（名前 → クラス）で解決する（`VideoProviderManager`）。認証情報はプロバイダごとに `apps.video_provider_settings`（暗号化した JSON）に入れるため、DRM 対応のプロバイダ（Mux など）を追加するときもテーブルの変更は不要。
- Cloudflare の API 呼び出しは Laravel の HTTP クライアント経由なので、テストでは `Http::fake()` で差し替えている（実際の通信なし）。ローカル開発で Cloudflare を使わない場合は `EEVEE_VIDEO_FAKE=true` で `FakeVideoProvider` に切り替わる。

## D-019 再生トークン

- 再生のたびに Cloudflare の API（`/token`）を呼ばず、アプリに登録した署名鍵（Key ID と RSA 秘密鍵）でサーバー側で JWT（RS256）を作る。ヘッダー `kid`、クレーム `sub`（動画の uid）・`kid`・`exp`・`nbf`（1分前）。
- 有効期限は「動画の長さ＋余裕時間（アプリ設定、初期値10分）」。長さが分からない場合は 3600 秒とみなす。
- 秘密鍵は Cloudflare の `POST /stream/keys` が返す `pem`（base64 でエンコードされた PEM）をそのまま貼っても、デコード済みの PEM でもよい。
- URL は `https://{カスタマーサブドメイン}/{token}/manifest/video.m3u8`（DASH は `video.mpd`）。サブドメイン未設定時は `videodelivery.net`。
- アップロード時（Direct Creator Upload）に `requireSignedURLs: true` を指定し、さらに Webhook・状態取得で `requireSignedURLs` が false の動画を見つけたら API で true にする（二重の対策）。
- 署名付き URL の追加の制限（`accessRules` による国や IP の制限）は今回は入れていない。必要なら `CloudflareStreamProvider::playbackUrls()` のクレームに追加する。

## D-020 同時視聴数の制御

- 視聴者は「ログインしていればユーザー、未ログインなら端末（installation_id）」。未ログインで公開動画を再生するときは `installation_id` を必須にした（同時視聴数の単位にするため）。
- Redis の ZSET（メンバー=セッションID、スコア=最終ハートビートの時刻）で有効なセッションを管理し、「期限切れの除去 → 件数の確認 → 拒否 or 最古の停止 → 追加」を Lua スクリプトで原子的に実行する。同じユーザーが同時に再生を押しても上限を超えない。
- `kick_oldest` で停止されたセッションは、Redis のセッション状態を `kicked` にし、次のハートビートで `{"action": "stop", "reason": "kicked"}` を返す。アプリは再生を止めて「別の端末で再生が始まりました」と表示する。すでに渡した HLS の URL 自体はトークンの期限まで有効（Cloudflare 側で即時に無効化する仕組みはないため）で、停止はアプリの協力が前提。
- 90 秒（`EEVEE_VIDEO_HEARTBEAT_TIMEOUT`）ハートビートがないセッションは数えない。DB の統計は、次の再生開始時・ハートビート時・毎分の `eevee:playback:expire` で「期限切れ（終了時刻＝最後のハートビート）」にする。
- 視聴秒数は、ハートビートの間隔から計算する（1回あたり最大 60 秒。通信が途絶えた時間を数えすぎないため）。
- 上限超過（拒否・最古の停止）は `playback_limit_events` に記録し、管理画面で動画ごとに件数を表示する。
- セッション ID は推測できない UUID だが、ハートビート・停止は開始した視聴者本人（同じユーザー、または同じ installation_id）でなければ 404 にする。

## D-021 視聴権限の判定のフック

- `VideoAccessPolicy` インターフェース（`check(App, Video, ?AppUser): AccessDecision`）を `config('eevee.video.access_policies')` に並べ、順に評価する。既定は `DefaultVideoAccessPolicy`（再生可能な状態か、公開期間内か、メンバー限定ならログインしているか）。
- 将来の有料プラン判定は、例えば `SubscriptionVideoAccessPolicy`（ユーザーの属性 `plan` や外部の課金 API を見る）を作って配列に追加するだけで差し込める。拒否の理由はコード付き（例: `subscription_required`）で API のエラーとして返る。

## D-022 動画のアップロード

- 管理画面で動画の情報を保存した時点で Cloudflare の Direct Creator Upload を発行し（動画の枠ができる）、編集画面のファイル選択からブラウザが直接 `uploadURL` に送信する。app-eevee のサーバーはファイルを受け取らない。
- 基本アップロード（multipart の POST）は 200MB まで。それを超えるファイルは tus（再開可能アップロード）が必要で、今回は未対応（`docs/manual-verification.md` に記載）。
- 変換完了は Webhook で反映する。Webhook が届かない環境（ローカル開発など）のために「プロバイダから状態を取得」ボタンも用意した。
- 管理画面で動画を削除すると、Cloudflare 側の動画も削除する。

## D-023 その他の細かな判断

- **日時**: DB はすべて UTC（`config/app.php` の timezone は UTC）。管理画面の表示と入力は「アプリの表示タイムゾーン → 組織のタイムゾーン → `EEVEE_DEFAULT_TIMEZONE`（Asia/Tokyo）」の順に決める（`eevee_dt()` ヘルパー）。API は ISO 8601 の UTC（`Z` 付き）で返す。
- **Sail の構成**: `sail:install --with=mysql,redis,mailpit` の標準構成（PHP 8.5 ランタイム、MySQL 8.4）に、Horizon とスケジューラーのサービスを追加した（同じイメージで `SUPERVISOR_PHP_COMMAND` を変えて起動）。
- **テスト DB**: JSON 関数を使うセグメントの SQL を本番と同じ方言で検証するため、テストは SQLite ではなく MySQL（Sail が作る `testing` DB）で実行する。視聴セッションのテストは Redis の DB 14 を使い、各テストの前に空にする。
- **OpenAPI**: Scramble で生成し、`docs/openapi.json` にコミットしている。`{app_key}` はミドルウェアで解決するためコントローラーの引数にないので、`ApiDocsServiceProvider` でパスパラメータと認証方式（userToken / appSecret）を補正している。API を変更したら `php artisan scramble:export` で再出力する。
- **管理画面の CSS/JS**: ビルド不要の素の CSS（`public/css/eevee.css`）と、ビューに埋め込んだ少量の JavaScript にした。Vite / npm のビルドなしで Sail を起動してすぐ使えるようにするため。
- **アプリユーザーの削除**: サーバー間API `DELETE /users/{external_user_id}` でユーザーとトークンを削除する（端末は未ログインの端末として残す）。管理画面からの削除・退会 API は今回は用意していない。
- **招待**: 有効期限 7 日。同じメールアドレスに再度招待すると古い招待は無効になる。既にアカウントがある人はパスワードを入力して参加する。

## D-024 本番は Laravel Cloud を前提にする

- **判断**: 本番環境は Laravel Cloud を前提にする。サーバーの準備・デプロイ・費用（MVP で常時稼働のサーバー代がかかること）を減らすため。
- **理由**: GitHub への push で自動デプロイでき、MySQL・Valkey（Redis 互換）・キューのワーカー・スケジューラーをマネージドで使える。アクセスがない間は休止して課金を抑えられる。CDN と DDoS 対策（Cloudflare）が標準で付く。app-eevee は1つの環境で複数の顧客・アプリを扱うので、費用を案件全体で分け合える。
- **調整した点**
  - Horizon はなくても動く構成にした（`EEVEE_HORIZON_ENABLED`）。Laravel Cloud のマネージドキューは `queue:work` で動き、Horizon に対応していないため。ワーカーは `push` と `default` のキューを処理する。
  - キューの `retry_after` の既定値を 90 秒から 660 秒にした。最長のジョブ（PrepareCampaignJob、timeout 600 秒）より短いと、処理中のジョブが別のワーカーで二重に実行されるため（送信自体は行ロックで二重にならないが、無駄な処理になる）。
  - Redis（Valkey）の接続に `REDIS_SCHEME`（TLS）を追加し、キーの接頭辞を APP_NAME から切り離して固定値（`eevee-`）を既定にした。
  - セキュリティログ（IP 制限の拒否など）の出力先を `LOG_SECURITY_CHANNELS` で切り替えられるようにした。Laravel Cloud では `stderr` にしてログ画面で見る。
  - スケジューラーの定期実行に `onOneServer()` を付け、複数台になっても1回だけ実行されるようにした。スケジューラーの最終実行時刻を記録し、`eevee:doctor` で確認できるようにした。
  - `eevee:doctor` コマンドを追加した。Valkey で視聴セッションの Lua スクリプト（EVAL / cjson / ZADD XX）が動くかなど、本番の設定と接続を確認する。
  - 管理画面とメールに出す名前を `APP_NAME` から取るようにした（コード内の識別子とは切り離した）。
- **リスクと保険**: Laravel Cloud は 2025 年に一般提供が始まった新しいサービスで、仕様や料金の変更があり得る。稼働率の保証は Business 以上の追加サポートでのみ付く。app-eevee は Laravel Cloud 専用の機能に依存しない普通の Laravel アプリとして保ち、Forge ＋ VPS や AWS にもそのまま移せるようにしておく。DB のバックアップは外部にも取る。

## D-025 動画のサムネイル

- **判断**: Cloudflare Stream が動画から自動で作るサムネイルを使う。サムネイルの URL は DB に保存せず、API・管理画面で表示するたびに署名付き URL（再生トークンと同じ RS256 の JWT）を作る。
- **理由**: 動画を「署名付き URL 必須」にしているため、サムネイルにもトークンが必要。署名なしの URL を保存しても表示できない。
- 署名の期限は「翌日の0時 + 1日」にそろえ、`nbf` を付けない。同じ日のうちは同じ URL になり、アプリ側で画像をキャッシュできる。
- アップロード時に `thumbnailTimestampPct: 0.1` を指定し、冒頭の黒い画面ではなく動画の 10% の位置からサムネイルを作る。
- 管理画面でサムネイルの URL を指定した動画は、そちらを優先する。以前の版で保存した Cloudflare の（署名なしの）URL は、自動のサムネイルとして扱い、署名付きに置き換える。
