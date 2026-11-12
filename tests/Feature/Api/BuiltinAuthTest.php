<?php

use App\Enums\AttributeType;
use App\Mail\AppUserPasswordResetMail;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\Device;
use App\Models\NotificationCategory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->clientApp = App::factory()->create();
    $this->base = "/api/v1/apps/{$this->clientApp->app_key}";
});

it('returns a machine readable error for an unknown app_key', function () {
    $this->getJson('/api/v1/apps/ak_nope/notification-categories')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'app_not_found');
});

it('registers, logs in, reads me and logs out', function () {
    $res = $this->postJson("{$this->base}/auth/register", ['email' => 'User@Example.com', 'password' => 'password123', 'name' => 'Taro'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'email']]]);
    expect($res->json('data.user.email'))->toBe('user@example.com');

    $login = $this->postJson("{$this->base}/auth/login", ['email' => 'user@example.com', 'password' => 'password123'])->assertOk();
    $token = $login->json('data.token');

    $this->withToken($token)->getJson("{$this->base}/me")->assertOk()->assertJsonPath('data.name', 'Taro');
    $this->withToken($token)->postJson("{$this->base}/auth/logout")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson("{$this->base}/me")->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects wrong passwords and duplicate emails', function () {
    AppUser::factory()->for($this->clientApp)->create(['email' => 'u@example.com']);
    $this->postJson("{$this->base}/auth/login", ['email' => 'u@example.com', 'password' => 'nope'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
    $this->postJson("{$this->base}/auth/register", ['email' => 'u@example.com', 'password' => 'password123'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
});

it('allows the same email in another app', function () {
    $other = App::factory()->create();
    AppUser::factory()->for($other)->create(['email' => 'u@example.com']);
    $this->postJson("{$this->base}/auth/register", ['email' => 'u@example.com', 'password' => 'password123'])->assertCreated();
});

it('does not accept a token of another app', function () {
    $other = App::factory()->create();
    $user = AppUser::factory()->for($other)->create();
    $token = $user->createToken('app')->plainTextToken;

    $this->withToken($token)->getJson("{$this->base}/me")->assertUnauthorized();
    $this->withToken($token)->getJson("/api/v1/apps/{$other->app_key}/me")->assertOk();
});

it('rejects builtin auth for external apps', function () {
    $ext = App::factory()->external()->create();
    $this->postJson("/api/v1/apps/{$ext->app_key}/auth/login", ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(409)->assertJsonPath('error.code', 'auth_mode_mismatch');
});

it('resets the password with an emailed code', function () {
    Mail::fake();
    $user = AppUser::factory()->for($this->clientApp)->create(['email' => 'u@example.com']);
    $old = $user->createToken('app')->plainTextToken;

    $this->postJson("{$this->base}/auth/password/forgot", ['email' => 'u@example.com'])->assertStatus(202);
    $this->postJson("{$this->base}/auth/password/forgot", ['email' => 'unknown@example.com'])->assertStatus(202);

    $code = null;
    Mail::assertSent(AppUserPasswordResetMail::class, function ($mail) use (&$code) {
        $code = $mail->token;

        return true;
    });
    Mail::assertSentCount(1);

    $this->postJson("{$this->base}/auth/password/reset", ['email' => 'u@example.com', 'token' => 'WRONG', 'password' => 'newpassword'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
    $this->postJson("{$this->base}/auth/password/reset", ['email' => 'u@example.com', 'token' => strtolower($code), 'password' => 'newpassword'])->assertOk();

    $this->postJson("{$this->base}/auth/login", ['email' => 'u@example.com', 'password' => 'newpassword'])->assertOk();
    expect($user->tokens()->where('token', hash('sha256', explode('|', $old)[1]))->exists())->toBeFalse();
});

it('links the device on login and unlinks on logout', function () {
    $installation = (string) Str::uuid();
    $this->postJson("{$this->base}/devices", ['installation_id' => $installation, 'platform' => 'ios', 'fcm_token' => 'tok-1'])->assertCreated();
    $user = AppUser::factory()->for($this->clientApp)->create(['email' => 'u@example.com']);

    $token = $this->postJson("{$this->base}/auth/login", ['email' => 'u@example.com', 'password' => 'password', 'installation_id' => $installation])->json('data.token');
    expect(Device::query()->where('installation_id', $installation)->value('app_user_id'))->toBe($user->id);

    $this->withToken($token)->postJson("{$this->base}/auth/logout", ['installation_id' => $installation])->assertOk();
    expect(Device::query()->where('installation_id', $installation)->value('app_user_id'))->toBeNull();
});

it('updates only app-writable attributes from the app', function () {
    AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => 'birth_month', 'type' => AttributeType::Int, 'label' => '誕生月', 'app_writable' => true]);
    AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => 'purchase_count', 'type' => AttributeType::Int, 'label' => '購入回数', 'app_writable' => false]);
    $user = AppUser::factory()->for($this->clientApp)->create(['custom_attributes' => ['purchase_count' => 3]]);
    $token = $user->createToken('app')->plainTextToken;

    $this->withToken($token)->patchJson("{$this->base}/me/attributes", ['attributes' => ['birth_month' => '4']])
        ->assertOk()->assertJsonPath('data.attributes.birth_month', 4)->assertJsonPath('data.attributes.purchase_count', 3);

    $this->withToken($token)->patchJson("{$this->base}/me/attributes", ['attributes' => ['purchase_count' => 10]])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['attributes.purchase_count']]]]);
    $this->withToken($token)->patchJson("{$this->base}/me/attributes", ['attributes' => ['unknown' => 1]])->assertUnprocessable();
    $this->withToken($token)->patchJson("{$this->base}/me/attributes", ['attributes' => ['birth_month' => 'abc']])->assertUnprocessable();

    $this->withToken($token)->patchJson("{$this->base}/me/attributes", ['attributes' => ['birth_month' => null]])
        ->assertOk()->assertJsonMissingPath('data.attributes.birth_month');
});

it('manages notification preferences per category', function () {
    NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'notice', 'label' => 'お知らせ', 'default_enabled' => true]);
    NotificationCategory::query()->create(['app_id' => $this->clientApp->id, 'key' => 'campaign', 'label' => 'キャンペーン', 'default_enabled' => false]);
    $token = AppUser::factory()->for($this->clientApp)->create()->createToken('app')->plainTextToken;

    $this->getJson("{$this->base}/notification-categories")->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($token)->getJson("{$this->base}/me/notification-preferences")
        ->assertJsonPath('data.0.enabled', true)->assertJsonPath('data.1.enabled', false);
    $this->withToken($token)->putJson("{$this->base}/me/notification-preferences", ['preferences' => ['campaign' => true, 'notice' => false]])
        ->assertJsonPath('data.0.enabled', false)->assertJsonPath('data.1.enabled', true);
    $this->withToken($token)->putJson("{$this->base}/me/notification-preferences", ['preferences' => ['nope' => true]])->assertUnprocessable();
});

it('applies a per-IP rate limit', function () {
    config(['eevee.rate_limits.ip_per_minute' => 3]);
    foreach (range(1, 3) as $i) {
        $this->getJson("{$this->base}/notification-categories")->assertOk();
    }
    $this->getJson("{$this->base}/notification-categories")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('applies the per-app rate limit separately for each app', function () {
    config(['eevee.rate_limits.app_per_minute' => 2]);
    $other = App::factory()->create();
    $this->getJson("{$this->base}/notification-categories")->assertOk();
    $this->getJson("{$this->base}/notification-categories")->assertOk();
    $this->getJson("{$this->base}/notification-categories")->assertStatus(429);
    $this->getJson("/api/v1/apps/{$other->app_key}/notification-categories")->assertOk();
});
