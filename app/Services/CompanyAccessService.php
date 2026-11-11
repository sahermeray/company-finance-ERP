<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\JournalLine;
use App\Models\PurchaseItem;
use App\Models\RevenueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CompanyAccessService
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function userBelongsToCompany(User $user, Company $company): bool
    {
        return $user->companies()->whereKey($company->getKey())->exists();
    }

    public function canAccessCompany(User $user, Company $company): bool
    {
        return Auth::id() !== null
            && (int) Auth::id() === (int) $user->getKey()
            && $this->userBelongsToCompany($user, $company);
    }

    public function canAccess(User $user, Model $record): bool
    {
        $activeCompany = $this->companyContext->current();

        $recordCompanyId = $this->companyIdFor($record);

        return $activeCompany !== null
            && $this->canAccessCompany($user, $activeCompany)
            && ($recordCompanyId === $activeCompany->getKey()
                || ($record instanceof ExchangeRate && $recordCompanyId === null));
    }

    public function companyIdFor(Model $record): ?int
    {
        if ($record->getAttribute('company_id') !== null) {
            return (int) $record->getAttribute('company_id');
        }

        $parentCompanyId = match (true) {
            $record instanceof PurchaseItem => $record->purchase()->withoutGlobalScopes()->value('company_id'),
            $record instanceof RevenueItem => $record->revenue()->withoutGlobalScopes()->value('company_id'),
            $record instanceof JournalLine => $record->journal()->withoutGlobalScopes()->value('company_id'),
            default => null,
        };

        return $parentCompanyId === null ? null : (int) $parentCompanyId;
    }
}
