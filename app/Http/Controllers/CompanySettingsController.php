<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\StoreExchangeRateRequest;
use App\Http\Requests\Settings\UpdateFinancialSettingsRequest;
use App\Http\Requests\Settings\UpdateForexAccountRequest;
use App\Http\Requests\Settings\UpdateGeneralSettingsRequest;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Services\CompanyContextService;
use App\Services\CompanySettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanySettingsService $settingsService,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('settings.general');
    }

    // -------------------------------------------------------------------------
    // General Information
    // -------------------------------------------------------------------------

    public function general(): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $timezones = collect(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL))
            ->groupBy(fn (string $tz): string => str_contains($tz, '/') ? explode('/', $tz)[0] : 'Other')
            ->sortKeys();

        return view('settings.general', [
            'company'   => $company,
            'timezones' => $timezones,
        ]);
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        // Preserve existing values for optional fields that were left blank.
        // Only overwrite a nullable column if the user explicitly provided a value.
        $data = collect($request->validated())
            ->reject(fn (mixed $value, string $key): bool => $key !== 'name' && ($value === null || $value === ''))
            ->all();

        $company->update($data);

        return redirect()->route('settings.general')
            ->with('status', __('ui.settings.general.saved'));
    }

    // -------------------------------------------------------------------------
    // Financial Settings
    // -------------------------------------------------------------------------

    public function financial(): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $company->load(['exchangeGainAccount', 'exchangeLossAccount']);
        $currencyLocked = $this->settingsService->hasPostedJournals($company);

        return view('settings.financial', [
            'company'        => $company,
            'currencyLocked' => $currencyLocked,
            'currencies'     => ['SYP', 'USD'],
        ]);
    }

    public function updateFinancial(UpdateFinancialSettingsRequest $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $this->settingsService->updateBaseCurrency($company, $request->validated('base_currency'));

        return redirect()->route('settings.financial')
            ->with('status', __('ui.settings.financial.saved_currency'));
    }

    // -------------------------------------------------------------------------
    // Exchange Rates
    // -------------------------------------------------------------------------

    public function exchangeRates(Request $request): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $filters = $request->validate([
            'currency'  => ['nullable', 'string', 'max:3'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $ratesQuery = ExchangeRate::query()
            ->when($filters['currency'] ?? null, fn (Builder $q, string $c) => $q->where('currency', strtoupper($c)))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('effective_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('effective_at', '<=', $d))
            ->orderByDesc('effective_at')
            ->orderByDesc('id');

        $rates = $ratesQuery->paginate(20)->withQueryString();

        // Latest rate per foreign currency for the summary section.
        $foreignCurrencies = $this->settingsService->foreignCurrencies($company);
        $latestRates = [];
        foreach ($foreignCurrencies as $fc) {
            $latestRates[$fc] = ExchangeRate::query()
                ->where('currency', $fc)
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->first();
        }

        return view('settings.exchange-rates', [
            'company'           => $company,
            'rates'             => $rates,
            'filters'           => $filters,
            'latestRates'       => $latestRates,
            'foreignCurrencies' => $foreignCurrencies,
        ]);
    }

    public function storeExchangeRate(StoreExchangeRateRequest $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $this->settingsService->storeExchangeRate($company, $request->validated());

        return redirect()->route('settings.exchange-rates')
            ->with('status', __('ui.settings.exchange_rates.saved'));
    }

    // -------------------------------------------------------------------------
    // Foreign Exchange Accounts
    // -------------------------------------------------------------------------

    public function forexAccounts(): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $company->load(['exchangeGainAccount', 'exchangeLossAccount']);

        return view('settings.forex-accounts', [
            'company'          => $company,
            'eligibleGain'     => $this->settingsService->eligibleGainAccounts($company),
            'eligibleLoss'     => $this->settingsService->eligibleLossAccounts($company),
        ]);
    }

    public function updateGainAccount(UpdateForexAccountRequest $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $this->settingsService->updateForexAccount(
            $company,
            'exchange_gain_account_id',
            (int) $request->validated('financial_account_id'),
        );

        return redirect()->route('settings.forex-accounts')
            ->with('status', __('ui.settings.forex_accounts.saved_gain'));
    }

    public function updateLossAccount(UpdateForexAccountRequest $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        $this->settingsService->updateForexAccount(
            $company,
            'exchange_loss_account_id',
            (int) $request->validated('financial_account_id'),
        );

        return redirect()->route('settings.forex-accounts')
            ->with('status', __('ui.settings.forex_accounts.saved_loss'));
    }

    // -------------------------------------------------------------------------
    // Financial Year
    // -------------------------------------------------------------------------

    public function fiscalYear(): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorize('view', $company);

        return view('settings.fiscal-year', [
            'company' => $company,
        ]);
    }
}
