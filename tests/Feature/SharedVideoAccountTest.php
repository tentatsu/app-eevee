<?php

use App\Enums\OrgRole;
use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\Video;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::connection()->flushdb();
    Http::preventStrayRequests();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    $this->account = ProviderAccount::query()->create([
        'provider' => 'cloudflare_stream',
        'name' => 'Oneframe 共用',
        'settings' => ['account_id' => 'shared-acc', 'api_token' => 'shared-token', 'signing_key_id' => 'shared-key', 'signing_private_key' => $pem, 'webhook_secret' => 'shared-whsec', 'customer_subdomain' => 'customer-shared.cloudflarestream.com'],
    ]);
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->appA = App::factory()->for($this->org)->create(['video_provider_account_id' => $this->account->id]);
    $this->appB = App::factory()->for(Organization::factory())->create(['video_provider_account_id' => $this->account->id]);
});

it('uses the shared account credentials for upload and signed playback', function () {
    Http::fake(['api.cloudflare.com/client/v4/accounts/shared-acc/stream/direct_upload' => Http::response(['success' => true, 'result' => ['uid' => 'u-1', 'uploadURL' => 'https://upload.example/x']])]);
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->post("/acme/apps/{$this->appA->id}/videos", ['title' => 'v', 'visibility' => 'public'])->assertRedirect();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/accounts/shared-acc/') && $r->hasHeader('Authorization', 'Bearer shared-token') && $r['meta']['app_key'] === $this->appA->app_key);

    $video = Video::withoutGlobalScopes()->sole();
    $video->forceFill(['status' => VideoStatus::Ready, 'duration_seconds' => 60])->save();
    $res = $this->postJson("/api/v1/apps/{$this->appA->app_key}/videos/{$video->id}/play", ['installation_id' => '3f1c2a9e-8b7d-4c1e-9f0a-1234567890ab'])->assertCreated();
    expect($res->json('data.hls_url'))->toStartWith('https://customer-shared.cloudflarestream.com/');
    $claims = (array) JWT::decode($res->json('data.token'), new Key($this->publicKey, 'RS256'));
    expect($claims['kid'])->toBe('shared-key');
});

it('routes shared webhooks to the app that owns the video', function () {
    $videoA = Video::withoutGlobalScopes()->create(['app_id' => $this->appA->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'uid-a', 'title' => 'A', 'status' => 'processing', 'visibility' => 'public']);
    $videoB = Video::withoutGlobalScopes()->create(['app_id' => $this->appB->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'uid-b', 'title' => 'B', 'status' => 'processing', 'visibility' => 'public']);
    $url = "/webhooks/cloudflare/shared/{$this->account->webhook_token}";

    $body = json_encode(['uid' => 'uid-b', 'readyToStream' => true, 'status' => ['state' => 'ready'], 'duration' => 30, 'requireSignedURLs' => true]);
    $this->call('POST', $url, [], [], [], self::headersToServer(signedWebhook($body, 'shared-whsec')), $body)->assertOk()->assertJsonPath('data.updated', true);
    expect($videoB->fresh()->status)->toBe(VideoStatus::Ready)->and($videoA->fresh()->status)->toBe(VideoStatus::Processing);

    // 署名が違う・トークンが違う
    $this->call('POST', $url, [], [], [], self::headersToServer(signedWebhook($body, 'wrong')), $body)->assertUnauthorized();
    $this->call('POST', '/webhooks/cloudflare/shared/nope', [], [], [], self::headersToServer(signedWebhook($body, 'shared-whsec')), $body)->assertNotFound();
});

it('does not update a video of an app that does not use the shared account', function () {
    $own = App::factory()->create();
    $video = Video::withoutGlobalScopes()->create(['app_id' => $own->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'uid-x', 'title' => 'X', 'status' => 'processing', 'visibility' => 'public']);
    $body = json_encode(['uid' => 'uid-x', 'readyToStream' => true, 'status' => ['state' => 'ready']]);
    $this->call('POST', "/webhooks/cloudflare/shared/{$this->account->webhook_token}", [], [], [], self::headersToServer(signedWebhook($body, 'shared-whsec')), $body)
        ->assertOk()->assertJsonPath('data.updated', false);
    expect($video->fresh()->status)->toBe(VideoStatus::Processing);
});

it('provisions the shared account with the shared webhook url', function () {
    $this->account->update(['settings' => ['account_id' => 'shared-acc', 'api_token' => 'shared-token']]);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/shared-acc/stream/webhook' => Http::sequence()->push(['success' => true, 'result' => []])->push(['success' => true, 'result' => ['secret' => 'new-secret']]),
        'api.cloudflare.com/client/v4/accounts/shared-acc/stream/keys' => Http::response(['success' => true, 'result' => ['id' => 'k-new', 'pem' => 'cGVt']]),
    ]);
    actingAsSystemAdmin();
    $this->post("/system/provider-accounts/{$this->account->id}/provision")->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r['notificationUrl'] === route('webhooks.cloudflare.shared', ['token' => $this->account->webhook_token]));
    expect($this->account->fresh()->setting('webhook_secret'))->toBe('new-secret')->and($this->account->fresh()->setting('signing_key_id'))->toBe('k-new');
});

it('lets only system admins manage and assign shared accounts', function () {
    actingAsSystemAdmin();
    $this->get('/system/provider-accounts')->assertOk()->assertSee('Oneframe 共用');
    $this->post('/system/provider-accounts', ['name' => 'second', 'account_id' => 'acc2', 'api_token' => 't'])->assertRedirect();
    $this->get('/system/organizations/acme')->assertOk()->assertSee('共用: Oneframe 共用');

    $own = App::factory()->for($this->org)->create();
    $this->put("/system/apps/{$own->id}/video-account", ['video_provider_account_id' => $this->account->id])->assertRedirect();
    expect($own->fresh()->video_provider_account_id)->toBe($this->account->id);
    $this->put("/system/apps/{$own->id}/video-account", ['video_provider_account_id' => null]);
    expect($own->fresh()->video_provider_account_id)->toBeNull();
    expect(AuditLog::withoutGlobalScopes()->where('action', 'system.app.video_account_assigned')->count())->toBe(2);

    // 使用中のアカウントは削除できない
    $this->delete("/system/provider-accounts/{$this->account->id}")->assertSessionHasErrors('account');
});

it('hides shared credentials from organization admins and blocks editing', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $this->get("/acme/apps/{$this->appA->id}/settings")->assertOk()->assertSee('共用のアカウントを使用中')->assertDontSee('shared-acc');
    $this->put("/acme/apps/{$this->appA->id}/video-provider", ['video_provider' => 'cloudflare_stream', 'account_id' => 'hack'])->assertSessionHasErrors('provision');
    $this->post("/acme/apps/{$this->appA->id}/video-provider/provision")->assertSessionHasErrors('provision');
    expect($this->account->fresh()->setting('account_id'))->toBe('shared-acc');
});

it('shows usage per app', function () {
    Video::withoutGlobalScopes()->create(['app_id' => $this->appA->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'u', 'title' => 'A', 'status' => 'ready', 'visibility' => 'public', 'duration_seconds' => 600]);
    actingAsSystemAdmin();
    $this->get('/system/usage')->assertOk()->assertSee($this->appA->name)->assertSee('共用: Oneframe 共用');
});
