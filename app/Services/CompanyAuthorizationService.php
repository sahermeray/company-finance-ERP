<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyAuthorizationService
{
    /**
     * Check whether the user has the given permission in the given company.
     *
     * Logic:
     *  1. User must be active (defaults to true if column absent in schema).
     *  2. If the user is the owner in this company → all permissions granted.
     *  3. If the user has a role → check the role's permission set.
     *  4. No role assigned → no permissions.
     */
    public function check(User $user, Company $company, string $permission): bool
    {
        // is_active defaults to true when the column is not yet present.
        if (($user->is_active ?? true) === false) {
            return false;
        }

        $pivot = $user->companies()
            ->withPivot('role_id', 'is_owner')
            ->whereKey($company->getKey())
            ->first();

        if ($pivot === null) {
            return false;
        }

        if ((bool) $pivot->pivot->is_owner) {
            return true;
        }

        $roleId = $pivot->pivot->role_id;
        if ($roleId === null) {
            return false;
        }

        $role = Role::with('permissions')->find($roleId);

        return $role !== null && $role->hasPermission($permission);
    }

    /**
     * Assert that the user has the given permission, aborting with 403 if not.
     */
    public function requirePermission(User $user, Company $company, string $permission): void
    {
        if (! $this->check($user, $company, $permission)) {
            throw new HttpException(403, 'This action requires the "'.$permission.'" permission.');
        }
    }

    /**
     * Return true if the user is flagged as owner in the company_user pivot.
     */
    public function userIsOwner(User $user, Company $company): bool
    {
        $pivot = $user->companies()
            ->withPivot('is_owner')
            ->whereKey($company->getKey())
            ->first();

        return $pivot !== null && (bool) $pivot->pivot->is_owner;
    }
}
