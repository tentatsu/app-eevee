<?php

namespace App\Enums;

enum NoticeTarget: string
{
    case All = 'all';
    case Segment = 'segment';

    public function label(): string
    {
        return match ($this) {
            self::All => '全員',
            self::Segment => 'セグメント',
        };
    }
}
