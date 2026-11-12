<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\PushPermission;
use App\Models\App;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'installation_id' => (string) Str::uuid(),
            'fcm_token' => 'fcm-'.Str::random(40),
            'platform' => fake()->randomElement([Platform::Ios, Platform::Android]),
            'app_version' => '1.0.0',
            'os_version' => '17.0',
            'locale' => 'ja-JP',
            'timezone' => 'Asia/Tokyo',
            'push_permission' => PushPermission::Authorized,
            'last_active_at' => now(),
        ];
    }
}
