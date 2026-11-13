<?php

use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Enums\DeliveryStatus;
use App\Enums\OrgRole;
use App\Enums\PushPermission;
use App\Jobs\PrepareCampaignJob;
use App\Jobs\SendCampaignChunkJob;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\NotificationCategory;
use App\Models\Organization;
use App\Models\PushCampaign;
use App\Models\PushDelivery;
use App\Models\Segment;
use App\Services\Push\CampaignDispatcher;
use App\Services\Push\Contracts\PushSender;
use App\Services\Push\FakePushSender;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->clientApp = App::factory()->for($this->org)->create(['push_rate_per_second' => 1000]);
    $this->fake = new FakePushSender;
    $this->instance(PushSender::class, $this->fake);
});

function campaign(array $attrs = []): PushCampaign
{
    return PushCampaign::query()->create(array_merge([
        'app_id' => test()->clientApp->id,
        'title' => 'Hello',
        'body' => 'World',
        'data' => ['type' => 'notice', 'id' => '123'],
        'target_type' => CampaignTarget::All,
        'status' => CampaignStatus::Draft,
    ], $attrs));
}

it('splits the targets into chunks of the configured size', function () {
    config(['eevee.push.chunk_size' => 2]);
    Device::factory()->count(5)->for($this->clientApp)->create();
    Queue::fake();

    $c = campaign();
    app(CampaignDispatcher::class)->send($c);
    Queue::assertPushed(PrepareCampaignJob::class, 1);

    // 準備ジョブを実行するとチャンク送信ジョブが投入される
    app(CampaignDispatcher::class)->prepare($c->fresh());
    Queue::assertPushed(SendCampaignChunkJob::class, 3);
    $sizes = Queue::pushed(SendCampaignChunkJob::class)->map(fn ($job) => count($job->deliveryIds))->sort()->values()->all();
    expect($sizes)->toBe([1, 2, 2])
        ->and($c->fresh()->target_count)->toBe(5)
        ->and($c->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('uses chunks of 500 by default', function () {
    expect(config('eevee.push.chunk_size'))->toBe(500);
});

it('sends to every pushable device once and completes the campaign', function () {
    $devices = Device::factory()->count(4)->for($this->clientApp)->create();
    Device::factory()->for($this->clientApp)->create(['push_permission' => PushPermission::Denied]);
    Device::factory()->for($this->clientApp)->create(['invalidated_at' => now()]);
    Device::factory()->for($this->clientApp)->create(['fcm_token' => null]);
    Device::factory()->for(App::factory()->create())->create(); // 別のアプリ

    $c = campaign();
    app(CampaignDispatcher::class)->send($c); // sync キューでその場で送信される

    $c->refresh();
    expect($c->status)->toBe(CampaignStatus::Sent)
        ->and($c->target_count)->toBe(4)
        ->and($c->success_count)->toBe(4)
        ->and($c->finished_at)->not->toBeNull()
        ->and($this->fake->sentTokens())->toEqualCanonicalizing($devices->pluck('fcm_token')->all());

    $msg = $this->fake->sent[0]['message'];
    expect($msg->title)->toBe('Hello')
        ->and($msg->data)->toEqual(['type' => 'notice', 'id' => '123', 'eevee_campaign_id' => (string) $c->id]);
});

it('never sends the same campaign twice to the same device', function () {
    config(['eevee.push.chunk_size' => 2]);
    Device::factory()->count(3)->for($this->clientApp)->create();
    $c = campaign();
    $dispatcher = app(CampaignDispatcher::class);
    $dispatcher->send($c);
    expect($this->fake->sentTokens())->toHaveCount(3);

    // 二重に送信ボタンを押す・準備ジョブ/チャンクジョブが重複して実行される
    expect(fn () => $dispatcher->send($c->fresh()))->toThrow(RuntimeException::class);
    $dispatcher->prepare($c->fresh());
    $ids = PushDelivery::query()->where('push_campaign_id', $c->id)->pluck('id')->all();
    (new SendCampaignChunkJob($c->id, $ids))->handle($this->fake);

    // 端末を増やして再度準備しても、既存の端末には送らない
    PushCampaign::query()->whereKey($c->id)->update(['status' => CampaignStatus::Queued]);
    Device::factory()->for($this->clientApp)->create(['fcm_token' => 'late-device']);
    $dispatcher->prepare($c->fresh());

    expect($this->fake->sentTokens())->toHaveCount(4)
        ->and(array_count_values($this->fake->sentTokens()))->each->toBe(1)
        ->and(PushDelivery::query()->where('push_campaign_id', $c->id)->count())->toBe(4);
});

it('marks devices with invalid tokens and excludes them afterwards', function () {
    $ok = Device::factory()->for($this->clientApp)->create(['fcm_token' => 'ok']);
    $bad = Device::factory()->for($this->clientApp)->create(['fcm_token' => 'unregistered']);
    $broken = Device::factory()->for($this->clientApp)->create(['fcm_token' => 'invalid-arg']);
    $this->fake->invalidTokens = ['unregistered', 'invalid-arg'];

    $c = campaign();
    app(CampaignDispatcher::class)->send($c);
    $c->refresh();

    expect($c->success_count)->toBe(1)->and($c->invalid_token_count)->toBe(2)->and($c->status)->toBe(CampaignStatus::Sent)
        ->and($bad->fresh()->invalidated_at)->not->toBeNull()
        ->and($bad->fresh()->invalidated_reason)->toBe('UNREGISTERED')
        ->and($broken->fresh()->invalidated_at)->not->toBeNull()
        ->and($ok->fresh()->invalidated_at)->toBeNull()
        ->and(PushDelivery::query()->where('device_id', $bad->id)->value('status'))->toBe(DeliveryStatus::InvalidToken);

    // 次のキャンペーンでは送信対象から外れる
    $this->fake->sent = [];
    app(CampaignDispatcher::class)->send(campaign(['title' => 'Second']));
    expect($this->fake->sentTokens())->toBe(['ok']);
});

it('records permanent failures without retrying', function () {
    Device::factory()->for($this->clientApp)->create(['fcm_token' => 'fails']);
    $this->fake->failingTokens = ['fails'];
    $c = campaign();
    app(CampaignDispatcher::class)->send($c);
    expect($c->fresh()->failure_count)->toBe(1)->and($c->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('retries temporary failures with exponential backoff and does not resend successful ones', function () {
    Queue::fake();
    Device::factory()->for($this->clientApp)->create(['fcm_token' => 'ok']);
    Device::factory()->for($this->clientApp)->create(['fcm_token' => 'flaky']);
    $this->fake->retryableTokens = ['flaky'];
    $this->fake->retryableTimes = 1;

    $c = campaign(['status' => CampaignStatus::Queued]);
    app(CampaignDispatcher::class)->prepare($c);
    $job = Queue::pushed(SendCampaignChunkJob::class)->first();

    expect($job->backoff())->toBe([10, 30, 90, 270])->and($job->maxExceptions)->toBe(5);

    expect(fn () => $job->handle($this->fake))->toThrow(RuntimeException::class);
    expect(PushDelivery::query()->where('status', DeliveryStatus::Pending)->count())->toBe(1)
        ->and($c->fresh()->status)->toBe(CampaignStatus::Sending);

    // 再試行: 一時的に失敗した端末だけに送る
    $job->handle($this->fake);
    expect($this->fake->sentTokens())->toBe(['ok', 'flaky', 'flaky'])
        ->and($c->fresh()->success_count)->toBe(2)
        ->and($c->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('gives up after the maximum attempts', function () {
    Queue::fake();
    config(['eevee.push.max_attempts' => 2]);
    Device::factory()->for($this->clientApp)->create(['fcm_token' => 'down']);
    $this->fake->retryableTokens = ['down'];

    $c = campaign(['status' => CampaignStatus::Queued]);
    app(CampaignDispatcher::class)->prepare($c);
    $job = Queue::pushed(SendCampaignChunkJob::class)->first();

    expect(fn () => $job->handle($this->fake))->toThrow(RuntimeException::class);
    $job->handle($this->fake); // 2回目で上限 → 失敗として確定
    expect($c->fresh()->failure_count)->toBe(1)->and($c->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('marks remaining deliveries as failed when the job finally fails', function () {
    Queue::fake();
    Device::factory()->count(2)->for($this->clientApp)->create();
    $c = campaign(['status' => CampaignStatus::Queued]);
    app(CampaignDispatcher::class)->prepare($c);
    $job = Queue::pushed(SendCampaignChunkJob::class)->first();
    $job->failed(new RuntimeException('boom'));
    expect($c->fresh()->failure_count)->toBe(2)->and($c->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('rate limits chunk jobs per app', function () {
    $job = new SendCampaignChunkJob(1, [1]);
    expect($job->middleware()[0])->toBeInstanceOf(RateLimited::class);
});

it('targets a segment, specific users and respects notification categories', function () {
    AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => 'gender', 'type' => 'string', 'label' => 'g']);
    $cat = NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'campaign', 'label' => 'c', 'default_enabled' => true]);
    $alice = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['gender' => 'female']]);
    $bob = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['gender' => 'male']]);
    $carol = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['gender' => 'female'], 'notification_preferences' => ['campaign' => false]]);
    foreach ([$alice, $bob, $carol] as $u) {
        Device::factory()->for($this->clientApp)->create(['app_user_id' => $u->id, 'fcm_token' => 'tok-'.$u->id]);
    }
    $segment = Segment::query()->create(['app_id' => $this->clientApp->id, 'name' => 'women', 'conditions' => ['op' => 'and', 'conditions' => [['field' => 'attr.gender', 'operator' => 'eq', 'value' => 'female']]]]);

    app(CampaignDispatcher::class)->send(campaign(['target_type' => CampaignTarget::Segment, 'segment_id' => $segment->id]));
    expect($this->fake->sentTokens())->toEqualCanonicalizing(['tok-'.$alice->id, 'tok-'.$carol->id]);

    $this->fake->sent = [];
    app(CampaignDispatcher::class)->send(campaign(['target_type' => CampaignTarget::Segment, 'segment_id' => $segment->id, 'notification_category_id' => $cat->id]));
    expect($this->fake->sentTokens())->toBe(['tok-'.$alice->id]);

    $this->fake->sent = [];
    app(CampaignDispatcher::class)->send(campaign(['target_type' => CampaignTarget::Users, 'target_user_ids' => [$bob->id, $carol->id]]));
    expect($this->fake->sentTokens())->toEqualCanonicalizing(['tok-'.$bob->id, 'tok-'.$carol->id]);
});

it('sends a test push only to test devices without touching the original campaign', function () {
    Device::factory()->count(3)->for($this->clientApp)->create();
    $test = Device::factory()->for($this->clientApp)->create(['is_test_device' => true, 'fcm_token' => 'test-device']);
    $c = campaign();

    $child = app(CampaignDispatcher::class)->sendTest($c, 'tester@example.com');
    expect($this->fake->sentTokens())->toBe(['test-device'])
        ->and($child->fresh()->is_test)->toBeTrue()
        ->and($child->fresh()->parent_campaign_id)->toBe($c->id)
        ->and($c->fresh()->status)->toBe(CampaignStatus::Draft);

    // テスト配信は何度でもできる
    app(CampaignDispatcher::class)->sendTest($c, null);
    expect($this->fake->sentTokens())->toBe(['test-device', 'test-device']);
});

it('schedules campaigns and dispatches them when due', function () {
    Device::factory()->for($this->clientApp)->create();
    $c = campaign(['scheduled_at' => now()->addHour()]);
    app(CampaignDispatcher::class)->send($c);
    expect($c->fresh()->status)->toBe(CampaignStatus::Scheduled);

    $this->artisan('eevee:push:dispatch-scheduled');
    expect($this->fake->sent)->toBe([]);

    $this->travel(61)->minutes();
    $this->artisan('eevee:push:dispatch-scheduled')->assertSuccessful();
    expect($c->fresh()->status)->toBe(CampaignStatus::Sent)->and($this->fake->sentTokens())->toHaveCount(1);
});

it('cancels a schedule', function () {
    $c = campaign(['scheduled_at' => now()->addHour()]);
    app(CampaignDispatcher::class)->send($c);
    expect(app(CampaignDispatcher::class)->cancelSchedule($c))->toBeTrue()
        ->and($c->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('records opens once per device and computes the open rate', function () {
    $d1 = Device::factory()->for($this->clientApp)->create();
    $d2 = Device::factory()->for($this->clientApp)->create();
    $c = campaign();
    app(CampaignDispatcher::class)->send($c);
    $base = "/api/v1/apps/{$this->clientApp->app_key}";

    $this->postJson("{$base}/push/opened", ['campaign_id' => $c->id, 'installation_id' => $d1->installation_id])->assertOk()->assertJsonPath('data.recorded', true);
    $this->postJson("{$base}/push/opened", ['campaign_id' => $c->id, 'installation_id' => $d1->installation_id])->assertOk()->assertJsonPath('data.recorded', false);
    expect($c->fresh()->open_count)->toBe(1)->and($c->fresh()->openRate())->toBe(50.0);

    // 別のアプリの端末・存在しないキャンペーン
    $other = Device::factory()->for(App::factory()->create())->create();
    $this->postJson("{$base}/push/opened", ['campaign_id' => $c->id, 'installation_id' => $other->installation_id])->assertNotFound()->assertJsonPath('error.code', 'delivery_not_found');
    $this->postJson("{$base}/push/opened", ['campaign_id' => 99999, 'installation_id' => $d2->installation_id])->assertNotFound();
});

it('prunes old delivery logs according to the retention period', function () {
    $this->clientApp->update(['delivery_log_retention_days' => 90]);
    Device::factory()->count(2)->for($this->clientApp)->create();
    $c = campaign();
    app(CampaignDispatcher::class)->send($c);
    PushDelivery::query()->limit(1)->update(['created_at' => now()->subDays(91)]);

    $this->artisan('eevee:push:prune-deliveries')->assertSuccessful();
    expect(PushDelivery::query()->count())->toBe(1)->and($c->fresh()->success_count)->toBe(2);
});

it('lets editors create and send a campaign from the admin UI', function () {
    Device::factory()->count(2)->for($this->clientApp)->create();
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->clientApp->forceFill(['firebase_credentials' => ['type' => 'service_account', 'project_id' => 'p', 'private_key' => 'k', 'client_email' => 'e']])->save();
    $url = "/acme/apps/{$this->clientApp->id}/campaigns";

    $this->post($url, ['title' => 'Sale', 'body' => 'Today only', 'data' => "type=notice\nid=1", 'target_type' => 'all'])->assertRedirect();
    $c = PushCampaign::query()->sole();
    expect($c->data)->toEqual(['type' => 'notice', 'id' => '1']);
    $this->get("{$url}/{$c->id}")->assertOk()->assertSee('送信予定の端末');

    $this->post("{$url}/{$c->id}/send")->assertRedirect();
    expect($c->fresh()->status)->toBe(CampaignStatus::Sent);
    $this->get("{$url}/{$c->id}")->assertOk()->assertSee('端末ごとの送信結果');
    $this->get($url)->assertOk()->assertSee('Sale');
    expect(AuditLog::query()->where('action', 'push.campaign_sent')->exists())->toBeTrue();

    $this->post("{$url}/{$c->id}/send")->assertSessionHasErrors('status');
});

it('refuses to send without firebase credentials and validates data keys', function () {
    actingAsOrgMember($this->org, OrgRole::Editor);
    $url = "/acme/apps/{$this->clientApp->id}/campaigns";
    $c = campaign();
    $this->post("{$url}/{$c->id}/send")->assertSessionHasErrors('firebase');
    $this->post($url, ['title' => 'x', 'body' => 'y', 'data' => 'eevee_campaign_id=1', 'target_type' => 'all'])->assertSessionHasErrors('data');
    $this->post($url, ['title' => 'x', 'body' => 'y', 'target_type' => 'segment'])->assertSessionHasErrors('segment_id');
});

it('forbids viewers from sending', function () {
    actingAsOrgMember($this->org, OrgRole::Viewer);
    $c = campaign();
    $this->post("/acme/apps/{$this->clientApp->id}/campaigns/{$c->id}/send")->assertForbidden();
    $this->get("/acme/apps/{$this->clientApp->id}/campaigns")->assertOk();
});

it('warns when there is no device to send to', function () {
    Device::factory()->for($this->clientApp)->create(['fcm_token' => null]);
    actingAsOrgMember($this->org, OrgRole::Editor);
    $c = campaign();
    $this->get("/acme/apps/{$this->clientApp->id}/campaigns/{$c->id}")->assertOk()->assertSee('送信予定の端末が 0 台です');
});

it('duplicates a sent campaign as a new draft', function () {
    Device::factory()->for($this->clientApp)->create();
    $cat = NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'campaign', 'label' => 'c', 'default_enabled' => true]);
    $original = campaign(['image_url' => 'https://example.com/a.png', 'notification_category_id' => $cat->id, 'target_type' => CampaignTarget::Users, 'target_user_ids' => [1, 2]]);
    app(CampaignDispatcher::class)->send(campaign(['title' => 'other']));
    PushCampaign::query()->whereKey($original->id)->update(['status' => CampaignStatus::Sent, 'success_count' => 10, 'open_count' => 3, 'scheduled_at' => now()->addDay()]);

    actingAsOrgMember($this->org, OrgRole::Editor);
    $url = "/acme/apps/{$this->clientApp->id}/campaigns";
    $this->get("{$url}/{$original->id}")->assertOk()->assertSee('コピーして作成');
    $this->get($url)->assertOk()->assertSee('コピー');

    $res = $this->post("{$url}/{$original->id}/duplicate");
    $copy = PushCampaign::query()->latest('id')->first();
    $res->assertRedirect("{$url}/{$copy->id}/edit");

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->status)->toBe(CampaignStatus::Draft)
        ->and($copy->title)->toBe('Hello')
        ->and($copy->body)->toBe('World')
        ->and($copy->image_url)->toBe('https://example.com/a.png')
        ->and($copy->data)->toEqual(['type' => 'notice', 'id' => '123'])
        ->and($copy->notification_category_id)->toBe($cat->id)
        ->and($copy->target_type)->toBe(CampaignTarget::Users)
        ->and($copy->target_user_ids)->toBe([1, 2])
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->success_count)->toBe(0)
        ->and($copy->open_count)->toBe(0);
    $this->get("{$url}/{$copy->id}/edit")->assertOk()->assertSee('Hello');
});

it('forbids viewers from duplicating campaigns', function () {
    actingAsOrgMember($this->org, OrgRole::Viewer);
    $c = campaign();
    $this->post("/acme/apps/{$this->clientApp->id}/campaigns/{$c->id}/duplicate")->assertForbidden();
});
