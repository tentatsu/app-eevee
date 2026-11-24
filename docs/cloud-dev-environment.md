# Claude Code のクラウド環境で開発するときの知見

app-eevee を Claude Code のクラウド実行（claude.ai/code やアプリから使う、使い捨てのコンテナ）で開発してわかったことのまとめです。ほかのリポジトリをクラウドに移すときにも使える形で書いています。

- 確認した時期: 2026年11月（コンテナのイメージや設定画面は変わるので、作業前に現状も確かめてください）
- 本番の置き場所（Laravel Cloud）の話は [laravel-cloud-checklist.md](laravel-cloud-checklist.md) を見てください。ここは**開発用のコンテナ**の話です。

---

## 0. 先に知っておくこと

| # | 内容 | どう困るか・どうするか |
|---|---|---|
| 1 | **Docker は使えない。** `docker` コマンドはあるが、Docker 本体（デーモン）が動いていない | Sail・docker compose は起動できない。PHP・MySQL・Redis をコンテナに直接入れて動かす（多くは最初から入っている） |
| 2 | **コンテナは使い捨て。** しばらく使わないと消え、次は新しいコンテナで始まる | 依存パッケージの導入や DB の作成を毎回やり直すことになる。環境の「セットアップスクリプト」に書いておく（3 章） |
| 3 | **MySQL・Redis は自動で起動しない** | 新しいコンテナでは毎回 `service mysql start` / `service redis-server start` が必要。途中で止まることもあるので、テストが `Connection refused` で全滅したらまず起動し直す |
| 4 | **外への通信は許可したホストだけ**（プロキシ経由） | パッケージのレジストリ（Packagist・npm・Maven Central など）は既定で通る。それ以外は環境の設定で許可する（4 章） |
| 5 | **GitHub の zip のダウンロードが通らない**（`api.github.com` / `codeload.github.com` が 403） | Composer の既定（dist = zip）が失敗する。git（github.com）は通るので、`scripts/composer-prefetch.py` で git から zip を作って Composer のキャッシュに置いてから入れる（2 章） |
| 6 | **`--prefer-source`（git clone）で逃げるとディスクがあふれる** | 各パッケージに履歴ごとの `.git` が残り、`vendor` 約 8GB ＋ キャッシュ約 8GB で、ディスクの割り当てを使い切って失敗した。8 分もかかる。使わない（2 章） |
| 7 | **ディスクの割り当てには上限がある** | `df` の空きが残っていても書けなくなる。`No space left on device` が出たら、大きなキャッシュ（Composer・Gradle）を消す |
| 8 | **API キーをチャットに貼らない** | 環境の「ネットワークシークレット」に保存すると、プロキシがリクエストに自動で付ける（5 章）。コンテナの中にもログにも値が残らない |
| 9 | **作業はコミットして push しないと残らない** | コンテナが消えると、push していない変更は失われる |

---

## 1. 最初から入っているもの（2026年11月時点）

app-eevee の環境で確認したもの。イメージが変わると違う可能性がある。

| もの | バージョン・場所 | メモ |
|---|---|---|
| PHP | 8.3（`pdo_mysql`・`redis`・`intl`・`gd` などの拡張つき） | Laravel 13 が動く。8.5 で確かめたいなら別途入れる |
| Composer | 2.8 | |
| MySQL | 8.0 | `root` はパスワードなし（ソケット接続）。起動は手動 |
| Redis | 7.0 | 起動は手動 |
| Node.js / npm | あり | |
| Chromium と Playwright | `require('playwright')` で使える | 画面のスクリーンショット確認に便利。`playwright install` はしない |
| Android SDK | `/opt/android-sdk` | Gradle の依存を落とすのに `dl.google.com` などの許可が要った（4 章） |
| JDK / Gradle / Maven | `/opt` の下 | プロキシの証明書は設定済み |

---

## 2. PHP（Composer）の入れ方

### 2-1. git から zip を作ってキャッシュに置く（`scripts/composer-prefetch.py`）

```bash
python3 scripts/composer-prefetch.py
COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-dist --no-interaction --no-progress
```

- `composer.lock` の各パッケージを、GitHub から `git fetch --depth 1 origin <コミット>` で浅く取得して zip にし、Composer のキャッシュ（`cache-files-dir`）に置く。8 並列。
- そのあと `--prefer-dist` で入れると、Composer はキャッシュから展開するので zip のダウンロードが起きない。
- キャッシュのファイル名は `パッケージ名/sha1(ダウンロード URL).zip`（参照 ID ではないので注意。Composer 2.8 で確認）。
- app-eevee（165 パッケージ）で、取得 31 秒・キャッシュ 331MB、インストール 9 秒・`vendor` 1.3GB。
- ほかのリポジトリでも、`scripts/composer-prefetch.py` をそのままコピーして使える（Python 3 の標準ライブラリだけで動く）。

### 2-2. 試してうまくいかなかった方法

| 方法 | 結果 |
|---|---|
| `composer install --prefer-source`（git clone） | 8 分以上かかり、`.git` 込みで `vendor` 約 8GB ＋ キャッシュ約 8GB。ディスクの割り当てを使い切って失敗 |
| `--prefer-source` のあとに `.git` を消す | 消す前のピークで容量が足りない |
| git の取得元がないパッケージ（`phpstan/phpstan`） | `--prefer-source` でも zip を取りに行き、403 → `Could not authenticate against github.com` で止まる（prefetch なら問題なし） |
| サードパーティの Composer ミラー | 安全確認の仕組みに止められた |
| GitHub のトークンを Composer に設定 | zip のホスト自体が許可されていないので解決しない |

## 3. セットアップスクリプト（環境の設定）

新しいコンテナを作るたびに、リポジトリを clone したあと・Claude が始まる前に root で実行される。環境の設定画面（セッションのタイトルバーの環境メニュー → 編集）の「セットアップスクリプト」に書く。

- リポジトリの**親ディレクトリ**から始まる。リポジトリには `cd リポジトリ名` で入る（絶対パスは使わない）。
- 失敗するとセッションが始まらない。リポジトリがない場合は飛ばすようにする。
- 環境変数・シークレットは読めない。秘密の値は書かない。

app-eevee 用（MySQL・Redis の起動、DB とユーザーの作成、Composer、`.env`、マイグレーション）:

```bash
#!/bin/bash
set -e
service mysql start >/dev/null 2>&1 || true
service redis-server start >/dev/null 2>&1 || true
for i in $(seq 1 30); do mysql -uroot -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS eevee; CREATE DATABASE IF NOT EXISTS testing; CREATE USER IF NOT EXISTS 'sail'@'localhost' IDENTIFIED BY 'password'; GRANT ALL ON *.* TO 'sail'@'localhost';"
if [ -d app-eevee ]; then
  cd app-eevee
  if [ ! -f .env ]; then
    cp .env.example .env
    # Sail 用の接続先（mysql / redis）を、このコンテナの 127.0.0.1 に変える。外部サービスはフェイクに
    sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/; s/^REDIS_HOST=.*/REDIS_HOST=127.0.0.1/; s/^MAIL_MAILER=.*/MAIL_MAILER=log/; s/^EEVEE_PUSH_FAKE=.*/EEVEE_PUSH_FAKE=true/; s/^EEVEE_VIDEO_FAKE=.*/EEVEE_VIDEO_FAKE=true/' .env
  fi
  # GitHub の zip をダウンロードできないので、git で取得して Composer のキャッシュに置いてから入れる
  python3 scripts/composer-prefetch.py
  COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-dist --no-interaction --no-progress
  grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
  php artisan migrate --force
fi
```

- MySQL の起動直後は接続できないことがあるので、`SELECT 1` が通るまで待つ（`mysqladmin ping` は待ちきれないことがあった）。
- テストは `phpunit.xml` で `testing` データベースと Redis の DB 14・15 を使う設定にしてある。
- 新しい clone・MySQL と Redis が止まった状態から、このスクリプトで約 50 秒。そのままテスト 230 件・静的解析・整形が通ることを確かめた（2026年11月）。
- **`.env.example` から作った `.env` で試すこと。** app-eevee では、`.env.example` の `REDIS_SCHEME=`（空）が Redis の接続を壊す不具合が、新しい環境で初めて見つかった（手元の `.env` にはその行がなかった）。設定側で空文字を未設定として扱うよう直した。

---

## 4. ネットワーク（許可するホスト）

既定で通るもの: パッケージのレジストリ（Packagist・npm・PyPI・Maven Central など）と、接続した MCP サーバー。それ以外は環境の設定で**ホスト名を1つずつ**許可する（ワイルドカードは画面の設定でだけ）。

app-eevee で追加したもの:

| ホスト | 理由 |
|---|---|
| `dl.google.com` | Android の Gradle（Google の Maven リポジトリ、AGP・AndroidX） |
| `gcp.mirrors.maven.org` | Gradle が Maven Central のミラーとして使う |
| `cloud.laravel.com` | Laravel Cloud の API（ネットワークシークレットを保存すると自動で許可される） |

- 許可していないホストへの通信は、プロキシが 403 を返す。Bash の出力の最後に `[agent-proxy]` の行で、どのホストが止められたかが出る。
- 本番のサイト（例: `*.laravel.cloud`）にも、許可しないと届かない。本番の確認は、Laravel Cloud の API でコマンドを実行する形にした（[laravel-cloud-checklist.md](laravel-cloud-checklist.md) 4 章）。
- 証明書は差し替えられている（プロキシが TLS を張り直す）。CA は `/root/.ccr/ca-bundle.crt`。よく使うツールは設定済みで、TLS の検証を切ってはいけない。

---

## 5. API キー・秘密の値

- **チャットに貼らない。** Claude が「ネットワークシークレット」の入力フォームを出すので、そこに入れる。値は環境に保存され、プロキシが指定のホストへのリクエストに自動で付ける（`Authorization: Bearer …` など）。Claude にもコンテナにも値は見えない。
- **環境ごとに保存される。** 同じ環境を使うほかのセッションでも使える。リポジトリごとに環境を分けると、キーが混ざらない（app-eevee は Laravel Cloud のトークンのために専用の環境「app-eevee」を作った）。
- CLI が「キーがないと起動しない」場合は、ダミーの値を入れておけば、プロキシが本物に差し替える。
- Firebase の `google-services.json` のような**ファイルの秘密**はシークレットにできない。チャットでファイルを渡してもらい、`.gitignore` に入れてコミットしない。

---

## 6. 環境の分け方

- **Default 環境**: すべてのリポジトリで共通。特定のリポジトリだけに要る設定（許可ホスト・シークレット・セットアップスクリプト）は入れない。
- **リポジトリごとの環境**: 例「app-eevee」。許可ホスト・シークレット・セットアップスクリプトをまとめる。新しいセッションを始めるときに選ぶ。
- Claude に「環境を作って」「セットアップスクリプトを保存して」と頼むと、内容を確認するカードが出て、承認すると保存される。

---

## 7. 新しいリポジトリをクラウドに移すときの手順

1. [ ] 新しいセッションでリポジトリを開き、必要なものが入っているか確かめる（`php -v`、`mysql --version`、`node -v` など）。
2. [ ] Docker 前提（Sail・compose）の手順を、コンテナに直接入れる手順に読み替える（DB の接続先は `127.0.0.1`）。
3. [ ] 依存パッケージを入れる。Composer なら 2 章。止められたホストは `[agent-proxy]` の行で確かめる。
4. [ ] 必要なホストを環境で許可する（4 章）。API キーはネットワークシークレットに（5 章）。
5. [ ] テスト・静的解析・整形が通るまでやる。
6. [ ] 通った手順をセットアップスクリプトに保存する（3 章）。新しいコンテナから一度試す。
7. [ ] README に「クラウドで開発する場合」の注意を一行書いておく。

---

## 8. そのほかの小さな知見

- **時刻に依存するテスト**: コンテナは UTC。「3時間進めても同じ日」のようなテストは、日本時間の朝（UTC の深夜）に落ちることがある。テストの中で時刻を固定する（app-eevee では `travelTo(now()->startOfDay()->addHours(9))`）。
- **長い処理**: Bash は10分で打ち切られ、それ以上はバックグラウンドに回る。
- **Android のビルド**: Robolectric と Roborazzi で、エミュレーターなしに画面のスクリーンショットのテストができた。APK はビルドしてファイルとして渡し、実機で確かめてもらう。
- **画面の確認**: Playwright（Chromium）で管理画面を開いてスクリーンショットを撮り、見た目を確かめられる。
