# Design Review — Phase 8H Financial Reports UI
**Design document:** `02-design.md` (Revision 1)
**Reviewed against:** `01-requirements.md` and actual source in `/Users/admin/Herd/company-finance-ERP`

---

## Findings

### 1. MEDIUM — Controller constructor injection conflicts with the actual `AccountingJournalController` pattern

**Where:** Controller section — "A single controller class following the `AccountingJournalController` pattern exactly: `readonly` service injection in the constructor."

**Problem:** `AccountingJournalController` has **no constructor at all**. It resolves `CompanyContextService` inline via `app(CompanyContextService::class)->requireCurrent()` in each action. The design proposes constructor injection of `FinancialReportingService`:

```php
public function __construct(private readonly FinancialReportingService $reports) {}
```

This is not wrong on its own, but it contradicts the stated rationale ("following the `AccountingJournalController` pattern exactly"). More importantly, `FinancialReportingService` is **not registered as a singleton** in `AppServiceProvider` (the register list is `CompanyContextService`, `AccountingService`, `PaymentService`, `PurchaseService` — no `FinancialReportingService`). Laravel will auto-resolve it via the container, but `FinancialReportingService` itself takes `CompanyContextService` as a constructor argument, and `CompanyContextService` *is* a singleton. Auto-resolution will work correctly, but the design should explicitly acknowledge this rather than implying the pattern matches `AccountingJournalController`.

**Concrete fix:** Remove the claim "following the `AccountingJournalController` pattern exactly" for constructor injection — `AccountingJournalController` does not use constructor injection at all. Either (a) state that the new controller introduces constructor injection for the service, or (b) match the actual pattern and use `app(FinancialReportingService::class)` per-action. Option (b) would be fully consistent; option (a) is acceptable but must not claim it follows the existing pattern.

---

### 2. MEDIUM — `gross_margin_percentage` has no comparison data but the view design implies it does

**Where:** Income statement view section — "each row shows the metric name, the current-period value, and — when a comparison period was submitted — the comparison value, the difference, and the percentage change."

**Problem:** The service's comparison loop iterates exactly these seven metrics:
```php
['revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'other_income', 'other_expenses', 'net_profit']
```
`gross_margin_percentage` is **not in this loop**. When a comparison period is submitted, `$report['metrics']['gross_margin_percentage']` will never have a `comparison` key — only the other seven will. If the view iterates all eight metrics and tries to render `$metric['comparison']` unconditionally, it will produce a PHP undefined key error for the gross margin row.

**Concrete fix:** Add one of these to the view spec:
- State explicitly that the gross margin row never shows a comparison column, even when a comparison period is active.
- Or instruct the implementer to guard with `@if(isset($metric['comparison']))` before rendering comparison cells.

Either way, the design must acknowledge this service behaviour so the view handles it correctly.

---

### 3. MEDIUM — `FinancialReportingService` not registered as singleton; the design omits this from `AppServiceProvider` changes

**Where:** "Files to modify" table — `app/Providers/AppServiceProvider.php` lists only "Add `'reports.*'` to the view composer pattern array."

**Problem:** `FinancialReportingService` takes `CompanyContextService` as a constructor dependency. If the controller uses constructor injection (as designed), Laravel auto-resolves it on every request, which is fine — **but** nothing in the design or files-to-modify table mentions adding a singleton binding. The existing services that do similar work (`AccountingService`, `PaymentService`, `PurchaseService`) are all singletons. If `FinancialReportingService` is not a singleton, every construction creates a new instance, which is harmless here (no state is held between calls). However, the inconsistency with the established pattern should be an explicit decision in the design, not a silent omission.

**Concrete fix:** Add a line to the `AppServiceProvider` changes either:
- Registering `FinancialReportingService::class` as a singleton alongside the others, or
- Explicitly noting it is intentionally **not** a singleton because it holds no state.

---

### 4. MEDIUM — Navigation test update instruction is incomplete: `administration` group has 2 coming-soon items, not 3 — verify count before changing

**Where:** "Navigation activation" section — "The existing `NavigationTest` asserts `toBe(15)` available and `toBe(3)` coming-soon at lines 98–99. After this change the reports item moves from coming-soon to available. The test must be updated to `toBe(16)` and `toBe(2)`."

**Problem:** The current navigation config (verified in source) has these items without a `route` key: `reports` (finance group), `users` (administration group), `company-settings` (administration group). That is 3 coming-soon items and 15 available items, matching the test. After activating `reports`, there will be 16 available and **2** coming-soon. The arithmetic is correct. However, the design states this as fact without instructing the implementer to verify — if another phase has since added or removed a nav item, the counts would be wrong and the test would fail in a confusing way.

**Concrete fix:** Add an instruction to the implementer: "Before changing the `toBe(15)` and `toBe(3)` assertions, run the existing `NavigationTest` to confirm the current counts match, then update to `toBe(16)` and `toBe(2)`." This is defensive but required because a stale count assumption would break the test.

---

### 5. NIT — `AccountingJournalController` does not use `readonly` constructor injection; design description is factually wrong

**Where:** Controller section first paragraph — "following the `AccountingJournalController` pattern exactly: `readonly` service injection in the constructor."

**Problem:** As confirmed in source, `AccountingJournalController` has no constructor at all. The description of the reference pattern is incorrect. This is covered partly by Finding 1 but the factual error in the reference pattern description needs a standalone correction so the implementer doesn't inspect `AccountingJournalController` looking for a constructor that isn't there.

**Concrete fix:** Change "following the `AccountingJournalController` pattern exactly: `readonly` service injection in the constructor" to "following the `AccountingJournalController` conventions: inline `$request->validate()`, `app(CompanyContextService::class)->requireCurrent()` per action, explicit `View|RedirectResponse` return types. Constructor injection of `FinancialReportingService` is a deliberate deviation to avoid nine repeated `app()` calls."

---

### 6. NIT — `CompanyContextService::requireCurrent()` returns HTTP 409, but only via its direct `abort()` call — the middleware gets there first in practice

**Where:** Error handling table — "No active company context | `CompanyContextService::requireCurrent()` | No | HTTP 409."

**Problem:** `CompanyContextService::requireCurrent()` does call `abort(409, ...)` directly. However, in the route flow, `EnsureActiveCompany` middleware runs **before** the controller, and the middleware also issues 409 (and 403 for no-company users). So `requireCurrent()` in the controller will only throw 409 if somehow the middleware was bypassed. This is a documentation accuracy issue, not a runtime bug, but it could confuse the implementer about where the 409 originates.

**Concrete fix:** Update the error table row to: "No active company context | `EnsureActiveCompany` middleware (precedes controller; `requireCurrent()` is a secondary safety net) | No | HTTP 409."

---

## Verified Assumptions

| Claim | Verified against | Result |
|---|---|---|
| `FinancialReportingService` exists with all five methods (`trialBalance`, `generalLedger`, `incomeStatement`, `incomeStatementTrend`, `balanceSheet`) | `app/Services/FinancialReportingService.php` | ✅ Correct |
| `emptyStatementAmounts()` uses singular keys `operating_expense` and `other_expense` | Service source | ✅ Confirmed — the method returns exactly `['revenue', 'cost_of_goods_sold', 'operating_expense', 'other_income', 'other_expense']` |
| `statementFromTrialBalance()` returns plural keys `operating_expenses` and `other_expenses` in its metrics array | Service source (`$this->metric(... $amounts['operating_expense'] ...)` keyed as `'operating_expenses'`) | ✅ Confirmed |
| `gross_margin_percentage` is the correct metric key (not `gross_margin`) | Service source | ✅ Confirmed |
| The view composer in `AppServiceProvider::boot()` currently covers `['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*']` | `app/Providers/AppServiceProvider.php` | ✅ Exact match |
| `config/navigation.php` `reports` item has no `route` key | Actual file | ✅ Confirmed |
| `reports` SVG icon exists in `resources/views/components/icon.blade.php` | File inspected | ✅ Confirmed |
| Non-print views extend `layouts.inventory` and use `@section('page-content')` | `resources/views/layouts/inventory.blade.php` confirmed it wraps `@yield('page-content')` | ✅ Correct |
| Print views extend `layouts.app` and use `@section('content')` | `statement-print.blade.php` confirmed as the reference pattern | ✅ Correct |
| `EnsureActiveCompany` middleware returns 403 for no-company users and 409 for multi-company users with no session context | `app/Http/Middleware/EnsureActiveCompany.php` | ✅ Confirmed |
| `NavigationTest` currently asserts `toBe(15)` available and `toBe(3)` coming-soon at lines 98–99 | `tests/Feature/NavigationTest.php` | ✅ Exact lines confirmed |
| Print back link uses `request()->query()` as the second argument (no model segment in route) | Routes design confirmed no model binding; pattern differs from `statement-print.blade.php` which spreads `[$party, ...request()->query()]` | ✅ Reasoning sound |
| `FinancialReportController` does not yet exist | Grep across all PHP files | ✅ Confirmed — not created yet |
| No report views exist under `resources/views/reports/` | Search across blade files | ✅ Confirmed — directory absent |
| Monetary amounts use `number_format((float) $value, 4)` and `<bdi dir="ltr">` wrappers | Verified against `statement-print.blade.php` and `journals/show.blade.php` | ✅ Matches established pattern |

---

## Unverified / Wrong Assumptions

| Claim | Issue |
|---|---|
| "following the `AccountingJournalController` pattern exactly: `readonly` service injection in the constructor" | **Wrong.** `AccountingJournalController` has no constructor at all. See Finding 1 and NIT Finding 5. |
| `gross_margin_percentage` will display a comparison value when a comparison period is active | **Wrong.** The service's comparison loop omits `gross_margin_percentage`. The view will get an undefined key if it tries to render a comparison cell for this metric. See Finding 2. |
| `AppServiceProvider` only needs the view composer change for the new controller to work | **Incomplete.** The singleton registration question for `FinancialReportingService` is unresolved and not documented. See Finding 3. |

---

## Verdict

**CHANGES_REQUESTED** — 3 MEDIUM findings (1, 2, 3) and 1 MEDIUM finding (4) require resolution before implementation.
