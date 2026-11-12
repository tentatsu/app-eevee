<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\AppUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppUser>
 */
class AppUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'name' => fake()->name(),
            'custom_attributes' => [],
        ];
    }
}
