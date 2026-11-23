<?php

use App\Support\ScheduleFrequency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// スケジューラー（Sail では scheduler サービス、Laravel Cloud ではスケジューラーを有効にする、VPS では cron で毎分 schedule:run）
// 予約配信の確認などの間隔は EEVEE_SCHEDULE_FREQUENT（既定は毎分）。Laravel Cloud の休止を使う場合は長くする（config/eevee.php）。
$frequent = ScheduleFrequency::expression();

Schedule::command('eevee:push:dispatch-scheduled')->cron($frequent)->withoutOverlapping()->onOneServer();
Schedule::command('eevee:playback:expire')->cron($frequent)->withoutOverlapping()->onOneServer();
Schedule::command('eevee:push:prune-deliveries')->dailyAt('03:30')->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

if (config('eevee.horizon.enabled')) {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}

// スケジューラーが動いているかを eevee:doctor で確認するための記録
Schedule::call(fn () => Cache::forever('eevee:scheduler:last_run', now()->toIso8601String()))
    ->cron($frequent)->name('eevee:scheduler-heartbeat')->onOneServer();
