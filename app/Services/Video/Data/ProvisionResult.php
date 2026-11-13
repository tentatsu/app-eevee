<?php

namespace App\Services\Video\Data;

final class ProvisionResult
{
    /**
     * @param  array<string, string>  $settings  apps.video_provider_settings にマージする値（秘密情報を含む）
     * @param  list<string>  $notes  管理画面に表示する説明
     */
    public function __construct(
        public readonly array $settings,
        public readonly array $notes = [],
    ) {}
}
