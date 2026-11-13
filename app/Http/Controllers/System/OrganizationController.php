<?php

namespace App\Http\Controllers\System;

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Models\IpAllowEntry;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\SystemAdmin;
use App\Services\Audit\AuditLogger;
use App\Services\IpAccess\IpAllowlistService;
use App\Services\Organizations\InvitationService;
use App\Support\Validation\OrganizationSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * システム管理者による組織の作成・停止・削除、組織管理者の招待。
 */
class OrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::query()->withCount(['apps', 'members'])->orderBy('slug')->get();

        return view('system.organizations.index', compact('organizations'));
    }

    public function create(): View
    {
        return view('system.organizations.form', ['organization' => new Organization(['timezone' => config('eevee.default_timezone')])]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', new OrganizationSlug, Rule::unique('organizations', 'slug')],
            'name' => ['required', 'string', 'max:191'],
            'timezone' => ['required', 'timezone:all'],
        ]);
        $organization = Organization::query()->create($data + ['status' => OrganizationStatus::Active]);
        $audit->record('organization.created', $organization, $data, '組織を作成: '.$organization->slug, $organization);

        return redirect()->route('system.organizations.show', $organization)->with('status', '組織を作成しました。');
    }

    public function show(Organization $organization): View
    {
        $members = OrganizationMember::query()->where('organization_id', $organization->id)->with('adminUser')->get();
        $invitations = OrganizationInvitation::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNull('accepted_at')->latest()->get();
        $ipEntries = IpAllowEntry::query()->where('organization_id', $organization->id)->get();

        return view('system.organizations.show', compact('organization', 'members', 'invitations', 'ipEntries'));
    }

    public function edit(Organization $organization): View
    {
        return view('system.organizations.form', compact('organization'));
    }

    public function update(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'timezone' => ['required', 'timezone:all'],
        ]);
        $organization->update($data);
        $audit->record('organization.updated', $organization, AuditLogger::diff($organization), '組織を更新', $organization);

        return redirect()->route('system.organizations.show', $organization)->with('status', '組織を更新しました。');
    }

    public function suspend(Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $organization->update(['status' => OrganizationStatus::Suspended]);
        $audit->record('organization.suspended', $organization, summary: '組織を停止', organization: $organization);

        return back()->with('status', '組織を停止しました。管理画面とAPIは利用できなくなります。');
    }

    public function resume(Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $organization->update(['status' => OrganizationStatus::Active]);
        $audit->record('organization.resumed', $organization, summary: '組織を再開', organization: $organization);

        return back()->with('status', '組織を再開しました。');
    }

    public function destroy(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        if ($request->input('confirm_slug') !== $organization->slug) {
            throw ValidationException::withMessages(['confirm_slug' => '確認のため組織のslugを正しく入力してください。']);
        }
        $audit->record('organization.deleted', $organization, summary: '組織を削除: '.$organization->slug, organization: $organization);
        $organization->delete();

        return redirect()->route('system.dashboard')->with('status', '組織を削除しました。');
    }

    public function invite(Request $request, Organization $organization, InvitationService $service, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'role' => ['required', Rule::enum(OrgRole::class)],
        ]);
        /** @var SystemAdmin $admin */
        $admin = $request->user('system');
        $invitation = $service->invite($organization, $data['email'], OrgRole::from($data['role']), 'system:'.$admin->email);
        $audit->record('member.invited', $invitation, $data, $invitation->email.' を招待（システム管理者）', $organization);

        return back()->with('status', $invitation->email.' に招待メールを送信しました。');
    }

    public function resendInvitation(Request $request, Organization $organization, int $invitation, InvitationService $service, AuditLogger $audit): RedirectResponse
    {
        $model = $this->findPendingInvitation($organization, $invitation);
        /** @var SystemAdmin $admin */
        $admin = $request->user('system');
        $new = $service->resend($model, 'system:'.$admin->email);
        $audit->record('member.invitation_resent', $new, ['email' => $new->email, 'role' => $new->role->value], $new->email.' に招待を再送（システム管理者）', $organization);

        return back()->with('status', $new->email.' に招待メールを再送しました（以前のリンクは無効になりました）。');
    }

    public function revokeInvitation(Organization $organization, int $invitation, AuditLogger $audit): RedirectResponse
    {
        $model = $this->findPendingInvitation($organization, $invitation);
        $model->delete();
        $audit->record('member.invitation_revoked', $model, ['email' => $model->email], $model->email.' の招待を取り消し（システム管理者）', $organization);

        return back()->with('status', '招待を取り消しました。');
    }

    private function findPendingInvitation(Organization $organization, int $id): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('accepted_at')
            ->find($id);
        abort_if($invitation === null, 404);

        return $invitation;
    }

    /**
     * 組織のIP制限を解除する（組織の管理者が締め出された場合の救済）。
     */
    public function clearIpAllowlist(Organization $organization, IpAllowlistService $service, AuditLogger $audit): RedirectResponse
    {
        $before = $service->dbRanges($organization);
        $service->clear($organization);
        $audit->record('ip_allowlist.cleared', $organization, ['old' => $before, 'new' => []], 'システム管理者がIP制限を解除', $organization);

        return back()->with('status', 'IP制限を解除しました。');
    }
}
