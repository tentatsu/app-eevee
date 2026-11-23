<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * 定期処理（予約配信の確認など）の間隔（config('eevee.schedule.frequent')）。
 */
class ScheduleFrequency
{
    public static function expression(): string
    {
        $value = (string) config('eevee.schedule.frequent', '* * * * *');

        return CronExpression::isValidExpression($value) ? $value : '* * * * *';
    }

    public static function isEveryMinute(): bool
    {
        return self::expression() === '* * * * *';
    }

    /**
     * 直近の実行予定（これより後にスケジューラーの記録があれば正常）。
     */
    public static function previousRun(): CarbonImmutable
    {
        $tz = (string) config('app.timezone');

        return CarbonImmutable::instance((new CronExpression(self::expression()))->getPreviousRunDate('now', 0, true, $tz));
    }

    /**
     * 実行の間隔（分）。予約配信が遅れうる最大の時間の目安。
     */
    public static function intervalMinutes(): int
    {
        $tz = (string) config('app.timezone');
        $cron = new CronExpression(self::expression());
        $next = CarbonImmutable::instance($cron->getNextRunDate('now', 0, false, $tz));
        $after = CarbonImmutable::instance($cron->getNextRunDate($next, 0, false, $tz));

        return max(1, (int) round($next->diffInMinutes($after, true)));
    }
}
