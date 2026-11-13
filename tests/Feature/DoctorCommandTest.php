<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

it('checks the environment including the Lua script used for concurrency control', function () {
    Cache::forever('eevee:scheduler:last_run', now()->toIso8601String());

    $this->artisan('eevee:doctor')
        ->expectsOutputToContain('EVAL / cjson / ZADD XX')
        ->assertSuccessful();
});

it('fails when the queue retry_after is shorter than the longest job', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 90]);
    $this->artisan('eevee:doctor')->assertFailed();
});

it('schedules the push and playback maintenance commands', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($commands)->toContain('eevee:push:dispatch-scheduled')->toContain('eevee:playback:expire');
});
