<?php

namespace App\Services\Video;

/**
 * Webhook の送信先に別の URL が設定済み（1つの Cloudflare アカウントにつき Webhook は1つ）。
 */
class WebhookConflictException extends VideoProviderException
{
    public function __construct(public readonly string $currentUrl)
    {
        parent::__construct("A different webhook URL is already configured: {$currentUrl}");
    }
}
