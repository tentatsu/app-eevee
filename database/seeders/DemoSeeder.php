<?php

namespace Database\Seeders;

use App\Enums\OrgRole;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SystemAdmin;
use Illuminate\Database\Seeder;

/**
 * デモデータ。パスワードはすべて "password"。
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        SystemAdmin::query()->updateOrCreate(['email' => 'system@example.com'], ['name' => 'Oneframe System Admin', 'password' => 'password']);

        $oneframe = Organization::query()->updateOrCreate(['slug' => 'oneframe'], ['name' => 'Oneframe', 'timezone' => 'Asia/Tokyo']);
        $demo = Organization::query()->updateOrCreate(['slug' => 'demo-client'], ['name' => 'Demo Client', 'timezone' => 'Asia/Tokyo']);

        foreach (OrgRole::cases() as $role) {
            $this->member($oneframe, $role->value.'@oneframe.example.com', 'Oneframe '.ucfirst($role->value), $role);
        }
        $this->member($demo, 'owner@demo-client.example.com', 'Demo Owner', OrgRole::Owner);
    }

    private function member(Organization $org, string $email, string $name, OrgRole $role): AdminUser
    {
        $user = AdminUser::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        OrganizationMember::query()->updateOrCreate(['organization_id' => $org->id, 'admin_user_id' => $user->id], ['role' => $role]);

        return $user;
    }
}
