# Phase 9A — Company Settings UI: Technical Design

## Overview

This feature adds a Company Settings section to the existing Laravel ERP at **Administration → Company Settings**. The section is organized into five sub-pages (tabs): General Information, Financial Settings, Exchange Rates, Foreign Exchange Accounts, and Financial Year. Each sub-page renders inside `layouts.inventory` and uses the same CSS classes, component conventions, and navigation patterns already in use across the application.

The implementation requires no new database migrations: every field (`name`, `legal_name`, `tax_identifier`, `email`, `phone`, `address`, `country_code`, `timezone`, `base_currency`, `exchange_gain_account_id`, `exchange_loss_account_id`) already exists on the `companies` table, and the `exchange_rates` table is complete. No new models are needed. The work is six PHP files (a controller, four Form Requests, and a service), five Blade views, one config change (navigation route binding), one view composer registration, and translation additions to both language files.

**Technology stack (locked):** PHP 8.4, Laravel 11, Blade (server-side rendering, no Livewire or Inertia), Tailwind CSS utility classes, BCMath for all rate arithmetic.

---

## Authorization

The existing schema has no owner or role column on `company_user`. Two policies already exist: `CompanyPolicy` (membership check) and `CompanyOwnedPolicy` (active-company scoping). `CompanyOwnedPolicy::viewAny()` returns `true` when the user is a member of the active company. This is the correct gate to use for settings — it matches how every other company-scoped resource is guarded.

The settings controller calls `$this->authorize('viewAny', Company::class)` using `CompanyPolicy`, which checks that the user has at least one company. This is intentionally permissive for Phase 9A (all members may read and write settings), consistent with the requirements decision documented in FR-2.1. Phase 9C will introduce granular roles. No new policy is created; Company and FinancialAccount models are already registered in `AppServiceProvider`.

The `company_id` is never read from any request body. Every controller action calls `app(CompanyContextService::class)->requireCurrent()` and uses that company's primary key when querying or writing.

---

## Route and Navigation

A new route group is added to `routes/web.php` inside the existing `auth` + `company.context` middleware group:

```
prefix: /settings
name prefix: settings.
middleware: auth, company.context
```

Routes:

| Method | URI | Name | Action |
|--------|-----|------|--------|
| GET | /settings | settings.index | CompanySettingsController@index (redirects to general) |
| GET | /settings/general | settings.general | CompanySettingsController@general |
| PUT | /settings/general | settings.general.update | CompanySettingsController@updateGeneral |
| GET | /settings/financial | settings.financial | CompanySettingsController@financial |
| PUT | /settings/financial | settings.financial.update | CompanySettingsController@updateFinancial |
| GET | /settings/exchange-rates | settings.exchange-rates | CompanySettingsController@exchangeRates |
| POST | /settings/exchange-rates | settings.exchange-rates.store | CompanySettingsController@storeExchangeRate |
| GET | /settings/forex-accounts | settings.forex-accounts | CompanySettingsController@forexAccounts |
| PUT | /settings/forex-accounts/gain | settings.forex-accounts.gain | CompanySettingsController@updateGainAccount |
| PUT | /settings/forex-accounts/loss | settings.forex-accounts.loss | CompanySettingsController@updateLossAccount |
| GET | /settings/fiscal-year | settings.fiscal-year | CompanySettingsController@fiscalYear |

`settings.index` redirects to `settings.general` using `return redirect()->route('settings.general')`. This ensures a clean landing URL.

`config/navigation.php`: the `company-settings` item in the Administration group gains a `route` key set to `'settings.general'` and an `active` key set to `['settings.*']`. This wire-up means the sidebar will render it as an active link (see `app-sidebar.blade.php` logic: it checks `Route::has($item['route'])` and `request()->routeIs(...$item['active'])`).

---

## View Composer

`AppServiceProvider::boot()` currently registers a view composer for the glob `['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*', 'reports.*']`. Settings views live at `resources/views/settings/*` and need the same `$activeCompany` and `$companies` variables for the sidebar and topbar to render correctly. Add `'settings.*'` to the existing composer glob — this is a one-line addition to the array.

---

## CompanySettingsController

**File:** `app/Http/Controllers/CompanySettingsController.php`

This controller has eleven public methods matching the route table above. It is kept thin: validation is in Form Requests, business-rule enforcement is in `CompanySettingsService`, and direct model writes for simple cases (like general information) happen in the controller using `$company->update(...)`.

```php
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanySettingsService $settingsService,
    ) {}
}
```

### `general()`

Calls `$this->authorize('view', $company)` using `CompanyPolicy::view()`. Passes `$company` and a list of timezone identifiers to the view.

Timezone list: `\DateTimeZone::listIdentifiers(\DateTimeZone::ALL)` sorted alphabetically. This produces the full IANA list (≈420 entries). The view renders them in a `<select>` grouped by region using `explode('/', $tz)[0]` as the optgroup label.

### `updateGeneral(UpdateGeneralSettingsRequest $request)`

Uses `$request->validated()`. Calls `$company->update($request->validated())`. Redirects back to `settings.general` with `session('status')`.

**Partial-update behaviour:** The Form Request rules mark all fields except `name` as `nullable`. The controller uses only `$request->validated()` — if a field is absent from the request (not sent in the form at all), it is not in the validated array and `update()` will not touch that column. Fields sent as empty string are set to `null` only when the column is `nullable` (which all optional company columns are). This satisfies FR-3.5.

### `financial()`

Loads `$company` with `exchange_gain_account_id` and `exchange_loss_account_id`. Calls `$this->settingsService->hasPostedJournals($company)` to pass a `$currencyLocked` boolean to the view.

### `updateFinancial(UpdateFinancialSettingsRequest $request)`

Calls `$this->settingsService->updateBaseCurrency($company, $request->validated('base_currency'))`. The service does the posted-journal check and throws a `ValidationException` if locked. On success, redirects to `settings.financial` with status.

### `exchangeRates(Request $request)`

Validates filter parameters inline (no Form Request — read-only filtering is simple enough). Fetches exchange rates with `ExchangeRate::query()`, which the `TenantScoped` trait scopes automatically. Orders by `effective_at` descending. Applies optional `currency` and date-range filters. Paginates to 20 per page. Separately fetches the most recent rate per currency (`effective_at` = max per `currency`) for the summary row. Passes `$supportedCurrencies` (SYP, USD minus base currency) to the "add rate" form.

### `storeExchangeRate(StoreExchangeRateRequest $request)`

Calls `$this->settingsService->storeExchangeRate($company, $request->validated())`. Redirects to `settings.exchange-rates` with status.

### `forexAccounts()`

Loads `$company->exchangeGainAccount` and `$company->exchangeLossAccount`. Fetches eligible gain accounts and eligible loss accounts via two separate queries (delegated to service query methods). Passes all to the view.

### `updateGainAccount(UpdateForexAccountRequest $request)` / `updateLossAccount(UpdateForexAccountRequest $request)`

The Form Request is reused for both. The route suffix (`gain` vs `loss`) identifies which field to update. The controller reads the route name to determine `exchange_gain_account_id` vs `exchange_loss_account_id` and calls `$this->settingsService->updateForexAccount($company, $field, $accountId)`. Redirects to `settings.forex-accounts`.

### `fiscalYear()`

Returns the fiscal year view with no data-fetching — the view only renders an informational notice.

---

## CompanySettingsService

**File:** `app/Services/CompanySettingsService.php`

This service owns all business rules that must be unit-testable independently of HTTP.

### `hasPostedJournals(Company $company): bool`

```php
return AccountingJournal::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', $company->getKey())
    ->where('status', 'posted')
    ->exists();
```

Uses `withoutGlobalScope` to avoid the CompanyScope dependency; scopes explicitly by `company_id`. This mirrors the existing pattern in `CompanyAccountMapping::hasPostedOperationalHistory()`.

### `updateBaseCurrency(Company $company, string $newCurrency): void`

1. Calls `$this->hasPostedJournals($company)`. If true, throws `ValidationException` with the key `base_currency` and the locked message key `ui.settings.financial.currency_locked_reason`.
2. Validates `$newCurrency` is in `['SYP', 'USD']`. If not, throws `ValidationException`.
3. If `$newCurrency === $company->base_currency`, returns early (no-op).
4. Calls `$company->update(['base_currency' => strtoupper($newCurrency)])`.

No accounting records are modified.

### `storeExchangeRate(Company $company, array $data): ExchangeRate`

Wraps in `DB::transaction()`. Creates the `ExchangeRate` record with:
- `company_id` = `$company->getKey()`
- `base_currency` = `$company->base_currency`
- `currency` = `strtoupper($data['currency'])`
- `rate` = `$data['rate']` (already a validated decimal string from Form Request)
- `effective_at` = `$data['effective_at']`
- `source` = `'manual'`
- `created_by` = `Auth::id()`

No existing record is modified. Returns the new instance.

### `updateForexAccount(Company $company, string $field, int $accountId): void`

`$field` is either `'exchange_gain_account_id'` or `'exchange_loss_account_id'`.

Fetches `FinancialAccount::query()->findOrFail($accountId)` — the `TenantScoped` global scope is active and ensures the account belongs to the current company. If not found, the scope will cause a 404 (model not found).

Validates:
- `$account->is_active === true`
- `strtoupper($account->currency) === strtoupper($company->base_currency)`
- For gain: `in_array(strtolower($account->account_type), ['revenue', 'income'])`
- For loss: `strtolower($account->account_type) === 'expense'`

If any check fails, throws `ValidationException` on `financial_account_id`.

Calls `$company->update([$field => $accountId])`.

### `eligibleGainAccounts(Company $company): Collection`

```php
return FinancialAccount::query()
    ->where('is_active', true)
    ->whereIn('account_type', ['revenue', 'income'])
    ->where('currency', $company->base_currency)
    ->orderBy('code')
    ->get(['id', 'code', 'name', 'account_type', 'currency']);
```

The `TenantScoped` global scope constrains this to the active company automatically.

### `eligibleLossAccounts(Company $company): Collection`

Same pattern, `account_type = 'expense'` only.

---

## Form Requests

### `UpdateGeneralSettingsRequest`

**File:** `app/Http/Requests/Settings/UpdateGeneralSettingsRequest.php`

`authorize()`: returns `true` (middleware and controller-level policy already applied; Form Request auth doubles as a no-op here, consistent with how `SwitchCompanyRequest` is written).

Rules:
```php
'name'           => ['required', 'string', 'max:255'],
'legal_name'     => ['nullable', 'string', 'max:255'],
'tax_identifier' => ['nullable', 'string', 'max:255'],
'email'          => ['nullable', 'email', 'max:255'],
'phone'          => ['nullable', 'string', 'max:50'],
'address'        => ['nullable', 'string', 'max:10000'],
'country_code'   => ['nullable', 'string', 'size:2'],
'timezone'       => ['nullable', 'string', 'timezone:all'],
```

The `timezone:all` rule is built into Laravel and validates against the full IANA list.

### `UpdateFinancialSettingsRequest`

```php
'base_currency'  => ['required', 'string', Rule::in(['SYP', 'USD'])],
'confirm_change' => ['required', 'accepted'],
```

`confirm_change` is a checkbox on the confirmation form. `accepted` validates it is `'1'` or `'true'` or `'yes'` or `'on'`. This prevents accidental submission without the user acknowledging the warning (FR-8.9). The checkbox is rendered in the view only when `!$currencyLocked`; when locked, the entire form is disabled and the route never receives a PUT, but this validation provides defence in depth.

### `StoreExchangeRateRequest`

```php
'currency'     => ['required', 'string', Rule::in($this->foreignCurrencies())],
'rate'         => ['required', 'numeric', 'gt:0', 'decimal:0,10'],
'effective_at' => ['required', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
```

`foreignCurrencies()` is a private method that resolves `app(CompanyContextService::class)->requireCurrent()->base_currency` and returns the array of supported currencies excluding the base. Example: if base is `SYP`, returns `['USD']`; if base is `USD`, returns `['SYP']`.

The `decimal:0,10` rule validates up to 10 decimal places (available since Laravel 11.0.0).

The `gt:0` rule is a Laravel built-in numeric validator (greater than zero). This covers the BCMath requirement: the service stores the value as-is (a string cast), and the DB column is `decimal(24,10)`, so no float imprecision occurs.

After the numeric validation, the service layer reads the rate value as `$data['rate']`, but stores it via Eloquent which casts to `decimal:10`. The rate's string representation is used for all arithmetic.

### `UpdateForexAccountRequest`

```php
'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
```

The route name (`settings.forex-accounts.gain` vs `.loss`) is used by the controller to determine which field to update. The Form Request is shared between both routes.

---

## Views

All views extend `layouts.inventory`, use `@section('page-content')`, and emit `@section('title', ...)`.

### View directory: `resources/views/settings/`

```
settings/
  _subnav.blade.php       (shared partial)
  general.blade.php
  financial.blade.php
  exchange-rates.blade.php
  forex-accounts.blade.php
  fiscal-year.blade.php
```

### `_subnav.blade.php`

A partial that renders the five horizontal tab-style navigation links. Active detection: `request()->routeIs('settings.general')`, etc. Uses the same `button button-primary` / `button button-secondary` class pattern used in `accounting/journals/index.blade.php` and `accounting/mappings.blade.php` for sub-navigation:

```blade
<nav class="mb-6 flex flex-wrap gap-2">
    <a href="{{ route('settings.general') }}" @class(['button', 'button-primary' => request()->routeIs('settings.general'), 'button-secondary' => !request()->routeIs('settings.general')])>
        {{ __('ui.settings.nav_general') }}
    </a>
    {{-- ... repeat for other sections --}}
</nav>
```

### `general.blade.php`

Page heading uses the established pattern:
```blade
<p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
<h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
```

The form `action="{{ route('settings.general.update') }}"` with `@method('PUT')`. Fields are inside a `<section class="panel max-w-4xl p-5">` using `grid gap-5 sm:grid-cols-2`. Each field follows the existing pattern: `<label class="form-label">` + `<input class="form-control">`.

Timezone field: a `<select class="form-control">` with optgroups. The controller groups `$timezones` by prefix: `collect(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL))->groupBy(fn($tz) => explode('/', $tz)[0])`. Optgroup label is the region; each `<option>` value is the full identifier.

Preserve-on-empty behaviour is handled transparently by using `old('field', $company->field)` in every input's `value` attribute. If the user intentionally empties a field that was previously set, the nullable column accepts null. If the user does not submit a field (which cannot happen in a standard HTML form — all fields in the form are always submitted — but would happen with an API call), the Form Request's `nullable` rule and the controller's `$request->validated()` approach handle it correctly.

### `financial.blade.php`

The page has two distinct areas:

**Currency section:** When `$currencyLocked` is true, renders a read-only display showing the current `base_currency` in a teal info box, with a non-dismissable amber notice panel explaining the lock (FR-8.10). No form is rendered.

When not locked, renders:
1. An amber warning panel explaining the consequences (always visible before the form).
2. A `<form method="POST">` with `@method('PUT')`:
   - A `<select name="base_currency">` with options `SYP` and `USD`, pre-selected to `$company->base_currency`.
   - A confirmation checkbox: `<input type="checkbox" name="confirm_change" value="1" id="confirm_change">` with label text from `ui.settings.financial.confirm_label`.
   - A submit button disabled until the checkbox is checked (a small `x-on:change` / `data-toggle-submit` attribute, progressively enhanced; the submit button starts with `disabled` attribute and JavaScript removes it on checkbox change, but the Form Request `accepted` rule provides the server-side safety net).

### `exchange-rates.blade.php`

**Summary section:** A grid of cards, one per foreign currency, showing the most recent rate and its `effective_at`. If no rate exists for a currency, shows "not configured" in amber.

**Add-rate form:** A `<section class="panel p-4">` containing:
- `<select name="currency">` populated from `$supportedCurrencies` (at most one option when base is SYP or USD, since only two currencies are supported).
- `<input name="rate" type="text" inputmode="decimal">` (using `type="text"` not `type="number"` to avoid browser locale-dependent decimal separator issues with BCMath precision).
- `<input name="effective_at" type="datetime-local">` defaulted to now.
- Submit button.

**Rate history table:** Paginated table with columns: Currency, Base Currency, Rate, Effective Date, Source. Ordered by `effective_at` descending. No edit or delete actions.

**Filter form:** Inline `<form method="GET">` with currency select and two date inputs (`date_from`, `date_to`), consistent with the filter pattern in `accounting/journals/index.blade.php`.

### `forex-accounts.blade.php`

Displays two panels side by side (or stacked on mobile): one for the Gain account, one for the Loss account.

Each panel follows the `accounting/mappings.blade.php` pattern exactly:
- Shows the currently configured account in a teal info box (code, name, type, currency).
- Shows an amber "not configured" badge if null (FR-6.2).
- Contains a `<form>` with a `<select>` of eligible accounts and a save button.
- Each form has its own distinct POST route (`settings.forex-accounts.gain` and `settings.forex-accounts.loss`).

Accounts in the selects use `$account->code . ' · ' . $account->name . ' (' . $account->currency . ')'` as option text, identical to the mappings view.

### `fiscal-year.blade.php`

A single `<section class="panel p-6">` containing:
- An info icon and heading.
- A paragraph using the `ui.settings.fiscal_year.notice` translation key explaining that fiscal year configuration is not available in the current version and will be delivered in a future phase.
- No form controls of any kind.

---

## Error Handling

| Operation | Failure condition | Recoverable? | What the caller receives | Logging |
|-----------|------------------|-------------|--------------------------|---------|
| `updateGeneral` | Validation failure | Yes | Laravel redirects back with `$errors` bag populated; the existing layout renders the error list | No |
| `updateGeneral` | DB write fails | No | 500 (Laravel default unhandled exception handler) | Yes (default Laravel logging) |
| `updateBaseCurrency` | Posted journals exist | Yes | `ValidationException` → redirect back with `$errors->base_currency`; view renders locked state | No |
| `updateBaseCurrency` | Unsupported currency submitted | Yes | `ValidationException` → redirect back | No |
| `storeExchangeRate` | Validation failure (rate ≤ 0, currency = base, future date) | Yes | Redirect back with `$errors` | No |
| `storeExchangeRate` | DB write fails inside transaction | No | Transaction rolled back; 500 | Yes |
| `updateForexAccount` | Account not found (wrong company) | No | 404 (model not found by scoped query) | No |
| `updateForexAccount` | Account inactive / wrong type / wrong currency | Yes | `ValidationException` → redirect back | No |
| `forexAccounts` store | Account from another company injected | No | 404 from `TenantScoped` scope | No |
| Filter exchange rates | Invalid date format in query param | Yes | Validation redirects back with error | No |

The `TenantScoped` global scope is never bypassed in the settings controller or service. The only place it is bypassed is `hasPostedJournals`, which explicitly scopes by `company_id` after bypassing, following the established pattern in `CompanyAccountMapping`.

---

## Input Validation Details

| Field | Required | Type | Constraints | On failure |
|-------|----------|------|-------------|------------|
| `name` | Yes | string | max 255 | Validation error, field highlighted |
| `legal_name` | No | string | max 255 | Validation error |
| `tax_identifier` | No | string | max 255 | Validation error |
| `email` | No | email string | max 255 | Validation error |
| `phone` | No | string | max 50 | Validation error |
| `address` | No | string | max 10000 | Validation error |
| `country_code` | No | string | exactly 2 chars | Validation error |
| `timezone` | No | string | must be a valid IANA timezone (`timezone:all`) | Validation error |
| `base_currency` | Yes | string | `in:SYP,USD` | Validation error |
| `confirm_change` | Yes | boolean | `accepted` | Validation error |
| `currency` (rate) | Yes | string | must be a supported currency excluding base | Validation error |
| `rate` | Yes | decimal string | > 0, max 10 decimal places | Validation error |
| `effective_at` | Yes | datetime | valid date, not more than 1 year in the future | Validation error |
| `financial_account_id` | Yes | integer | must exist in `financial_accounts` | Validation error; service does secondary type/currency check |

---

## Translation Keys

New keys are added to the top-level `'settings'` array in both `lang/en/ui.php` and `lang/ar/ui.php`. Structure:

```php
'settings' => [
    'title'        => 'Company settings',
    'breadcrumb'   => 'Administration',
    'nav_general'  => 'General information',
    'nav_financial'=> 'Financial settings',
    'nav_exchange_rates' => 'Exchange rates',
    'nav_forex_accounts' => 'Foreign exchange accounts',
    'nav_fiscal_year'    => 'Financial year',
    'general' => [
        'heading'       => 'General information',
        'intro'         => 'View and update your company profile.',
        'name'          => 'Company name',
        'legal_name'    => 'Legal name',
        'tax_identifier'=> 'Tax identifier',
        'email'         => 'Email address',
        'phone'         => 'Phone number',
        'address'       => 'Address',
        'country_code'  => 'Country code',
        'timezone'      => 'Timezone',
        'save'          => 'Save general information',
        'saved'         => 'Company information saved.',
    ],
    'financial' => [
        'heading'              => 'Financial settings',
        'currency_heading'     => 'Base currency',
        'currency_intro'       => 'The base currency is used for all financial reports and journal entries.',
        'current_currency'     => 'Current base currency',
        'currency_locked'      => 'Base currency is locked',
        'currency_locked_reason' => 'The base currency cannot be changed because this company has posted accounting journals. Changing the base currency after posting would corrupt historical financial records.',
        'currency_warning'     => 'Changing the base currency affects all future transactions. This change cannot be undone once journals are posted.',
        'confirm_label'        => 'I understand this change affects all future financial transactions.',
        'save_currency'        => 'Update base currency',
        'saved_currency'       => 'Base currency updated.',
    ],
    'exchange_rates' => [
        'heading'          => 'Exchange rates',
        'intro'            => 'Exchange rates express the amount of base currency per one unit of foreign currency.',
        'latest_heading'   => 'Latest rates',
        'history_heading'  => 'Rate history',
        'no_rates'         => 'No exchange rates have been recorded.',
        'currency'         => 'Currency',
        'base_currency'    => 'Base currency',
        'rate'             => 'Rate',
        'effective_at'     => 'Effective from',
        'source'           => 'Source',
        'source_manual'    => 'Manual',
        'add_heading'      => 'Add exchange rate',
        'add_intro'        => 'Adding a new rate preserves all historical rates.',
        'save'             => 'Add rate',
        'saved'            => 'Exchange rate added.',
        'not_configured'   => 'No rate recorded',
        'filter_currency'  => 'All currencies',
        'filter_from'      => 'From',
        'filter_to'        => 'To',
        'apply_filters'    => 'Apply',
        'clear_filters'    => 'Clear',
    ],
    'forex_accounts' => [
        'heading'            => 'Foreign exchange accounts',
        'intro'              => 'These accounts receive the gain or loss when foreign-currency balances are revalued.',
        'gain_heading'       => 'Exchange gain account',
        'gain_intro'         => 'An income or revenue account that receives foreign exchange gains.',
        'loss_heading'       => 'Exchange loss account',
        'loss_intro'         => 'An expense account that absorbs foreign exchange losses.',
        'current_account'    => 'Current account',
        'not_configured'     => 'Not configured',
        'select_account'     => 'Select account',
        'save_gain'          => 'Save gain account',
        'save_loss'          => 'Save loss account',
        'saved_gain'         => 'Exchange gain account saved.',
        'saved_loss'         => 'Exchange loss account saved.',
        'no_eligible_gain'   => 'No active revenue or income accounts in :currency are available.',
        'no_eligible_loss'   => 'No active expense accounts in :currency are available.',
    ],
    'fiscal_year' => [
        'heading' => 'Financial year',
        'notice'  => 'Fiscal year configuration is not available in this version. This feature will be delivered in a future phase.',
    ],
],
```

Arabic equivalents are added to `lang/ar/ui.php` under the same structure.

---

## Edge Cases

**Base currency selector when already the same value:** `updateBaseCurrency` treats same-value submission as a no-op and returns a success status without writing to the database.

**Exchange rate with currency equal to base:** `StoreExchangeRateRequest` uses `Rule::in($this->foreignCurrencies())`. `foreignCurrencies()` explicitly excludes the base currency, so the validation fails before reaching the service.

**Rate exactly zero or negative:** `gt:0` Laravel rule catches this. Additionally `bccomp($rate, '0', 10) > 0` is verified in the service as a second layer (for any non-HTTP call path, e.g. tests).

**Foreign account injection in forex save:** `FinancialAccount::query()->findOrFail($accountId)` runs with the `TenantScoped` global scope active. The scope constrains to the active company's `company_id`. An account from another company will not be found, resulting in a `ModelNotFoundException` which Laravel resolves to a 404.

**Timezone select with an unexpected value in the database:** `old('timezone', $company->timezone)` will pre-select the stored value. If the stored value is not in the select list (e.g. a legacy `UTC+3` format), the browser will render no selection. The Form Request validator uses `timezone:all` which accepts any IANA identifier; a resubmission of the page will update it to a valid choice.

**ExchangeRate `TenantScoped` with `includesGlobalTenantRecords() = true`:** The `ExchangeRate` model returns `true` from `includesGlobalTenantRecords()`, meaning the `CompanyScope` also includes records with `company_id = null` (global system rates). The rates list view will show these if present. When creating a new rate, the service always sets `company_id = $company->getKey()`, so a new rate is never inserted as a global record.

**Confirm change checkbox with JavaScript disabled:** The checkbox `accepted` validation rule enforces server-side that the box was checked. The form submits and returns a validation error if the box is unchecked, displaying the message via the existing `$errors` layout rendering. No JavaScript is required for correctness.

**RTL/LTR for rates and codes:** Currency codes and numeric rates use `<bdi dir="ltr">` wrappers, matching the pattern in `accounting/mappings.blade.php`. All text strings flow with the document's `dir` attribute (set by `layouts.app.blade.php`).

---

## Testability Notes

`CompanySettingsService` is a plain class with constructor injection. Its three stateful methods (`hasPostedJournals`, `updateBaseCurrency`, `storeExchangeRate`) can be unit-tested with a SQLite in-memory database. `hasPostedJournals` has no side effects and can be tested against a seeded company with and without posted journals. `updateBaseCurrency` can verify the ValidationException path and the success path. `storeExchangeRate` can verify the new record is created, the old record is untouched, and `created_by` is set.

The controller methods are thin enough for feature tests (HTTP-level tests with `actingAs` and `assertRedirect`).

The Form Requests can be unit-tested in isolation using `FormRequest::create(...)` or via feature tests.

---

## Files to Create

| Path | Type |
|------|------|
| `app/Http/Controllers/CompanySettingsController.php` | New controller |
| `app/Http/Requests/Settings/UpdateGeneralSettingsRequest.php` | New Form Request |
| `app/Http/Requests/Settings/UpdateFinancialSettingsRequest.php` | New Form Request |
| `app/Http/Requests/Settings/StoreExchangeRateRequest.php` | New Form Request |
| `app/Http/Requests/Settings/UpdateForexAccountRequest.php` | New Form Request |
| `app/Services/CompanySettingsService.php` | New service |
| `resources/views/settings/_subnav.blade.php` | New view partial |
| `resources/views/settings/general.blade.php` | New view |
| `resources/views/settings/financial.blade.php` | New view |
| `resources/views/settings/exchange-rates.blade.php` | New view |
| `resources/views/settings/forex-accounts.blade.php` | New view |
| `resources/views/settings/fiscal-year.blade.php` | New view |

## Files to Modify

| Path | Change |
|------|--------|
| `routes/web.php` | Add settings route group (11 routes) |
| `config/navigation.php` | Add `route` and `active` keys to `company-settings` item |
| `app/Providers/AppServiceProvider.php` | Add `'settings.*'` to view composer glob; register `CompanySettingsService` as singleton |
| `lang/en/ui.php` | Add `settings` top-level key |
| `lang/ar/ui.php` | Add `settings` top-level key with Arabic translations |

No database migrations. No changes to any existing service, model, or accounting logic.
