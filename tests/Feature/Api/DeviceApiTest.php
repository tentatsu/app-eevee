<?php

use App\Models\App;
use App\Models\AppUser;
use App\Models\Device;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->clientApp = App::factory()->create();
    $this->base = "/api/v1/apps/{$this->clientApp->app_key}";
});

it('registers an anonymous device and upserts by installation_id', function () {
    $id = (string) Str::uuid();
    $this->postJson("{$this->base}/devices", [
        'installation_id' => $id, 'platform' => 'ios', 'fcm_token' => 'tok-a', 'app_version' => '1.2.3',
        'os_version' => '18.1', 'locale' => 'ja-JP', 'timezone' => 'Asia/Tokyo', 'push_permission' => 'authorized',
    ])->assertCreated()->assertJsonPath('data.app_user_id', null)->assertJsonPath('data.has_fcm_token', true);

    $this->postJson("{$this->base}/devices", ['installation_id' => $id, 'platform' => 'ios', 'fcm_token' => 'tok-b', 'app_version' => '1.3.0'])
        ->assertOk()->assertJsonPath('data.app_version', '1.3.0')->assertJsonPath('data.locale', 'ja-JP');

    $device = Device::query()->sole();
    expect($device->fcm_token)->toBe('tok-b')
        ->and($device->app_version_num)->toBe(Device::versionToNumber('1.3.0'))
        ->and($device->last_active_at)->not->toBeNull();
});

it('validates the request', function () {
    $this->postJson("{$this->base}/devices", ['installation_id' => 'not-uuid', 'platform' => 'windows'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['installation_id', 'platform']]]]);
});

it('links the device to the user when called with a token', function () {
    $user = AppUser::factory()->for($this->clientApp)->create();
    $token = $user->createToken('app')->plainTextToken;
    $id = (string) Str::uuid();
    $this->withToken($token)->postJson("{$this->base}/devices", ['installation_id' => $id, 'platform' => 'android'])
        ->assertCreated()->assertJsonPath('data.app_user_id', $user->id);
});

it('rejects an invalid token on the optional-auth endpoint', function () {
    $this->withToken('1|invalid')->postJson("{$this->base}/devices", ['installation_id' => (string) Str::uuid(), 'platform' => 'android'])
        ->assertUnauthorized();
});

it('links and unlinks via the me endpoints', function () {
    $user = AppUser::factory()->for($this->clientApp)->create();
    $token = $user->createToken('app')->plainTextToken;
    $device = Device::factory()->for($this->clientApp)->create();

    $this->withToken($token)->postJson("{$this->base}/me/devices/link", ['installation_id' => $device->installation_id])->assertOk();
    expect($device->fresh()->app_user_id)->toBe($user->id);
    $this->withToken($token)->postJson("{$this->base}/me/devices/unlink", ['installation_id' => $device->installation_id])->assertJsonPath('data.unlinked', true);
    expect($device->fresh()->app_user_id)->toBeNull();

    $this->withToken($token)->postJson("{$this->base}/me/devices/link", ['installation_id' => (string) Str::uuid()])->assertNotFound()->assertJsonPath('error.code', 'device_not_found');
});

it('cannot link a device of another app', function () {
    $other = App::factory()->create();
    $device = Device::factory()->for($other)->create();
    $token = AppUser::factory()->for($this->clientApp)->create()->createToken('app')->plainTextToken;
    $this->withToken($token)->postJson("{$this->base}/me/devices/link", ['installation_id' => $device->installation_id])->assertNotFound();
    expect($device->fresh()->app_user_id)->toBeNull();
});

it('revalidates a device whose token was invalidated when a new token arrives', function () {
    $device = Device::factory()->for($this->clientApp)->create(['fcm_token' => 'old', 'invalidated_at' => now(), 'invalidated_reason' => 'UNREGISTERED']);
    $this->postJson("{$this->base}/devices", ['installation_id' => $device->installation_id, 'platform' => 'ios', 'fcm_token' => 'new'])->assertOk();
    expect($device->fresh()->invalidated_at)->toBeNull();
});

it('moves an fcm token from an old installation to avoid duplicate pushes', function () {
    $old = Device::factory()->for($this->clientApp)->create(['fcm_token' => 'same-token']);
    $this->postJson("{$this->base}/devices", ['installation_id' => (string) Str::uuid(), 'platform' => 'ios', 'fcm_token' => 'same-token'])->assertCreated();
    expect($old->fresh()->fcm_token)->toBeNull()->and($old->fresh()->invalidated_reason)->toBe('token_moved');
});

it('converts versions to comparable numbers', function () {
    expect(Device::versionToNumber('1.10.0'))->toBeGreaterThan(Device::versionToNumber('1.9.9'))
        ->and(Device::versionToNumber('2'))->toBe(Device::versionToNumber('2.0.0'))
        ->and(Device::versionToNumber('1.2.3-beta'))->toBe(Device::versionToNumber('1.2.3'));
});
