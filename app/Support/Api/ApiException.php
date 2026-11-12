<?php

namespace App\Support\Api;

use RuntimeException;

/**
 * API の統一エラー。レスポンスは {"error": {"code", "message", "details"}} になる。
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
