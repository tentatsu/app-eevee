<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\ProviderAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Video\Contracts\ProvisionsAutomatically;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\WebhookConflictException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Oneframe が用意して複数のアプリで共用する Cloudflare Stream のアカウント。
 */
class ProviderAccountController extends Controller
{
    public function index(): View
    {
        $accounts = ProviderAccount::query()->where('provider', 'cloudflare_stream')->withCount('apps')->orderBy('name')->get();

        return view('system.provider-accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        return view('system.provider-accounts.form', ['account' => new ProviderAccount(['provider' => 'cloudflare_stream'])]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, null);
        $account = ProviderAccount::query()->create([
            'provider' => 'cloudflare_stream',
            'name' => $data['name'],
            'settings' => array_filter(['account_id' => $data['account_id'], 'api_token' => $data['api_token'] ?? null]),
        ]);
        $audit->record('system.provider_account.created', $account, ['name' => $account->name], '共用の動画アカウントを作成: '.$account->name);

        return redirect()->route('system.provider-accounts.edit', $account)->with('status', '作成しました。続けて「Cloudflare を自動設定」を押してください。');
    }

    public function edit(ProviderAccount $providerAccount): View
    {
        $apps = $providerAccount->apps()->withoutGlobalScopes()->with('organization')->get();

        return view('system.provider-accounts.form', ['account' => $providerAccount, 'apps' => $apps]);
    }

    public function update(Request $request, ProviderAccount $providerAccount, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, $providerAccount);
        $settings = $providerAccount->settings ?? [];
        $settings['account_id'] = $data['account_id'];
        if (! empty($data['api_token'])) {
            $settings['api_token'] = $data['api_token'];
        }
        $providerAccount->update(['name' => $data['name'], 'settings' => $settings]);
        $audit->record('system.provider_account.updated', $providerAccount, ['name' => $providerAccount->name], '共用の動画アカウントを更新');

        return back()->with('status', '保存しました。');
    }

    /**
     * 署名鍵の作成と、共用の Webhook の受け口の設定。
     */
    public function provision(Request $request, ProviderAccount $providerAccount, VideoProviderManager $providers, AuditLogger $audit): RedirectResponse
    {
        if ($providerAccount->setting('account_id') === null || $providerAccount->setting('api_token') === null) {
            throw ValidationException::withMessages(['provision' => '先に Account ID と API Token を保存してください。']);
        }
        $asApp = $providerAccount->asApp();
        $provider = $providers->for($asApp);
        if (! $provider instanceof ProvisionsAutomatically) {
            throw ValidationException::withMessages(['provision' => 'このプロバイダは自動設定に対応していません。']);
        }

        $webhookUrl = route('webhooks.cloudflare.shared', ['token' => $providerAccount->webhook_token]);
        try {
            $result = $provider->provision($asApp, $webhookUrl, $request->boolean('overwrite_webhook'));
        } catch (WebhookConflictException $e) {
            return back()->with('webhook_conflict', $e->currentUrl);
        } catch (VideoProviderException $e) {
            throw ValidationException::withMessages(['provision' => 'Cloudflare の設定に失敗しました: '.$e->getMessage()]);
        }

        $providerAccount->settings = array_merge($providerAccount->settings ?? [], $result->settings);
        $providerAccount->save();
        $audit->record('system.provider_account.provisioned', $providerAccount, ['signing_key_id' => $result->settings['signing_key_id'] ?? null], '共用の動画アカウントを自動設定');

        return back()->with('status', implode(' ', $result->notes));
    }

    public function destroy(ProviderAccount $providerAccount, AuditLogger $audit): RedirectResponse
    {
        if ($providerAccount->apps()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages(['account' => 'このアカウントを使っているアプリがあるため削除できません。先にアプリの割り当てを外してください。']);
        }
        $providerAccount->delete();
        $audit->record('system.provider_account.deleted', $providerAccount, summary: '共用の動画アカウントを削除: '.$providerAccount->name);

        return redirect()->route('system.provider-accounts.index')->with('status', '削除しました。');
    }

    /**
     * アプリに共用のアカウントを割り当てる（または外して、アプリ専用の設定に戻す）。
     */
    public function assign(Request $request, int $app, AuditLogger $audit): RedirectResponse
    {
        $model = App::withoutGlobalScopes()->findOrFail($app);
        $data = $request->validate(['video_provider_account_id' => ['nullable', 'integer', 'exists:provider_accounts,id']]);
        $old = $model->video_provider_account_id;
        $model->video_provider_account_id = $data['video_provider_account_id'] ?? null;
        if ($model->video_provider_account_id !== null) {
            $model->video_provider = 'cloudflare_stream';
        }
        $model->save();

        $org = $model->organization()->withoutGlobalScopes()->first();
        $audit->record('system.app.video_account_assigned', $model, ['old' => $old, 'new' => $model->video_provider_account_id], 'アプリの動画アカウントを変更: '.$model->name, $org, $model);

        return back()->with('status', $model->video_provider_account_id ? '共用のアカウントを割り当てました。' : '共用のアカウントを外しました（アプリ専用の設定を使います）。');
    }

    /**
     * @return array{name: string, account_id: string, api_token?: string|null}
     */
    private function validated(Request $request, ?ProviderAccount $account): array
    {
        /** @var array{name: string, account_id: string, api_token?: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'account_id' => ['required', 'string', 'max:64'],
            'api_token' => [$account === null ? 'required' : 'nullable', 'string', 'max:255'],
        ]);
    }
}
