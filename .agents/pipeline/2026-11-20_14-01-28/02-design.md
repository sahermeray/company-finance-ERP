# Phase 8H — Financial Reports UI: Technical Design (Revision 1)

## Overview

This phase adds the HTTP presentation layer for the five financial reports whose service backend (`FinancialReportingService`) is complete. The work touches exactly these layers: `app/Http/Controllers/`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `resources/views/reports/`, `config/navigation.php`, `lang/en/ui.php`, `lang/ar/ui.php`, and `tests/Feature/`. No database schema changes are made. The technology stack is PHP 8.4, Laravel 12, Blade, and Tailwind CSS — identical to every other module in the application.

---

## Controller

**File:** `app/Http/Controllers/FinancialReportController.php`

A single controller class following the `AccountingJournalController` pattern exactly: `readonly` service injection in the constructor, `app(CompanyContextService::class)->requireCurrent()` to fetch the active company, inline `$request->validate()` for input validation, and explicit `View|RedirectResponse` return types.

```php
class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportingService $reports,
    ) {}
```

The controller has ten public methods: `index`, `trialBalance`, `generalLedger`, `incomeStatement`, `balanceSheet`, `performanceTrends`, `printTrialBalance`, `printGeneralLedger`, `printIncomeStatement`, and `printBalanceSheet`. Each calls `app(CompanyContextService::class)->requireCurrent()` at the start, then validates, then delegates to the service.

**Why a single controller:** all five reports share the same route prefix (`/reports`), the same middleware group, and the same service. Splitting them into sub-controllers would add indirection with no benefit, which contradicts how `AccountingJournalController` handles multiple related views in one class.

### Validation rules per action

| Action | Required inputs | Optional inputs | Failure behaviour |
|---|---|---|---|
| `trialBalance` | `date_from` (date), `date_to` (date, after_or_equal:date_from) | — | `redirect()->back()->withErrors()` via Laravel's standard `$request->validate()` exception handling |
| `generalLedger` | `date_from` (date), `date_to` (date, after_or_equal:date_from) | `account_id` (integer, `Rule::exists('financial_accounts', 'id')->where('company_id', $company->getKey())`) | Same |
| `incomeStatement` | `date_from` (date), `date_to` (date, after_or_equal:date_from) | `comparison_from` (nullable date, `required_with:comparison_to`), `comparison_to` (nullable date, `after_or_equal:comparison_from`, `required_with:comparison_from`) | Same; both comparison fields handled exclusively at the controller layer — service propagation is not relied on |
| `balanceSheet` | `as_of_date` (date) | `period_start` (date, before_or_equal:as_of_date) | Same |
| `performanceTrends` | `date_from` (date), `date_to` (date, after_or_equal:date_from) | — | Same |

The print actions (`printTrialBalance`, `printGeneralLedger`, `printIncomeStatement`, `printBalanceSheet`) accept and validate the same parameters as their non-print counterparts, then call the same service method and pass the result to the print Blade view. No separate data-fetching logic.

### Income statement comparison-period validation

The income statement action uses controller-level `required_with` validation exclusively for comparison dates. The service's `ValidationException` with key `comparison_period` is never relied on — controller validation fires before the service call. The concrete validation array:

```php
$validated = $request->validate([
    'date_from'       => ['required', 'date'],
    'date_to'         => ['required', 'date', 'after_or_equal:date_from'],
    'comparison_from' => ['nullable', 'date', 'required_with:comparison_to'],
    'comparison_to'   => ['nullable', 'date', 'after_or_equal:comparison_from', 'required_with:comparison_from'],
]);
```

This means if only `comparison_from` is submitted, `comparison_to` fails with `required_with:comparison_from`, binding to the `comparison_to` form field. The inverse is symmetric. The service's internal guard still exists as a defensive check but will never be reached through the UI path.

### Default filter values

When inputs are absent (first visit with no query string), the controller fills in defaults before passing them to the view form. Defaults are computed in the controller using Carbon helpers, not in the view.

| Report | Default `date_from` | Default `date_to` / `as_of_date` |
|---|---|---|
| Trial Balance | First day of current month (`now()->startOfMonth()->toDateString()`) | Last day of current month (`now()->endOfMonth()->toDateString()`) |
| General Ledger | First day of current month | Last day of current month |
| Income Statement | First day of current month | Last day of current month |
| Balance Sheet | — | Today (`now()->toDateString()`) |
| Performance Trends | First day of current year (`now()->startOfYear()->toDateString()`) | Today |

The controller always passes a `$filters` array (populated with either user input or defaults) to the view, so form fields can pre-populate their `value` attributes with no conditional logic in Blade.

### Error handling table

| Failure | Source | Recoverable? | Controller response |
|---|---|---|---|
| Validation failure (missing/invalid input) | `$request->validate()` | Yes | Redirect back with `$errors` bag populated |
| `account_id` not in active company | `Rule::exists` with company scope | Yes | Redirect back with `$errors` bag (validation layer, not service) |
| `comparison_from` without `comparison_to` | Controller `required_with` rule | Yes | Redirect back with `$errors` on `comparison_to` field |
| `date_from > date_to` service ValidationException (key: `period`) | Service `validatedRange()` | Yes | Propagated; Laravel converts to redirect-back-with-errors |
| No active company context | `CompanyContextService::requireCurrent()` | No | HTTP 409 (existing `EnsureActiveCompany` middleware, only when user has two+ companies with no session context) |
| Unauthenticated user | `auth` middleware | No | Redirect to `/login` |
| User belongs to no company | `EnsureActiveCompany` | No | HTTP 403 |

---

## Routes

**File:** `routes/web.php`

A new `prefix('reports')->name('reports.')` block is added inside the existing `auth` + `company.context` middleware group, alongside the `accounting` prefix block. Print routes are listed before their non-print counterparts for routing clarity, though both are static segments and ordering does not affect matching.

```php
use App\Http\Controllers\FinancialReportController;

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

This block is placed inside the `Route::middleware('company.context')->group(...)` block alongside the existing `accounting` prefix block.

---

## Navigation activation

**File:** `config/navigation.php`

The `reports` item in the `finance` group is updated from:

```php
[
    'key'   => 'reports',
    'label' => 'ui.navigation.reports',
    'icon'  => 'reports',
],
```

to:

```php
[
    'key'    => 'reports',
    'label'  => 'ui.navigation.reports',
    'route'  => 'reports.index',
    'active' => ['reports.*'],
    'icon'   => 'reports',
],
```

The `reports` SVG icon already exists in `resources/views/components/icon.blade.php`.

The existing `NavigationTest` asserts `toBe(15)` available and `toBe(3)` coming-soon at lines 98–99. After this change the reports item moves from coming-soon to available. The test must be updated to `toBe(16)` and `toBe(2)`. This is the only pre-existing test change required by this phase.

---

## `$activeCompany` injection — view composer extension

**File:** `app/Providers/AppServiceProvider.php`

The view composer in `AppServiceProvider::boot()` currently covers `['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*']`. Views under `reports.*` are not in this list and will throw `Undefined variable $activeCompany` at runtime unless the pattern is extended.

**Chosen approach: extend the view composer pattern to include `'reports.*'`.**

This is consistent with every other module's approach and avoids per-action boilerplate in the controller.

```php
// Before
view()->composer(['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*'], function (View $view): void { … });

// After
view()->composer(['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*', 'reports.*'], function (View $view): void { … });
```

This single-line change ensures `$activeCompany` and `$companies` are injected into all `reports.*` views (both non-print and print-layout views named under `reports/`), identical to how they reach `accounting.*` views.

**Why not pass explicitly per controller action:** five report actions plus four print actions (nine total) each passing `$activeCompany` and `$companies` explicitly would add nine identical code blocks. The view composer pattern already exists for this exact purpose and is the established convention.

**Non-print views:** extend `layouts.inventory` and use `@section('page-content')`. They receive `$activeCompany` through the extended composer.

**Print views:** extend `layouts.app` directly. They are named `reports/trial-balance-print.blade.php` etc. — still under the `reports.*` namespace — and therefore also receive `$activeCompany` through the extended composer. No explicit per-action pass is needed for print views either.

---

## Views

All views live under `resources/views/reports/`. Directory structure:

```
resources/views/reports/
    index.blade.php
    trial-balance.blade.php
    trial-balance-print.blade.php
    general-ledger.blade.php
    general-ledger-print.blade.php
    income-statement.blade.php
    income-statement-print.blade.php
    balance-sheet.blade.php
    balance-sheet-print.blade.php
    performance-trends.blade.php
```

### Layout choice

All non-print views extend `layouts.inventory` and use `@section('page-content')`, exactly as `accounting/journals/index.blade.php` does. This provides the sidebar, topbar, company switcher, locale switcher, and the `$errors` / `session('status')` flash blocks.

Print views extend `layouts.app` directly (not `layouts.inventory`), which gives the `<html lang dir>` attributes and the Vite assets but no sidebar or topbar. This matches `commercial/parties/statement-print.blade.php`. The `@section('content')` section name is used in print views, matching `statement-print.blade.php`.

### Page header pattern

Each non-print report page uses the two-line header pattern seen in `accounting/journals/index.blade.php`:

```blade
<p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
<h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.trial_balance_title') }}</h1>
```

A secondary nav row renders links to all five report pages, using `button-primary` for the current page and `button-secondary` for the others, determined by `request()->routeIs('reports.trial-balance')`.

### Filter form pattern

Each report page renders a filter form inside a `<section class="panel">` element. The form uses `method="GET"` and `action=""`. It uses the same grid classes as the journals filter: `grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4`. Apply and Clear buttons follow the journals filter pattern. Field `value` attributes are pre-populated from `$filters`.

For General Ledger, the account filter `<select>` is populated from `$accounts` (passed by the controller, queried with `FinancialAccount::where('company_id', $company->getKey())->where('is_active', true)->orderBy('code')->get()`). The first option is "All accounts" (empty value). Account display format: `code – name`. When `$filters['account_id']` is set, the matching option is pre-selected.

For Income Statement, two additional `<input type="date">` fields for `comparison_from` and `comparison_to` appear below the main period fields.

For Balance Sheet, the optional `period_start` date input appears below `as_of_date`.

### Monetary amount display

All monetary amounts use `number_format((float) $value, 4)` and the currency code, wrapped in `<bdi dir="ltr">`, matching the established pattern throughout the codebase. Zero amounts render as `—` where contextually appropriate (General Ledger debit/credit columns), following `accounting/journals/show.blade.php`.

### Status / incomplete warning

When `$report['status']` is not `'complete'` (or not `'balanced'` for Balance Sheet), a warning banner renders immediately above the report table:

```blade
@if ($report['status'] !== 'complete')
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
        {{ __('ui.reports.incomplete_data_warning') }}
    </div>
@endif
```

For Balance Sheet, the success condition is `'balanced'`; both `'discrepant'` and `'incomplete'` show the banner.

### index.blade.php

A card grid: each of the five report types renders as an `<a href="...">` card with the report name (bold) and a one-line description. Cards use `rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm` classes. No service call on this page.

### trial-balance.blade.php

Table columns: Account code, Account name, Opening debit, Opening credit, Period debit, Period credit, Closing debit, Closing credit. All six amount columns display 4-decimal values wrapped in `<bdi dir="ltr">`. A totals footer row sums each column from `$report['totals']`. After the main table, if `$report['native_currency_totals']` is non-empty, a secondary collapsible section shows the native currency summary.

### general-ledger.blade.php

Table columns: Date, Journal #, Description, Source ref, Debit (native), Credit (native), Currency, Base debit, Base credit, Running balance. `$report['transactions']` is a flat array. When `$transaction['debit']` is `null`, the native debit and credit cells render `—`. Running balance is `$transaction['running_balance_base']`.

### income-statement.blade.php

A `<dl>` definition-list layout with one row per metric. Each row shows the metric name, the current-period value, and — when a comparison period was submitted — the comparison value, the difference, and the percentage change. Metrics whose `availability` is not `'available'` display a "Incomplete data" badge instead of the value.

**Exact metric array keys from the service:**

| Metric label | Array key | Notes |
|---|---|---|
| Revenue | `revenue` | |
| Cost of goods sold | `cost_of_goods_sold` | |
| Gross profit | `gross_profit` | |
| Gross margin | `gross_margin_percentage` | Append `%`, no currency code |
| Operating expenses | `operating_expenses` | Plural — from `statementFromTrialBalance()`, not from trends |
| Other income | `other_income` | |
| Other expenses | `other_expenses` | Plural — from `statementFromTrialBalance()` |
| Net profit | `net_profit` | |

Access gross margin as `$report['metrics']['gross_margin_percentage']`. Do not use `$report['metrics']['gross_margin']` — that key does not exist.

### balance-sheet.blade.php

Three sections (assets, liabilities, equity) each rendered as a `<section class="panel">` with an account list table (code, name, balance) and a subtotal row. Within equity, three additional rows show: equity account balance total (`$report['equity']['account_balances_total']`), current period earnings (`$report['equity']['current_period_earnings']`), and prior unclosed earnings (`$report['equity']['prior_unclosed_earnings']`). The balance status badge (`balanced` / `discrepant` / `incomplete`) is prominent in the page header area, using `$report['status']`.

### performance-trends.blade.php

A single wide table with months as columns. The row headers and their array keys are:

| Row label | Key in `$months[$monthKey]` |
|---|---|
| Revenue | `revenue` |
| Cost of goods sold | `cost_of_goods_sold` |
| Gross profit | `gross_profit` |
| Operating expenses | `operating_expense` ← **singular** |
| Other income | `other_income` |
| Other expenses | `other_expense` ← **singular** |
| Net profit | `net_profit` |

The keys `operating_expense` and `other_expense` are singular because `emptyStatementAmounts()` uses singular forms, and `incomeStatementTrend()` populates months from that method. The plural forms `operating_expenses` and `other_expenses` exist only in the `incomeStatement()` metrics array and must not be used here.

Month column headers format as `Y-m` (e.g. `"2026-01"`). Data is in `$report['months']`, which is a keyed array `['2026-01' => [...], '2026-02' => [...], ...]`. The table uses `overflow-x-auto` scroll on mobile.

### Print views

All four print views follow `statement-print.blade.php` exactly:

1. `@extends('layouts.app')` — no sidebar. Uses `@section('content')`.
2. A `<main class="mx-auto max-w-5xl p-4 sm:p-8">` wrapper.
3. A `<div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3">` with:
   - A "Back" `<a>` link to the corresponding non-print route.
   - A `<button type="button" class="button button-primary" data-print-document>` print button.
4. An `<article class="document-paper border border-slate-200 bg-white p-5 sm:p-10">` containing:
   - A document header with company name (from `$activeCompany->name`), app name, report title, and the period or as-of date.
   - The report table (same structure as the non-print view but without the filter form).

**Back link pattern for print views:**

Print routes have no dynamic route segments — all parameters are query strings. The Back link uses Laravel's convention of passing unknown named-route parameters as query strings:

```blade
<a href="{{ route('reports.trial-balance', request()->query()) }}" class="button button-secondary">
    {{ __('ui.reports.back') }}
</a>
```

This differs from `statement-print.blade.php`'s spread syntax (`[$party, ...request()->query()]`), which works because that route has a model-binding segment. Report print routes have no bound model, so passing `request()->query()` as the second argument appends all query params as `?key=value` pairs on the generated URL. This is the correct and safe pattern for all four print Back links.

---

## Translation keys

**Files:** `lang/en/ui.php` and `lang/ar/ui.php`

A new top-level `'reports'` key is added to both files. No existing keys are removed or renamed.

```php
// lang/en/ui.php — 'reports' key
'reports' => [
    'title'                        => 'Financial reports',
    'intro'                        => 'Generate and review financial reports for the selected company and period.',

    // Report names and descriptions
    'trial_balance_title'          => 'Trial balance',
    'trial_balance_description'    => 'Opening balances, period movements, and closing balances for all accounts.',
    'general_ledger_title'         => 'General ledger',
    'general_ledger_description'   => 'Chronological transaction detail with running balance per account.',
    'income_statement_title'       => 'Income statement',
    'income_statement_description' => 'Revenue, expenses, and net profit for the selected period.',
    'balance_sheet_title'          => 'Balance sheet',
    'balance_sheet_description'    => 'Assets, liabilities, and equity as of a specific date.',
    'performance_trends_title'     => 'Financial performance trends',
    'performance_trends_description' => 'Monthly revenue, expenses, and profit breakdown over a period.',

    // Filter labels
    'date_from'       => 'From date',
    'date_to'         => 'To date',
    'as_of_date'      => 'As of date',
    'period_start'    => 'Period start (optional)',
    'account_filter'  => 'Filter by account',
    'all_accounts'    => 'All accounts',
    'comparison_from' => 'Comparison from',
    'comparison_to'   => 'Comparison to',
    'apply_filters'   => 'Apply',
    'clear_filters'   => 'Clear',

    // Section headings
    'base_currency_totals'         => 'Totals',
    'native_currency_summary'      => 'Native currency summary',
    'assets'                       => 'Assets',
    'liabilities'                  => 'Liabilities',
    'equity'                       => 'Equity',
    'current_period_earnings'      => 'Current period earnings',
    'prior_unclosed_earnings'      => 'Prior unclosed earnings',
    'equity_accounts_total'        => 'Equity accounts total',
    'total_assets'                 => 'Total assets',
    'total_liabilities'            => 'Total liabilities',
    'total_equity'                 => 'Total equity',
    'total_liabilities_and_equity' => 'Total liabilities and equity',
    'equation_check'               => 'Balance check',

    // Table column headers
    'col_account_code'   => 'Code',
    'col_account_name'   => 'Account',
    'col_opening_debit'  => 'Opening debit',
    'col_opening_credit' => 'Opening credit',
    'col_period_debit'   => 'Period debit',
    'col_period_credit'  => 'Period credit',
    'col_closing_debit'  => 'Closing debit',
    'col_closing_credit' => 'Closing credit',
    'col_date'           => 'Date',
    'col_journal'        => 'Journal #',
    'col_description'    => 'Description',
    'col_source_ref'     => 'Ref.',
    'col_debit_native'   => 'Debit',
    'col_credit_native'  => 'Credit',
    'col_currency'       => 'Currency',
    'col_base_debit'     => 'Base debit',
    'col_base_credit'    => 'Base credit',
    'col_running_balance' => 'Running balance',
    'col_balance'        => 'Balance',

    // Income statement metric names
    'metric_revenue'            => 'Revenue',
    'metric_cogs'               => 'Cost of goods sold',
    'metric_gross_profit'       => 'Gross profit',
    'metric_gross_margin'       => 'Gross margin',
    'metric_operating_expenses' => 'Operating expenses',
    'metric_other_income'       => 'Other income',
    'metric_other_expenses'     => 'Other expenses',
    'metric_net_profit'         => 'Net profit',
    'comparison_period'         => 'Comparison period',
    'difference'                => 'Difference',
    'change_pct'                => 'Change %',
    'current_period'            => 'Current period',

    // Status messages
    'balanced'               => 'Balanced',
    'discrepant'             => 'Discrepancy detected',
    'equation_difference'    => 'Difference',
    'incomplete_data_warning' => 'This report contains incomplete data. Some source journals could not be fully processed. Review the data quality warnings before relying on these figures.',
    'metric_unavailable'     => 'Incomplete data',

    // Empty states
    'no_transactions' => 'No transactions match the selected filters.',
    'no_accounts'     => 'No accounts found for this company.',

    // Print controls
    'print'           => 'Print',
    'back'            => 'Back',
    'print_period'    => 'Period',
    'print_as_of'     => 'As of',
    'print_generated' => 'Generated',
],
```

```php
// lang/ar/ui.php — 'reports' key
'reports' => [
    'title'                        => 'التقارير المالية',
    'intro'                        => 'أنشئ التقارير المالية وراجعها للشركة والفترة المحددتين.',

    'trial_balance_title'          => 'ميزان المراجعة',
    'trial_balance_description'    => 'الأرصدة الافتتاحية وحركات الفترة والأرصدة الختامية لجميع الحسابات.',
    'general_ledger_title'         => 'دفتر الأستاذ العام',
    'general_ledger_description'   => 'تفاصيل الحركات المحاسبية مرتبةً زمنياً مع الرصيد الجاري لكل حساب.',
    'income_statement_title'       => 'قائمة الدخل',
    'income_statement_description' => 'الإيرادات والمصروفات وصافي الربح للفترة المحددة.',
    'balance_sheet_title'          => 'الميزانية العمومية',
    'balance_sheet_description'    => 'الأصول والالتزامات وحقوق الملكية كما في تاريخ محدد.',
    'performance_trends_title'     => 'اتجاهات الأداء المالي',
    'performance_trends_description' => 'تفصيل شهري للإيرادات والمصروفات والأرباح على مدار فترة زمنية.',

    'date_from'       => 'من تاريخ',
    'date_to'         => 'إلى تاريخ',
    'as_of_date'      => 'بتاريخ',
    'period_start'    => 'بداية الفترة (اختياري)',
    'account_filter'  => 'تصفية حسب الحساب',
    'all_accounts'    => 'جميع الحسابات',
    'comparison_from' => 'فترة المقارنة من',
    'comparison_to'   => 'فترة المقارنة إلى',
    'apply_filters'   => 'تطبيق',
    'clear_filters'   => 'إعادة ضبط',

    'base_currency_totals'         => 'الإجماليات',
    'native_currency_summary'      => 'ملخص العملات الأصلية',
    'assets'                       => 'الأصول',
    'liabilities'                  => 'الالتزامات',
    'equity'                       => 'حقوق الملكية',
    'current_period_earnings'      => 'أرباح الفترة الحالية',
    'prior_unclosed_earnings'      => 'الأرباح غير المقفلة السابقة',
    'equity_accounts_total'        => 'إجمالي حسابات حقوق الملكية',
    'total_assets'                 => 'إجمالي الأصول',
    'total_liabilities'            => 'إجمالي الالتزامات',
    'total_equity'                 => 'إجمالي حقوق الملكية',
    'total_liabilities_and_equity' => 'إجمالي الالتزامات وحقوق الملكية',
    'equation_check'               => 'التحقق من التوازن',

    'col_account_code'    => 'الرمز',
    'col_account_name'    => 'الحساب',
    'col_opening_debit'   => 'مدين افتتاحي',
    'col_opening_credit'  => 'دائن افتتاحي',
    'col_period_debit'    => 'مدين الفترة',
    'col_period_credit'   => 'دائن الفترة',
    'col_closing_debit'   => 'مدين ختامي',
    'col_closing_credit'  => 'دائن ختامي',
    'col_date'            => 'التاريخ',
    'col_journal'         => 'رقم القيد',
    'col_description'     => 'البيان',
    'col_source_ref'      => 'المرجع',
    'col_debit_native'    => 'مدين',
    'col_credit_native'   => 'دائن',
    'col_currency'        => 'العملة',
    'col_base_debit'      => 'مدين (أساسي)',
    'col_base_credit'     => 'دائن (أساسي)',
    'col_running_balance' => 'الرصيد الجاري',
    'col_balance'         => 'الرصيد',

    'metric_revenue'            => 'الإيرادات',
    'metric_cogs'               => 'تكلفة البضاعة المباعة',
    'metric_gross_profit'       => 'مجمل الربح',
    'metric_gross_margin'       => 'هامش الربح الإجمالي',
    'metric_operating_expenses' => 'المصروفات التشغيلية',
    'metric_other_income'       => 'الإيرادات الأخرى',
    'metric_other_expenses'     => 'المصروفات الأخرى',
    'metric_net_profit'         => 'صافي الربح',
    'comparison_period'         => 'فترة المقارنة',
    'difference'                => 'الفرق',
    'change_pct'                => 'نسبة التغيير',
    'current_period'            => 'الفترة الحالية',

    'balanced'               => 'متوازنة',
    'discrepant'             => 'يوجد فرق',
    'equation_difference'    => 'الفارق',
    'incomplete_data_warning' => 'يحتوي هذا التقرير على بيانات غير مكتملة. تعذر معالجة بعض القيود المحاسبية بالكامل. يُنصح بمراجعة تنبيهات جودة البيانات قبل الاعتماد على هذه الأرقام.',
    'metric_unavailable'     => 'بيانات غير مكتملة',

    'no_transactions' => 'لا توجد حركات مطابقة للفلاتر المحددة.',
    'no_accounts'     => 'لا توجد حسابات لهذه الشركة.',

    'print'           => 'طباعة',
    'back'            => 'رجوع',
    'print_period'    => 'الفترة',
    'print_as_of'     => 'بتاريخ',
    'print_generated' => 'تاريخ الإنشاء',
],
```

---

## Company isolation

The controller always calls `app(CompanyContextService::class)->requireCurrent()` before any service call. The service itself uses `withoutGlobalScope(CompanyScope::class)->where('company_id', $company->getKey())` for all data queries, enforcing company isolation at two levels: the controller refuses to proceed without a company context, and the service scopes every query.

For the General Ledger `account_id` filter, validation uses `Rule::exists('financial_accounts', 'id')->where('company_id', $company->getKey())`. This prevents a user from requesting another company's account by guessing its ID. The service's own `account_id` check (`ValidationException` with key `account_id`) will never fire through this UI path because controller validation catches the case first.

---

## RTL/LTR support

Report views use no inline `dir` overrides at the page level. The `dir` attribute on `<html>` is set by `layouts.app` based on `app()->getLocale()`, which is set by `SetApplicationLocale` middleware before the controller runs. All numeric values and reference codes are wrapped in `<bdi dir="ltr">`, matching the established pattern in `accounting/journals/show.blade.php` and `commercial/parties/statement.blade.php`.

---

## Feature tests

**File:** `tests/Feature/FinancialReportUiTest.php`

The test file uses `uses(RefreshDatabase::class)` and a local `financialReportUiSetUp()` helper function that creates a company, a user, attaches them, logs in, and creates a minimal set of financial accounts — mirroring the `accountingUiSetUp()` helper in `AccountingUiTest.php`. Tests are written as Pest `it(...)` closures.

### AC-14 — no active company context (corrected)

The `EnsureActiveCompany` middleware returns HTTP 403 when the user belongs to no companies, and HTTP 409 only when the user belongs to more than one company with no session context. The test for AC-14 therefore:

- Creates a user attached to **two** companies with no `active_company_id` in the session, and asserts `->assertStatus(409)`.
- Optionally adds a separate assertion for the 403 case (user with no company attached).

A test that creates a user with no company and asserts 409 would fail (actual: 403).

### Test table

| Acceptance criterion | Test description |
|---|---|
| AC-1 | `GET /reports` as authenticated user returns 200 and contains links to all five reports |
| AC-2 | `GET /reports/trial-balance` with valid dates returns 200 and renders an accounts table |
| AC-3 | `GET /reports/trial-balance` with missing `date_from` redirects with validation error |
| AC-4 | `GET /reports/general-ledger` with valid dates returns 200 and renders a transactions table |
| AC-5 | `GET /reports/general-ledger` with an `account_id` not in the active company returns validation error (not 500) |
| AC-6 | `GET /reports/income-statement` with valid dates returns 200 and shows all eight P&L metric labels |
| AC-7 | `GET /reports/income-statement` with both comparison dates returns 200 and shows comparison column headings |
| AC-8 | `GET /reports/income-statement` with only `comparison_from` returns a redirect with validation error on `comparison_to` |
| AC-9 | `GET /reports/balance-sheet` with `as_of_date` returns 200 and shows assets/liabilities/equity section headings |
| AC-10 | `GET /reports/performance-trends` with valid dates returns 200 and renders a monthly table |
| AC-11 | `GET /reports/trial-balance/print` with valid dates returns 200 and contains a `data-print-document` button |
| AC-12 | The reports sidebar link renders with `data-navigation-status="available"` after the navigation config change |
| AC-13 | Unauthenticated GET to `/reports` redirects to `/login` |
| AC-14 | Authenticated user attached to two companies with no session context gets HTTP 409 on `GET /reports` |
| AC-15 | English page renders no raw `ui.reports.` key literals |
| AC-16 | Arabic locale renders `dir="rtl"` on `<html>` and numeric amounts inside `<bdi dir="ltr">` |
| AC-17 | The `FinancialReportUiTest` file exists and all its tests pass |
| AC-18 | Pre-existing tests continue to pass |

The test helper creates: one company, one user, a small set of financial accounts covering all account types (`asset`, `liability`, `equity`, `revenue`, `expense`), and one posted journal (using `AccountingService` helpers as in `AccountingUiTest`). Tests assert HTTP status, presence of section headings and translation keys, and absence of raw key literals — not exact monetary values.

For AC-16, the test sets `'locale' => 'ar'` in the session and asserts `assertSee('<html lang="ar" dir="rtl">', false)` and `assertSee('<bdi dir="ltr">', false)` — the same pattern as `NavigationTest::it renders every navigation label in Arabic and English`.

---

## Files to create

| File | Action |
|---|---|
| `app/Http/Controllers/FinancialReportController.php` | **Create** |
| `resources/views/reports/index.blade.php` | **Create** |
| `resources/views/reports/trial-balance.blade.php` | **Create** |
| `resources/views/reports/trial-balance-print.blade.php` | **Create** |
| `resources/views/reports/general-ledger.blade.php` | **Create** |
| `resources/views/reports/general-ledger-print.blade.php` | **Create** |
| `resources/views/reports/income-statement.blade.php` | **Create** |
| `resources/views/reports/income-statement-print.blade.php` | **Create** |
| `resources/views/reports/balance-sheet.blade.php` | **Create** |
| `resources/views/reports/balance-sheet-print.blade.php` | **Create** |
| `resources/views/reports/performance-trends.blade.php` | **Create** |
| `tests/Feature/FinancialReportUiTest.php` | **Create** |

## Files to modify

| File | Change |
|---|---|
| `app/Providers/AppServiceProvider.php` | Add `'reports.*'` to the view composer pattern array |
| `routes/web.php` | Add `FinancialReportController` import and reports route group |
| `config/navigation.php` | Add `'route'` and `'active'` keys to the `reports` nav item |
| `lang/en/ui.php` | Add `'reports'` key with all UI strings |
| `lang/ar/ui.php` | Add `'reports'` key with Arabic translations |
| `tests/Feature/NavigationTest.php` | Update `available` count from 15→16 and `coming-soon` count from 3→2 at lines 98–99 |

---

## Testability

The controller has no logic beyond validation and service delegation, which makes unit testing unnecessary — integration tests cover everything meaningful. The service is already unit-tested in `FinancialReportingServiceTest`. `FinancialReportUiTest` integration tests cover all controller paths: validation failure, successful render, authentication/authorization, and locale-dependent rendering.

The view composer extension (adding `'reports.*'`) is implicitly covered by AC-15 and AC-16, which would fail with `Undefined variable $activeCompany` if the composer were not extended.

---

## Review finding responses

### Finding 1 (HIGH) — `$activeCompany` not injected by view composer

**Addressed.** The design now commits to Option A: extending the view composer pattern in `AppServiceProvider::boot()` to include `'reports.*'`. The layout-choice section states this explicitly and identifies `AppServiceProvider.php` as a file to modify.

### Finding 2 (HIGH) — Performance Trends array key mismatch

**Addressed.** The performance-trends view section now contains a table of row labels with their exact array keys, explicitly noting that `operating_expense` and `other_expense` are singular and come from `emptyStatementAmounts()`, contrasted with the plural forms from `incomeStatement()`. The implementer has no ambiguity.

### Finding 3 (HIGH) — Comparison-period validation error key mismatch

**Addressed.** The design now uses controller-level `required_with` validation exclusively for comparison dates. The exact validation array is shown. The service propagation mechanism is explicitly discarded for this case. The error will bind to `comparison_to` (or `comparison_from`), which corresponds to actual form fields.

### Finding 4 (MEDIUM) — Internal contradiction about `$activeCompany` in non-print views

**Addressed.** Both the layout-choice section and the view-composer section now state the same consistent mechanism: the extended view composer injects `$activeCompany` and `$companies` into all `reports.*` views. The phrase "shares it globally" does not appear.

### Finding 5 (MEDIUM) — AC-14 test scenario produces 403, not 409

**Addressed.** The feature-tests section now correctly describes the AC-14 test as using a user attached to two companies with no session context, asserting 409. It also notes that a user with no company gets 403, with an optional additional test for that case. The middleware's actual behaviour is stated.

### Finding 6 (MEDIUM) — Print view Back link spread syntax

**Addressed.** The print-views section now uses `route('reports.trial-balance', request()->query())` and explicitly notes why this differs from the `statement-print.blade.php` pattern (no model-binding segment). Laravel's behaviour of appending unknown route parameters as query strings is stated.

### Finding 7 (MEDIUM) — Gross margin key is `gross_margin_percentage`

**Addressed.** The income-statement view section now contains an explicit table of all eight metric array keys. `gross_margin_percentage` is called out with a note not to use `gross_margin`.

### Finding 8 (NIT) — "11 methods" should be "10"

**Addressed.** The controller section now states "ten public methods".
