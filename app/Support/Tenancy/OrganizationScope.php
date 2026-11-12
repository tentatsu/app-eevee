<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $orgId = app(TenantContext::class)->organizationId();
        if ($orgId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $orgId);
        }
    }
}
