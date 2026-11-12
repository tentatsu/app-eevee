# app-eevee

受託で開発するスマホアプリ（iOS / Android）に共通するバックエンド機能を、1つのシステムでまとめて提供する「アプリ開発の母体」です。
1つの app-eevee で複数の顧客組織・複数のアプリを管理するマルチテナント構成です。

（詳細は各フェーズの実装に合わせて追記します）

## 開発環境（Laravel Sail）

```bash
cp .env.example .env
composer install            # または docker で: docker run --rm -v $(pwd):/app -w /app laravelsail/php85-composer composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

## テスト・静的解析・整形

```bash
./vendor/bin/sail test                 # Pest
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G   # Larastan (level 6)
./vendor/bin/sail pint                 # Pint
```
