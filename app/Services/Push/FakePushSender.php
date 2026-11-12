<?php

namespace App\Services\Push;

use App\Models\App;
use App\Services\Push\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

/**
 * 実際には送信しないプッシュ送信クライアント。テストと、ローカル開発（EEVEE_PUSH_FAKE=true）で使う。
 *
 * - invalidTokens: UNREGISTERED を返すトークン
 * - failingTokens: 再試行しない失敗を返すトークン
 * - retryableTokens: 一時的な失敗を返すトークン（retryableTimes 回まで）
 */
class FakePushSender implements PushSender
{
    /** @var list<array{app_id: int, message: PushMessage}> */
    public array $sent = [];

    /** @var list<string> */
    public array $invalidTokens = [];

    /** @var list<string> */
    public array $failingTokens = [];

    /** @var list<string> */
    public array $retryableTokens = [];

    public int $retryableTimes = PHP_INT_MAX;

    /** @var array<string, int> */
    private array $retryCount = [];

    public function __construct(private readonly bool $log = false) {}

    public function send(App $app, PushMessage $message): array
    {
        $this->sent[] = ['app_id' => $app->id, 'message' => $message];
        if ($this->log) {
            Log::info('[FakePushSender] push', ['app_id' => $app->id, 'title' => $message->title, 'recipients' => count($message->tokens)]);
        }

        $results = [];
        foreach ($message->tokens as $deliveryId => $token) {
            $deliveryId = (int) $deliveryId;
            if (in_array($token, $this->invalidTokens, true)) {
                $results[] = new PushResult($deliveryId, PushResult::INVALID_TOKEN, errorCode: 'UNREGISTERED', errorMessage: 'Requested entity was not found.');
            } elseif (in_array($token, $this->failingTokens, true)) {
                $results[] = new PushResult($deliveryId, PushResult::FAILED, errorCode: 'SENDER_ID_MISMATCH', errorMessage: 'fake failure');
            } elseif (in_array($token, $this->retryableTokens, true) && ($this->retryCount[$token] = ($this->retryCount[$token] ?? 0) + 1) <= $this->retryableTimes) {
                $results[] = new PushResult($deliveryId, PushResult::RETRYABLE, errorCode: 'UNAVAILABLE', errorMessage: 'fake unavailable');
            } else {
                $results[] = new PushResult($deliveryId, PushResult::SENT, messageId: 'projects/fake/messages/'.$deliveryId);
            }
        }

        return $results;
    }

    /**
     * 送信したトークンの一覧（送信回数分）。
     *
     * @return list<string>
     */
    public function sentTokens(): array
    {
        $tokens = [];
        foreach ($this->sent as $entry) {
            foreach ($entry['message']->tokens as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
