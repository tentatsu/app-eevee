<?php

namespace App\Enums;

enum ConcurrencyPolicy: string
{
    case Reject = 'reject';
    case KickOldest = 'kick_oldest';

    public function label(): string
    {
        return match ($this) {
            self::Reject => '新しい再生を拒否',
            self::KickOldest => '最も古いセッションを停止',
        };
    }
}
