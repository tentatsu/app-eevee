<?php

namespace App\Services\Video\Data;

use Carbon\CarbonInterface;

/**
 * ブラウザから直接アップロードするための URL（app-eevee のサーバーを経由しない）。
 */
final class DirectUpload
{
    public function __construct(
        public readonly string $uploadUrl,
        public readonly string $providerVideoId,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}
}
