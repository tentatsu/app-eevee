<?php

use App\Enums\ConcurrencyPolicy;
use App\Enums\OrgRole;
use App\Enums\VideoStatus;
use App\Enums\VideoVisibility;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PlaybackLimitEvent;
use App\Models\PlaybackSession;
use App\Models\Video;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Redis::connection()->flushdb();
    Http::preventStrayRequests();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->clientApp = App::factory()->for($this->org)->create([
        'max_concurrent_streams' => 2,
        'concurrency_policy' => ConcurrencyPolicy::Reject,
        'playback_token_grace_minutes' => 10,
    ]);
    $this->clientApp->video_provider_settings = [
        'account_id' => 'acc123', 'api_token' => 'cf-token', 'signing_key_id' => 'key-abc',
        // Cloudflare の API が返す形式（base64 でエンコードした PEM）
        'signing_private_key' => base64_encode($pem),
        'webhook_secret' => 'whsec_test', 'customer_subdomain' => 'customer-xyz.cloudflarestream.com',
    ];
    $this->clientApp->save();

    $this->video = Video::query()->create([
        'app_id' => $this->clientApp->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'cf-uid-1',
        'title' => 'Lesson 1', 'status' => VideoStatus::Ready, 'visibility' => VideoVisibility::Members, 'duration_seconds' => 600,
    ]);
    $this->user = AppUser::factory()->for($this->clientApp)->create();
    $this->token = $this->user->createToken('app')->plainTextToken;
    $this->base = "/api/v1/apps/{$this->clientApp->app_key}";
});

function play(?string $token = null, ?int $videoId = null, ?string $installation = null): TestResponse
{
    app('auth')->forgetGuards();
    $t = test()->flushHeaders();
    if ($token) {
        $t = $t->withToken($token);
    }

    return $t->postJson(test()->base.'/videos/'.($videoId ?? test()->video->id).'/play', array_filter(['installation_id' => $installation]));
}

function heartbeat(string $sessionId, ?string $token = null, ?string $installation = null): TestResponse
{
    app('auth')->forgetGuards();
    $t = test()->flushHeaders();
    if ($token) {
        $t = $t->withToken($token);
    }

    return $t->postJson(test()->base."/playback-sessions/{$sessionId}/heartbeat", array_filter(['installation_id' => $installation]));
}

it('returns a signed HLS URL with an RS256 JWT that expires after duration + grace', function () {
    $this->freezeTime();
    $res = play($this->token)->assertCreated()
        ->assertJsonStructure(['data' => ['session_id', 'hls_url', 'dash_url', 'token', 'expires_at', 'heartbeat_interval']])
        ->assertJsonPath('data.heartbeat_interval', 30);

    $token = $res->json('data.token');
    expect($res->json('data.hls_url'))->toBe("https://customer-xyz.cloudflarestream.com/{$token}/manifest/video.m3u8");

    [$header] = explode('.', $token);
    $header = json_decode(JWT::urlsafeB64Decode($header), true);
    expect($header)->toMatchArray(['alg' => 'RS256', 'kid' => 'key-abc']);

    $claims = (array) JWT::decode($token, new Key($this->publicKey, 'RS256'));
    expect($claims['sub'])->toBe('cf-uid-1')
        ->and($claims['kid'])->toBe('key-abc')
        ->and($claims['exp'])->toBe(now()->addSeconds(600)->addMinutes(10)->getTimestamp())
        ->and($claims['nbf'])->toBeLessThanOrEqual(now()->getTimestamp());

    $session = PlaybackSession::query()->sole();
    expect($session->token_expires_at->getTimestamp())->toBe($claims['exp'])
        ->and($session->app_user_id)->toBe($this->user->id);
});

it('does not call the Cloudflare API when playing', function () {
    Http::fake();
    play($this->token)->assertCreated();
    Http::assertNothingSent();
});

it('uses a default duration when the video length is unknown', function () {
    $this->freezeTime();
    $this->video->update(['duration_seconds' => null]);
    $res = play($this->token)->assertCreated();
    $claims = (array) JWT::decode($res->json('data.token'), new Key($this->publicKey, 'RS256'));
    expect($claims['exp'])->toBe(now()->addSeconds(3600)->addMinutes(10)->getTimestamp());
});

it('requires login for members-only videos and allows anonymous playback of public videos', function () {
    play()->assertUnauthorized()->assertJsonPath('error.code', 'login_required');

    $this->video->update(['visibility' => VideoVisibility::Public]);
    play()->assertStatus(422)->assertJsonPath('error.code', 'installation_id_required');
    play(null, null, (string) Str::uuid())->assertCreated();
});

it('refuses videos that are not ready, outside the publish window or in another app', function () {
    $this->video->update(['status' => VideoStatus::Processing]);
    play($this->token)->assertNotFound()->assertJsonPath('error.code', 'video_not_available');

    $this->video->update(['status' => VideoStatus::Ready, 'publish_start_at' => now()->addDay()]);
    play($this->token)->assertNotFound();

    $this->video->update(['publish_start_at' => null, 'publish_end_at' => now()->subMinute()]);
    play($this->token)->assertNotFound();

    $otherVideo = Video::query()->create(['app_id' => App::factory()->create()->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'x', 'title' => 'x', 'status' => 'ready', 'visibility' => 'public']);
    play($this->token, $otherVideo->id)->assertNotFound();
});

it('rejects a new playback when the concurrent limit is reached (reject policy)', function () {
    play($this->token)->assertCreated();
    play($this->token)->assertCreated();
    play($this->token)->assertStatus(409)->assertJsonPath('error.code', 'concurrent_limit_exceeded')->assertJsonPath('error.details.max_concurrent_streams', 2);

    expect(PlaybackSession::query()->count())->toBe(2)
        ->and(PlaybackLimitEvent::query()->where('policy', 'reject')->count())->toBe(1);

    // 別のユーザーは影響を受けない
    $other = AppUser::factory()->for($this->clientApp)->create()->createToken('app')->plainTextToken;
    play($other)->assertCreated();
});

it('stops the oldest session when the limit is reached (kick_oldest policy)', function () {
    $this->clientApp->update(['concurrency_policy' => ConcurrencyPolicy::KickOldest]);
    $first = play($this->token)->json('data.session_id');
    $this->travel(5)->seconds();
    $second = play($this->token)->json('data.session_id');
    $this->travel(5)->seconds();
    $third = play($this->token)->assertCreated()->json('data.session_id');

    heartbeat($first, $this->token)->assertOk()->assertJsonPath('data.action', 'stop')->assertJsonPath('data.reason', 'kicked');
    heartbeat($second, $this->token)->assertJsonPath('data.action', 'continue');
    heartbeat($third, $this->token)->assertJsonPath('data.action', 'continue');

    expect(PlaybackSession::query()->find($first)->end_reason)->toBe('kicked')
        ->and(PlaybackLimitEvent::query()->where('policy', 'kick_oldest')->value('kicked_session_id'))->toBe($first);
});

it('expires sessions without a heartbeat for 90 seconds and frees the slot', function () {
    $a = play($this->token)->json('data.session_id');
    $this->travel(60)->seconds();
    $b = play($this->token)->json('data.session_id');
    play($this->token)->assertStatus(409);

    // a は 91 秒ハートビートなし → 期限切れ。b は 31 秒なので有効
    $this->travel(31)->seconds();
    heartbeat($b, $this->token)->assertJsonPath('data.action', 'continue');
    play($this->token)->assertCreated();

    heartbeat($a, $this->token)->assertJsonPath('data.action', 'stop')->assertJsonPath('data.reason', 'expired');
    expect(PlaybackSession::query()->find($a)->end_reason)->toBe('expired');
});

it('keeps a session alive while heartbeats arrive every 30 seconds and counts watched seconds', function () {
    $id = play($this->token)->json('data.session_id');
    foreach (range(1, 5) as $i) {
        $this->travel(30)->seconds();
        heartbeat($id, $this->token)->assertJsonPath('data.action', 'continue');
    }
    expect(PlaybackSession::query()->find($id)->watched_seconds)->toBe(150);

    // 通信が途絶えた分は最大 60 秒までしか数えない（90秒以内なら継続）
    $this->travel(80)->seconds();
    heartbeat($id, $this->token)->assertJsonPath('data.action', 'continue');
    expect(PlaybackSession::query()->find($id)->watched_seconds)->toBe(210);
});

it('frees the slot when playback stops', function () {
    $a = play($this->token)->json('data.session_id');
    play($this->token)->assertCreated();
    play($this->token)->assertStatus(409);

    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson("{$this->base}/playback-sessions/{$a}/stop")->assertOk();
    play($this->token)->assertCreated();
    heartbeat($a, $this->token)->assertJsonPath('data.action', 'stop')->assertJsonPath('data.reason', 'stopped');
    expect(PlaybackSession::query()->find($a)->ended_at)->not->toBeNull();
});

it('only lets the viewer who started the session send heartbeats', function () {
    $id = play($this->token)->json('data.session_id');
    $other = AppUser::factory()->for($this->clientApp)->create()->createToken('app')->plainTextToken;
    heartbeat($id, $other)->assertNotFound()->assertJsonPath('error.code', 'session_not_found');
    heartbeat($id)->assertNotFound();
    heartbeat('not-a-uuid', $this->token)->assertNotFound();

    $this->video->update(['visibility' => VideoVisibility::Public]);
    $installation = (string) Str::uuid();
    $anon = play(null, null, $installation)->json('data.session_id');
    heartbeat($anon, null, $installation)->assertJsonPath('data.action', 'continue');
    heartbeat($anon, null, (string) Str::uuid())->assertNotFound();
});

it('applies the limit per device for anonymous viewers', function () {
    $this->video->update(['visibility' => VideoVisibility::Public]);
    $this->clientApp->update(['max_concurrent_streams' => 1]);
    $d1 = (string) Str::uuid();
    play(null, null, $d1)->assertCreated();
    play(null, null, $d1)->assertStatus(409);
    play(null, null, (string) Str::uuid())->assertCreated();
});

it('expires stale sessions with the scheduled command', function () {
    $id = play($this->token)->json('data.session_id');
    $this->travel(2)->minutes();
    $this->artisan('eevee:playback:expire')->assertSuccessful();
    $s = PlaybackSession::query()->find($id);
    expect($s->end_reason)->toBe('expired')->and($s->ended_at->equalTo($s->last_heartbeat_at))->toBeTrue();
});

it('lists videos with playable flags and filters', function () {
    Video::query()->create(['app_id' => $this->clientApp->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'p2', 'title' => 'Free', 'status' => 'ready', 'visibility' => 'public', 'sort_order' => -1, 'category' => 'intro', 'tags' => ['beginner']]);
    Video::query()->create(['app_id' => $this->clientApp->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'p3', 'title' => 'Processing', 'status' => 'processing', 'visibility' => 'public']);

    $this->getJson("{$this->base}/videos")->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.title', 'Free')->assertJsonPath('data.0.playable', true)
        ->assertJsonPath('data.1.title', 'Lesson 1')->assertJsonPath('data.1.playable', false);
    $this->withToken($this->token)->getJson("{$this->base}/videos")->assertJsonPath('data.1.playable', true);
    $this->getJson("{$this->base}/videos?category=intro")->assertJsonPath('meta.total', 1);
    $this->getJson("{$this->base}/videos?tag=beginner")->assertJsonPath('meta.total', 1);
    $this->getJson("{$this->base}/videos/{$this->video->id}")->assertOk()->assertJsonMissingPath('data.provider_video_id');
});

/*
| Webhook
*/

function signedWebhook(string $body, string $secret, ?int $time = null): array
{
    $time ??= time();

    return ['Webhook-Signature' => 'time='.$time.',sig1='.hash_hmac('sha256', $time.'.'.$body, $secret), 'Content-Type' => 'application/json'];
}

it('verifies the webhook signature and updates the video status and duration', function () {
    Http::fake();
    $this->video->update(['status' => VideoStatus::Processing, 'duration_seconds' => null]);
    $body = json_encode(['uid' => 'cf-uid-1', 'readyToStream' => true, 'status' => ['state' => 'ready'], 'duration' => 754.3, 'thumbnail' => 'https://example.com/t.jpg', 'requireSignedURLs' => true]);

    $this->call('POST', "/webhooks/cloudflare/{$this->clientApp->app_key}", [], [], [], self::headersToServer(signedWebhook($body, 'whsec_test')), $body)
        ->assertOk()->assertJsonPath('data.updated', true);

    $this->video->refresh();
    expect($this->video->status)->toBe(VideoStatus::Ready)
        ->and($this->video->duration_seconds)->toBe(755)
        // サムネイルは署名付きで表示のたびに作るので保存しない
        ->and($this->video->thumbnail_url)->toBeNull();
    Http::assertNothingSent();
});

it('rejects webhooks with a bad, missing or stale signature', function (callable $headers) {
    $body = json_encode(['uid' => 'cf-uid-1', 'status' => ['state' => 'error', 'errorReasonText' => 'bad']]);
    $this->call('POST', "/webhooks/cloudflare/{$this->clientApp->app_key}", [], [], [], self::headersToServer($headers($body)), $body)
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');
    expect($this->video->fresh()->status)->toBe(VideoStatus::Ready);
})->with([
    'wrong secret' => [fn ($body) => signedWebhook($body, 'other-secret')],
    'missing header' => [fn ($body) => ['Content-Type' => 'application/json']],
    'stale timestamp' => [fn ($body) => signedWebhook($body, 'whsec_test', time() - 600)],
    'tampered body' => [fn ($body) => signedWebhook($body.' ', 'whsec_test')],
]);

it('marks a video as error from the webhook and requires signed URLs when missing', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['uid' => 'cf-uid-1']])]);
    $body = json_encode(['uid' => 'cf-uid-1', 'readyToStream' => false, 'status' => ['state' => 'error', 'errorReasonText' => 'Unsupported codec'], 'requireSignedURLs' => false]);
    $this->call('POST', "/webhooks/cloudflare/{$this->clientApp->app_key}", [], [], [], self::headersToServer(signedWebhook($body, 'whsec_test')), $body)->assertOk();

    expect($this->video->fresh()->status)->toBe(VideoStatus::Error)->and($this->video->fresh()->status_message)->toBe('Unsupported codec');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/accounts/acc123/stream/cf-uid-1') && $r['requireSignedURLs'] === true);
});

it('does not apply a webhook to a video of another app', function () {
    $otherApp = App::factory()->create();
    $otherApp->video_provider_settings = ['webhook_secret' => 'other'];
    $otherApp->save();
    $body = json_encode(['uid' => 'cf-uid-1', 'status' => ['state' => 'error']]);
    $this->call('POST', "/webhooks/cloudflare/{$otherApp->app_key}", [], [], [], self::headersToServer(signedWebhook($body, 'other')), $body)
        ->assertOk()->assertJsonPath('data.updated', false);
    expect($this->video->fresh()->status)->toBe(VideoStatus::Ready);
});

/*
| 管理画面（Direct Creator Upload）
*/

it('creates a direct upload with signed URLs required and deletes from Cloudflare', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/stream/direct_upload' => Http::response(['success' => true, 'result' => ['uid' => 'new-uid', 'uploadURL' => 'https://upload.videodelivery.net/abc']]),
        'api.cloudflare.com/client/v4/accounts/acc123/stream/new-uid' => Http::response(['success' => true, 'result' => ['uid' => 'new-uid', 'readyToStream' => true, 'status' => ['state' => 'ready'], 'duration' => 30, 'requireSignedURLs' => true]]),
    ]);
    actingAsOrgMember($this->org, OrgRole::Editor);
    $url = "/acme/apps/{$this->clientApp->id}/videos";

    $this->post($url, ['title' => '新しい動画', 'visibility' => 'members', 'tags' => 'a, b'])->assertRedirect()->assertSessionHas('upload_url', 'https://upload.videodelivery.net/abc');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/direct_upload') && $r['requireSignedURLs'] === true && $r['thumbnailTimestampPct'] === 0.1 && $r->hasHeader('Authorization', 'Bearer cf-token'));

    $video = Video::query()->where('provider_video_id', 'new-uid')->sole();
    expect($video->status)->toBe(VideoStatus::PendingUpload)->and($video->tags)->toBe(['a', 'b']);
    $this->get("{$url}/{$video->id}/edit")->assertOk()->assertSee('動画ファイルのアップロード');

    $this->postJson("{$url}/{$video->id}/uploaded")->assertJsonPath('status', 'processing');
    $this->post("{$url}/{$video->id}/refresh")->assertRedirect();
    expect($video->fresh()->status)->toBe(VideoStatus::Ready)->and($video->fresh()->duration_seconds)->toBe(30);

    $this->delete("{$url}/{$video->id}")->assertRedirect();
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/stream/new-uid'));
    expect(Video::query()->find($video->id))->toBeNull();
});

it('shows a friendly error when Cloudflare is not configured', function () {
    $this->clientApp->video_provider_settings = null;
    $this->clientApp->save();
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->post("/acme/apps/{$this->clientApp->id}/videos", ['title' => 'x', 'visibility' => 'public'])->assertSessionHasErrors('provider');
});

it('shows playback statistics in the admin UI', function () {
    $this->clientApp->update(['max_concurrent_streams' => 1]);
    $id = play($this->token)->json('data.session_id');
    play($this->token)->assertStatus(409);
    $this->travel(30)->seconds();
    heartbeat($id, $this->token);

    actingAsOrgMember($this->org, OrgRole::Viewer);
    $this->get("/acme/apps/{$this->clientApp->id}/videos")->assertOk()->assertSee('Lesson 1');
    $this->get("/acme/apps/{$this->clientApp->id}/videos/{$this->video->id}/edit")->assertOk()->assertSee('同時視聴の超過件数');
});

/*
| Cloudflare の自動設定
*/

it('provisions the signing key and webhook on Cloudflare without exposing secrets', function () {
    $this->clientApp->video_provider_settings = ['account_id' => 'acc123', 'api_token' => 'cf-token'];
    $this->clientApp->save();
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/stream/webhook' => Http::sequence()
            ->push(['success' => true, 'result' => ['notificationUrl' => '']])
            ->push(['success' => true, 'result' => ['notificationUrl' => 'x', 'secret' => 'wh-secret-123']]),
        'api.cloudflare.com/client/v4/accounts/acc123/stream/keys' => Http::response(['success' => true, 'result' => ['id' => 'key-new', 'pem' => base64_encode('-----BEGIN RSA PRIVATE KEY-----x-----END RSA PRIVATE KEY-----')]]),
    ]);
    actingAsOrgMember($this->org, OrgRole::Admin);

    $res = $this->post("/acme/apps/{$this->clientApp->id}/video-provider/provision")->assertRedirect()->assertSessionHas('status');
    expect(session('status'))->not->toContain('wh-secret-123');

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/stream/webhook')
        && $r['notificationUrl'] === route('webhooks.cloudflare', ['app_key' => $this->clientApp->app_key]));

    $app = $this->clientApp->fresh();
    expect($app->videoSetting('webhook_secret'))->toBe('wh-secret-123')
        ->and($app->videoSetting('signing_key_id'))->toBe('key-new')
        ->and($app->videoSetting('signing_private_key'))->not->toBeNull()
        ->and($app->videoSetting('api_token'))->toBe('cf-token');
    expect(AuditLog::query()->get()->toJson())->not->toContain('wh-secret-123')->not->toContain('PRIVATE KEY');
});

it('asks for confirmation before overwriting another webhook url', function () {
    $this->clientApp->video_provider_settings = ['account_id' => 'acc123', 'api_token' => 'cf-token'];
    $this->clientApp->save();
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/stream/webhook' => Http::response(['success' => true, 'result' => ['notificationUrl' => 'https://other.example.com/hook', 'secret' => 's']]),
        'api.cloudflare.com/client/v4/accounts/acc123/stream/keys' => Http::response(['success' => true, 'result' => ['id' => 'k', 'pem' => 'p']]),
    ]);
    actingAsOrgMember($this->org, OrgRole::Admin);

    $this->post("/acme/apps/{$this->clientApp->id}/video-provider/provision")->assertSessionHas('webhook_conflict', 'https://other.example.com/hook');
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT');

    $this->post("/acme/apps/{$this->clientApp->id}/video-provider/provision", ['overwrite_webhook' => 1])->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT');
});

it('requires the account id and api token before provisioning', function () {
    $this->clientApp->video_provider_settings = null;
    $this->clientApp->save();
    actingAsOrgMember($this->org, OrgRole::Admin);
    $this->post("/acme/apps/{$this->clientApp->id}/video-provider/provision")->assertSessionHasErrors('provision');
});

it('sets the customer subdomain from the first webhook automatically', function () {
    $settings = $this->clientApp->video_provider_settings;
    unset($settings['customer_subdomain']);
    $this->clientApp->video_provider_settings = $settings;
    $this->clientApp->save();
    Http::fake();

    $body = json_encode(['uid' => 'cf-uid-1', 'readyToStream' => true, 'status' => ['state' => 'ready'], 'requireSignedURLs' => true,
        'playback' => ['hls' => 'https://customer-abc123.cloudflarestream.com/cf-uid-1/manifest/video.m3u8']]);
    $this->call('POST', "/webhooks/cloudflare/{$this->clientApp->app_key}", [], [], [], self::headersToServer(signedWebhook($body, 'whsec_test')), $body)->assertOk();

    expect($this->clientApp->fresh()->videoSetting('customer_subdomain'))->toBe('customer-abc123.cloudflarestream.com');
});

it('keeps unsent settings when saving only the account id and token', function () {
    actingAsOrgMember($this->org, OrgRole::Admin);
    $this->put("/acme/apps/{$this->clientApp->id}/video-provider", ['video_provider' => 'cloudflare_stream', 'account_id' => 'acc999'])->assertSessionHasNoErrors();
    $app = $this->clientApp->fresh();
    expect($app->videoSetting('account_id'))->toBe('acc999')
        ->and($app->videoSetting('signing_key_id'))->toBe('key-abc')
        ->and($app->videoSetting('customer_subdomain'))->toBe('customer-xyz.cloudflarestream.com');
});

/*
| サムネイル
*/

it('returns a signed thumbnail url generated by Cloudflare when no thumbnail is set', function () {
    $this->freezeTime();
    Http::fake();
    $res = $this->withToken($this->token)->getJson("{$this->base}/videos/{$this->video->id}")->assertOk();
    $url = $res->json('data.thumbnail_url');

    expect($url)->toStartWith('https://customer-xyz.cloudflarestream.com/')->toEndWith('/thumbnails/thumbnail.jpg?height=480');
    $token = explode('/', parse_url($url, PHP_URL_PATH))[1];
    $claims = (array) JWT::decode($token, new Key($this->publicKey, 'RS256'));
    expect($claims['sub'])->toBe('cf-uid-1')
        ->and($claims['exp'])->toBe(now()->startOfDay()->addDays(2)->getTimestamp())
        ->and($claims)->not->toHaveKey('nbf');

    // 同じ日のうちは同じ URL（アプリ側でキャッシュできる）
    $this->travel(3)->hours();
    app('auth')->forgetGuards();
    expect($this->withToken($this->token)->getJson("{$this->base}/videos")->json('data.0.thumbnail_url'))->toBe($url);
    Http::assertNothingSent();
});

it('prefers a thumbnail url set in the admin panel and ignores stored provider thumbnails', function () {
    $this->video->update(['thumbnail_url' => 'https://cdn.example.com/custom.jpg']);
    $this->getJson("{$this->base}/videos")->assertJsonPath('data.0.thumbnail_url', 'https://cdn.example.com/custom.jpg');

    // 以前の版で保存した Cloudflare の（署名なしの）URL は、署名付きに置き換える
    $this->video->update(['thumbnail_url' => 'https://customer-xyz.cloudflarestream.com/cf-uid-1/thumbnails/thumbnail.jpg']);
    expect($this->getJson("{$this->base}/videos")->json('data.0.thumbnail_url'))->not->toBe('https://customer-xyz.cloudflarestream.com/cf-uid-1/thumbnails/thumbnail.jpg')
        ->toEndWith('/thumbnails/thumbnail.jpg?height=480');
});

it('shows the thumbnail in the admin panel', function () {
    actingAsOrgMember($this->org, OrgRole::Viewer);
    $this->get("/acme/apps/{$this->clientApp->id}/videos")->assertOk()->assertSee('/thumbnails/thumbnail.jpg', false);
    $this->get("/acme/apps/{$this->clientApp->id}/videos/{$this->video->id}/edit")->assertOk()->assertSee('class="thumb-large"', false);
});
