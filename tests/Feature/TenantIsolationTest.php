<?php

use App\Enums\OrgRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolation;

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['slug' => 'org-a']);
    $this->orgB = Organization::factory()->create(['slug' => 'org-b']);
    $this->invA = OrganizationInvitation::issue($this->orgA, 'a@example.com', OrgRole::Viewer, null)[0];
    $this->invB = OrganizationInvitation::issue($this->orgB, 'b@example.com', OrgRole::Viewer, null)[0];
});

it('scopes organization-owned models to the current organization', function () {
    $ctx = app(TenantContext::class);
    $ctx->run($this->orgA, function () {
        expect(OrganizationInvitation::query()->pluck('email')->all())->toBe(['a@example.com'])
            ->and(OrganizationInvitation::query()->find($this->invB->id))->toBeNull();
    });
    $ctx->run($this->orgB, function () {
        expect(OrganizationInvitation::query()->pluck('email')->all())->toBe(['b@example.com']);
    });
});

it('fills the organization on create and refuses another organization', function () {
    app(TenantContext::class)->run($this->orgA, function () {
        $log = new AuditLog(['actor_type' => 'test', 'action' => 'x']);
        $log->save();
        expect($log->organization_id)->toBe($this->orgA->id);

        expect(fn () => AuditLog::query()->create(['organization_id' => $this->orgB->id, 'actor_type' => 'test', 'action' => 'x']))
            ->toThrow(TenantViolation::class);
    });
});

it('cannot revoke an invitation of another organization through the admin UI', function () {
    actingAsOrgMember($this->orgA, OrgRole::Owner);
    $this->delete("/org-a/members/invitations/{$this->invB->id}")->assertNotFound();
    expect($this->invB->fresh())->not->toBeNull();
});

it('cannot change a member of another organization through the admin UI', function () {
    actingAsOrgMember($this->orgA, OrgRole::Owner);
    $other = AdminUser::factory()->memberOf($this->orgB, OrgRole::Owner)->create();
    $member = OrganizationMember::query()->where('admin_user_id', $other->id)->firstOrFail();

    $this->put("/org-a/members/{$member->id}", ['role' => 'viewer'])->assertNotFound();
    $this->delete("/org-a/members/{$member->id}")->assertNotFound();
    expect($member->fresh()->role)->toBe(OrgRole::Owner);
});
