<?php

namespace App\Providers;

use App\Jobs\SendCampaignChunkJob;
use App\Models\App;
use App\Models\AppUser;
use App\Support\Tenancy\CurrentMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // リクエスト（ジョブ）ごとにリセットされるテナント文脈
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentMembership::class);

        $this->app->register(EeveeServiceProvider::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'app_user' => AppUser::class,
        ]);

        Password::defaults(fn () => Password::min(8));

        // 組織の管理画面のメニュー: アプリの切り替え用に、組織のアプリ一覧を渡す
        View::composer('layouts.org', function ($view) {
            if (app(TenantContext::class)->organizationId() !== null) {
                $view->with('orgApps', App::query()->orderBy('name')->get(['id', 'name']));
            }
        });

        $this->defineGates();
        $this->defineRateLimiters();
    }

    private function defineGates(): void
    {
        $role = fn () => app(CurrentMembership::class)->role();

        Gate::define('manage-organization', fn ($user) => $role()?->canManageOrganization() ?? false);
        Gate::define('manage-apps', fn ($user) => $role()?->canManageApps() ?? false);
        Gate::define('edit-content', fn ($user) => $role()?->canEditContent() ?? false);
        Gate::define('view-audit-logs', fn ($user) => $role()?->canViewAuditLogs() ?? false);
    }

    /**
     * レート制限のキーに使うアプリ。ResolveAppByKey がルートから app_key を外すため、解決済みのアプリを優先する。
     */
    private static function appKeyOf(Request $request): string
    {
        $app = $request->attributes->get('eevee_app');

        return $app instanceof App ? $app->app_key : (string) $request->route('app_key');
    }

    private function defineRateLimiters(): void
    {
        // アプリ向けAPI: アプリ単位とIP単位
        RateLimiter::for('app-api', function (Request $request) {
            $appKey = self::appKeyOf($request);

            return [
                Limit::perMinute((int) config('eevee.rate_limits.app_per_minute'))->by('app:'.$appKey),
                Limit::perMinute((int) config('eevee.rate_limits.ip_per_minute'))->by('ip:'.$request->ip()),
            ];
        });

        // ログイン・登録・パスワード再設定（総当たり対策）
        RateLimiter::for('app-auth', function (Request $request) {
            $appKey = self::appKeyOf($request);

            return [
                Limit::perMinute((int) config('eevee.rate_limits.auth_per_minute'))
                    ->by('auth:'.$appKey.':'.$request->ip().':'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        // サーバー間API: アプリ単位
        RateLimiter::for('server-api', function (Request $request) {
            return Limit::perMinute((int) config('eevee.rate_limits.server_per_minute'))
                ->by('server:'.self::appKeyOf($request));
        });

        // プッシュ送信: アプリごとに 1 秒あたりのチャンク数を制限する
        RateLimiter::for('push-chunks', function (object $job) {
            $appId = $job instanceof SendCampaignChunkJob ? $job->appId() : null;
            $perSecond = $appId !== null ? (int) (App::withoutGlobalScopes()->whereKey($appId)->value('push_rate_per_second') ?: 20) : 20;

            return Limit::perSecond($perSecond)->by('push:'.$appId);
        });

        // 管理画面のログイン
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(10)->by('admin-login:'.$request->ip().':'.mb_strtolower((string) $request->input('email')));
        });
    }
}
