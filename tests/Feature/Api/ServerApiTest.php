<?php

use App\Enums\AttributeType;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->clientApp = App::factory()->external()->create(['external_token_ttl_minutes' => 30]);
    $this->secret = $this->clientApp->regenerateSecret();
    $this->server = "/api/v1/server/apps/{$this->clientApp->app_key}";
    $this->base = "/api/v1/apps/{$this->clientApp->app_key}";
    foreach ([['purchase_count', AttributeType::Int], ['total_amount', AttributeType::Decimal], ['last_login_at', AttributeType::Date], ['gender', AttributeType::String], ['premium', AttributeType::Bool]] as [$key, $type]) {
        AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => $key, 'type' => $type, 'label' => $key]);
    }
});

it('requires the app secret', function () {
    $this->putJson("{$this->server}/users/ext-1")->assertUnauthorized()->assertJsonPath('error.code', 'invalid_app_secret');
    $this->withToken('as_wrong')->putJson("{$this->server}/users/ext-1")->assertUnauthorized();

    $other = App::factory()->external()->create();
    $otherSecret = $other->regenerateSecret();
    $this->withToken($otherSecret)->putJson("{$this->server}/users/ext-1")->assertUnauthorized();
});

it('stops accepting an old secret after regeneration', function () {
    $this->clientApp->regenerateSecret();
    $this->withToken($this->secret)->putJson("{$this->server}/users/ext-1")->assertUnauthorized();
});

it('upserts users by external_user_id with normalized attributes', function () {
    $this->withToken($this->secret)->putJson("{$this->server}/users/ext-1", [
        'name' => 'Hanako',
        'attributes' => ['purchase_count' => '5', 'total_amount' => '1234.5', 'last_login_at' => '2026-04-01T09:00:00+09:00', 'gender' => 'female', 'premium' => 'true'],
    ])->assertCreated()
        ->assertJsonPath('data.attributes.purchase_count', 5)
        ->assertJsonPath('data.attributes.total_amount', 1234.5)
        ->assertJsonPath('data.attributes.last_login_at', '2026-04-01 00:00:00')
        ->assertJsonPath('data.attributes.premium', true);

    $this->withToken($this->secret)->putJson("{$this->server}/users/ext-1", ['attributes' => ['gender' => null, 'purchase_count' => 6]])
        ->assertOk()->assertJsonPath('data.name', 'Hanako')->assertJsonPath('data.attributes.purchase_count', 6)
        ->assertJsonMissingPath('data.attributes.gender');

    expect(AppUser::query()->where('external_user_id', 'ext-1')->count())->toBe(1);
});

it('issues a short-lived token that works on the app API and links the device', function () {
    $installation = (string) Str::uuid();
    $this->postJson("{$this->base}/devices", ['installation_id' => $installation, 'platform' => 'android', 'fcm_token' => 't'])->assertCreated();
    $this->withToken($this->secret)->putJson("{$this->server}/users/ext-1")->assertCreated();

    $res = $this->withToken($this->secret)->postJson("{$this->server}/users/ext-1/tokens", ['installation_id' => $installation])->assertCreated();
    $token = $res->json('data.token');
    expect(Carbon::parse($res->json('data.expires_at'))->diffInMinutes(now(), true))->toBeGreaterThan(29)->toBeLessThan(31);
    expect(Device::query()->where('installation_id', $installation)->first()->appUser->external_user_id)->toBe('ext-1');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson("{$this->base}/me")->assertOk()->assertJsonPath('data.external_user_id', 'ext-1');

    $this->travel(31)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson("{$this->base}/me")->assertUnauthorized();
});

it('updates attributes in bulk and validates all items first', function () {
    $a = AppUser::factory()->for($this->clientApp)->create(['external_user_id' => 'a', 'email' => null]);
    $b = AppUser::factory()->for($this->clientApp)->create(['external_user_id' => 'b', 'email' => null]);

    $this->withToken($this->secret)->postJson("{$this->server}/users/attributes", ['users' => [
        ['external_user_id' => 'a', 'attributes' => ['purchase_count' => 1]],
        ['user_id' => $b->id, 'attributes' => ['purchase_count' => 'bad']],
    ]])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['users.1.attributes.purchase_count']]]]);
    expect($a->fresh()->custom_attributes)->toBe([]);

    $this->withToken($this->secret)->postJson("{$this->server}/users/attributes", ['users' => [
        ['external_user_id' => 'a', 'attributes' => ['purchase_count' => 1]],
        ['user_id' => $b->id, 'attributes' => ['purchase_count' => 2]],
        ['external_user_id' => 'zzz', 'attributes' => ['purchase_count' => 3]],
    ]])->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.not_found', ['zzz']);

    $this->withToken($this->secret)->postJson("{$this->server}/users/attributes", ['create_missing' => true, 'users' => [
        ['external_user_id' => 'zzz', 'attributes' => ['purchase_count' => 3]],
    ]])->assertJsonPath('data.updated', 1);
    expect(AppUser::query()->where('external_user_id', 'zzz')->first()->custom_attributes)->toBe(['purchase_count' => 3]);
});

it('cannot touch users of another app with its own secret', function () {
    $other = App::factory()->external()->create();
    AppUser::factory()->for($other)->create(['external_user_id' => 'shared-id']);

    $this->withToken($this->secret)->getJson("{$this->server}/users/shared-id")->assertNotFound()->assertJsonPath('error.code', 'user_not_found');
    $this->withToken($this->secret)->postJson("{$this->server}/users/attributes", ['users' => [['external_user_id' => 'shared-id', 'attributes' => ['purchase_count' => 1]]]])
        ->assertJsonPath('data.not_found', ['shared-id']);
});

it('deletes a user', function () {
    AppUser::factory()->for($this->clientApp)->create(['external_user_id' => 'x']);
    $this->withToken($this->secret)->deleteJson("{$this->server}/users/x")->assertOk();
    expect(AppUser::query()->where('external_user_id', 'x')->exists())->toBeFalse();
});
