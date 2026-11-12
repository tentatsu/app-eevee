<?php

use App\Enums\OrgRole;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

it('creates an app and shows the secret once', function () {
    actingAsOrgMember($this->org, OrgRole::Admin);
    $res = $this->post('/acme/apps', ['name' => 'Demo', 'auth_mode' => 'builtin']);
    $app = App::query()->sole();
    $res->assertRedirect("/acme/apps/{$app->id}/settings")->assertSessionHas('app_secret');
    expect(str_starts_with($app->app_key, 'ak_'))->toBeTrue()
        ->and($app->verifySecret(session('app_secret')))->toBeTrue()
        ->and($app->app_secret_hash)->not->toBe(session('app_secret'));
});

it('forbids editors from creating apps', function () {
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->post('/acme/apps', ['name' => 'Demo', 'auth_mode' => 'builtin'])->assertForbidden();
});

it('stores firebase and cloudflare credentials encrypted', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $app = App::factory()->for($this->org)->create();
    $json = json_encode(['type' => 'service_account', 'project_id' => 'demo-proj', 'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n", 'client_email' => 'x@demo-proj.iam.gserviceaccount.com']);

    $this->put("/acme/apps/{$app->id}/firebase", ['service_account_json' => $json])->assertSessionHasNoErrors();
    $this->put("/acme/apps/{$app->id}/video-provider", ['video_provider' => 'cloudflare_stream', 'account_id' => 'acc123', 'api_token' => 'cf-token-secret', 'webhook_secret' => 'whsec'])->assertSessionHasNoErrors();

    $row = DB::table('apps')->where('id', $app->id)->first();
    expect($row->firebase_credentials)->not->toContain('demo-proj')
        ->and($row->video_provider_settings)->not->toContain('cf-token-secret');

    $app->refresh();
    expect($app->firebase_credentials['project_id'])->toBe('demo-proj')
        ->and($app->videoSetting('api_token'))->toBe('cf-token-secret')
        ->and($app->toArray())->not->toHaveKeys(['firebase_credentials', 'video_provider_settings', 'app_secret_hash']);

    // 空欄で送ると秘密の値は維持される
    $this->put("/acme/apps/{$app->id}/video-provider", ['video_provider' => 'cloudflare_stream', 'account_id' => 'acc999']);
    expect($app->fresh()->videoSetting('api_token'))->toBe('cf-token-secret')->and($app->fresh()->videoSetting('account_id'))->toBe('acc999');

    // 監査ログに秘密が出ない
    $logs = AuditLog::query()->get()->toJson();
    expect($logs)->not->toContain('cf-token-secret')->not->toContain('BEGIN PRIVATE KEY');
});

it('rejects an invalid service account json', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $app = App::factory()->for($this->org)->create();
    $this->put("/acme/apps/{$app->id}/firebase", ['service_account_json' => '{"type":"user"}'])->assertSessionHasErrors('service_account_json');
    $this->put("/acme/apps/{$app->id}/firebase", ['service_account_json' => 'not json'])->assertSessionHasErrors('service_account_json');
});

it('regenerates the app secret', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $app = App::factory()->for($this->org)->create();
    $old = $app->regenerateSecret();
    $this->post("/acme/apps/{$app->id}/secret")->assertSessionHas('app_secret');
    expect($app->fresh()->verifySecret($old))->toBeFalse()
        ->and($app->fresh()->verifySecret(session('app_secret')))->toBeTrue();
});

it('returns 404 for an app of another organization', function () {
    $other = Organization::factory()->create(['slug' => 'other']);
    $otherApp = App::factory()->for($other)->create();
    actingAsOrgMember($this->org, OrgRole::Owner);

    $this->get("/acme/apps/{$otherApp->id}")->assertNotFound();
    $this->get("/acme/apps/{$otherApp->id}/settings")->assertNotFound();
    $this->put("/acme/apps/{$otherApp->id}/settings", ['name' => 'hacked'])->assertNotFound();
    $this->post("/acme/apps/{$otherApp->id}/secret")->assertNotFound();
    $this->get('/acme/apps')->assertOk()->assertDontSee($otherApp->app_key);
});

it('cannot edit an attribute definition of another app through the URL', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $appA = App::factory()->for($this->org)->create();
    $appB = App::factory()->for($this->org)->create();
    $defB = AttributeDefinition::query()->create(['app_id' => $appB->id, 'key' => 'k', 'type' => 'int', 'label' => 'B']);

    $this->put("/acme/apps/{$appA->id}/attributes/{$defB->id}", ['label' => 'hacked'])->assertNotFound();
    $this->delete("/acme/apps/{$appA->id}/attributes/{$defB->id}")->assertNotFound();
    expect($defB->fresh()->label)->toBe('B');
});

it('manages attribute definitions and categories', function () {
    actingAsOrgMember($this->org, OrgRole::Admin);
    $app = App::factory()->for($this->org)->create();
    $this->post("/acme/apps/{$app->id}/attributes", ['key' => 'birth_month', 'type' => 'int', 'label' => '誕生月', 'app_writable' => '1'])->assertSessionHasNoErrors();
    $this->post("/acme/apps/{$app->id}/attributes", ['key' => 'birth_month', 'type' => 'int', 'label' => 'dup'])->assertSessionHasErrors('key');
    $this->post("/acme/apps/{$app->id}/attributes", ['key' => 'Bad-Key', 'type' => 'int', 'label' => 'x'])->assertSessionHasErrors('key');
    $this->post("/acme/apps/{$app->id}/categories", ['key' => 'campaign', 'label' => 'キャンペーン', 'default_enabled' => '1'])->assertSessionHasNoErrors();

    $this->get("/acme/apps/{$app->id}/attributes")->assertOk()->assertSee('birth_month');
    $this->get("/acme/apps/{$app->id}/categories")->assertOk()->assertSee('campaign');
});

it('renders the app pages for a viewer', function () {
    $app = App::factory()->for($this->org)->create();
    Device::factory()->for($app)->create();
    AppUser::factory()->for($app)->create();
    actingAsOrgMember($this->org, OrgRole::Viewer);
    foreach (['', '/users', '/devices', '/attributes', '/categories'] as $path) {
        $this->get("/acme/apps/{$app->id}{$path}")->assertOk();
    }
    $this->get("/acme/apps/{$app->id}/settings")->assertForbidden();
});

it('lets editors register test devices', function () {
    $app = App::factory()->for($this->org)->create();
    $device = Device::factory()->for($app)->create();
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->put("/acme/apps/{$app->id}/devices/{$device->id}/test", ['is_test_device' => 1, 'test_label' => 'iPhone 開発'])->assertRedirect();
    expect($device->fresh()->is_test_device)->toBeTrue();
});

it('renders the settings page for an owner', function () {
    $app = App::factory()->for($this->org)->create();
    actingAsOrgMember($this->org, OrgRole::Owner);
    $this->get("/acme/apps/{$app->id}/settings")->assertOk()->assertSee($app->app_key);
    $this->get('/acme/apps/create')->assertOk();
    $this->get('/acme')->assertOk()->assertSee($app->name);
});
