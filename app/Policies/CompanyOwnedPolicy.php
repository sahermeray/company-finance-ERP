<?php

namespace App\Policies;

use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\CompanyContextService;
use Illuminate\Database\Eloquent\Model;

class CompanyOwnedPolicy
{
    public function __construct(
        private readonly CompanyAccessService $companyAccess,
        private readonly CompanyContextService $companyContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $activeCompany = $this->companyContext->current();

        return $activeCompany !== null
            && $this->companyAccess->canAccessCompany($user, $activeCompany);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->companyAccess->canAccess($user, $record);
    }
}
