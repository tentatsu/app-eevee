<?php

namespace App\Http\Controllers\Org\Apps;

use App\Enums\AuthMode;
use App\Enums\ConcurrencyPolicy;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

/**
 * アプリの作成と設定（認証方式、認証情報の暗号化保存、動画・プッシュの設定）。
 */
class AppController extends Controller
{
    public function index(Organization $organization): View
    {
        $apps = App::query()->withCount(['appUsers', 'devices'])->orderBy('name')->get();

        return view('org.apps.index', compact('organization', 'apps'));
    }

    public function create(Organization $organization): View
    {
        Gate::authorize('manage-apps');

        return view('org.apps.create', compact('organization'));
    }

    public function store(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'auth_mode' => ['required', Rule::enum(AuthMode::class)],
        ]);

        $app = App::query()->create($data + ['organization_id' => $organization->id]);
        $secret = $app->regenerateSecret();
        $audit->record('app.created', $app, $data, 'アプリを作成: '.$app->name, app: $app);

        return redirect()->route('org.apps.settings', [$organization, $app])
            ->with('app_secret', $secret)
            ->with('status', 'アプリを作成しました。app_secret は今だけ表示されます。安全な場所に保存してください。');
    }

    public function show(Organization $organization, App $app): View
    {
        $stats = [
            'users' => $app->appUsers()->count(),
            'devices' => $app->devices()->count(),
            'pushable' => $app->devices()->pushable()->count(),
            'notices' => $app->notices()->count(),
            'videos' => $app->videos()->count(),
            'campaigns' => $app->pushCampaigns()->where('is_test', false)->count(),
        ];

        return view('org.apps.show', compact('organization', 'app', 'stats'));
    }

    public function settings(Organization $organization, App $app): View
    {
        Gate::authorize('manage-apps');

        return view('org.apps.settings', compact('organization', 'app'));
    }

    public function update(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'auth_mode' => ['required', Rule::enum(AuthMode::class)],
            'timezone' => ['nullable', 'timezone:all'],
            'external_token_ttl_minutes' => ['required', 'integer', 'min:5', 'max:43200'],
            'max_concurrent_streams' => ['required', 'integer', 'min:1', 'max:100'],
            'concurrency_policy' => ['required', Rule::enum(ConcurrencyPolicy::class)],
            'playback_token_grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'push_rate_per_second' => ['required', 'integer', 'min:1', 'max:1000'],
            'delivery_log_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);
        $app->update($data);
        $audit->record('app.updated', $app, AuditLogger::diff($app), 'アプリの設定を更新');

        return back()->with('status', '設定を保存しました。');
    }

    public function regenerateSecret(Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $secret = $app->regenerateSecret();
        $audit->record('app.secret_regenerated', $app, summary: 'app_secret を再発行');

        return back()->with('app_secret', $secret)->with('status', 'app_secret を再発行しました。古い値は使えなくなります。新しい値は今だけ表示されます。');
    }

    public function updateFirebase(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');

        if ($request->boolean('clear')) {
            $app->firebase_credentials = null;
            $app->save();
            $audit->record('app.firebase_cleared', $app, summary: 'Firebase の認証情報を削除');

            return back()->with('status', 'Firebase の認証情報を削除しました。');
        }

        $request->validate([
            'service_account_json' => ['required_without:service_account_file', 'nullable', 'string', 'max:20000'],
            'service_account_file' => ['required_without:service_account_json', 'nullable', 'file', 'max:20'],
        ]);
        $raw = $request->file('service_account_file')?->get() ?? (string) $request->input('service_account_json');

        try {
            $json = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['service_account_json' => 'JSON として読み取れません。']);
        }
        if (! is_array($json) || ($json['type'] ?? null) !== 'service_account' || empty($json['project_id']) || empty($json['private_key']) || empty($json['client_email'])) {
            throw ValidationException::withMessages(['service_account_json' => 'Firebase のサービスアカウントの JSON（type=service_account, project_id, private_key, client_email）を指定してください。']);
        }

        $app->firebase_credentials = $json;
        $app->save();
        $audit->record('app.firebase_updated', $app, ['project_id' => $json['project_id'], 'client_email' => $json['client_email']], 'Firebase の認証情報を更新');

        return back()->with('status', 'Firebase の認証情報を保存しました（暗号化して保存）。');
    }

    public function updateVideoProvider(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-apps');
        $providers = array_keys((array) config('eevee.video.providers'));
        $data = $request->validate([
            'video_provider' => ['required', Rule::in($providers === [] ? ['cloudflare_stream'] : $providers)],
            'account_id' => ['nullable', 'string', 'max:64'],
            'api_token' => ['nullable', 'string', 'max:255'],
            'signing_key_id' => ['nullable', 'string', 'max:64'],
            'signing_private_key' => ['nullable', 'string', 'max:20000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'customer_subdomain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/'],
        ]);

        $settings = $app->video_provider_settings ?? [];
        // 公開してよい値は空欄で上書き、秘密の値は空欄なら変更しない
        foreach (['account_id', 'signing_key_id', 'customer_subdomain'] as $key) {
            $settings[$key] = $data[$key] ?? null;
        }
        foreach (['api_token', 'signing_private_key', 'webhook_secret'] as $key) {
            if (! empty($data[$key])) {
                $settings[$key] = $data[$key];
            }
            if ($request->boolean('clear_'.$key)) {
                unset($settings[$key]);
            }
        }

        $app->video_provider = $data['video_provider'];
        $app->video_provider_settings = array_filter($settings, fn ($v) => $v !== null && $v !== '');
        $app->save();
        $audit->record('app.video_provider_updated', $app, AuditLogger::mask(['video_provider' => $data['video_provider']] + $data), '動画プロバイダの設定を更新');

        return back()->with('status', '動画プロバイダの設定を保存しました（秘密情報は暗号化して保存）。');
    }
}
