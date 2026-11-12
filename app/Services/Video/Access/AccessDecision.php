<?php

namespace App\Services\Video\Access;

final class AccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly int $status = 403,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $code, string $message, int $status = 403): self
    {
        return new self(false, $code, $message, $status);
    }
}
