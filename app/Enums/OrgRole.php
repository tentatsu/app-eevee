<?php

namespace App\Enums;

enum OrgRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'オーナー',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Viewer => '閲覧者',
        };
    }

    /** IP制限・メンバー・組織設定 */
    public function canManageOrganization(): bool
    {
        return $this === self::Owner;
    }

    /** アプリの設定、配信、コンテンツ管理 */
    public function canManageApps(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** お知らせ・動画・プッシュの作成と送信 */
    public function canEditContent(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }

    public function canViewAuditLogs(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
