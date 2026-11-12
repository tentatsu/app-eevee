<?php

namespace App\Services\Push;

/**
 * 1チャンク分の送信内容（内容は同じで、宛先のトークンだけが異なる）。
 */
final class PushMessage
{
    /**
     * @param  array<string, string>  $data
     * @param  array<int, string>  $tokens  delivery_id => FCM トークン
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $imageUrl,
        public readonly array $data,
        public readonly array $tokens,
    ) {}
}
