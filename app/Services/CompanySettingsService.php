<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanySettingsService
{
    /**
     * Returns true when the company has at least one posted accounting journal.
     * Draft journals do not constitute posted financial history.
     */
    public function hasPostedJournals(Company $company): bool
    {
        return AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Update the company's base currency.
     *
     * Throws a ValidationException when posted journals exist, the requested
     * currency is unsupported, or the new value equals the current value
     * (the caller can treat the no-op case as success).
     */
    public function updateBaseCurrency(Company $company, string $newCurrency): void
    {
        $newCurrency = strtoupper(trim($newCurrency));

        if (! in_array($newCurrency, ['SYP', 'USD'], true)) {
            throw ValidationException::withMessages([
                'base_currency' => __('ui.settings.financial.currency_invalid'),
            ]);
        }

        if ($this->hasPostedJournals($company)) {
            throw ValidationException::withMessages([
                'base_currency' => __('ui.settings.financial.currency_locked_reason'),
            ]);
        }

        if ($newCurrency === strtoupper((string) $company->base_currency)) {
            // No-op — treat as success without writing.
            return;
        }

        $company->update(['base_currency' => $newCurrency]);
    }

    /**
     * Store a new exchange rate record.
     * Never modifies existing historical records.
     */
    public function storeExchangeRate(Company $company, array $data): ExchangeRate
    {
        $rate = $data['rate'];

        // BCMath second-layer guard (defence in depth beyond Form Request gt:0).
        if (bccomp((string) $rate, '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'rate' => __('ui.settings.exchange_rates.rate_positive'),
            ]);
        }

        return DB::transaction(function () use ($company, $data, $rate): ExchangeRate {
            return ExchangeRate::create([
                'company_id'    => $company->getKey(),
                'base_currency' => strtoupper((string) $company->base_currency),
                'currency'      => strtoupper((string) $data['currency']),
                'rate'          => $rate,
                'effective_at'  => $data['effective_at'],
                'source'        => 'manual',
                'created_by'    => Auth::id(),
            ]);
        });
    }

    /**
     * Update the exchange gain or loss account on the company.
     *
     * $field must be 'exchange_gain_account_id' or 'exchange_loss_account_id'.
     * Uses the TenantScoped global scope to ensure the account belongs to the
     * active company; a cross-company injection results in a ModelNotFoundException.
     */
    public function updateForexAccount(Company $company, string $field, int $accountId): void
    {
        if (! in_array($field, ['exchange_gain_account_id', 'exchange_loss_account_id'], true)) {
            throw new \InvalidArgumentException("Invalid forex account field: {$field}");
        }

        // TenantScoped constrains to the active company automatically.
        $account = FinancialAccount::query()->findOrFail($accountId);

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('ui.settings.forex_accounts.account_inactive'),
            ]);
        }

        if (strtoupper((string) $account->currency) !== strtoupper((string) $company->base_currency)) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('ui.settings.forex_accounts.account_currency_mismatch'),
            ]);
        }

        $accountType = strtolower((string) $account->account_type);

        if ($field === 'exchange_gain_account_id') {
            if (! in_array($accountType, ['revenue', 'income'], true)) {
                throw ValidationException::withMessages([
                    'financial_account_id' => __('ui.settings.forex_accounts.gain_wrong_type'),
                ]);
            }
        } else {
            if ($accountType !== 'expense') {
                throw ValidationException::withMessages([
                    'financial_account_id' => __('ui.settings.forex_accounts.loss_wrong_type'),
                ]);
            }
        }

        $company->update([$field => $accountId]);
    }

    /**
     * Active revenue/income accounts in the company's base currency,
     * eligible for assignment as the exchange gain account.
     *
     * @return Collection<int, FinancialAccount>
     */
    public function eligibleGainAccounts(Company $company): Collection
    {
        return FinancialAccount::query()
            ->where('is_active', true)
            ->whereIn('account_type', ['revenue', 'income'])
            ->where('currency', $company->base_currency)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'currency']);
    }

    /**
     * Active expense accounts in the company's base currency,
     * eligible for assignment as the exchange loss account.
     *
     * @return Collection<int, FinancialAccount>
     */
    public function eligibleLossAccounts(Company $company): Collection
    {
        return FinancialAccount::query()
            ->where('is_active', true)
            ->where('account_type', 'expense')
            ->where('currency', $company->base_currency)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'currency']);
    }

    /**
     * Returns the supported currencies excluding the company's base currency.
     *
     * @return array<int, string>
     */
    public function foreignCurrencies(Company $company): array
    {
        $base = strtoupper((string) $company->base_currency);

        return array_values(array_filter(['SYP', 'USD'], fn (string $c) => $c !== $base));
    }
}
