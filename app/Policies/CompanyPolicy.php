<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyAccessService;

class CompanyPolicy
{
    public function __construct(private readonly CompanyAccessService $companyAccess) {}

    public function viewAny(User $user): bool
    {
        return $user->companies()->exists();
    }

    public function view(User $user, Company $company): bool
    {
        return $this->companyAccess->userBelongsToCompany($user, $company);
    }
}
