<?php

use App\Enums\CampaignStatus;
use App\Jobs\PrepareCampaignJob;
use App\Models\App;
use App\Models\PushCampaign;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function dueCampaign(App $app): PushCampaign
{
    return PushCampaign::withoutGlobalScopes()->create([
        'app_id' => $app->id, 'title' => 't', 'body' => 'b', 'target_type' => 'all',
        'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->subMinutes(5),
    ]);
}

it('runs the frequent tasks every minute by default', function () {
    $events = collect(app(Schedule::class)->events());
    $dispatch = $events->first(fn ($e) => str_contains((string) $e->command, 'eevee:push:dispatch-scheduled'));
    expect($dispatch->expression)->toBe('* * * * *');
});

it('dispatches due campaigns on a request when the schedule is relaxed for hibernation', function () {
    Queue::fake();
    $app = App::factory()->create();
    $campaign = dueCampaign($app);

    // 既定（毎分）ではアクセスのついでには送らない
    $this->get('/health')->assertOk();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);

    config(['eevee.schedule.frequent' => '0 * * * *']);
    Cache::forget('eevee:push:due-check');
    $this->get('/health')->assertOk();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Queued);
    Queue::assertPushed(PrepareCampaignJob::class, 1);

    // 1分に1回まで
    $second = dueCampaign($app);
    $this->get('/health')->assertOk();
    expect($second->fresh()->status)->toBe(CampaignStatus::Scheduled);
});

it('reports the scheduler as healthy when it ran at the last hourly slot', function () {
    config(['eevee.schedule.frequent' => '0 * * * *']);
    $this->travelTo(now()->startOfHour()->addMinutes(40));
    Cache::forever('eevee:scheduler:last_run', now()->startOfHour()->toIso8601String());
    Artisan::call('eevee:doctor');
    $output = Artisan::output();
    expect($output)->toContain('約60分ごと')->toMatch('/スケジューラー\s*\|\s*OK/u');

    Cache::forever('eevee:scheduler:last_run', now()->subHours(2)->toIso8601String());
    Artisan::call('eevee:doctor');
    expect(Artisan::output())->toMatch('/スケジューラー\s*\|\s*WARN/u');
});
