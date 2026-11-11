<?php

namespace App\Eloquent;

use App\Services\CompanyContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CompanyContextService::class)->id();

        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if (method_exists($model, 'tenantParentRelation')) {
            $relation = $model->tenantParentRelation();
            $builder->whereHas($relation, fn (Builder $query) => $query->where('company_id', $companyId));

            return;
        }

        $builder->where(function (Builder $query) use ($model, $companyId): void {
            $query->where($model->qualifyColumn('company_id'), $companyId);

            if (method_exists($model, 'includesGlobalTenantRecords') && $model->includesGlobalTenantRecords()) {
                $query->orWhereNull($model->qualifyColumn('company_id'));
            }
        });
    }
}
