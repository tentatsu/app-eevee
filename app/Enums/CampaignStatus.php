<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Scheduled => '予約済み',
            self::Queued => '送信待ち',
            self::Sending => '送信中',
            self::Sent => '送信完了',
            self::Failed => '失敗',
            self::Canceled => 'キャンセル',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }
}
