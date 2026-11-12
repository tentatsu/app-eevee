<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\IpAllowEntry;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\IpAccess\IpAllowlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IpAllowlistController extends Controller
{
    public function edit(Request $request, Organization $organization): View
    {
        $entries = IpAllowEntry::query()->where('organization_id', $organization->id)->orderBy('id')->get();

        return view('org.ip-allowlist', [
            'organization' => $organization,
            'entries' => $entries,
            'input' => IpAllowlistService::toInput($entries),
            'currentIp' => $request->ip(),
        ]);
    }

    public function update(Request $request, Organization $organization, IpAllowlistService $service, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['entries' => ['nullable', 'string', 'max:20000']]);
        $parsed = $service->parseInput($request->input('entries'));
        if ($parsed['errors'] !== []) {
            throw ValidationException::withMessages(['entries' => $parsed['errors']]);
        }

        $ranges = array_column($parsed['entries'], 'cidr');
        // 自分を締め出さない対策: 現在のIPが含まれない場合は確認を求める
        if ($service->wouldLockOut($ranges, $request->ip()) && ! $request->boolean('confirm_lockout')) {
            return back()->withInput()->with('lockout_warning', true);
        }

        $before = $service->dbRanges($organization);
        $service->replace($organization, $parsed['entries']);

        $audit->record('ip_allowlist.updated', $organization, ['old' => $before, 'new' => $ranges], 'IP許可リストを更新（'.count($ranges).'件）');

        return redirect()->route('org.ip-allowlist.edit', $organization)->with('status', 'IP許可リストを保存しました。');
    }
}
