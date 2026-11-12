<?php

namespace App\Enums;

enum VideoVisibility: string
{
    case Public = 'public';
    case Members = 'members';

    public function label(): string
    {
        return match ($this) {
            self::Public => '公開（ログイン不要）',
            self::Members => 'メンバー限定（ログイン必須）',
        };
    }
}
