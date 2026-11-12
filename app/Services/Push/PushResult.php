<?php

namespace App\Services\Push;

/**
 * 宛先1件の送信結果。
 */
final class PushResult
{
    public const SENT = 'sent';

    /** トークンが無効（UNREGISTERED / INVALID_ARGUMENT など）。以後は送信対象から外す */
    public const INVALID_TOKEN = 'invalid_token';

    /** 一時的な失敗（UNAVAILABLE / INTERNAL / QUOTA_EXCEEDED など）。再試行する */
    public const RETRYABLE = 'retryable';

    /** 再試行しても成功しない失敗 */
    public const FAILED = 'failed';

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
