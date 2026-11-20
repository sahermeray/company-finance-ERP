# Implementation Plan — Phase 8H: Financial Reports UI

## Context

`FinancialReportingService` and its test suite are complete. Nothing in the presentation layer
exists yet. The five public service methods are `trialBalance`, `generalLedger`, `incomeStatement`,
`incomeStatementTrend`, and `balanceSheet`. All work below is additive; no existing files are
deleted.

The service's comparison loop iterates exactly seven metrics (`['revenue', 'cost_of_goods_sold',
'gross_profit', 'operating_expenses', 'other_income', 'other_expenses', 'net_profit']`) and does
**not** add `comparison` to `gross_margin_percentage` — residual finding #2 must guard that key.

`NavigationTest` lines 98–99 currently read `toBe(15)` / `toBe(3)` (confirmed by reading the
file). After activating the `reports` nav item those values become `toBe(16)` / `toBe(2)`.

---

## Plan

- [ ] 1. **Register `FinancialReportingService` as a singleton in `AppServiceProvider::register()`.**
      Add `$this->app->singleton(FinancialReportingService::class);` alongside the four existing
      singleton registrations. Add the `use App\Services\FinancialReportingService;` import.
      Files: `app/Providers/AppServiceProvider.php`
      Verify: `php artisan config:clear && php artisan test --filter NavigationTest` — passes (boot
      does not break); `php artisan route:list` — no errors.

- [ ] 2. **Extend the view composer pattern to include `reports.*`.**
      In `AppServiceProvider::boot()`, change the first argument of `view()->composer(...)` from
      `['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*']` to
      `['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*', 'reports.*']`.
      Files: `app/Providers/AppServiceProvider.php`
      Verify: `php artisan test --filter NavigationTest` — still passes; existing accounting/
      inventory tests still pass.

- [ ] 3. **Add `'reports'` translation keys to both locale files.**
      Append a top-level `'reports' => [...]` array to `lang/en/ui.php` and `lang/ar/ui.php`
      exactly as specified in the design's translation section (all keys from `title` through
      `print_generated`). No existing keys are removed.
      Files: `lang/en/ui.php`, `lang/ar/ui.php`
      Verify: `php artisan test --filter NavigationTest` — the labels-in-Arabic-and-English test
      still passes (it does not cover reports keys but would fail on a syntax error).

- [ ] 4. **Activate the `reports` navigation item in `config/navigation.php`.**
      Add `'route' => 'reports.index'` and `'active' => ['reports.*']` to the existing `reports`
      entry in the `finance` group. The entry gains these two keys; `key`, `label`, and `icon` are
      left unchanged.
      Files: `config/navigation.php`
      Verify: `php artisan config:clear && php artisan test --filter NavigationTest` — the
      available/coming-soon count assertions must fail here (expected because the route does not
      exist yet). Confirm only the count assertion fails, not a syntax or config-load error.
      *(The count fix comes in item 6 below, once the route exists.)*

- [ ] 5. **Create `FinancialReportController`.**
      New file at `app/Http/Controllers/FinancialReportController.php`. The class is `readonly`
      with constructor injection of `FinancialReportingService`. The constructor comment must read
      exactly:
      `// Constructor injection is used here (unlike AccountingJournalController which resolves services per-action) to avoid nine repeated app() calls.`
      Implement ten public methods: `index`, `trialBalance`, `generalLedger`, `incomeStatement`,
      `balanceSheet`, `performanceTrends`, `printTrialBalance`, `printGeneralLedger`,
      `printIncomeStatement`, `printBalanceSheet`. Each calls
      `app(CompanyContextService::class)->requireCurrent()` first, then validates (rules as
      specified in the design), then calls the service. Validation rules:

      | Method | Required | Optional |
      |---|---|---|
      | `trialBalance` / `printTrialBalance` | `date_from` (date), `date_to` (date, after_or_equal:date_from) | — |
      | `generalLedger` / `printGeneralLedger` | same | `account_id` (integer, `Rule::exists('financial_accounts','id')->where('company_id', $company->getKey())`) |
      | `incomeStatement` / `printIncomeStatement` | `date_from`, `date_to` | `comparison_from` (nullable date, required_with:comparison_to), `comparison_to` (nullable date, after_or_equal:comparison_from, required_with:comparison_from) |
      | `balanceSheet` / `printBalanceSheet` | `as_of_date` (date) | `period_start` (date, before_or_equal:as_of_date) |
      | `performanceTrends` | `date_from`, `date_to` | — |

      Default filter values (populated from Carbon when inputs are absent):

      | Report | `date_from` default | `date_to` / `as_of_date` default |
      |---|---|---|
      | Trial balance, general ledger, income statement | `now()->startOfMonth()->toDateString()` | `now()->endOfMonth()->toDateString()` |
      | Balance sheet | — | `now()->toDateString()` |
      | Performance trends | `now()->startOfYear()->toDateString()` | `now()->toDateString()` |

      `index` has no filters and no service call; it returns `view('reports.index')`.
      `generalLedger` / `printGeneralLedger` pass `$accounts` (active accounts for the company
      ordered by code, queried via `FinancialAccount::where(...)->where('is_active', true)->orderBy('code')->get()`).
      All non-print methods return `View|RedirectResponse`; all print methods return `View|RedirectResponse`.
      Files: `app/Http/Controllers/FinancialReportController.php`
      Verify: `php artisan route:list` — does not error (routes not registered yet, but syntax
      must be valid). `php artisan test --filter AccountingUiTest` — no regressions.

- [ ] 6. **Register the reports routes and update `NavigationTest` counts.**
      Add the reports prefix group inside the `Route::middleware('company.context')->group(...)`
      block in `routes/web.php`, directly after the closing brace of the `accounting` prefix block.
      Add `use App\Http\Controllers\FinancialReportController;` to the imports at the top of
      `routes/web.php`. Route group:

      ```php
      Route::prefix('reports')->name('reports.')->group(function (): void {
          Route::get('/', [FinancialReportController::class, 'index'])->name('index');
          Route::get('/trial-balance/print', [FinancialReportController::class, 'printTrialBalance'])->name('trial-balance.print');
          Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('trial-balance');
          Route::get('/general-ledger/print', [FinancialReportController::class, 'printGeneralLedger'])->name('general-ledger.print');
          Route::get('/general-ledger', [FinancialReportController::class, 'generalLedger'])->name('general-ledger');
          Route::get('/income-statement/print', [FinancialReportController::class, 'printIncomeStatement'])->name('income-statement.print');
          Route::get('/income-statement', [FinancialReportController::class, 'incomeStatement'])->name('income-statement');
          Route::get('/balance-sheet/print', [FinancialReportController::class, 'printBalanceSheet'])->name('balance-sheet.print');
          Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('balance-sheet');
          Route::get('/performance-trends', [FinancialReportController::class, 'performanceTrends'])->name('performance-trends');
      });
      ```

      Then update `tests/Feature/NavigationTest.php` lines 98–99:
      `->toBe(15)` → `->toBe(16)` and `->toBe(3)` → `->toBe(2)`.
      Files: `routes/web.php`, `tests/Feature/NavigationTest.php`
      Verify: `php artisan config:clear && php artisan test --filter NavigationTest` — all
      NavigationTest assertions pass including the updated count values.

- [ ] 7. **Create the reports index view.**
      `resources/views/reports/index.blade.php` — extends `layouts.inventory`, uses
      `@section('page-content')`. Renders the two-line header (teal label + h1), the five-report
      card grid (`<a href="...">` cards with rounded-xl border pattern), and the secondary nav
      row linking to all five report pages. No service call.
      Files: `resources/views/reports/index.blade.php`
      Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-1 passes (file must already
      be created with enough content to return 200 and show report links).

- [ ] 8. **Create the trial balance view and print view.**
      `resources/views/reports/trial-balance.blade.php` — extends `layouts.inventory`, filter
      form (GET, grid layout matching journals filter), incomplete-data warning banner
      (`@if ($report['status'] !== 'complete')`), table with 8 columns (code, name, opening
      debit/credit, period debit/credit, closing debit/credit), all amounts via
      `number_format((float) $value, 4)` wrapped in `<bdi dir="ltr">`, totals footer from
      `$report['totals']`, collapsible native-currency summary when
      `$report['native_currency_totals']` is non-empty.

      `resources/views/reports/trial-balance-print.blade.php` — extends `layouts.app`,
      `@section('content')`, print-controls div with Back link
      (`route('reports.trial-balance', request()->query())`) and `data-print-document` button,
      article wrapper with document header (company name, app name, report title, period), same
      table structure as the non-print view without the filter form.
      Files: `resources/views/reports/trial-balance.blade.php`,
             `resources/views/reports/trial-balance-print.blade.php`
      Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-2, AC-3, AC-11 pass.

- [ ] 9. **Create the general ledger view and print view.**
      `resources/views/reports/general-ledger.blade.php` — filter form with account `<select>`
      (populated from `$accounts`, first option "All accounts" with empty value, format
      `code – name`, pre-selected when `$filters['account_id']` is set), incomplete-data banner,
      table with 10 columns (date, journal #, description, source ref, debit native, credit
      native, currency, base debit, base credit, running balance). Native debit/credit cells
      render `—` when `$transaction['debit']` is `null`. Running balance from
      `$transaction['running_balance_base']`.

      `resources/views/reports/general-ledger-print.blade.php` — same structure as
      trial-balance-print with Back link `route('reports.general-ledger', request()->query())`.
      Files: `resources/views/reports/general-ledger.blade.php`,
             `resources/views/reports/general-ledger-print.blade.php`
      Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-4, AC-5 pass.

- [ ] 10. **Create the income statement view and print view.**
       `resources/views/reports/income-statement.blade.php` — filter form with four date fields
       (date_from, date_to, comparison_from, comparison_to), incomplete-data banner, `<dl>`
       layout with one row per metric. The eight metrics and their exact service keys:

       | Label translation key | Service key | Notes |
       |---|---|---|
       | `metric_revenue` | `revenue` | |
       | `metric_cogs` | `cost_of_goods_sold` | |
       | `metric_gross_profit` | `gross_profit` | |
       | `metric_gross_margin` | `gross_margin_percentage` | Append `%`, no currency code |
       | `metric_operating_expenses` | `operating_expenses` | Plural |
       | `metric_other_income` | `other_income` | |
       | `metric_other_expenses` | `other_expenses` | Plural |
       | `metric_net_profit` | `net_profit` | |

       **Residual finding #2:** the comparison column cell for `gross_margin_percentage` must be
       guarded with `@isset($metric['comparison'])` because the service's comparison loop omits
       this metric. All other metric rows show the comparison column only when `$filters`
       contained comparison dates; use the same guard for consistency.
       Metrics whose `availability` is not `'available'` display the `metric_unavailable`
       translation instead of a value.

       `resources/views/reports/income-statement-print.blade.php` — same as other print views,
       Back link `route('reports.income-statement', request()->query())`.
       Files: `resources/views/reports/income-statement.blade.php`,
              `resources/views/reports/income-statement-print.blade.php`
       Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-6, AC-7, AC-8 pass with
       no `Undefined index: comparison` error.

- [ ] 11. **Create the balance sheet view and print view.**
       `resources/views/reports/balance-sheet.blade.php` — filter form with `as_of_date` and
       optional `period_start`, balance status badge in the page header area using
       `$report['status']` (values: `balanced`, `discrepant`, `incomplete`). Incomplete banner
       for status !== `'balanced'`. Three `<section class="panel">` blocks for assets, liabilities,
       equity — each with an account list table (code, name, balance) and a subtotal row. Within
       equity: extra rows for `$report['equity']['account_balances_total']`,
       `$report['equity']['current_period_earnings']`, `$report['equity']['prior_unclosed_earnings']`.

       `resources/views/reports/balance-sheet-print.blade.php` — Back link
       `route('reports.balance-sheet', request()->query())`.
       Files: `resources/views/reports/balance-sheet.blade.php`,
              `resources/views/reports/balance-sheet-print.blade.php`
       Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-9 passes.

- [ ] 12. **Create the performance trends view.**
       `resources/views/reports/performance-trends.blade.php` — filter form (date_from, date_to),
       incomplete-data banner, single wide table with `overflow-x-auto`, month column headers
       formatted as `Y-m` (from `array_keys($report['months'])`), seven row labels with their
       exact singular/plural key distinction:

       | Row label translation key | Key in `$months[$monthKey]` |
       |---|---|
       | `metric_revenue` | `revenue` |
       | `metric_cogs` | `cost_of_goods_sold` |
       | `metric_gross_profit` | `gross_profit` |
       | `metric_operating_expenses` | `operating_expense` ← **singular** |
       | `metric_other_income` | `other_income` |
       | `metric_other_expenses` | `other_expense` ← **singular** |
       | `metric_net_profit` | `net_profit` |

       No print view for performance trends (design does not include one).
       Files: `resources/views/reports/performance-trends.blade.php`
       Verify: `php artisan test --filter "FinancialReportUiTest"` — AC-10 passes.

- [ ] 13. **Create `tests/Feature/FinancialReportUiTest.php`.**
       Uses `uses(RefreshDatabase::class)`. Local `financialReportUiSetUp()` helper (following
       `accountingUiSetUp()` in `AccountingUiTest.php`) creates a company, a user, attaches them,
       calls `CompanyContextService::switchTo()`, and creates one account per type (asset,
       liability, equity, revenue, expense). Uses `AccountingService` helpers to post at least one
       journal so service methods return non-empty results.

       Tests (Pest `it(...)` closures) covering:
       - **AC-1:** `GET /reports` returns 200, contains links to all five report names.
       - **AC-2:** `GET /reports/trial-balance` with valid dates returns 200, renders account table.
       - **AC-3:** `GET /reports/trial-balance` missing `date_from` redirects with validation error.
       - **AC-4:** `GET /reports/general-ledger` with valid dates returns 200, renders transactions table headings.
       - **AC-5:** `GET /reports/general-ledger` with `account_id` from a different company returns validation error redirect (not 500).
       - **AC-6:** `GET /reports/income-statement` with valid dates returns 200, shows all eight metric label translations.
       - **AC-7:** `GET /reports/income-statement` with both comparison dates returns 200, shows the comparison period column heading.
       - **AC-8:** `GET /reports/income-statement` with only `comparison_from` redirects with validation error on `comparison_to`.
       - **AC-9:** `GET /reports/balance-sheet` with `as_of_date` returns 200, shows assets/liabilities/equity headings.
       - **AC-10:** `GET /reports/performance-trends` with valid dates returns 200, renders a table.
       - **AC-11:** `GET /reports/trial-balance/print` with valid dates returns 200, contains `data-print-document` attribute.
       - **AC-12:** NavigationTest `data-navigation-status="available"` count covers the reports item (this is validated by NavigationTest itself after item 6; no duplicate assertion needed here).
       - **AC-13:** Unauthenticated `GET /reports` redirects to `/login`.
       - **AC-14:** User with two companies and no session context gets HTTP 409 on `GET /reports`.
       - **AC-15:** English page renders no raw `ui.reports.` key literals.
       - **AC-16:** With `'locale' => 'ar'` in session, `GET /reports/trial-balance` with valid dates asserts `assertSee('<html lang="ar" dir="rtl">', false)` and `assertSee('<bdi dir="ltr">', false)`.

       Files: `tests/Feature/FinancialReportUiTest.php`
       Verify: `php artisan config:clear && php artisan test --filter FinancialReportUiTest` —
       all 16 tests pass. Then `php artisan test` — full suite passes (AC-18).

---

## Digest

### Files created

| File | Block to implement | Test coverage |
|---|---|---|
| `app/Http/Controllers/FinancialReportController.php` | Full class with constructor + 10 methods | AC-1 through AC-16 in FinancialReportUiTest |
| `resources/views/reports/index.blade.php` | Card grid, secondary nav | AC-1 |
| `resources/views/reports/trial-balance.blade.php` | Filter form, table, totals footer | AC-2, AC-3, AC-15, AC-16 |
| `resources/views/reports/trial-balance-print.blade.php` | Print layout, `data-print-document` | AC-11 |
| `resources/views/reports/general-ledger.blade.php` | Filter form with account select, table | AC-4, AC-5 |
| `resources/views/reports/general-ledger-print.blade.php` | Print layout | — |
| `resources/views/reports/income-statement.blade.php` | `<dl>` metric list, `@isset($metric['comparison'])` guard on gross_margin_percentage | AC-6, AC-7, AC-8 |
| `resources/views/reports/income-statement-print.blade.php` | Print layout | — |
| `resources/views/reports/balance-sheet.blade.php` | Three panel sections, equity breakdown rows | AC-9 |
| `resources/views/reports/balance-sheet-print.blade.php` | Print layout | — |
| `resources/views/reports/performance-trends.blade.php` | Wide table, singular key names | AC-10 |
| `tests/Feature/FinancialReportUiTest.php` | `financialReportUiSetUp()` + 16 `it(...)` tests | AC-17 |

### Files modified

| File | Block to change | Verified today |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | `register()`: add `FinancialReportingService::class` singleton; `boot()`: extend view composer array | Reads: singletons at lines 48–52, composer at line 60 |
| `routes/web.php` | Inside `middleware('company.context')` group, after the `accounting` prefix block (line ~123) | Reads: accounting block ends ~line 130 |
| `config/navigation.php` | `reports` entry in `finance` group: add `'route'` and `'active'` keys | Reads: entry at lines 132–135 |
| `lang/en/ui.php` | Append `'reports' => [...]` top-level key | Reads: file has `'reports' => 'Reports'` only under `navigation` at line 74 — no top-level `reports` key exists |
| `lang/ar/ui.php` | Append `'reports' => [...]` top-level key | Reads: same situation |
| `tests/Feature/NavigationTest.php` | Lines 98–99: `toBe(15)` → `toBe(16)`, `toBe(3)` → `toBe(2)` | Confirmed current values by reading file |

### Build and test commands

- **Syntax check:** `php artisan config:clear` (exits non-zero on PHP parse errors in config/providers)
- **Route registration check:** `php artisan route:list`
- **Test suite:** `php artisan test` (runs Pest via `php artisan test`)
- **Filtered:** `php artisan test --filter FinancialReportUiTest`
- **Navigation regression:** `php artisan test --filter NavigationTest`
