<?php

namespace Database\Factories;

use App\Enums\OrgRole;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ];
    }

    public function memberOf(Organization $organization, OrgRole $role = OrgRole::Owner): static
    {
        return $this->afterCreating(function (AdminUser $user) use ($organization, $role) {
            OrganizationMember::query()->create([
                'organization_id' => $organization->id,
                'admin_user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }
}
