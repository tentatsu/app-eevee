<?php

use App\Enums\CampaignTarget;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\OrgRole;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\Notice;
use App\Models\Organization;
use App\Models\PushCampaign;
use App\Models\Segment;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->clientApp = App::factory()->for($this->org)->create();
    $this->base = "/api/v1/apps/{$this->clientApp->app_key}";
    AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => 'plan', 'type' => 'string', 'label' => 'plan']);
    $this->segment = Segment::query()->create(['app_id' => $this->clientApp->id, 'name' => 'gold', 'conditions' => ['op' => 'and', 'conditions' => [['field' => 'attr.plan', 'operator' => 'eq', 'value' => 'gold']]]]);
    $this->gold = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['plan' => 'gold']]);
    $this->free = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['plan' => 'free']]);
});

function notice(array $attrs = []): Notice
{
    return Notice::query()->create(array_merge([
        'app_id' => test()->clientApp->id, 'title' => 'N', 'body' => "# Hello\n\n**bold** <script>alert(1)</script>",
        'status' => NoticeStatus::Published, 'target_type' => NoticeTarget::All,
    ], $attrs));
}

it('lists only visible notices with paging, pinned first', function () {
    $old = notice(['title' => 'old', 'publish_start_at' => now()->subDays(3)]);
    $new = notice(['title' => 'new', 'publish_start_at' => now()->subDay()]);
    $pinned = notice(['title' => 'pinned', 'is_pinned' => true, 'publish_start_at' => now()->subDays(10)]);
    notice(['title' => 'draft', 'status' => NoticeStatus::Draft]);
    notice(['title' => 'future', 'publish_start_at' => now()->addDay()]);
    notice(['title' => 'expired', 'publish_end_at' => now()->subMinute()]);
    notice(['title' => 'gold only', 'target_type' => NoticeTarget::Segment, 'segment_id' => $this->segment->id]);
    Notice::query()->create(['app_id' => App::factory()->create()->id, 'title' => 'other app', 'body' => 'x', 'status' => 'published', 'target_type' => 'all']);

    $this->getJson("{$this->base}/notices")->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.title', 'pinned')->assertJsonPath('data.1.title', 'new')->assertJsonPath('data.2.title', 'old')
        ->assertJsonMissingPath('data.0.body');

    $this->getJson("{$this->base}/notices?per_page=2&page=2")->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
});

it('shows segment notices only to matching users', function () {
    $n = notice(['title' => 'gold only', 'target_type' => NoticeTarget::Segment, 'segment_id' => $this->segment->id]);
    $goldToken = $this->gold->createToken('app')->plainTextToken;
    $freeToken = $this->free->createToken('app')->plainTextToken;

    $this->withToken($goldToken)->getJson("{$this->base}/notices")->assertJsonPath('meta.total', 1);
    $this->withToken($goldToken)->getJson("{$this->base}/notices/{$n->id}")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($freeToken)->getJson("{$this->base}/notices")->assertJsonPath('meta.total', 0);
    $this->withToken($freeToken)->getJson("{$this->base}/notices/{$n->id}")->assertNotFound()->assertJsonPath('error.code', 'notice_not_found');
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson("{$this->base}/notices/{$n->id}")->assertNotFound();
});

it('returns the detail with markdown rendered to safe HTML', function () {
    $n = notice();
    $res = $this->getJson("{$this->base}/notices/{$n->id}")->assertOk();
    expect($res->json('data.body_html'))->toContain('<h1>Hello</h1>')->toContain('<strong>bold</strong>')->not->toContain('<script>');
});

it('records reads and counts unread notices', function () {
    $a = notice(['title' => 'a']);
    $b = notice(['title' => 'b']);
    notice(['title' => 'gold', 'target_type' => NoticeTarget::Segment, 'segment_id' => $this->segment->id]);
    $token = $this->gold->createToken('app')->plainTextToken;

    $this->withToken($token)->getJson("{$this->base}/notices/unread-count")->assertJsonPath('data.unread_count', 3);
    $this->withToken($token)->postJson("{$this->base}/notices/{$a->id}/read")->assertOk()->assertJsonPath('data.unread_count', 2);
    $this->withToken($token)->postJson("{$this->base}/notices/{$a->id}/read")->assertJsonPath('data.unread_count', 2);
    $this->withToken($token)->getJson("{$this->base}/notices")->assertJsonPath('data.0.is_read', false);
    $list = collect($this->withToken($token)->getJson("{$this->base}/notices")->json('data'))->keyBy('title');
    expect($list['a']['is_read'])->toBeTrue()->and($list['b']['is_read'])->toBeFalse();

    app('auth')->forgetGuards();
    $this->flushHeaders()->postJson("{$this->base}/notices/{$a->id}/read")->assertUnauthorized();
});

it('manages notices in the admin UI and creates a push from a notice', function () {
    actingAsOrgMember($this->org, OrgRole::Editor);
    $url = "/acme/apps/{$this->clientApp->id}/notices";
    $this->get("{$url}/create")->assertOk();
    $this->post($url, [
        'title' => 'メンテナンスのお知らせ', 'body' => "11/20 の深夜にメンテナンスを行います。\n\n**ご迷惑をおかけします。**",
        'target_type' => 'segment', 'segment_id' => $this->segment->id, 'status' => 'published',
        'publish_start_at' => '2026-11-01T09:00', 'is_pinned' => '1',
    ])->assertRedirect();
    $notice = Notice::query()->sole();
    // Asia/Tokyo の 09:00 は UTC の 00:00
    expect($notice->publish_start_at->toDateTimeString())->toBe('2026-11-01 00:00:00')->and($notice->is_pinned)->toBeTrue();
    $this->get("{$url}/{$notice->id}/edit")->assertOk()->assertSee('このお知らせをプッシュ通知する');
    $this->get($url)->assertOk()->assertSee('メンテナンスのお知らせ');

    $this->post("{$url}/{$notice->id}/push")->assertRedirect();
    $campaign = PushCampaign::query()->sole();
    expect($campaign->data)->toEqual(['type' => 'notice', 'id' => (string) $notice->id])
        ->and($campaign->title)->toBe('メンテナンスのお知らせ')
        ->and($campaign->target_type)->toBe(CampaignTarget::Segment)
        ->and($campaign->segment_id)->toBe($this->segment->id)
        ->and($campaign->source_type)->toBe('notice');
});

it('cannot edit a notice of another app', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $other = App::factory()->for($this->org)->create();
    $n = Notice::query()->create(['app_id' => $other->id, 'title' => 'x', 'body' => 'y', 'status' => 'draft', 'target_type' => 'all']);
    $this->get("/acme/apps/{$this->clientApp->id}/notices/{$n->id}/edit")->assertNotFound();
    $this->put("/acme/apps/{$this->clientApp->id}/notices/{$n->id}", ['title' => 'hacked'])->assertNotFound();
    $this->post("/acme/apps/{$this->clientApp->id}/notices/{$n->id}/push")->assertNotFound();
});
