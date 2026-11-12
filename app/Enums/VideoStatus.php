<?php

namespace App\Enums;

enum VideoStatus: string
{
    case PendingUpload = 'pending_upload';
    case Processing = 'processing';
    case Ready = 'ready';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::PendingUpload => 'アップロード待ち',
            self::Processing => '変換中',
            self::Ready => '再生可能',
            self::Error => 'エラー',
        };
    }
}
