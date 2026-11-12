<?php

namespace App\Support\Tenancy;

use App\Models\App;
use App\Models\Organization;
use Closure;

/**
 * 現在のリクエスト（またはジョブ）が属する組織・アプリを保持する。
 *
 * BelongsToOrganization / BelongsToApp トレイトのグローバルスコープはこの値を参照し、
 * 全クエリに organization_id / app_id の条件を自動で付与する。
 */
class TenantContext
{
    private ?Organization $organization = null;

    private ?App $app = null;

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
        if ($organization === null || ($this->app !== null && $this->app->organization_id !== $organization->id)) {
            $this->app = null;
        }
    }

    public function setApp(?App $app): void
    {
        $this->app = $app;
        if ($app !== null) {
            $this->organization = $app->organization()->withoutGlobalScopes()->first();
        }
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function app(): ?App
    {
        return $this->app;
    }

    public function organizationId(): ?int
    {
        return $this->organization?->id;
    }

    public function appId(): ?int
    {
        return $this->app?->id;
    }

    public function hasTenant(): bool
    {
        return $this->organization !== null || $this->app !== null;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->app = null;
    }

    /**
     * 指定したテナントの文脈でコールバックを実行し、終了後に元へ戻す。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Organization|App|null $tenant, Closure $callback): mixed
    {
        $prevOrg = $this->organization;
        $prevApp = $this->app;
        try {
            if ($tenant instanceof App) {
                $this->setApp($tenant);
            } else {
                $this->setOrganization($tenant);
            }

            return $callback();
        } finally {
            $this->organization = $prevOrg;
            $this->app = $prevApp;
        }
    }
}
