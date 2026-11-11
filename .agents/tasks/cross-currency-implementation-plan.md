# Cross-Currency Supplier Settlement — Implementation Plan

**Date:** 2026-10-10  
**Based on:** cross-currency-settlement-design.md (read-only audit)  
**Scope:** Fix two confirmed defects, add forex account columns to companies, implement three-line cross-currency journal, fix outstanding-balance direction, write 15 feature tests.

---

## Quick-Reference — Method Locations

| Method | File | Approx. offset |
|--------|------|----------------|
| `postPaymentJournal` | `app/Services/PaymentService.php` | offset ~671 |
| `calculateOutstandingFor` | `app/Services/PaymentService.php` | after public outstanding helpers |
| `preparePaymentAttributes` | `app/Services/PaymentService.php` | private section, after `resolveAccountId` |
| `recordSupplierPayment` | `app/Services/PaymentService.php` | public API section |
| `recordPartnerPaidPurchase` | `app/Services/PaymentService.php` | public API section |

---

## Design Decisions (made here, not delegated to implementer)

**D1 — Two separate forex columns, not one combined.**  
`exchange_gain_account_id` (income) and `exchange_loss_account_id` (expense) are kept separate because they map to different account types and the original brief explicitly prohibits the ambiguous single-field design.

**D2 — `applied_currency` is always normalised to `purchase.currency` by `preparePaymentAttributes`.**  
`postPaymentJournal` can then use `applied_amount` / `applied_exchange_rate` directly without re-computing the payable direction. `calculateOutstandingFor` still contains the `bcdiv` safety-fallback but will not be triggered for well-formed payments.

**D3 — The payable debit line uses the purchase's original exchange rate (R₀), not the settlement rate.**  
This follows IAS 21 carrying-value extinguishment: the debit closes the liability at its book value; the forex line absorbs the difference between book value and actual cash paid.

**D4 — `postPaymentJournal` receives `?int $exchangeGainAccountId, ?int $exchangeLossAccountId` as new parameters.**  
`recordSupplierPayment` and `recordPartnerPaidPurchase` each resolve these from: (1) caller-supplied `exchange_gain_account_id` / `exchange_loss_account_id`, then (2) company-level defaults via the new relationship fields. If a cross-currency payment is attempted and neither source yields valid accounts, a `ValidationException` is thrown before any journal is created.

**D5 — Partner-paid cross-currency uses the identical three-line path.**  
`recordPartnerPaidPurchase` already calls `postPaymentJournal`; adding the two forex account parameters gives it the same three-line capability. The implementation adds the same `exchange_gain_account_id` / `exchange_loss_account_id` parameters to `recordPartnerPaidPurchase`.

**D6 — Migration filename follows YYYY_MM_DD_HHMMSS convention.**  
Most recent existing: `2026_10_10_010000_add_payment_status_columns.php`. New file: `2026_10_11_000000_add_forex_accounts_to_companies.php`.

---

## File List — What Changes in Each File

| File | Change summary |
|------|----------------|
| `database/migrations/2026_10_11_000000_add_forex_accounts_to_companies.php` | **NEW** — add `exchange_gain_account_id`, `exchange_loss_account_id` nullable FKs to `companies` |
| `app/Models/Company.php` | Add both fields to `$fillable`; add `exchangeGainAccount()` and `exchangeLossAccount()` BelongsTo relationships |
| `app/Services/PaymentService.php` | Fix `calculateOutstandingFor` (bcdiv); fix `preparePaymentAttributes` (normalise `applied_currency`); rewrite `postPaymentJournal` (three-line cross-currency); update `recordSupplierPayment` and `recordPartnerPaidPurchase` signatures; add private `resolveForexAccountId` helper |
| `tests/Feature/CrossCurrencySettlementTest.php` | **NEW** — 15 feature tests |

No changes to `AccountingService`, `PurchaseService`, migrations for payments/journal_lines, or any other file.

---

## Item 1 — Migration

**File to create:** `database/migrations/2026_10_11_000000_add_forex_accounts_to_companies.php`

**Exact columns:**

```php
Schema::table('companies', function (Blueprint $table) {
    $table->foreignId('exchange_gain_account_id')
        ->nullable()
        ->after('base_currency')
        ->constrained('financial_accounts')
        ->nullOnDelete();

    $table->foreignId('exchange_loss_account_id')
        ->nullable()
        ->after('exchange_gain_account_id')
        ->constrained('financial_accounts')
        ->nullOnDelete();
});
```

`nullOnDelete()` is the correct deletion behaviour: if a forex account is deleted, the company field becomes null rather than blocking the delete (matching the pattern used for `exchange_rates.created_by → users` and `accounting_journals.project_id → projects` in the existing migrations). The `restrictOnDelete()` pattern (used on `accounting_journals.company_id`) is intentionally NOT used here because a forex account deletion should not cascade-fail on the companies table.

**Down method:**

```php
Schema::table('companies', function (Blueprint $table) {
    $table->dropConstrainedForeignId('exchange_gain_account_id');
    $table->dropConstrainedForeignId('exchange_loss_account_id');
});
```

**Verify:** `php artisan migrate --env=testing` — exits 0 with no errors.

---

## Item 2 — Company Model

**File:** `app/Models/Company.php`

Add to `$fillable`:
```php
'exchange_gain_account_id',
'exchange_loss_account_id',
```

Add two BelongsTo methods:
```php
public function exchangeGainAccount(): BelongsTo
{
    return $this->belongsTo(FinancialAccount::class, 'exchange_gain_account_id');
}

public function exchangeLossAccount(): BelongsTo
{
    return $this->belongsTo(FinancialAccount::class, 'exchange_loss_account_id');
}
```

Add import: `use Illuminate\Database\Eloquent\Relations\BelongsTo;` (it's not currently in the file).

**Verify:** `php vendor/bin/pest tests/Feature/CrossCurrencySettlementTest.php --no-coverage` — the company-model assertions in setup helpers pass.

---

## Item 3 — `PaymentService::calculateOutstandingFor` — Fix Division Direction

**File:** `app/Services/PaymentService.php`

**Current code (wrong):**
```php
if (bccomp($rate, '0', 10) > 0) {
    $paymentAmount = bcmul($paymentAmount, $rate, 10);
}
```

**Replacement:**
```php
if (bccomp($rate, '0', 10) > 0) {
    // Rate is base-per-foreign (e.g. 12000 SYP per 1 USD).
    // $paymentAmount is in the payment currency (e.g. SYP).
    // $payableCurrency is the purchase currency (e.g. USD).
    // To convert payment-currency → payable-currency we divide by the rate:
    //   USD = SYP ÷ (SYP-per-USD)
    $paymentAmount = bcdiv($paymentAmount, $rate, 10);
}
```

This fix is in the `foreach ($payments as $payment)` loop inside `calculateOutstandingFor`, in the `if ($amountCurrency !== $payableCurrency)` branch.

**Verify:** `php vendor/bin/pest tests/Feature/CrossCurrencySettlementTest.php --no-coverage` — test #6 ("outstanding balance direction fix") passes.

---

## Item 4 — `PaymentService::preparePaymentAttributes` — Normalise `applied_currency`

**File:** `app/Services/PaymentService.php`

**Current code (simplified):**
```php
$appliedCurrency     = strtoupper((string) ($attributes['applied_currency'] ?? $currency));
$appliedExchangeRate = $this->normalizeAmount((string) ($attributes['applied_exchange_rate'] ?? $exchangeRate), ...);
$appliedAmount       = $this->normalizeAmount((string) ($attributes['applied_amount'] ?? $amount), ...);
```

**What to change:** When a `payable_type`/`payable_id` are present in `$attributes` AND the payable's currency differs from `$currency`, automatically set `applied_currency` to the payable's currency and `applied_amount` to `$amount ÷ $exchangeRate` (the settlement rate).

**Exact replacement block** (replaces the three `$applied*` lines):

```php
// Resolve payable currency when a purchase/revenue document is linked.
$payableNativeCurrency = null;
if (! empty($attributes['payable_type']) && ! empty($attributes['payable_id'])) {
    $payableClass = $attributes['payable_type'];
    if (class_exists($payableClass)) {
        $payableRecord = $payableClass::withoutGlobalScope(\App\Eloquent\CompanyScope::class)
            ->find($attributes['payable_id']);
        if ($payableRecord !== null) {
            $payableNativeCurrency = strtoupper((string) ($payableRecord->currency ?? $payableRecord->base_currency ?? null));
        }
    }
}

$appliedCurrency = strtoupper((string) ($attributes['applied_currency'] ?? $currency));

// When the payment currency differs from the payable's native currency and the
// caller has not explicitly set applied_currency, normalise it to the payable's
// currency so that calculateOutstandingFor never needs to cross-convert.
if (
    $payableNativeCurrency !== null
    && $payableNativeCurrency !== $currency
    && ! isset($attributes['applied_currency'])
) {
    $appliedCurrency = $payableNativeCurrency;
}

// applied_exchange_rate is the rate that converts the payment currency to the
// payable currency — use the payment's own exchange_rate as the default.
$appliedExchangeRate = $this->normalizeAmount(
    (string) ($attributes['applied_exchange_rate'] ?? $exchangeRate),
    'applied_exchange_rate',
);

// When applied_currency ≠ payment currency and no explicit applied_amount was
// supplied, derive it: payable-currency-amount = payment-amount ÷ rate.
if (! isset($attributes['applied_amount']) && $appliedCurrency !== $currency) {
    $derivedApplied = bccomp($appliedExchangeRate, '0', 10) > 0
        ? bcdiv($amount, $appliedExchangeRate, 10)
        : '0';
    $appliedAmount = $this->normalizeAmount($derivedApplied, 'applied_amount');
} else {
    $appliedAmount = $this->normalizeAmount((string) ($attributes['applied_amount'] ?? $amount), 'applied_amount');
}

$appliedBaseAmount = $this->normalizeAmount(
    (string) ($attributes['applied_base_amount'] ?? $this->convertAmount($appliedAmount, $appliedExchangeRate, $appliedCurrency, $baseCurrency)),
    'applied_base_amount',
);
```

**Same-currency case:** When `$currency == $payableNativeCurrency` (or there is no payable), the existing logic is followed unchanged. `applied_currency = currency`, `applied_amount = amount`. No behavioural difference for existing same-currency tests.

**Verify:** Existing `PartnerPaidPurchaseTest` and `PaymentAccountingTest` still pass (same-currency paths unchanged); new cross-currency tests pass.

---

## Item 5 — `PaymentService::postPaymentJournal` — Three-Line Cross-Currency Journal

### 5a. New method signature

```php
private function postPaymentJournal(
    Payment $payment,
    object  $sourceDocument,
    Company $company,
    int     $debitAccountId,
    int     $creditAccountId,
    string  $description,
    ?int    $exchangeGainAccountId = null,
    ?int    $exchangeLossAccountId = null,
): AccountingJournal
```

The two new parameters are nullable — when null and the payment is same-currency they are never used. When the payment is cross-currency and they are null, the method throws `ValidationException`.

### 5b. Full proposed postPaymentJournal body

Replace the entire method body from the duplicate-posting guard onwards:

```php
// Guard: prevent a second journal being created for the same payment.
$existingCount = AccountingJournal::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', $company->getKey())
    ->where('sourceable_type', Payment::class)
    ->where('sourceable_id', $payment->getKey())
    ->where('status', 'posted')
    ->count();

if ($existingCount > 0) {
    throw ValidationException::withMessages([
        'payment' => 'A journal entry has already been posted for this payment.',
    ]);
}

// Use the payment's stored amounts — never recalculate.
$amount           = $this->normalizeAmount((string) ($payment->amount ?? '0'), 'amount');
$currency         = strtoupper((string) ($payment->currency ?? $company->base_currency));
$baseCurrency     = strtoupper((string) ($payment->base_currency ?? $company->base_currency));
$exchangeRate     = $this->normalizeAmount((string) ($payment->exchange_rate ?? '1'), 'exchange_rate');
$baseAmount       = $this->normalizeAmount((string) ($payment->base_amount ?? $amount), 'base_amount');

$appliedAmount    = $this->normalizeAmount((string) ($payment->applied_amount ?? $amount), 'applied_amount');
$appliedCurrency  = strtoupper((string) ($payment->applied_currency ?? $currency));
$appliedRate      = $this->normalizeAmount((string) ($payment->applied_exchange_rate ?? $exchangeRate), 'applied_exchange_rate');

$entryDate = $payment->paid_at
    ? $payment->paid_at->toDateString()
    : now()->toDateString();

$journal = $this->accounting->createDraftJournal([
    'company_id'      => $company->getKey(),
    'project_id'      => $payment->project_id,
    'created_by'      => $payment->created_by,
    'sourceable_type' => Payment::class,
    'sourceable_id'   => $payment->getKey(),
    'journal_number'  => 'PAY-'.strtoupper($payment->payment_number),
    'entry_date'      => $entryDate,
    'description'     => $description,
], $company);

$isCrossCurrency = $appliedCurrency !== $currency;

if ($isCrossCurrency) {
    // Validate that forex accounts are available.
    if ($exchangeGainAccountId === null || $exchangeLossAccountId === null) {
        throw ValidationException::withMessages([
            'exchange_gain_account_id' => 'Exchange gain and loss accounts are required for cross-currency payments.',
        ]);
    }

    // Carrying value of the liability in base currency = applied_amount × applied_exchange_rate.
    // applied_exchange_rate is the original purchase rate (R₀), i.e. the rate at which
    // the liability was first recorded.
    $carryingBase = bcmul($appliedAmount, $appliedRate, 10);

    // Actual cash paid in base currency = payment.base_amount (e.g. actual SYP disbursed).
    $cashBase = $baseAmount;

    // FX difference = cash paid − carrying value.
    // Positive diff → exchange loss (paid MORE SYP than liability was worth).
    // Negative diff → exchange gain (paid FEWER SYP than liability was worth).
    $fxDiff = bcsub($cashBase, $carryingBase, 10);

    // Line 1: Dr Supplier Payable in the payable's native currency (e.g. USD) at carrying rate.
    $this->accounting->addJournalLine($journal, [
        'financial_account_id' => $debitAccountId,
        'party_id'             => $payment->party_id,
        'project_id'           => $payment->project_id,
        'debit'                => $appliedAmount,    // e.g. 1000 USD
        'currency'             => $appliedCurrency,  // e.g. 'USD'
        'exchange_rate'        => $appliedRate,       // e.g. 12000 (original purchase rate R₀)
        'base_currency'        => $baseCurrency,
        'base_debit'           => $carryingBase,     // e.g. 12,000,000 SYP
        'description'          => $description,
    ]);

    // Line 2: Cr Cash/Bank in the payment currency (e.g. SYP).
    $this->accounting->addJournalLine($journal, [
        'financial_account_id' => $creditAccountId,
        'party_id'             => $payment->party_id,
        'project_id'           => $payment->project_id,
        'credit'               => $amount,           // e.g. 12,500,000 SYP
        'currency'             => $currency,         // e.g. 'SYP'
        'exchange_rate'        => '1',               // SYP line — rate is 1
        'base_currency'        => $baseCurrency,
        'base_credit'          => $cashBase,         // e.g. 12,500,000 SYP
        'description'          => $description,
    ]);

    // Line 3: Exchange Gain or Loss (base currency only).
    if (bccomp($fxDiff, '0', 10) > 0) {
        // Exchange Loss: paid more base currency than the liability carried.
        // Dr Exchange Loss (expense account) by |fxDiff|.
        $this->accounting->addJournalLine($journal, [
            'financial_account_id' => $exchangeLossAccountId,
            'party_id'             => $payment->party_id,
            'project_id'           => $payment->project_id,
            'debit'                => $fxDiff,
            'currency'             => $baseCurrency,
            'exchange_rate'        => '1',
            'base_currency'        => $baseCurrency,
            'base_debit'           => $fxDiff,
            'description'          => $description,
        ]);
    } elseif (bccomp($fxDiff, '0', 10) < 0) {
        // Exchange Gain: paid fewer base currency units than the liability carried.
        // Cr Exchange Gain (income account) by |fxDiff|.
        $absDiff = bcmul($fxDiff, '-1', 10);
        $this->accounting->addJournalLine($journal, [
            'financial_account_id' => $exchangeGainAccountId,
            'party_id'             => $payment->party_id,
            'project_id'           => $payment->project_id,
            'credit'               => $absDiff,
            'currency'             => $baseCurrency,
            'exchange_rate'        => '1',
            'base_currency'        => $baseCurrency,
            'base_credit'          => $absDiff,
            'description'          => $description,
        ]);
    }
    // If fxDiff == 0, rates are identical — no forex line needed; two lines balance.

} else {
    // Same-currency path — existing two-line logic, unchanged.
    $this->accounting->addJournalLine($journal, [
        'financial_account_id' => $debitAccountId,
        'party_id'             => $payment->party_id,
        'project_id'           => $payment->project_id,
        'debit'                => $amount,
        'currency'             => $currency,
        'exchange_rate'        => $exchangeRate,
        'base_currency'        => $baseCurrency,
        'base_debit'           => $baseAmount,
        'description'          => $description,
    ]);

    $this->accounting->addJournalLine($journal, [
        'financial_account_id' => $creditAccountId,
        'party_id'             => $payment->party_id,
        'project_id'           => $payment->project_id,
        'credit'               => $amount,
        'currency'             => $currency,
        'exchange_rate'        => $exchangeRate,
        'base_currency'        => $baseCurrency,
        'base_credit'          => $baseAmount,
        'description'          => $description,
    ]);
}

return $this->accounting->postJournal($journal);
```

### 5c. Base-currency balance proof

**Exchange loss scenario (R₁ > R₀):**
```
base_debit (Line 1) = appliedAmount × appliedRate  = 1000 × 12000 = 12,000,000
base_debit (Line 3) = fxDiff                       =                    500,000
Total base_debit    =                                                 12,500,000

base_credit (Line 2) = cashBase (base_amount)      =                 12,500,000
Total base_credit    =                                                12,500,000  ✓
```

**Exchange gain scenario (R₁ < R₀):**
```
base_debit (Line 1) = 1000 × 12000                 = 12,000,000
Total base_debit    =                                 12,000,000

base_credit (Line 2) = cashBase                    = 11,800,000
base_credit (Line 3) = |fxDiff| = 12000000−11800000 =    200,000
Total base_credit   =                                 12,000,000  ✓
```

**No forex (R₁ = R₀):**
```
base_debit (Line 1) = 1000 × 12000                 = 12,000,000
Total base_debit    =                                 12,000,000

base_credit (Line 2) = cashBase                    = 12,000,000
Total base_credit   =                                 12,000,000  ✓
```

**Verify:** `php vendor/bin/pest tests/Feature/CrossCurrencySettlementTest.php --no-coverage` — journal balance tests pass.

---

## Item 6 — `PaymentService::recordSupplierPayment` — Add Forex Account Parameters

**File:** `app/Services/PaymentService.php`

**What to add:** After resolving `$cashAccountId`, add resolution of the two forex accounts. Then pass them to `postPaymentJournal`.

**New logic block** (insert after `$cashAccountId` is confirmed non-null):

```php
// Resolve forex accounts (required for cross-currency payments).
$exchangeGainAccountId = $this->resolveForexAccountId(
    $company,
    isset($attributes['exchange_gain_account_id']) ? (int) $attributes['exchange_gain_account_id'] : null,
    $company->exchange_gain_account_id ? (int) $company->exchange_gain_account_id : null,
    ['income'],
    'exchange_gain_account_id',
    'Exchange gain account',
);

$exchangeLossAccountId = $this->resolveForexAccountId(
    $company,
    isset($attributes['exchange_loss_account_id']) ? (int) $attributes['exchange_loss_account_id'] : null,
    $company->exchange_loss_account_id ? (int) $company->exchange_loss_account_id : null,
    ['expense'],
    'exchange_loss_account_id',
    'Exchange loss account',
);
```

**Updated `postPaymentJournal` call:**

```php
$this->postPaymentJournal(
    $payment,
    $purchase,
    $company,
    debitAccountId:         $payableAccountId,
    creditAccountId:        $cashAccountId,
    description:            'Supplier payment '.$payment->payment_number.' for '.$purchase->purchase_number,
    exchangeGainAccountId:  $exchangeGainAccountId,
    exchangeLossAccountId:  $exchangeLossAccountId,
);
```

**Important:** `$company` must be refreshed before accessing `exchange_gain_account_id` / `exchange_loss_account_id` so the new columns are loaded:

```php
$company = $purchase->company?->fresh() ?: $this->companyContext->requireCurrent();
```

---

## Item 7 — `PaymentService::recordPartnerPaidPurchase` — Add Forex Account Parameters

Same pattern as Item 6. Add `resolveForexAccountId` calls after `$partnerLiabilityAccountId` is resolved and before `postPaymentJournal`. Pass `exchangeGainAccountId` and `exchangeLossAccountId` to `postPaymentJournal`.

**Note on partner-paid cross-currency:** The accounting treatment is identical to direct supplier payment (the only difference is Line 2 credits the Due-to-Partner account instead of Cash/Bank). The same three-line logic applies without any special casing. This is confirmed by design section D3 and Q5.

---

## Item 8 — `PaymentService::resolveForexAccountId` — New Private Helper

Add a new private method to `PaymentService`:

```php
/**
 * Resolve a forex account ID (gain or loss).
 *
 * Priority: (1) caller-supplied ID, (2) company-level default.
 * Returns null when neither is available — the caller decides whether to require it.
 *
 * @param  string[]  $allowedTypes  e.g. ['income'] or ['expense']
 */
private function resolveForexAccountId(
    Company $company,
    ?int $callerSupplied,
    ?int $companyDefault,
    array $allowedTypes,
    string $attribute,
    string $label,
): ?int {
    $accountId = $callerSupplied ?? $companyDefault;

    if ($accountId === null) {
        return null;
    }

    $account = FinancialAccount::query()->find($accountId);

    if ($account === null) {
        throw ValidationException::withMessages([
            $attribute => 'The selected '.$label.' does not exist.',
        ]);
    }

    if ((int) $account->company_id !== (int) $company->getKey()) {
        throw ValidationException::withMessages([
            $attribute => 'The selected '.$label.' must belong to the same company.',
        ]);
    }

    if (! in_array(strtolower((string) $account->account_type), $allowedTypes, true)) {
        throw ValidationException::withMessages([
            $attribute => 'The selected '.$label.' must be of type: '.implode(', ', $allowedTypes).'.',
        ]);
    }

    return $account->getKey();
}
```

This method returns `null` rather than throwing when no account is configured, which allows `postPaymentJournal` to decide contextually (it throws only if a cross-currency journal is actually being posted).

**Verify:** Item 6/7 tests pass; wrong-type and wrong-company tests for forex accounts pass.

---

## Item 9 — Existing Tests at Risk

| Test | File | Risk | Reason |
|------|------|------|--------|
| `preserves USD amounts and exchange rates for a USD partner payment` | `PartnerPaidPurchaseTest` | **Low** | Same-currency (USD payment, USD accounts). The two-line path in `postPaymentJournal` is unchanged. No forex accounts needed. |
| `preserves USD amount and exchange rate in the journal lines` | `PaymentAccountingTest` | **Low** | Same-currency (USD payment, USD purchase, USD accounts). Same-currency two-line path. |
| `accepts a cross-currency payment when an explicit valid exchange rate is supplied` | `AccountingIntegrityPhase1Test` | **Low** | Calls `recordPayment` (not `recordSupplierPayment`), never reaches `postPaymentJournal`. No impact. |
| `rejects a cross-currency payment when no exchange rate exists` | `AccountingIntegrityPhase1Test` | **Low** | Same as above — `recordPayment` path only, no journal posted. |
| `represents an SYP payment applied to a USD invoice` | `MultiCurrencyArchitectureTest` | **Low** | Bypasses service entirely (`Payment::create` direct). The `applied_currency = USD` is already stored on the created payment. `calculateOutstandingFor` with `applied_currency == payableCurrency` (both USD) never enters the conversion branch, so the `bcdiv` fix doesn't affect it. |
| `records a partner-paid purchase and creates the correct journal entries` | `PartnerPaidPurchaseTest` | **Medium** | This test asserts exactly 2 journal lines. After the change, same-currency partner payment (SYP accounts, SYP payment) still takes the two-line path — should remain passing. Confirm: both accounts are SYP, payment is SYP → `appliedCurrency == currency → !isCrossCurrency`. |
| `company-funded supplier payment still works` | `PartnerPaidPurchaseTest` | **Medium** | Same-currency SYP payment. Two-line path unchanged. Low actual risk but ensure the `$company->fresh()` call in recordSupplierPayment doesn't break anything. |
| Any test that checks `$lines->toHaveCount(2)` | Multiple files | **Medium** | ALL existing tests use same-currency setups, so they always take the two-line path. Cross-check: `PaymentAccountingTest` test 1 asserts count=2 (SYP); `PartnerPaidPurchaseTest` tests 1, 6 assert count=2 (SYP). These are safe. |

**Tests that will definitely NOT break:**
- Any test using same-currency (SYP) purchases with SYP payment accounts
- Any test that calls `recordPayment` directly (not `recordSupplierPayment` or `recordPartnerPaidPurchase`)
- Any `AccountingIntegrityPhase1Test` test that doesn't involve `postPaymentJournal`

**Potential regression risk — `preparePaymentAttributes` change:**  
The new code reads the payable record when `payable_type`/`payable_id` are present. If the payable's currency matches the payment currency (all existing tests), the `if ($payableNativeCurrency !== null && $payableNativeCurrency !== $currency && !isset($attributes['applied_currency']))` condition is false, so behaviour is identical. No existing test should break.

---

## Item 10 — Test Scaffold: `CrossCurrencySettlementTest.php`

**File:** `tests/Feature/CrossCurrencySettlementTest.php`

### Class name and structure

Following project convention: a standalone Pest file with `uses(RefreshDatabase::class)` at the top, no PHP class wrapper, helper functions defined at file scope.

### Setup helper structure

```php
uses(RefreshDatabase::class);

/**
 * Create a company with full cross-currency infrastructure:
 *   - USD Supplier Payable (liability, USD)
 *   - SYP Cash/Bank (asset, SYP)
 *   - Exchange Gain (income, SYP)
 *   - Exchange Loss (expense, SYP)
 *   - Due-to-Partner (liability, SYP) — for partner-paid tests
 * Optionally store forex accounts as company-level defaults.
 */
function makeCrossContext(bool $setCompanyDefaults = true): array
{
    $company = Company::create(['name' => 'CrossCo '.uniqid(), 'base_currency' => 'SYP']);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Supplier Payable USD', 'account_type' => 'liability', 'currency' => 'USD',
    ]);
    $sypCash = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash SYP', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '7100',
        'name' => 'FX Gain', 'account_type' => 'income', 'currency' => 'SYP',
    ]);
    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '8100',
        'name' => 'FX Loss', 'account_type' => 'expense', 'currency' => 'SYP',
    ]);
    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partner', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    if ($setCompanyDefaults) {
        $company->update([
            'exchange_gain_account_id' => $gainAccount->id,
            'exchange_loss_account_id' => $lossAccount->id,
        ]);
        $company->refresh();
    }

    return [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner];
}

/**
 * Create a posted USD purchase at the given original rate R₀.
 */
function makeCrossUsdPurchase(Company $company, string $total = '1000', string $rate = '12000'): Purchase
{
    $base = bcmul($total, $rate, 4);
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);
    return Purchase::create([
        'company_id' => $company->id, 'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(), 'purchase_date' => '2026-10-01',
        'status' => 'posted', 'currency' => 'USD', 'exchange_rate' => $rate,
        'base_currency' => 'SYP', 'subtotal' => $total, 'tax_total' => '0', 'total' => $total,
        'base_subtotal' => $base, 'base_tax_total' => '0', 'base_total' => $base,
    ]);
}
```

### Method names and test descriptions (all 15)

```
1.  it('posts a three-line journal with exchange loss when settlement rate exceeds original rate')
2.  it('posts a three-line journal with exchange gain when settlement rate is below original rate')
3.  it('posts a two-line journal when settlement rate equals original rate (no forex line)')
4.  it('outstanding balance is zero after a full cross-currency payment')
5.  it('outstanding balance accumulates correctly across two partial cross-currency payments at different rates')
6.  it('calculateOutstandingFor divides (not multiplies) to convert SYP applied_amount to USD')
7.  it('rejects a SYP debit to a USD-denominated payable account (journal line currency enforcement)')
8.  it('posts a three-line journal for a cross-currency partner-paid purchase')
9.  it('throws when exchange_gain_account_id is missing for a cross-currency payment')
10. it('throws when the exchange gain account belongs to another company')
11. it('throws when the exchange gain account is the wrong type (not income)')
12. it('prevents overpayment with correct conversion direction after the bcdiv fix')
13. it('rolls back payment and journal when forex account is invalid mid-transaction')
14. it('preserves the original purchase journal unchanged after cross-currency settlement')
15. it('isolates cross-currency payments across companies (tenant isolation)')
```

### Key assertions per test

**Test 1 (exchange loss):** `$lines->toHaveCount(3)` · Line 1: `financial_account_id == $usdPayable->id`, `currency == 'USD'`, `debit == '1000.0000'`, `base_debit == '12000000.0000'` · Line 2: `financial_account_id == $sypCash->id`, `currency == 'SYP'`, `credit == '12500000.0000'`, `base_credit == '12500000.0000'` · Line 3: `financial_account_id == $lossAccount->id`, `currency == 'SYP'`, `debit == '500000.0000'`, `base_debit == '500000.0000'` · `sum(base_debit) == sum(base_credit)`.

**Test 2 (exchange gain):** Same structure. R₁ = 11800 < R₀ = 12000. Payment = 11,800,000 SYP. `fxDiff = 11800000 − 12000000 = −200000`. Line 3: `financial_account_id == $gainAccount->id`, `credit == '200000.0000'`, `base_credit == '200000.0000'`.

**Test 3 (no forex):** R₀ = R₁ = 12000. Payment = 12,000,000 SYP. `$lines->toHaveCount(2)`.

**Test 4 (outstanding = 0):** After full cross-currency payment, `bccomp($outstanding, '0', 10) == 0`.

**Test 5 (partial accumulation):** Two payments. After P1 (500 USD applied at 12200): outstanding = 500. After P2 (500 USD applied at 11900): outstanding = 0.

**Test 6 (direction fix):** Create payment directly with `Payment::create` using `applied_amount = '12000000'`, `applied_currency = 'SYP'`, `applied_exchange_rate = '12000'`, payable has `currency = 'USD'`, `total = '1000'`. Call `calculateOutstandingForPurchase` and assert `bccomp($outstanding, '0', 10) == 0` (i.e. 12000000 ÷ 12000 = 1000 = full purchase total).

**Test 7 (currency enforcement):** Create a draft journal and manually call `$accountingService->addJournalLine` with a SYP-currency line pointing at a USD-denominated account. Expect `ValidationException` with `'currency'` key.

**Test 8 (partner-paid cross-currency):** `recordPartnerPaidPurchase` with USD purchase, SYP payment, SYP Due-to-Partner. Three lines: `$usdPayable`, `$dueToPartner`, `$lossAccount` (or `$gainAccount` depending on rates). Assert `$lines->toHaveCount(3)` and correct accounts.

**Test 9 (missing forex account):** Call `recordSupplierPayment` on a USD purchase with SYP payment, no forex accounts on company, no `exchange_gain_account_id` in attributes. Expect `ValidationException` with `'exchange_gain_account_id'` key.

**Test 10 (wrong-company forex account):** Create `$gainAccountB` for a second company. Pass it as `exchange_gain_account_id` for company A's payment. Expect `ValidationException`.

**Test 11 (wrong type):** Create a liability account and pass it as `exchange_gain_account_id`. Expect `ValidationException`.

**Test 12 (overpayment blocked):** USD purchase total = 1000. Attempt to pay 1200 USD equivalent in SYP. Assert `ValidationException` with `'amount'` key.

**Test 13 (rollback):** Pass an invalid `exchange_loss_account_id` (99999). Assert payment count and journal count are both unchanged after the failed call.

**Test 14 (purchase journal preserved):** Post a purchase journal before calling `recordSupplierPayment`. After payment, fetch the original purchase journal's lines and assert they are identical (same count, same amounts, same IDs). The payment creates an additional journal but does not touch the purchase journal.

**Test 15 (tenant isolation):** Create purchase in Company A. Attempt `recordSupplierPayment` in a Company B context. Expect `ValidationException`.

---

## Verification Commands

After each item is implemented, run:

```bash
# Targeted (fast feedback during implementation):
php vendor/bin/pest tests/Feature/CrossCurrencySettlementTest.php --no-coverage

# Full suite (must pass before marking complete):
php vendor/bin/pest --no-coverage
```

Expected full-suite result: all previously passing tests still pass, plus 15 new tests. Zero failures.

---

## Known Constraints and Risks

1. **`AccountingIntegrityPhase1Test` "rejects a cross-currency payment when no exchange rate exists"** — this test passes `applied_currency = 'SYP'` and `applied_exchange_rate = '0'` to `recordPayment` directly. After the `preparePaymentAttributes` change, when `payable_type/payable_id` are present and `payable.currency = 'USD'` ≠ `'SYP'`, the code will try to derive `applied_amount = 12000000 ÷ 0`. The guard `bccomp($appliedExchangeRate, '0', 10) > 0` prevents division by zero and falls back to `'0'`. This makes `applied_amount = '0'`. The overpayment guard then compares `0 ≤ outstanding (1000)` — so the `recordPayment` call would NOT throw due to overpayment, but the test expects a `ValidationException`. However, the test also has `applied_exchange_rate = '0'` which triggers the guard check in `guardPaymentAgainstOverpayment` (it calls `resolveExchangeRate` which throws because no USD→SYP rate exists). So the test still throws — but now at the overpayment guard with `applied_amount = '0'` after `preparePaymentAttributes` normalises it. **Review this carefully during implementation** and confirm the exception is still thrown from the expected guard. If needed, add an explicit guard in `preparePaymentAttributes` that throws when `applied_exchange_rate = '0'` and currencies differ.

2. **`guardPaymentAgainstOverpayment` also calls `bcmul` for conversion** — this is a separate occurrence of the multiply/divide issue inside `guardPaymentAgainstOverpayment`. That method converts `appliedAmount` (which is in `payload['applied_currency']`) to payable currency for the overpayment comparison. After the `preparePaymentAttributes` fix, `applied_currency` will always equal `payable.currency` for well-formed payments, so this conversion branch is not entered. However, for calls that supply `applied_currency ≠ payable.currency` explicitly (rare, legacy), the conversion in `guardPaymentAgainstOverpayment` still multiplies. This is a lower-priority fix — note it in code comments but do not change it in this phase (risk of breaking the Phase 1 integrity test).

3. **`CompanyContextService` must be active** when `recordSupplierPayment` or `recordPartnerPaidPurchase` are called. All existing tests use `runForCompany`. New tests must follow the same pattern.

4. **`$company->fresh()`** — calling `fresh()` inside `recordSupplierPayment` to pick up the new `exchange_gain_account_id` / `exchange_loss_account_id` columns adds one DB query. This is acceptable given the existing pattern in the codebase.

5. **Partner-paid cross-currency is implemented** (same code path). The design document's "Document limitation in code comment; implement only if identical treatment is confirmed" condition is satisfied — Section D3 of the design confirms the journal is identical except Line 2 credits Due-to-Partner instead of Cash. No separate implementation needed.
