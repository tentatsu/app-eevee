<?php

use Illuminate\Support\Facades\Schedule;

// スケジューラー（sail では scheduler サービス、本番では cron で `php artisan schedule:run` を毎分実行）
Schedule::command('eevee:push:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('eevee:push:prune-deliveries')->dailyAt('03:30')->onOneServer();
Schedule::command('eevee:playback:expire')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
