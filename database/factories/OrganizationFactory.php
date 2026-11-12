<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => 'org-'.fake()->unique()->numerify('#####'),
            'name' => fake()->company(),
            'status' => OrganizationStatus::Active,
            'timezone' => 'Asia/Tokyo',
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => OrganizationStatus::Suspended]);
    }
}
