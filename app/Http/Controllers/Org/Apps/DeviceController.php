<?php

namespace App\Http\Controllers\Org\Apps;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Device;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request, Organization $organization, App $app): View
    {
        $query = Device::query()->with('appUser')->latest('last_active_at');
        if ($request->boolean('test_only')) {
            $query->where('is_test_device', true);
        }
        if ($q = $request->string('q')->trim()->value()) {
            $query->where(fn ($w) => $w->where('installation_id', $q)->orWhere('app_user_id', ctype_digit($q) ? (int) $q : 0)->orWhere('test_label', 'like', "%{$q}%"));
        }
        $devices = $query->paginate(50)->withQueryString();

        return view('org.apps.devices', compact('organization', 'app', 'devices'));
    }

    /**
     * テスト配信の対象にする端末を登録・解除する。
     */
    public function updateTest(Request $request, Organization $organization, App $app, Device $device, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $data = $request->validate(['is_test_device' => ['required', 'boolean'], 'test_label' => ['nullable', 'string', 'max:100']]);
        $device->update(['is_test_device' => $request->boolean('is_test_device'), 'test_label' => $data['test_label'] ?? $device->test_label]);
        $audit->record($device->is_test_device ? 'device.test_registered' : 'device.test_unregistered', $device, ['label' => $device->test_label], 'テスト端末の設定を変更');

        return back()->with('status', $device->is_test_device ? 'テスト端末に登録しました。' : 'テスト端末から外しました。');
    }
}
