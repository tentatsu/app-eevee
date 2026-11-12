<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @implements Scope<Model>
 */
class AppScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn('app_id');

        if (($appId = $context->appId()) !== null) {
            $builder->where($column, $appId);

            return;
        }

        if (($orgId = $context->organizationId()) !== null) {
            // アプリが確定していなくても、組織に属するアプリのデータだけに限定する
            $builder->whereIn($column, function (QueryBuilder $q) use ($orgId) {
                $q->select('id')->from('apps')->where('organization_id', $orgId);
            });
        }
    }
}
