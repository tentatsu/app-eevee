<?php

use App\Enums\OrgRole;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\SystemAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * 組織のメンバーを作成し、その組織にログインした状態にする。
 */
function actingAsOrgMember(Organization $organization, OrgRole $role = OrgRole::Owner, ?AdminUser $user = null): AdminUser
{
    $user ??= AdminUser::factory()->memberOf($organization, $role)->create();

    test()->actingAs($user, 'admin')->withSession([EnsureOrganizationMember::SESSION_KEY => [$organization->id]]);

    return $user;
}

function actingAsSystemAdmin(): SystemAdmin
{
    $admin = SystemAdmin::factory()->create();
    test()->actingAs($admin, 'system');

    return $admin;
}
