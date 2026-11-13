<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Services\Video\Cloudflare\CloudflareStreamProvider;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * アプリに保存した Cloudflare の Account ID と API Token で、必要な API が呼べるかを確かめる（値は表示しない）。
 */
class CheckVideoProvider extends Command
{
    protected $signature = 'eevee:video:check {app_key}';

    protected $description = 'アプリの Cloudflare Stream の認証情報で、トークンの有効性と Stream API の権限を確認する';

    public function handle(): int
    {
        $app = App::withoutGlobalScopes()->where('app_key', (string) $this->argument('app_key'))->first();
        if ($app === null) {
            $this->error('アプリが見つかりません。');

            return self::FAILURE;
        }
        $account = $app->videoSetting('account_id');
        $token = $app->videoSetting('api_token');
        if ($account === null || $token === null) {
            $this->error('Account ID または API Token が未設定です。');

            return self::FAILURE;
        }

        $this->line('Account ID: '.$account.'（'.strlen($account).'文字）');
        $this->line('API Token: '.strlen($token).'文字'.(trim($token) !== $token ? '（前後に空白があります）' : ''));

        $base = CloudflareStreamProvider::API_BASE;
        $http = fn () => Http::withToken(trim($token))->acceptJson()->timeout(20);
        $rows = [];

        // 1. トークン自体の有効性（ユーザーのトークン / アカウントのトークン）
        $user = $this->call_($http, 'GET', "{$base}/user/tokens/verify");
        $acct = $this->call_($http, 'GET', "{$base}/accounts/{$account}/tokens/verify");
        $rows[] = ['トークンの確認（ユーザーのトークン）', ...$this->summary($user)];
        $rows[] = ['トークンの確認（アカウントのトークン）', ...$this->summary($acct)];

        // 2. アカウントへのアクセス
        $rows[] = ['アカウントの参照', ...$this->summary($this->call_($http, 'GET', "{$base}/accounts/{$account}"))];

        // 3. Stream の権限
        $rows[] = ['Stream: 動画一覧（読み取り）', ...$this->summary($this->call_($http, 'GET', "{$base}/accounts/{$account}/stream?limit=1"))];
        $rows[] = ['Stream: 署名鍵の一覧', ...$this->summary($this->call_($http, 'GET', "{$base}/accounts/{$account}/stream/keys"))];
        $rows[] = ['Stream: Webhook の参照', ...$this->summary($this->call_($http, 'GET', "{$base}/accounts/{$account}/stream/webhook"))];

        $this->table(['確認', 'HTTP', '結果'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  callable(): PendingRequest  $http
     */
    private function call_(callable $http, string $method, string $url): Response|string
    {
        try {
            return $http()->send($method, $url);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function summary(Response|string $res): array
    {
        if (is_string($res)) {
            return ['-', 'NG: '.mb_substr($res, 0, 120)];
        }
        $json = $res->json();
        $ok = $res->successful() && is_array($json) && ($json['success'] ?? false) === true;
        if ($ok) {
            $status = is_array($json['result'] ?? null) ? ($json['result']['status'] ?? null) : null;

            return [(string) $res->status(), 'OK'.(is_string($status) ? '（'.$status.'）' : '')];
        }
        $parts = [];
        foreach ((is_array($json) && is_array($json['errors'] ?? null) ? $json['errors'] : []) as $e) {
            if (is_array($e)) {
                $parts[] = ($e['code'] ?? '').' '.($e['message'] ?? '');
            }
        }
        $errors = implode(' / ', $parts);

        return [(string) $res->status(), 'NG: '.mb_substr($errors, 0, 160)];
    }
}
