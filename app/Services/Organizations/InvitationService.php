<?php

namespace App\Services\Organizations;

use App\Enums\OrgRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InvitationService
{
    public function invite(Organization $organization, string $email, OrgRole $role, ?string $invitedBy): OrganizationInvitation
    {
        $email = mb_strtolower(trim($email));

        // 同じメールアドレスへの未使用の招待は取り消して作り直す
        OrganizationInvitation::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        [$invitation, $token] = OrganizationInvitation::issue($organization, $email, $role, $invitedBy);

        $url = route('org.invitations.show', ['organization' => $organization->slug, 'token' => $token]);
        Mail::to($email)->send(new OrganizationInvitationMail($organization, $invitation, $url));

        return $invitation;
    }

    /**
     * 招待を受け入れて組織のメンバーにする。
     */
    public function accept(OrganizationInvitation $invitation, AdminUser $user): OrganizationMember
    {
        return DB::transaction(function () use ($invitation, $user) {
            $member = OrganizationMember::query()->firstOrNew([
                'organization_id' => $invitation->organization_id,
                'admin_user_id' => $user->id,
            ]);
            $member->role = $invitation->role;
            $member->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $member;
        });
    }
}
