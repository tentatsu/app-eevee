<?php

namespace App\Services\Video\Data;

use App\Enums\VideoStatus;

/**
 * プロバイダ側の動画の状態。
 */
final class ProviderVideo
{
    public function __construct(
        public readonly string $providerVideoId,
        public readonly VideoStatus $status,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $message = null,
        public readonly ?bool $signedPlaybackRequired = null,
    ) {}
}
