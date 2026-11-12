<?php

use App\Enums\OrgRole;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationMember;

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['slug' => 'org-a']);
    $this->orgB = Organization::factory()->create(['slug' => 'org-b']);
});

it('shows the login page per organization and 404 for unknown organizations', function () {
    $this->get('/org-a/login')->assertOk()->assertSee($this->orgA->name);
    $this->get('/no-such-org/login')->assertNotFound();
});

it('redirects guests to the organization login page', function () {
    $this->get('/org-a')->assertRedirect('/org-a/login');
});

it('logs in a member of the organization', function () {
    $user = AdminUser::factory()->memberOf($this->orgA)->create(['email' => 'a@example.com']);
    $this->post('/org-a/login', ['email' => 'a@example.com', 'password' => 'password'])->assertRedirect('/org-a');
    $this->assertAuthenticatedAs($user, 'admin');
    $this->get('/org-a')->assertOk();
});

it('does not log in a user who is not a member of the organization', function () {
    AdminUser::factory()->memberOf($this->orgA)->create(['email' => 'a@example.com']);
    $this->post('/org-b/login', ['email' => 'a@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest('admin');
});

it('returns 404 when a logged-in admin opens an organization they do not belong to', function () {
    actingAsOrgMember($this->orgA);
    $this->get('/org-a')->assertOk();
    $this->get('/org-b')->assertNotFound();
    $this->get('/org-b/members')->assertNotFound();
    $this->get('/org-b/audit-logs')->assertNotFound();
});

it('requires a separate login for each organization even when the user belongs to both', function () {
    $user = AdminUser::factory()->memberOf($this->orgA)->create(['email' => 'multi@example.com']);
    OrganizationMember::query()->create(['organization_id' => $this->orgB->id, 'admin_user_id' => $user->id, 'role' => OrgRole::Viewer]);

    $this->post('/org-a/login', ['email' => 'multi@example.com', 'password' => 'password']);
    $this->get('/org-a')->assertOk();
    $this->get('/org-b')->assertRedirect('/org-b/login');

    $this->post('/org-b/login', ['email' => 'multi@example.com', 'password' => 'password'])->assertRedirect('/org-b');
    $this->get('/org-b')->assertOk();
    $this->get('/org-a')->assertOk();
});

it('enforces roles on organization pages', function (OrgRole $role, int $members, int $audit) {
    actingAsOrgMember($this->orgA, $role);
    $this->get('/org-a/members')->assertStatus($members);
    $this->get('/org-a/ip-allowlist')->assertStatus($members);
    $this->get('/org-a/audit-logs')->assertStatus($audit);
})->with([
    'owner' => [OrgRole::Owner, 200, 200],
    'admin' => [OrgRole::Admin, 403, 200],
    'editor' => [OrgRole::Editor, 403, 403],
    'viewer' => [OrgRole::Viewer, 403, 403],
]);

it('logs out', function () {
    actingAsOrgMember($this->orgA);
    $this->post('/org-a/logout')->assertRedirect('/org-a/login');
    $this->assertGuest('admin');
});
