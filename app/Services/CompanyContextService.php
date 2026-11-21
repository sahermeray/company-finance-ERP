<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

class CompanyContextService
{
    private ?Company $overriddenCompany = null;

    public function current(): ?Company
    {
        if ($this->overriddenCompany !== null) {
            return $this->overriddenCompany;
        }

        $user = Auth::user();
        $session = $this->sessionStore();

        if ($session === null) {
            return null;
        }

        $companyId = $session->get('active_company_id');

        if (! $user instanceof User || ! is_numeric($companyId)) {
            return null;
        }

        $company = $user->companies()->whereKey((int) $companyId)->first();

        if ($company === null) {
            $session->forget('active_company_id');
        }

        return $company;
    }

    public function id(): ?int
    {
        return $this->current()?->getKey();
    }

    public function requireCurrent(): Company
    {
        return $this->current() ?? abort(409, 'An active company context is required.');
    }

    private function sessionStore(): ?Store
    {
        if (! app()->bound('session.store')) {
            return null;
        }

        $store = app('session.store');

        return $store instanceof Store ? $store : null;
    }

    public function switchTo(User $user, int $companyId): Company
    {
        $company = $user->companies()->whereKey($companyId)->first();

        if ($company === null) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$companyId]);
        }

        $this->overriddenCompany = $company;

        if (app()->bound('session')) {
            app('session')->put('active_company_id', $company->getKey());
        }

        return $company;
    }

    /**
     * Run trusted internal work with an explicit tenant context.
     */
    public function runForCompany(Company $company, Closure $callback): mixed
    {
        $previousCompany = $this->overriddenCompany;
        $this->overriddenCompany = $company;

        try {
            return $callback();
        } finally {
            $this->overriddenCompany = $previousCompany;
        }
    }

    /**
     * Clear any in-memory company override so that subsequent calls to
     * current() fall back to resolving the company from the session.
     *
     * This is needed at the start of each HTTP request because the service
     * is a singleton and switchTo() from a prior request leaves a stale
     * overriddenCompany that would mask the session value.
     */
    public function clearOverride(): void
    {
        $this->overriddenCompany = null;
    }
}
