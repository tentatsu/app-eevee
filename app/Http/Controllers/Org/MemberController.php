<?php

namespace App\Http\Controllers\Org;

use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Services\Audit\AuditLogger;
use App\Services\Organizations\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Organization $organization): View
    {
        $members = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->with('adminUser')
            ->orderBy('id')
            ->get();
        $invitations = OrganizationInvitation::query()->whereNull('accepted_at')->latest()->get();

        return view('org.members.index', compact('organization', 'members', 'invitations'));
    }

    public function invite(Request $request, Organization $organization, InvitationService $service, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'role' => ['required', Rule::enum(OrgRole::class)],
        ]);
        /** @var AdminUser $actor */
        $actor = $request->user('admin');
        $invitation = $service->invite($organization, $data['email'], OrgRole::from($data['role']), $actor->email);

        $audit->record('member.invited', $invitation, ['email' => $invitation->email, 'role' => $data['role']], $invitation->email.' を招待');

        return back()->with('status', $invitation->email.' に招待メールを送信しました。');
    }

    public function revokeInvitation(Organization $organization, OrganizationInvitation $invitation, AuditLogger $audit): RedirectResponse
    {
        $invitation->delete();
        $audit->record('member.invitation_revoked', $invitation, ['email' => $invitation->email], $invitation->email.' の招待を取り消し');

        return back()->with('status', '招待を取り消しました。');
    }

    public function update(Request $request, Organization $organization, OrganizationMember $member, AuditLogger $audit): RedirectResponse
    {
        $this->ensureSameOrganization($organization, $member);
        $data = $request->validate(['role' => ['required', Rule::enum(OrgRole::class)]]);
        $role = OrgRole::from($data['role']);

        if ($member->role === OrgRole::Owner && $role !== OrgRole::Owner && $this->ownerCount($organization) <= 1) {
            throw ValidationException::withMessages(['role' => 'オーナーが1人もいなくなるため変更できません。']);
        }

        $old = $member->role;
        $member->role = $role;
        $member->save();
        $audit->record('member.role_changed', $member, ['role' => ['old' => $old->value, 'new' => $role->value]], 'ロール変更');

        return back()->with('status', 'ロールを変更しました。');
    }

    public function destroy(Organization $organization, OrganizationMember $member, AuditLogger $audit): RedirectResponse
    {
        $this->ensureSameOrganization($organization, $member);
        if ($member->role === OrgRole::Owner && $this->ownerCount($organization) <= 1) {
            throw ValidationException::withMessages(['member' => 'オーナーが1人もいなくなるため削除できません。']);
        }
        $member->delete();
        $audit->record('member.removed', $member, ['admin_user_id' => $member->admin_user_id], 'メンバーを削除');

        return back()->with('status', 'メンバーを削除しました。');
    }

    private function ensureSameOrganization(Organization $organization, OrganizationMember $member): void
    {
        abort_unless($member->organization_id === $organization->id, 404);
    }

    private function ownerCount(Organization $organization): int
    {
        return OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrgRole::Owner->value)
            ->count();
    }
}
