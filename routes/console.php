<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// スケジューラー（Sail では scheduler サービス、Laravel Cloud ではスケジューラーを有効にする、VPS では cron で毎分 schedule:run）
Schedule::command('eevee:push:dispatch-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('eevee:push:prune-deliveries')->dailyAt('03:30')->onOneServer();
Schedule::command('eevee:playback:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

if (config('eevee.horizon.enabled')) {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}

// スケジューラーが動いているかを eevee:doctor で確認するための記録
Schedule::call(fn () => Cache::forever('eevee:scheduler:last_run', now()->toIso8601String()))
    ->everyMinute()->name('eevee:scheduler-heartbeat')->onOneServer();
