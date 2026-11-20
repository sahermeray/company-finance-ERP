# Phase 8H — Financial Reports UI: Requirements

## Workspace Recovery Summary

The user's original request is a **recovery-and-completion task**: a previous session started Phase 8H, then stopped without delivering a final report. This requirements document therefore begins with an inspection of what already exists, then states the full requirements for what must be built.

### Current workspace state (as inspected)

| Artifact | Status | Detail |
|---|---|---|
| `FinancialReportingService` | **Complete** | Full implementation in `app/Services/FinancialReportingService.php`. All four core methods exist: `trialBalance`, `generalLedger`, `incomeStatement` (with comparison period), `incomeStatementTrend`, and `balanceSheet`. |
| `FinancialReportingServiceTest` | **Complete** | `tests/Feature/FinancialReportingServiceTest.php` exists with comprehensive coverage of all five service methods, multi-currency isolation, unclassified-account reporting, and balance-sheet equation checks. |
| `FinancialReportController` | **Not started** | No controller exists. No `app/Http/Controllers/FinancialReportController.php` or equivalent. |
| Financial report routes | **Not started** | `routes/web.php` has no `/reports` or `/finance/reports` routes. The navigation config's `reports` item has no `route` key — it renders as "Coming soon" in the sidebar. |
| Financial report views | **Not started** | No views exist under `resources/views/reports/` or any similar path. |
| Finance → Reports navigation activation | **Not started** | `config/navigation.php` has the `reports` item under the `finance` group but deliberately omits a `route` key, so the sidebar marks it unavailable. |
| Translation keys for reports UI | **Partially implemented** | `lang/en/ui.php` and `lang/ar/ui.php` both have `ui.navigation.reports` (the sidebar label). No report-page-level keys exist (titles, filters, table headings, status messages, print labels). |
| Feature tests for reports UI (HTTP layer) | **Not started** | No `FinancialReportUiTest.php` or similar HTTP-level test file exists. |
| Print views for reports | **Not started** | No print layout for any financial report exists. |
| RTL/LTR and Arabic locale support | **Partially implemented** | The application-wide locale system (middleware, `SetApplicationLocale`, language switcher in the topbar, `bdi` elements in existing views) is fully in place. Report views must use the same patterns; no report-specific work has been done. |

---

## Summary

Phase 8H adds the **HTTP presentation layer** for financial reports to the ERP. The backend service (`FinancialReportingService`) is already complete and tested. This phase builds the controller, routes, Blade views, translation strings, navigation activation, print layouts, and feature tests that expose those service results to authenticated users through the web UI.

The five report types are:

1. **Trial Balance** — per-account opening, period debit/credit, and closing balances for a date range.
2. **General Ledger** — chronological journal-line listing per account, with running balance, optionally filtered to one account.
3. **Income Statement** — period P&L metrics (revenue, COGS, gross profit, operating expenses, other income/expense, net profit), with optional comparison period.
4. **Balance Sheet** — assets, liabilities, and equity as of a single date, with the accounting equation check.
5. **Financial Performance Trends** — monthly income statement breakdown across a multi-month range, presented as a data table or chart.

---

## Functional Requirements

### FR-1 Routes and controller

1. A `FinancialReportController` must be created under `app/Http/Controllers/`.
2. All report routes must be registered under the `finance` route group, protected by `auth` and `company.context` middleware, following the existing pattern in `routes/web.php`.
3. Routes required:
   - `GET /reports` → index / report selector page (`reports.index`)
   - `GET /reports/trial-balance` → Trial Balance (`reports.trial-balance`)
   - `GET /reports/general-ledger` → General Ledger (`reports.general-ledger`)
   - `GET /reports/income-statement` → Income Statement (`reports.income-statement`)
   - `GET /reports/balance-sheet` → Balance Sheet (`reports.balance-sheet`)
   - `GET /reports/performance-trends` → Financial Performance Trends (`reports.performance-trends`)
   - `GET /reports/trial-balance/print` → Trial Balance print view (`reports.trial-balance.print`)
   - `GET /reports/general-ledger/print` → General Ledger print view (`reports.general-ledger.print`)
   - `GET /reports/income-statement/print` → Income Statement print view (`reports.income-statement.print`)
   - `GET /reports/balance-sheet/print` → Balance Sheet print view (`reports.balance-sheet.print`)
4. Each controller action must call the corresponding `FinancialReportingService` method using the active company from `CompanyContextService::requireCurrent()`.

### FR-2 Navigation activation

5. The `reports` item in `config/navigation.php` must have `'route' => 'reports.index'` and `'active' => ['reports.*']` added so the sidebar renders it as a live link instead of "Coming soon".

### FR-3 Query parameter handling and validation

6. **Trial Balance and General Ledger**: accept `date_from` and `date_to` (required, `Y-m-d` format). General Ledger also accepts an optional `account_id` integer.
7. **Income Statement**: accept `date_from`, `date_to` (required), and optional `comparison_from` / `comparison_to` for the comparison period. When only one comparison date is supplied, return a validation error matching the existing service behaviour.
8. **Balance Sheet**: accept `as_of_date` (required, `Y-m-d`) and optional `period_start` (`Y-m-d`).
9. **Performance Trends**: accept `date_from` and `date_to` (required). The underlying service constrains the range; the controller must pass both values through.
10. All date inputs must be validated before the service is called. Invalid or missing required inputs must redirect back with validation errors using Laravel's standard `$errors` bag, consistent with the patterns used in `AccountingJournalController`.
11. Each report page must default its date filters to a sensible range on first visit (e.g. current calendar month for period reports; today for Balance Sheet `as_of_date`) so the page renders useful data without manual filter entry.

### FR-4 Report index page

12. The index page (`reports.index`) must list all five report types with a name, short description, and a link to each report's page.

### FR-5 Report display pages

13. Each report page must display the filter form at the top and the report output below after a successful fetch.
14. **Trial Balance view**: tabular display of account code, account name, opening debit/credit, period debit, period credit, closing debit/credit. Show base-currency totals row. If the report has `status = 'incomplete'`, display a prominent warning. Show the native-currency summary section when native balances are present.
15. **General Ledger view**: list transactions in date order showing date, journal number, description, source document reference, debit (native), credit (native), currency, base debit, base credit, running balance. The account filter dropdown must be populated from accounts belonging to the active company.
16. **Income Statement view**: display each metric (revenue, COGS, gross profit, gross margin %, operating expenses, other income, other expenses, net profit) with its value. When a comparison period is present, show the comparison value and the difference/percentage change alongside the main period value. Show "unavailable" or a visual indicator when a metric's `availability` field is not `'available'`.
17. **Balance Sheet view**: display assets, liabilities, and equity sections each with an account list and subtotal. Show current-period earnings and accumulated earnings within equity. Display the balance status (`balanced` / `discrepant` / `incomplete`) prominently.
18. **Performance Trends view**: tabular display of monthly columns with revenue, COGS, gross profit, operating expense, other income/expense, and net profit rows. Each cell shows the base-currency amount for that month.
19. All monetary amounts must be displayed using `number_format` with 4 decimal places and the base currency code, consistent with the existing views. Amounts inside `<bdi dir="ltr">` wrappers must be used for all numeric values and reference numbers, following the pattern established in existing views.

### FR-6 Print views

20. Each of the four printable reports (Trial Balance, General Ledger, Income Statement, Balance Sheet) must have a dedicated print-layout view extending `layouts.app` (not `layouts.inventory`), following the same `document-paper` / `document-print-controls` pattern used in `resources/views/commercial/parties/statement-print.blade.php`.
21. The print view must include a "Back" link to the main report page (preserving query string) and a `data-print-document` button, identical to the pattern in `statement-print.blade.php`.
22. Print views must include the company name, report title, and the period or as-of date in the document header.

### FR-7 Translations

23. All UI strings introduced by this phase must be added to both `lang/en/ui.php` and `lang/ar/ui.php` under a `'reports'` key, covering at minimum: page titles, section headings, filter labels, table column headers, status messages, print button labels, empty-state messages, and the "incomplete data" warning.
24. No existing translation keys may be removed or renamed.
25. The Arabic translations must be semantically correct financial-domain Arabic — not machine-literal. *(Assumption: the implementer will use the same domain register as the existing Arabic keys, e.g. "ميزان المراجعة", "دفتر الأستاذ العام", "قائمة الدخل", "الميزانية العمومية", "اتجاهات الأداء المالي".)*

### FR-8 Company isolation

26. Every controller action must retrieve the active company via `CompanyContextService::requireCurrent()` and pass it to the service. The service already enforces company-scoped data access; the controller must not query financial data without that company reference.
27. No cross-company data may appear in any report page, consistent with the existing `TenantScoped` / `CompanyScope` architecture.

### FR-9 RTL/LTR support

28. Report views must follow the established RTL/LTR patterns: numeric values and reference codes inside `<bdi dir="ltr">` elements; layout direction derived from the application locale via the existing `SetApplicationLocale` middleware and the `dir` attribute on the root `<html>` element (already set in `layouts.app`).
29. No inline `dir` overrides that contradict the layout direction must be introduced at the page level.

---

## Non-Functional Requirements

### NFR-1 Consistency with existing code conventions

- Controllers must follow the same structure as `AccountingJournalController`: single-class, `readonly` service injection, inline validation using `$request->validate()`, `View` / `RedirectResponse` return types declared.
- Views must extend `layouts.inventory` (the authenticated shell layout), use the same Tailwind utility classes and component patterns as the accounting journal views, and use `@section('page-content')` to place content inside the main content area.
- Route naming must follow the existing convention (`{resource}.{action}`).

### NFR-2 No database migrations

- This phase is entirely in the presentation layer. No database schema changes are required or permitted.

### NFR-3 No modification of unrelated modules

- The implementation must not touch payment, purchase, inventory, expense, or party controllers, services, or views.

### NFR-4 No destructive git operations

- Branch state must be preserved. No `git reset --hard`, force pushes, or `clean -fd` commands.

---

## Acceptance Criteria

1. `GET /reports` returns HTTP 200 for an authenticated user with an active company, and displays links to all five report types.
2. `GET /reports/trial-balance?date_from=2026-01-01&date_to=2026-12-31` returns HTTP 200 and renders a table of accounts with opening, period, and closing balance columns.
3. `GET /reports/trial-balance` with missing `date_from` redirects back with a validation error (the form renders an inline error message).
4. `GET /reports/general-ledger?date_from=2026-01-01&date_to=2026-12-31` returns HTTP 200 and renders a chronological transaction table.
5. `GET /reports/general-ledger?date_from=2026-01-01&date_to=2026-12-31&account_id=999` where account 999 does not belong to the active company returns a validation error (not a 500).
6. `GET /reports/income-statement?date_from=2026-01-01&date_to=2026-12-31` returns HTTP 200 and displays each of the eight P&L metrics.
7. `GET /reports/income-statement?date_from=2026-10-01&date_to=2026-10-31&comparison_from=2026-09-01&comparison_to=2026-09-30` returns HTTP 200 and displays comparison values alongside current-period values.
8. `GET /reports/income-statement?date_from=2026-10-01&date_to=2026-10-31&comparison_from=2026-09-01` (only one comparison date) returns a validation error.
9. `GET /reports/balance-sheet?as_of_date=2026-12-31` returns HTTP 200 and displays the asset, liability, and equity sections with totals and the equation status.
10. `GET /reports/performance-trends?date_from=2026-01-01&date_to=2026-12-31` returns HTTP 200 and renders a monthly breakdown table with one column per month.
11. `GET /reports/trial-balance/print?date_from=2026-01-01&date_to=2026-12-31` returns HTTP 200 and renders the print layout with a `data-print-document` button and no sidebar navigation.
12. The Finance → Reports sidebar link is live (renders as `<a>`) and routes to `reports.index`, not marked "Coming soon".
13. Visiting any report route without an authenticated session redirects to `/login`.
14. Visiting any report route with an authenticated user but no active company returns HTTP 409 (the existing `EnsureActiveCompany` middleware behaviour).
15. All UI strings on every report page resolve correctly in both English and Arabic — no raw translation keys (e.g. `ui.reports.xxx`) appear in the rendered HTML.
16. Report pages render in RTL layout when the Arabic locale is active, with numeric amounts inside `<bdi dir="ltr">` wrappers.
17. A `FinancialReportUiTest` feature test file exists and all its tests pass under `php artisan test`.
18. All pre-existing tests continue to pass after the Phase 8H implementation (`php artisan test` exits 0).

---

## Out of Scope

- **Export to PDF or Excel**: not part of this phase. Print views use browser print only.
- **Custom account grouping or hierarchy in views**: reports display flat account lists as returned by the service.
- **Drill-down from Trial Balance into General Ledger**: not required; each report is a standalone page.
- **Chart.js or any JavaScript charting library**: the Performance Trends view uses an HTML table, not a chart.
- **New financial report types** beyond the five listed (e.g. cash flow statement).
- **User permission roles / policy gates beyond the existing `auth` middleware**: no new policy classes are required; use the same `$this->authorize` pattern only if an existing policy applies.
- **Real-time or AJAX data refresh**: all reports are server-rendered, GET-based.
- **API or JSON endpoints** for any report.
- **Changes to `FinancialReportingService`**: the service is complete; no modifications are permitted unless a bug is discovered during integration.

---

## Assumptions

- **A1**: "Financial Performance Trends" maps to `FinancialReportingService::incomeStatementTrend()`. The user's original requirements listed it without naming the service method; the service has exactly one trend method and it matches the description.
- **A2**: The print route for Performance Trends is excluded because the monthly multi-column table does not have a natural print document format (no equivalent in existing print views). If a print route is required, it should be raised during design review.
- **A3**: The General Ledger page should populate its account filter dropdown from `FinancialAccount` where `company_id = active company` and `is_active = true`, ordered by `code`. Selecting "all accounts" (no filter) shows all transactions across all accounts.
- **A4**: Default date ranges: period reports (`date_from` / `date_to`) default to the first and last day of the current calendar month; Balance Sheet `as_of_date` defaults to today. Performance Trends defaults to the first day of the current year to today.
- **A5**: The `reports.index` page is a simple card-based landing page, not a report itself. It does not call the service.
- **A6**: `FinancialReportController` will be a single class (not split into sub-controllers) because the five reports share the same service, middleware group, and route prefix, consistent with how `AccountingJournalController` handles multiple related views in one class.
