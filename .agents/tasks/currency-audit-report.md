# Supplier Payment Currency Handling — Audit Report

**Scope:** `app/Services/PaymentService.php`, `app/Services/AccountingService.php`, related models and migrations.  
**Suite status at time of audit:** 164 Pest tests, 445 assertions, 0 failures.  
**Key fix preceding this audit:** `guardPaymentAgainstOverpayment()` corrected from `bcmul` to `bcdiv` in the cross-currency branch.

---

## 1. Entry Points

All public methods that can create a supplier payment or partner-paid purchase record:

| Method | Signature | Creates payment row | Creates journal |
|---|---|---|---|
| `createDraftPayment` | `(array $attributes, ?Company $company): Payment` | Yes (draft) | No |
| `recordPayment` | `(array $attributes, ?Company $company): Payment` | Yes | No (journal is caller's responsibility) |
| `recordSupplierPayment` | `(Purchase $purchase, array $attributes): Payment` | Yes (via `recordPayment`) | Yes (3-line cross-currency or 2-line same-currency) |
| `recordPartnerPaidPurchase` | `(Purchase $purchase, array $attributes): Payment` | Yes (via `recordPayment`) | Yes |
| `recordPartnerReimbursement` | `(Company $company, array $attributes): Payment` | Yes (via `recordPayment`) | Yes (not linked to a purchase) |
| `recordCustomerReceipt` | `(Revenue $revenue, array $attributes): Payment` | Yes (via `recordPayment`) | Yes |

`recordPayment` is the single choke-point where `guardPaymentAgainstOverpayment`, `assertCurrencyMatchesAccount`, and `ensurePaymentIsPositive` are called; every purchase-settling entry point funnels through it.

---

## 2. Field Semantics (Documented Intent vs. Actual Implementation)

### `amount` / `currency`
- **Intended meaning:** The actual cash amount disbursed/received, in the currency of the cash/bank account. E.g., for a SYP payment against a USD invoice, `amount = 12,500,000`, `currency = 'SYP'`.
- **Actual implementation:** ✓ Matches. `preparePaymentAttributes` sets `currency` to the caller-supplied value (or the company base). The cash-account currency-match check in `assertCurrencyMatchesAccount` enforces this for payments that reference a `financial_account_id`.

### `applied_amount` / `applied_currency`
- **Intended meaning:** The portion of the payable document that this payment discharges, expressed in the payable's native currency. E.g., `applied_amount = 1000`, `applied_currency = 'USD'` means this payment clears USD 1,000 of the invoice.
- **Actual implementation:** ✓ Matches for the auto-derive path. When `applied_currency ≠ currency`, `preparePaymentAttributes` derives `applied_amount = amount ÷ applied_exchange_rate`.
- **Gap (see §7):** A caller can explicitly supply an `applied_amount` in a currency inconsistent with the purchase without arithmetic validation — the guard still converts correctly, but no semantic consistency check exists.

### `exchange_rate` / `applied_exchange_rate`
- **Rate convention:** Both use **base-per-foreign** (SYP-per-USD). A rate of `12000` means 1 USD = 12,000 SYP.
- **`exchange_rate`:** The rate at which the cash amount (`amount`) relates to the company's base currency. For a SYP payment, `exchange_rate = 1`.
- **`applied_exchange_rate`:** The **original purchase rate R₀** — the rate at which the payable was first recorded. Used as the carrying value rate for the debit leg of the journal. Defaults to `payableOriginalRate` when a payable is linked.
- **Actual implementation:** ✓ Both rates follow the same convention throughout all conversion paths.

### `base_amount`
- **Intended meaning:** The cash disbursed expressed in the company's base currency. `base_amount = amount × exchange_rate` (or caller-supplied).
- **Actual implementation:** ✓ Matches. `preparePaymentAttributes` calls `convertAmount($amount, $exchangeRate, $baseCurrency, $currency)` — see §10 for a parameter-order anomaly that is arithmetically harmless.

### `applied_base_amount`
- **Intended meaning:** `applied_amount × applied_exchange_rate` — the carrying value of the discharged liability in base currency.
- **Actual implementation:** ✓ Matches. `convertAmount($appliedAmount, $appliedExchangeRate, $appliedCurrency, $baseCurrency)` — correctly multiplies.

---

## 3. Confirmed Findings (Correct and Well-Guarded)

### 3.1 `guardPaymentAgainstOverpayment()` — currency consistency (CONFIRMED CORRECT)
Every path through `guardPaymentAgainstOverpayment()` compares amounts in the same currency — the payable's native currency — before calling `bccomp`:

- **Same-currency path:** `appliedCurrency == payableCurrency` → no conversion, direct compare. ✓
- **Cross-currency path:** `appliedCurrency ≠ payableCurrency` → divides by the stored/resolved rate (SYP-per-USD), converting the SYP applied amount to USD before comparing. `bcdiv($appliedAmount, $rate, 10)` is correct. ✓

The previous `bcmul` bug (which would have multiplied SYP × 12,000, producing an absurdly large USD value that would only incidentally reject overpayments) has been corrected to `bcdiv`. The regression test in `CrossCurrencySettlementTest` (`guard uses bcdiv (not bcmul)…`) explicitly verifies both rejection and acceptance at exactly the boundary.

### 3.2 `calculateOutstandingFor()` — conversion direction (CONFIRMED CORRECT)
Line 680 (approx.): `$paymentAmount = bcdiv($paymentAmount, $rate, 10)` — converts a SYP `applied_amount` to USD by dividing, consistent with the SYP-per-USD rate convention. ✓

The rate fallback chain (stored `applied_exchange_rate` → stored `exchange_rate` → resolved live rate) is consistent with the guard's rate fallback chain, so the two methods use the same value for the same payment.

### 3.3 `postPaymentJournal()` — journal debit line uses `applied_currency` (CONFIRMED CORRECT)
Line 1 of the cross-currency journal uses `$appliedCurrency` (e.g. `'USD'`) for `currency` and `$appliedAmount` for `debit`. This satisfies `AccountingService::postJournal()`'s enforcement that `line.currency == account.currency` for a USD-denominated payable account. ✓

### 3.4 `applied_amount` consistency between journal and outstanding (CONFIRMED CORRECT)
The `applied_amount` that is written to the `payments` row (from `preparePaymentAttributes`) is the same field read by both:
- `calculateOutstandingFor()` (to reduce the outstanding balance), and
- `postPaymentJournal()` (as the `debit` on the payable account line).

Because both read `$payment->applied_amount` from the persisted row after `Payment::create()`, any in-flight divergence is impossible. ✓

### 3.5 Cross-company account validation runs inside the DB transaction (CONFIRMED CORRECT)
The sequence in `recordSupplierPayment` and `recordPartnerPaidPurchase` is:
1. Validate `partner_liability_account_id` / `payable_account_id` **before** the `DB::transaction()` closure.
2. Validate `financial_account_id` (via `assertCurrencyMatchesAccount`) **inside** `recordPayment`, which is also inside the outer transaction.
3. Validate forex accounts (`resolveForexAccountId`) **inside** the transaction closure.

Any account validation failure rolls back the entire transaction; no partial records can persist. ✓

The rollback test in `CrossCurrencySettlementTest` (`rolls back payment and journal when forex account is invalid mid-transaction`) confirms this empirically.

### 3.6 Duplicate-journal prevention (CONFIRMED CORRECT)
`postPaymentJournal()` checks `AccountingJournal::count()` for a matching `sourceable_type/sourceable_id/status=posted` before creating a new journal. Combined with the payment immutability hook (`Payment::booted()` → `updating` throws), a second journal for the same payment cannot be created. ✓

### 3.7 Rate convention is uniform (CONFIRMED CORRECT)
The SYP-per-USD (base-per-foreign) convention is used consistently across:
- `resolveExchangeRate()` (looks up `ExchangeRate` where `currency=USD, base_currency=SYP`),
- `guardPaymentAgainstOverpayment()` (`bcdiv`),
- `calculateOutstandingFor()` (`bcdiv`),
- `postPaymentJournal()` (`applied_exchange_rate` stored as R₀; `bcmul($appliedAmount, $appliedRate)` for carrying value — USD × SYP/USD = SYP ✓),
- `AccountingService::convertAmount()` (`bcmul(amount, rate)` — line amount × SYP/USD = SYP ✓).

No path assumes the inverse convention. ✓

### 3.8 Positive-amount enforcement (CONFIRMED CORRECT)
`ensurePaymentIsPositive()` enforces `amount > 0` and `exchange_rate > 0` before `Payment::create()`. `AccountingService::addJournalLine()` separately enforces `debit ≥ 0` and `credit ≥ 0` and rejects lines with both zero debit and credit. ✓

### 3.9 `AccountingService::applyBaseAmounts()` zero-rate guard (CONFIRMED CORRECT)
`applyBaseAmounts()` in `AccountingService` throws `ValidationException` if `exchange_rate ≤ 0` when computing base amounts for journal lines. This provides a second layer of protection against zero-rate journal corruption. ✓

---

## 4. Potential Defects or Risks

### 4.1 (RISK) Caller-supplied `applied_amount` in an inconsistent currency can be accepted silently

**Location:** `preparePaymentAttributes()`, condition at approx. line 1035–1045.

**Condition:** The caller explicitly supplies **both** `applied_amount` and `applied_currency`, where:
- `applied_currency` matches the purchase currency (e.g., `'USD'`), so no auto-derivation occurs.
- But `applied_amount` is expressed in the wrong units — e.g., the caller passes the SYP raw amount in `applied_amount` without dividing by the rate.

Example call (theoretical but reachable through `recordPayment`):
```php
$paymentService->recordPayment([
    'amount'           => '12500000',  // SYP
    'currency'         => 'SYP',
    'applied_amount'   => '12500000',  // ← wrong: should be 1000 USD, not 12,500,000 USD
    'applied_currency' => 'USD',       // ← says USD
    'applied_exchange_rate' => '12000',
    ...
]);
```

**What happens:**
- `preparePaymentAttributes` detects `applied_currency == currency` is false (`USD ≠ SYP`) but `isset($attributes['applied_amount'])` is true, so it takes the `else` branch and uses the caller-supplied `12500000` as-is.
- `guardPaymentAgainstOverpayment` reads `applied_currency = 'USD'` which equals `payableCurrency = 'USD'` → **no conversion**, compares `12,500,000 USD > 1,000 USD outstanding → REJECT** (guard fires correctly here by accident — the value is so large it always exceeds outstanding).
- But if a caller supplies `applied_amount = 999` with `applied_currency = 'USD'` on a 1000-USD purchase while sending 12,500,000 SYP, the guard accepts it, the payment is written, and `calculateOutstandingFor` will later read `applied_currency = 'USD'`, find `amountCurrency == payableCurrency`, and subtract 999 USD — leaving 1 USD outstanding even though the full SYP amount was sent.

**Severity:** Medium risk. Not exploitable through `recordSupplierPayment` (which always derives `applied_amount` from the purchase currency when no `applied_currency` is given), but it is reachable through `recordPayment` directly. The regression test for `bcdiv` exercises the `applied_currency = 'SYP'` path; the inconsistent `applied_currency = 'USD'` with wrong applied_amount is not tested.

**Classification:** Theoretical risk given current callers. Direct callers of `recordPayment` can introduce this inconsistency.

---

### 4.2 (RISK) `applied_exchange_rate` is not validated for positivity in `preparePaymentAttributes`

**Location:** `preparePaymentAttributes()`, approx. line 1024–1042.

**Condition:** If the caller explicitly passes `applied_exchange_rate = '0'` (or omits it when `payableOriginalRate` is null and `currency == payableCurrency`), `$appliedExchangeRate` becomes `'0'`. The cross-currency derivation path then hits:
```php
$derivedApplied = bccomp($appliedExchangeRate, '0', 10) > 0
    ? bcdiv($amount, $appliedExchangeRate, 10)
    : '0';
```
This correctly short-circuits to `'0'` rather than dividing by zero, so no PHP exception is thrown. However, `applied_amount = '0'` on a non-zero purchase **passes** `guardPaymentAgainstOverpayment` (0 ≤ outstanding), and the payment is written with `applied_amount = 0`. The outstanding balance calculation will then not reduce at all, leaving the full outstanding visible even though cash left the company.

**Severity:** Medium risk. `ensurePaymentIsPositive` does not validate `applied_exchange_rate`. The only guard against zero `applied_exchange_rate` is `resolveExchangeRate` (which throws if the looked-up rate is ≤ 0), but that path is only taken if neither `attributes['applied_exchange_rate']` nor `payableOriginalRate` is available.

**Classification:** Theoretical risk, reachable by passing `applied_exchange_rate = '0'` to `recordPayment`.

---

### 4.3 (RISK) `normalizeAmount` in `PaymentService` passes negative values through silently

**Location:** `PaymentService::normalizeAmount()`, approx. line 1315–1334.

**Condition:** The regex `/^\d+\.\d+$/` does not match negative numbers (no `^-?`). For inputs like `'-100'`:
- `is_numeric('-100')` returns `true` (passes the numeric check).
- `preg_match('/^\d+\.\d+$/', '-100')` returns 0 (no match).
- Returns `'-100'` unchanged via the fallback `: $value` branch.

Downstream calls to `bccomp($amount, '0', 10)` and `bcadd/bcsub` will accept the negative string and compute with it.

**Effect:**
- A negative `amount` would pass `normalizeAmount` but be caught by `ensurePaymentIsPositive` (`bccomp <= 0 → throw`).
- A negative `applied_amount` supplied by the caller **is not caught** — `ensurePaymentIsPositive` only checks `amount` and `exchange_rate`. The guard compares `applied_amount > outstanding`, and a negative value always compares as less than outstanding, so the guard silently passes. The payment is written with a negative `applied_amount`, which would *increase* the outstanding balance.

**Note:** `AccountingService::normalizeAmount` has the same pattern but `addJournalLine` catches negative debit/credit values. The PaymentService model layer (`Payment::$casts`) will cast amounts as `decimal:4`, which truncates negative values correctly, but the service-level guard is bypassed.

**Classification:** Theoretical risk — current callers never pass negative amounts, and `ensurePaymentIsPositive` guards `amount`. The gap is specifically `applied_amount < 0`.

---

### 4.4 (RISK) Inconsistent `applied_currency = 'USD'` with `applied_exchange_rate = 1` silently accepted

**Location:** `preparePaymentAttributes()` and `guardPaymentAgainstOverpayment()`.

**Condition:** Caller passes:
```php
'applied_currency'      => 'USD',
'applied_exchange_rate' => '1',
'applied_amount'        => '1000',   // on a 1000 USD purchase
```
while `currency = 'SYP'` and `amount = 1000` (not 12,000,000 SYP).

**What happens:** `applied_currency ('USD') == payableCurrency ('USD')` → guard does no conversion, compares `1000 USD ≤ 1000 USD outstanding → accepted`. But the journal's carrying value will be computed as `1000 × 1 = 1,000 SYP` (not 12,000,000 SYP). The `base_amount` (actual cash) is `1000 SYP`, matching — so the journal technically balances, but it misrepresents the actual economic transaction.

There is no explicit check that `applied_exchange_rate` is consistent with the actual exchange rate between `currency` and `applied_currency`. A caller can set any rate.

**Classification:** Theoretical risk given current callers (high-level entry points set sensible defaults). A future UI form could inadvertently pass incorrect rates. This is a validation gap, not a confirmed defect.

---

## 5. Gaps in the Validation Contract

| Gap | Location | Effect if triggered | Priority |
|---|---|---|---|
| `applied_amount < 0` not rejected | `preparePaymentAttributes` / `guardPaymentAgainstOverpayment` | Outstanding increases instead of decreasing | Medium |
| `applied_exchange_rate = 0` causes `applied_amount = 0` | `preparePaymentAttributes` (bcdiv guard) | Payment written with zero applied amount, outstanding unchanged | Medium |
| No consistency check: `applied_currency` vs. `applied_exchange_rate` | `preparePaymentAttributes` | Carrying value on journal uses wrong rate | Low |
| `base_amount` can be zero even with non-zero `amount` if caller explicitly passes `base_amount = 0` | `preparePaymentAttributes` | Journal line `base_debit/credit = 0`, misleading ledger | Low |
| No upper-bound check on `applied_exchange_rate` | `preparePaymentAttributes` | Extremely large rate causes negligible `applied_amount` (bcdiv), guard accepts any amount | Low |
| Malformed string like `'1,000'` | `normalizeAmount` | `is_numeric` returns false → `ValidationException` thrown with "financial amount must be numeric" — correct behavior, but may surface to UI as a cryptic error | Informational |
| Extremely large bcmath strings | All bcmath calls | bcmath handles arbitrary precision — no practical limit. Silent pass-through for numbers like `'99999999999999999999'` | Informational |

---

## 6. Duplicate / Conflicting Conversion Logic

### 6.1 Currency conversion logic appears in three separate locations

| Location | Operation | Direction | Rate used |
|---|---|---|---|
| `calculateOutstandingFor()` | SYP → USD | `bcdiv($paymentAmount, $rate, 10)` | `applied_exchange_rate ?? exchange_rate ?? live rate` |
| `guardPaymentAgainstOverpayment()` | SYP → USD | `bcdiv($appliedAmount, $rate, 10)` | `applied_exchange_rate ?? exchange_rate ?? live rate` |
| `postPaymentJournal()` | USD → SYP (carrying) | `bcmul($appliedAmount, $appliedRate, 10)` | `applied_exchange_rate` (R₀, from stored payment) |
| `preparePaymentAttributes()` (derive `applied_amount`) | SYP → USD | `bcdiv($amount, $appliedExchangeRate, 10)` | `applied_exchange_rate` |
| `AccountingService::convertAmount()` | any → base | `bcmul($amount, $exchangeRate, 10)` | caller-supplied |

Both `calculateOutstandingFor` and `guardPaymentAgainstOverpayment` contain **identical** rate-fallback chains and `bcdiv` logic. If one is ever modified (e.g., to support a different convention), the other must be updated in lockstep — a **drift risk**.

### 6.2 Rate fallback chain is duplicated verbatim

Both `guardPaymentAgainstOverpayment` and `calculateOutstandingFor` implement:
```
rate = storedRate > 0 ? storedRate : resolveExchangeRate(...)
```
with the same priority order: `applied_exchange_rate → exchange_rate → live lookup`. This identical logic appears in two private methods (~30 lines apart). Any future requirement to add a third fallback (e.g., a snapshot rate at `paid_at`) would require parallel changes.

### 6.3 `convertAmount` in `PaymentService` vs `AccountingService`

`PaymentService::convertAmount` (`private`) and `AccountingService::convertAmount` (`private`) are separate implementations that both do `bcmul(amount, rate, 10)` for cross-currency and return `$amount` for same-currency. They are not connected. A divergence (e.g., one moves to 12 decimal places) would not be caught by tests that only exercise one path.

### 6.4 `normalizeAmount` is duplicated between services

`PaymentService::normalizeAmount` and `AccountingService::normalizeAmount` are independent copies with **different behavior**:
- `PaymentService` regex: `/^\d+\.\d+$/` — only strips trailing zeros from decimal strings.
- `AccountingService` regex: same pattern.
- Both pass negative values through silently.

If one is tightened to reject negatives (e.g., `/^-?\d+…/` → `throw`) the other is not automatically fixed.

---

## 7. Cross-Company Account Reference Validation — Timing

The validation sequence in `recordSupplierPayment` is:

1. **Before transaction:** `resolveAccountId(partner_liability_account_id)` — company-ownership check.
2. **Inside transaction, before write:** `resolveForexAccountId(gain/loss)` — company-ownership check.
3. **Inside transaction, before write:** `assertCurrencyMatchesAccount` (in `recordPayment`) — company-ownership check for `financial_account_id`.
4. **Inside transaction, after write:** post-write guard (outstanding ≥ 0).

All cross-company account checks run **before** any persistent row is written (the `Payment::create` and `AccountingJournal::create` calls happen after all validation completes). This is confirmed by the rollback test. ✓

However, step 1 (`resolveAccountId`) runs **outside** the `DB::transaction` closure. If the database had been modified between the pre-transaction validation and the transaction's first write (extremely unlikely in practice), a non-existent account would pass step 1 but fail when the foreign key constraint fires during `Payment::create`. This edge case is inherent to the current architecture and does not represent a practical risk.

---

## 8. Recommended Next Steps (Priority Order)

### P1 — Add `applied_exchange_rate > 0` validation in `ensurePaymentIsPositive`

**Risk addressed:** §4.2 — zero `applied_exchange_rate` silently sets `applied_amount = 0`.

```php
// In ensurePaymentIsPositive():
if (isset($payload['applied_exchange_rate'])
    && bccomp($payload['applied_exchange_rate'] ?? '0', '0', 10) <= 0) {
    throw ValidationException::withMessages([
        'applied_exchange_rate' => 'The applied exchange rate must be greater than zero.',
    ]);
}
```

Add a targeted test: supply `applied_exchange_rate = '0'` to `recordPayment` for a cross-currency purchase and assert `ValidationException` is thrown.

---

### P2 — Add `applied_amount > 0` validation when a payable is linked

**Risk addressed:** §4.3 — negative `applied_amount` increases outstanding.

In `guardPaymentAgainstOverpayment()` (or `ensurePaymentIsPositive`), after resolving `$appliedAmount`:

```php
if (bccomp($appliedAmount, '0', 10) <= 0) {
    throw ValidationException::withMessages([
        'applied_amount' => 'The applied amount must be greater than zero.',
    ]);
}
```

---

### P3 — Extract the shared rate-fallback logic into a private helper

**Risk addressed:** §6.2 — duplicated rate-fallback chain between `guardPaymentAgainstOverpayment` and `calculateOutstandingFor`.

Create `private function resolveConversionRate(array|object $source, string $fromCurrency, string $toCurrency, Company $company): string` that encapsulates the `applied_exchange_rate → exchange_rate → live lookup` chain. Both methods call it. This eliminates the drift risk.

---

### P4 — Add a consistency check for `applied_currency` + `applied_exchange_rate`

**Risk addressed:** §4.4 — caller can supply `applied_currency = 'USD'` with `applied_exchange_rate = 1` on a SYP payment.

In `preparePaymentAttributes`, after resolving `$appliedExchangeRate`:

```php
// If applied currency differs from payment currency and the rate equals 1,
// warn (or reject) — this is almost certainly a caller error.
if ($appliedCurrency !== $currency && bccomp($appliedExchangeRate, '1', 10) === 0) {
    // Consider throwing, or at minimum logging.
}
```

Whether to throw or merely log depends on whether any legitimate scenario requires an effective 1:1 cross-currency rate (e.g., USD ↔ USD between accounts with different currency labels).

---

### P5 — Improve `normalizeAmount` regex to explicitly reject negative values

**Risk addressed:** §4.3, §5.

Change the regex in `PaymentService::normalizeAmount`:
```php
// Current:  '/^\d+\.\d+$/'  — only matches positive decimals; falls through for integers and negatives
// Proposed: explicitly check for negative BEFORE the regex
if (str_starts_with($value, '-')) {
    throw ValidationException::withMessages([
        $field => 'Financial amounts must not be negative.',
    ]);
}
```

Apply the same fix to `AccountingService::normalizeAmount`. This makes the behavior explicit and removes reliance on downstream checks.

---

### P6 (Informational) — Document the `convertAmount` parameter-order anomaly

**Location:** `preparePaymentAttributes`, line ~982:
```php
$this->convertAmount($amount, $exchangeRate, $baseCurrency, $currency)
```
The `from/to` parameters appear reversed (from=baseCurrency, to=currency) but the arithmetic is correct because `convertAmount` only uses `from/to` to detect the same-currency short-circuit. Add a clarifying comment or fix the argument order to reduce future confusion:
```php
// Converting payment currency → base currency (multiply by rate)
$this->convertAmount($amount, $exchangeRate, $currency, $baseCurrency)
```

---

## Summary

The primary structural fix — replacing `bcmul` with `bcdiv` in `guardPaymentAgainstOverpayment` — is **correct and verified**. The rate convention (SYP-per-USD base-per-foreign) is applied **uniformly** across all conversion paths. The `applied_amount` used in the journal debit leg is the **same field** used by the outstanding balance calculation. Cross-company account checks all run **before** any persistent write within the same transaction.

The main residual risk is that `applied_exchange_rate = 0` silently zeroes `applied_amount` without a validation error (P1), and that negative `applied_amount` values are not explicitly caught (P2). Both are theoretical under current callers but reachable through the public `recordPayment` method. The duplicated rate-fallback logic (P3) is the most significant maintenance risk for future regressions.
