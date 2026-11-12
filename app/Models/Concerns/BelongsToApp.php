<?php

namespace App\Models\Concerns;

use App\Models\App;
use App\Support\Tenancy\AppScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $app_id
 */
trait BelongsToApp
{
    public static function bootBelongsToApp(): void
    {
        static::addGlobalScope(new AppScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            $appId = $context->appId();
            if ($appId !== null) {
                if (empty($model->app_id)) {
                    $model->app_id = $appId;
                } elseif ((int) $model->app_id !== $appId) {
                    throw new TenantViolation('Cannot create a record for another app.');
                }

                return;
            }
            $orgId = $context->organizationId();
            if ($orgId !== null && ! empty($model->app_id)) {
                $belongs = App::withoutGlobalScopes()->whereKey($model->app_id)->where('organization_id', $orgId)->exists();
                if (! $belongs) {
                    throw new TenantViolation('Cannot create a record for an app of another organization.');
                }
            }
        });

        static::updating(function ($model) {
            $appId = app(TenantContext::class)->appId();
            if ($appId !== null && (int) $model->app_id !== $appId) {
                throw new TenantViolation('Cannot move a record to another app.');
            }
        });
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
