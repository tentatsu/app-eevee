<?php

namespace App\Services\Video\Data;

use Carbon\CarbonInterface;

/**
 * 署名付きの再生 URL。
 */
final class PlaybackUrls
{
    public function __construct(
        public readonly string $hlsUrl,
        public readonly ?string $dashUrl,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
