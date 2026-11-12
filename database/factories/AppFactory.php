<?php

namespace Database\Factories;

use App\Enums\AuthMode;
use App\Enums\ConcurrencyPolicy;
use App\Models\App;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->word().' App',
            'auth_mode' => AuthMode::Builtin,
            'video_provider' => 'cloudflare_stream',
            'max_concurrent_streams' => 2,
            'concurrency_policy' => ConcurrencyPolicy::Reject,
            'playback_token_grace_minutes' => 10,
        ];
    }

    public function external(): static
    {
        return $this->state(['auth_mode' => AuthMode::External]);
    }
}
