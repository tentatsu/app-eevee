<?php

namespace Database\Factories;

use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemAdmin>
 */
class SystemAdminFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ];
    }
}
