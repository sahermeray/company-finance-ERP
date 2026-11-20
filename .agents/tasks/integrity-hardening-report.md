# Supplier Payables Reconciliation — Integrity Hardening Report

## Summary

Implemented the supplier payables reconciliation phase addressing audit findings D1–D4.
Full test suite: **529 tests, 2271 assertions, 0 failures, exit code 0** (baseline was 501/2150).

---

## Files Changed

| File | Change |
|---|---|
| `app/Services/PaymentService.php` | Fix 1 (party_id defaulting + conflict guard), Fix 3 (`calculateSupplierPayableBalance`), Fix 4 (`reconcileSupplierPayables`) |
| `app/Models/FinancialAccount.php` | Fix 2 — already implemented (postedJournalLines, all balance methods already filter to posted journals) |
| `tests/Feature/SupplierReconciliationTest.php` | 28 new tests (121 assertions) covering all 5 fixes |

---

## Fix 1 — Supplier Identity in `recordSupplierPayment()`

### Problem
`recordSupplierPayment()` did not default `party_id` to the purchase's supplier. When the caller omitted `party_id`, the payment and all journal lines were created with `party_id = NULL`, making per-supplier GL queries impossible.

### Solution
In `PaymentService::recordSupplierPayment()`, immediately after refreshing the purchase:

1. If `party_id` is omitted: `$attributes['party_id'] = $purchase->party_id`
2. If `party_id` is supplied but conflicts with `$purchase->party_id`: throw `ValidationException` **before** any DB writes (pre-write guard, no partial records created).
3. If `party_id` matches `$purchase->party_id`: proceed normally.

### Design — Journal Line party_id
Per inspection of `postPaymentJournal()` and existing tests: the current design sets `party_id = $payment->party_id` on **all three journal lines** (payable debit, cash/bank credit, FX gain/loss). This is preserved. The spec clause "do NOT apply party_id to cash/bank or FX lines unless the current design already does so" was verified — the existing design does, so all lines carry the supplier party_id.

---

## Fix 2 — Posted-Journal Filtering in Balance Methods

### Finding
**Already implemented before this phase.** `FinancialAccount` has a `postedJournalLines()` relationship that filters to `status = 'posted'`, and all five balance methods (`debitTotal()`, `creditTotal()`, `balance()`, `nativeCurrencyBalance()`, `baseCurrencyBalance()`, `balanceByCurrency()`) already use it.

### Verification
28 new tests include Fix2-specific tests proving:
- Draft journal lines are excluded from `balance()`, `baseCurrencyBalance()`, `nativeCurrencyBalance()`, `debitTotal()`, `creditTotal()`
- Posting a journal makes lines visible in all balance methods
- A posted reversal journal nets to zero (original posted + reversal posted = both included, net = 0)

The existing 501 tests all passed, confirming no regressions.

### Reversal Design Note
`AccountingService::reverseJournal()` does **not** change the original journal's status to `'reversed'`. Both the original journal and the reversal journal remain at `status = 'posted'`. They net to zero because the reversal has equal-and-opposite debit/credit. The filter `status = 'posted'` correctly includes both.

---

## Fix 3 — `calculateSupplierPayableBalance(Party $supplier, FinancialAccount $payableAccount)`

### Method added to `PaymentService`

```php
public function calculateSupplierPayableBalance(
    Party $supplier,
    FinancialAccount $payableAccount,
): array
```

**Returns:**
```php
[
    'native_balance' => string,  // credit − debit in account's native currency, positive = owed
    'base_balance'   => string,  // base_credit − base_debit, positive = owed
    'currency'       => string,  // account's currency code
]
```

**Design:**
- Filters: `financial_account_id = payableAccount.id`, `party_id = supplier.id`, `journal.status = 'posted'`
- Uses `JournalLine::withoutGlobalScope(CompanyScope::class)` with `whereHas('journal', fn => $q->withoutGlobalScope(CompanyScope::class)->where('status', 'posted'))` to bypass tenant scope (safe; enforces company isolation explicitly)
- Company isolation: throws `ValidationException` if supplier and account belong to different companies
- Liability sign convention: `credit − debit` = positive means company owes supplier
- NULL party_id lines are excluded (documented in docblock)
- FX gain/loss lines are on separate accounts (expense/income) — never on the payable account — they do not appear here
- Posted reversal journals included automatically (they are `status = 'posted'`), their debit lines net out the original credits

---

## Fix 4 — `reconcileSupplierPayables(Company $company)`

### Method added to `PaymentService`

```php
public function reconcileSupplierPayables(Company $company): array
```

**Returns:**
```php
[
    'company_id'   => int,
    'company_name' => string,
    'reconciled'   => bool,
    'suppliers'    => [
        [
            'supplier_id'             => int,
            'supplier_name'           => string,
            'currency'                => string,
            'operational_outstanding' => string,
            'gl_balance'              => string,
            'gl_base_balance'         => string,
            'delta'                   => string,  // gl_balance − operational_outstanding
            'status'                  => 'reconciled' | 'mismatch' | 'no_gl_data',
            'warnings'                => string[],
        ],
    ],
    'warnings' => string[],
]
```

**Design rules:**
- READ-ONLY — never posts journals or mutates records
- Groups by supplier + currency (a supplier with both SYP and USD purchases appears as two entries)
- Operational outstanding: `calculateOutstandingFor()` across all posted purchases for that supplier+currency
- GL balance: `calculateSupplierPayableBalance()` summed across all liability accounts for that currency
- NULL party_id lines on payable accounts → global warning + per-entry warning + mismatch status
- `no_gl_data` when no liability account exists for a purchase's currency
- Another company's data is excluded (queries scoped to `company->getKey()`)
- FX gain/loss entries are on expense/income accounts — never counted in payable balance

**FX reconciliation note (documented in docblock):** Cross-currency settlement creates 3 journal lines:
1. Dr Supplier Payable (USD at R₀) — on payable account
2. Cr Cash/Bank (SYP) — on cash account, NOT payable
3. Dr/Cr Exchange Gain/Loss (SYP) — on FX account, NOT payable

Therefore the payable account balance is always at R₀ (original purchase rate), and both the GL balance (in USD) and operational outstanding (in USD) agree at R₀. FX lines do not distort reconciliation.

---

## Sign Conventions

| Direction | Account Type | Meaning |
|---|---|---|
| Credit > Debit | Liability (payable) | Company owes supplier — positive outstanding |
| Debit > Credit | Liability (payable) | Over-paid — net debit on liability |

Both `calculateSupplierPayableBalance()` and `reconcileSupplierPayables()` use **credit − debit** for the GL balance, matching `calculatePartnerLiabilityBalance()`.

The operational outstanding (`calculateOutstandingFor()`) uses **purchase total − sum of posted applied_amounts**, floor at zero.

---

## Tests Added (tests/Feature/SupplierReconciliationTest.php)

### Fix 1 Tests (4 tests)
| Test | Expected value |
|---|---|
| Omitted party_id defaults to purchase supplier | `payment->party_id == purchase->party_id`; payable debit line `party_id == supplier->id` |
| Conflicting party_id throws before any DB writes | `ValidationException('party_id')`; payment count unchanged, journal count unchanged |
| Correct party_id explicitly supplied | Payment posted, payable line has correct `party_id` |
| Cross-currency: USD payable debit carries supplier party_id | 3-line journal; payable line `currency=USD`, `party_id==supplier->id` |

### Fix 2 Tests (3 tests)
| Test | Expected value |
|---|---|
| Draft journal excluded from all balance methods | `balance()=0`, `baseCurrencyBalance()=0`, `nativeCurrencyBalance('SYP')=0`, `debitTotal()=0`, `creditTotal()=0` |
| Posting makes lines visible | After post: `balance()=750`, `baseCurrencyBalance()=750`, `nativeCurrencyBalance('SYP')=750` |
| Posted reversal nets to zero | `balance()=0`; `debitTotal()=500` (original), `creditTotal()=500` (reversal) |

### Fix 3 Tests (7 tests)
| Test | Independently computed expected value |
|---|---|
| Supplier purchase, no payment | `native_balance = 1000 SYP`, `base_balance = 1000 SYP` |
| Supplier fully paid | `native_balance = 0`, `base_balance = 0` |
| Supplier B on same account, no lines | `native_balance = 0` |
| USD purchase at R₀=12000 | `native_balance = 1000 USD`, `base_balance = 12,000,000 SYP` (1000 × 12000) |
| Reversal returns to pre-payment state | `native_balance = 1000 SYP` after payment reversal |
| Company isolation | `ValidationException('same company')` |
| NULL party_id lines excluded | `native_balance = 0` (untagged line not counted for supplier X) |

### Fix 4 Tests (6 tests)
| Test | Expected value |
|---|---|
| Single supplier, no payments | `status=reconciled`, `operational=2000`, `gl_balance=2000`, `delta=0` |
| Partial payment (400 of 1000) | `status=reconciled`, `operational=600`, `gl_balance=600`, `delta=0` |
| Reversal returns both views to pre-payment state | `status=reconciled`, `operational=1000`, `gl_balance=1000` |
| Two suppliers independent | A: `operational=500`, `gl=500`; B: `operational=300`, `gl=300` |
| NULL party_id on payment line | Global warning "NULL party_id"; entry warning "party_id missing"; mismatch surfaced |
| Other company not included | `suppliers` array contains companyA's supplier ID, not companyB's |

### Fix 5 End-to-End Tests via PurchaseService::postPurchase() (8 tests)
| Test | Independently computed expected value |
|---|---|
| A: Post real purchase | `outstanding=1000 SYP`, `GL credit=1000 SYP`, `delta=0`, `status=reconciled` |
| B: Partial payment 400 | `outstanding=1000−400=600`, `GL=1000−400=600`, `delta=0` |
| C: Cross-currency full settlement | Pay 12,500,000 SYP at R₁=12500; `GL USD balance=1000−1000=0`, `GL base=12M−12M=0`; FX loss 500,000 on separate account |
| D: Payment reversal | After reverse: `outstanding=1000`, `GL=1000`, `status=reconciled` |
| E: Draft journal excluded | Draft adds 500 SYP to payable; `GL balance unchanged at 800 SYP` |
| F: Two suppliers independent | A: 500 SYP, B: 300 SYP — each reconciles independently |
| G: Second company excluded | CompanyA report contains only companyA's supplier (not companyB's 9999 SYP purchase) |
| H: Pre-Fix 1 NULL party_id surfaced | Global warning + entry-level warning; not silently ignored |

---

## Remaining Limitations

1. **No purchase reversal workflow.** Per hard restrictions, purchase reversal was not implemented. If a purchase is reversed outside the system, operational outstanding does not update and reconciliation would show a mismatch.

2. **Multi-account per currency.** `reconcileSupplierPayables` aggregates across all liability accounts for a given currency. If a company has multiple payable accounts in the same currency (unusual), the GL balance is summed. The current design has one payable account per currency in practice.

3. **NULL party_id on purchase journal lines (from before Fix 1).** `calculateSupplierPayableBalance` only counts lines tagged to the supplier. Historical lines with `party_id = NULL` are excluded and surfaced as warnings in `reconcileSupplierPayables`. Manual remediation of historical data is outside this phase.

4. **Customer receivables and partner liability reconciliation** are covered by the existing `AccountingReconciliationService` (separate service). `reconcileSupplierPayables` covers only supplier payables.

5. **No posting invariant enforcement.** Per specification, reconciliation is diagnostic only. Posting a purchase or payment does not gate on reconciliation status.

---

## Test Suite Results

```
Tests:    529 passed (2271 assertions)
Duration: ~15s
Exit Code: 0
Baseline: 501 tests, 2150 assertions
Added:    28 tests, 121 assertions
```
