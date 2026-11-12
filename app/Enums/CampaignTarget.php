<?php

namespace App\Enums;

enum CampaignTarget: string
{
    case All = 'all';
    case Segment = 'segment';
    case Users = 'users';
    case TestDevices = 'test';

    public function label(): string
    {
        return match ($this) {
            self::All => '全員',
            self::Segment => 'セグメント',
            self::Users => 'ユーザーID指定',
            self::TestDevices => 'テスト端末',
        };
    }
}
