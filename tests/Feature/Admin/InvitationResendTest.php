<?php

use App\Enums\OrgRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    [$this->invitation, $this->oldToken] = OrganizationInvitation::issue($this->org, 'owner@example.com', OrgRole::Owner, null);
});

/**
 * 送られたメールの招待リンクのパスを返す。
 */
function sentInvitationPath(): string
{
    $url = null;
    Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$url) {
        $url = $mail->acceptUrl;

        return true;
    });

    return (string) parse_url((string) $url, PHP_URL_PATH);
}

it('lets a system admin resend an invitation with a new link', function () {
    Mail::fake();
    $this->invitation->forceFill(['expires_at' => now()->subDay()])->save(); // 期限切れでも再送できる
    actingAsSystemAdmin();

    $this->get('/system/organizations/acme')->assertOk()->assertSee('owner@example.com')->assertSee('再送');
    $this->post("/system/organizations/acme/invitations/{$this->invitation->id}/resend")->assertRedirect()->assertSessionHas('status');

    Mail::assertSent(OrganizationInvitationMail::class, fn ($m) => $m->hasTo('owner@example.com'));
    $new = OrganizationInvitation::withoutGlobalScopes()->sole();
    expect($new->role)->toBe(OrgRole::Owner)
        ->and($new->expires_at->isFuture())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'member.invitation_resent')->exists())->toBeTrue();

    // 古いリンクは使えず、新しいリンクで参加できる
    auth('system')->logout();
    $this->get("/acme/invitations/{$this->oldToken}")->assertNotFound();
    $path = sentInvitationPath();
    $this->post($path, ['name' => 'Owner', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])->assertRedirect('/acme');
});

it('lets an organization owner resend an invitation', function () {
    Mail::fake();
    actingAsOrgMember($this->org, OrgRole::Owner);
    $this->get('/acme/members')->assertOk()->assertSee('再送');
    $this->post("/acme/members/invitations/{$this->invitation->id}/resend")->assertRedirect();
    Mail::assertSent(OrganizationInvitationMail::class, 1);
    expect(OrganizationInvitation::query()->count())->toBe(1);
});

it('lets a system admin revoke an invitation', function () {
    actingAsSystemAdmin();
    $this->delete("/system/organizations/acme/invitations/{$this->invitation->id}")->assertRedirect();
    expect(OrganizationInvitation::withoutGlobalScopes()->count())->toBe(0);
});

it('does not resend invitations of another organization', function () {
    Mail::fake();
    $other = Organization::factory()->create(['slug' => 'other']);
    actingAsSystemAdmin();
    $this->post("/system/organizations/other/invitations/{$this->invitation->id}/resend")->assertNotFound();

    actingAsOrgMember($other, OrgRole::Owner);
    $this->post("/other/members/invitations/{$this->invitation->id}/resend")->assertNotFound();
    Mail::assertNothingSent();
});

it('does not resend an accepted invitation', function () {
    Mail::fake();
    $this->invitation->forceFill(['accepted_at' => now()])->save();
    actingAsSystemAdmin();
    $this->post("/system/organizations/acme/invitations/{$this->invitation->id}/resend")->assertNotFound();
    Mail::assertNothingSent();
});

it('forbids non-owners from resending', function () {
    actingAsOrgMember($this->org, OrgRole::Admin);
    $this->post("/acme/members/invitations/{$this->invitation->id}/resend")->assertForbidden();
});
