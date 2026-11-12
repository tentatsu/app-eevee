<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Audit\AuditLogger;
use App\Services\Organizations\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 招待メールのリンクから組織に参加する。
 */
class InvitationController extends Controller
{
    public function show(Organization $organization, string $token): View
    {
        $invitation = $this->findUsable($organization, $token);
        $existing = AdminUser::query()->where('email', $invitation->email)->first();

        return view('org.auth.invitation', [
            'organization' => $organization,
            'invitation' => $invitation,
            'token' => $token,
            'existing' => $existing,
        ]);
    }

    public function accept(Request $request, Organization $organization, string $token, InvitationService $service, AuditLogger $audit): RedirectResponse
    {
        $invitation = $this->findUsable($organization, $token);
        $user = AdminUser::query()->where('email', $invitation->email)->first();

        if ($user !== null) {
            $data = $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'パスワードが正しくありません。']);
            }
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
            $user = AdminUser::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);
        }

        $member = $service->accept($invitation, $user);

        Auth::guard('admin')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureOrganizationMember::SESSION_KEY, [$organization->id]);

        $audit->record('member.joined', $member, ['role' => $member->role->value], $user->email.' が招待を受け入れて参加', $organization);

        return redirect()->route('org.dashboard', $organization)->with('status', $organization->name.' に参加しました。');
    }

    private function findUsable(Organization $organization, string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::findByToken($organization, $token);
        abort_if($invitation === null || ! $invitation->isUsable(), 404, '招待が見つからないか、有効期限が切れています。');

        return $invitation;
    }
}
