<?php

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\SystemAdmin;
use Illuminate\Support\Facades\Mail;

it('requires system admin login for /system', function () {
    $this->get('/system')->assertRedirect('/system/login');
});

it('lets a system admin log in', function () {
    $admin = SystemAdmin::factory()->create(['email' => 'sys@example.com']);
    $this->post('/system/login', ['email' => 'sys@example.com', 'password' => 'password'])->assertRedirect('/system');
    $this->assertAuthenticatedAs($admin, 'system');
});

it('creates an organization with a valid slug', function () {
    actingAsSystemAdmin();
    $this->post('/system/organizations', ['slug' => 'acme', 'name' => 'ACME', 'timezone' => 'Asia/Tokyo'])
        ->assertRedirect();
    expect(Organization::query()->where('slug', 'acme')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'organization.created')->exists())->toBeTrue();
});

it('rejects invalid or reserved slugs', function (string $slug) {
    actingAsSystemAdmin();
    $this->post('/system/organizations', ['slug' => $slug, 'name' => 'X', 'timezone' => 'Asia/Tokyo'])
        ->assertSessionHasErrors('slug');
})->with(['ab', 'Acme', 'acme_corp', 'system', 'api', 'login', 'webhooks', 'health', '-acme', 'acme-', str_repeat('a', 33), 'ac me']);

it('rejects a duplicated slug even after soft delete', function () {
    actingAsSystemAdmin();
    Organization::factory()->create(['slug' => 'taken'])->delete();
    $this->post('/system/organizations', ['slug' => 'taken', 'name' => 'X', 'timezone' => 'Asia/Tokyo'])
        ->assertSessionHasErrors('slug');
});

it('suspends and resumes an organization', function () {
    actingAsSystemAdmin();
    $org = Organization::factory()->create(['slug' => 'acme']);
    $this->post("/system/organizations/{$org->slug}/suspend")->assertRedirect();
    expect($org->fresh()->status)->toBe(OrganizationStatus::Suspended);
    $this->get('/acme/login')->assertForbidden();

    $this->post("/system/organizations/{$org->slug}/resume");
    $this->get('/acme/login')->assertOk();
});

it('deletes an organization only when the slug is confirmed', function () {
    actingAsSystemAdmin();
    $org = Organization::factory()->create(['slug' => 'acme']);
    $this->delete('/system/organizations/acme', ['confirm_slug' => 'wrong'])->assertSessionHasErrors('confirm_slug');
    $this->delete('/system/organizations/acme', ['confirm_slug' => 'acme'])->assertRedirect('/system');
    expect($org->fresh()->trashed())->toBeTrue();
    $this->get('/acme/login')->assertNotFound();
});

it('invites an organization admin by email and accepts the invitation', function () {
    Mail::fake();
    actingAsSystemAdmin();
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->post('/system/organizations/acme/invitations', ['email' => 'Owner@Example.com', 'role' => 'owner'])->assertRedirect();

    $url = null;
    Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$url) {
        $url = $mail->acceptUrl;

        return $mail->hasTo('owner@example.com');
    });

    $path = parse_url((string) $url, PHP_URL_PATH);
    auth('system')->logout();
    $this->get($path)->assertOk()->assertSee('owner@example.com');
    $this->post($path, ['name' => 'Owner', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
        ->assertRedirect('/acme');

    $user = AdminUser::query()->where('email', 'owner@example.com')->firstOrFail();
    expect($user->roleIn($org))->toBe(OrgRole::Owner);
    $this->get('/acme')->assertOk();

    // 使用済みの招待は再利用できない
    $this->post($path, ['name' => 'X', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])->assertNotFound();
});

it('lets an existing admin user join another organization with their password', function () {
    $orgA = Organization::factory()->create(['slug' => 'org-a']);
    $orgB = Organization::factory()->create(['slug' => 'org-b']);
    $user = AdminUser::factory()->memberOf($orgA, OrgRole::Editor)->create(['email' => 'multi@example.com']);
    [, $token] = OrganizationInvitation::issue($orgB, 'multi@example.com', OrgRole::Viewer, null);

    $this->post("/org-b/invitations/{$token}", ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post("/org-b/invitations/{$token}", ['password' => 'password'])->assertRedirect('/org-b');

    expect($user->roleIn($orgA))->toBe(OrgRole::Editor)
        ->and($user->roleIn($orgB))->toBe(OrgRole::Viewer);
});

it('rejects expired invitations', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    [$invitation, $token] = OrganizationInvitation::issue($org, 'x@example.com', OrgRole::Viewer, null);
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->get("/acme/invitations/{$token}")->assertNotFound();
});

it('manages members and protects the last owner', function () {
    $org = Organization::factory()->create();
    $owner = actingAsOrgMember($org, OrgRole::Owner);
    $ownerMember = OrganizationMember::query()->where('admin_user_id', $owner->id)->firstOrFail();

    $this->put("/{$org->slug}/members/{$ownerMember->id}", ['role' => 'admin'])->assertSessionHasErrors('role');
    $this->delete("/{$org->slug}/members/{$ownerMember->id}")->assertSessionHasErrors('member');

    $editor = AdminUser::factory()->memberOf($org, OrgRole::Editor)->create();
    $editorMember = OrganizationMember::query()->where('admin_user_id', $editor->id)->firstOrFail();
    $this->put("/{$org->slug}/members/{$editorMember->id}", ['role' => 'admin'])->assertRedirect();
    expect($editor->roleIn($org))->toBe(OrgRole::Admin);
    $this->delete("/{$org->slug}/members/{$editorMember->id}")->assertRedirect();
    expect($editor->roleIn($org))->toBeNull();
});
