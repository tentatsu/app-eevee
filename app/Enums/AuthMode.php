<?php

namespace App\Enums;

enum AuthMode: string
{
    /** app-eevee がメール+パスワードで認証する */
    case Builtin = 'builtin';
    /** 顧客の既存バックエンドで認証し、app-eevee は外部ユーザーIDを受け取る */
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Builtin => 'builtin（このシステムで認証）',
            self::External => 'external（顧客バックエンドで認証）',
        };
    }
}
