<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $organization_id
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            $orgId = app(TenantContext::class)->organizationId();
            if ($orgId === null) {
                return;
            }
            if (empty($model->organization_id)) {
                $model->organization_id = $orgId;
            } elseif ((int) $model->organization_id !== $orgId) {
                throw new TenantViolation('Cannot create a record for another organization.');
            }
        });

        static::updating(function ($model) {
            $orgId = app(TenantContext::class)->organizationId();
            if ($orgId !== null && (int) $model->organization_id !== $orgId) {
                throw new TenantViolation('Cannot move a record to another organization.');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
