<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\IpAllowEntry;
use App\Services\Audit\AuditLogger;
use App\Services\IpAccess\IpAllowlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * システム管理画面用の IP 許可リスト（DB 分）。.env の SYSTEM_IP_ALLOWLIST と合算して判定する。
 */
class IpAllowlistController extends Controller
{
    public function edit(Request $request, IpAllowlistService $service): View
    {
        $entries = IpAllowEntry::query()->whereNull('organization_id')->orderBy('id')->get();

        return view('system.ip-allowlist', [
            'entries' => $entries,
            'input' => IpAllowlistService::toInput($entries),
            'envRanges' => $service->envSystemRanges(),
            'currentIp' => $request->ip(),
        ]);
    }

    public function update(Request $request, IpAllowlistService $service, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['entries' => ['nullable', 'string', 'max:20000']]);
        $parsed = $service->parseInput($request->input('entries'));
        if ($parsed['errors'] !== []) {
            throw ValidationException::withMessages(['entries' => $parsed['errors']]);
        }

        $ranges = array_values(array_unique(array_merge($service->envSystemRanges(), array_column($parsed['entries'], 'cidr'))));
        if ($service->wouldLockOut($ranges, $request->ip()) && ! $request->boolean('confirm_lockout')) {
            return back()->withInput()->with('lockout_warning', true);
        }

        $before = $service->dbRanges(null);
        $service->replace(null, $parsed['entries']);
        $audit->record('system.ip_allowlist.updated', null, ['old' => $before, 'new' => array_column($parsed['entries'], 'cidr')], 'システム管理画面のIP許可リストを更新');

        return redirect()->route('system.ip-allowlist.edit')->with('status', 'IP許可リストを保存しました。');
    }
}
