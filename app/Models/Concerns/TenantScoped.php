<?php

namespace App\Models\Concerns;

use App\Eloquent\CompanyScope;
use App\Services\CrossCompanyValidationService;
use Illuminate\Database\Eloquent\Model;

trait TenantScoped
{
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::saving(function (Model $model): void {
            app(CrossCompanyValidationService::class)->validate($model);
        });
    }

    public function includesGlobalTenantRecords(): bool
    {
        return false;
    }
}
