# Supplier Outstanding Balances vs. General Ledger — Read-Only Audit

**Scope:** Supplier (purchase) side of the Laravel ERP at `/Users/admin/Herd/company-finance-ERP`.
**Mode:** Strictly read-only. No files were edited, created, or deleted (except this report). No migrations were run. The test suite was not executed (it would migrate the test DB); the stated baseline is taken as given.
**Baseline (as reported):** 227 tests, 607 assertions, 0 failures.

All claims below are grounded in source I actually read. Where a behavior could not be confirmed from code, it is marked **UNVERIFIED**.

---

## A. Exact operational outstanding calculation

### A.1 The query, filters, and arithmetic

**File/method:** `app/Services/PaymentService.php` → `calculateOutstandingFor(object $payable): string`
(also exposed as `calculateOutstandingForPurchase()` and `calculateOutstandingForRevenue()`, both thin delegates; `PurchaseService::calculateOutstanding()` also delegates here — there is exactly **one** authoritative implementation).

```php
$total = $payable->total ?? $payable->base_total ?? $payable->amount ?? '0';   // NATIVE currency
$payableCurrency = $payable->currency ?? $payable->base_currency ?? 'SYP';

$payments = Payment::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', $companyId)
    ->where('payable_type', get_class($payable))
    ->where('payable_id',   $payable->getKey())
    ->where('status', 'posted')          // <-- only posted payments
    ->get();

$paid = '0';
foreach ($payments as $payment) {
    $paymentAmount = $payment->applied_amount ?? $payment->amount ?? '0';
    $amountCurrency = $payment->applied_currency ?? $payment->currency ?? $payableCurrency;
    if ($amountCurrency !== $payableCurrency) {
        $rate = $this->resolveConversionRate($amountCurrency, $payableCurrency, $payment, $company);
        $paymentAmount = bcdiv($paymentAmount, $rate, 10);   // DIVIDE
    }
    $paid = bcadd($paid, $paymentAmount, 10);
}
$outstanding = bcsub($total, $paid, 10);
return bccomp($outstanding, '0', 10) < 0 ? '0' : $outstanding;   // clamp at 0
```

**Filters:** `company_id` (= payable's `company_id`), `payable_type` = `get_class($payable)`, `payable_id` = payable's key, `status = 'posted'`. `CompanyScope` is bypassed (it fails closed with no session) and replaced by the explicit `company_id` filter, so tenant isolation is preserved.

**Arithmetic:** `outstanding = total − Σ(applied_amount, converted to payable currency)`. All math is bcmath, scale 10.

### A.2 Partial payments
Each posted payment's `applied_amount` is summed. Example (same currency, SYP): purchase `total = 1000`, two posted payments `applied_amount = 400` and `200` → `paid = 600`, `outstanding = 400`. Verified by `tests/Feature/PaymentOutstandingTest.php` (tests 3, 4) and `PaymentIntegrityTest.php`.

### A.3 Cross-currency
The payable's **native** currency is the unit of measure (`$payable->total`, e.g. USD). Each payment contributes `applied_amount`; if its `applied_currency` differs from the payable currency, it is **divided** by the rate (`bcdiv`), never multiplied. The rate is **base-per-foreign** (e.g. 12000 SYP per 1 USD), so `USD = SYP ÷ (SYP-per-USD)`.

In practice, `preparePaymentAttributes()` normalizes `applied_currency` to the payable's native currency whenever the payment currency differs (unless the caller sets it explicitly), so the divide branch is a fallback. The rate used is `resolveConversionRate()` with this priority: same currency → `'1'`; `applied_exchange_rate` if > 0; `exchange_rate` if > 0; live `ExchangeRate` lookup; else throw.

Verified by `tests/Feature/CrossCurrencySettlementTest.php` ("calculateOutstandingFor divides (not multiplies)…") and `PaymentIntegrityHardeningTest.php` (test 11, guard/Calculator rate consistency).

### A.4 Reversed payments
A reversal payment is created **without** `payable_type`/`payable_id` (intentionally omitted in `reversePayment()`), and the original payment's `status` transitions `posted → reversed`. Because the query filters both `payable_type`/`payable_id` **and** `status = 'posted'`, the original (now `'reversed'`) and the reversal (no payable link) are **both excluded**. Outstanding is therefore restored to the full total after a reversal. Verified by `PaymentReversalTest.php` (tests 2, 10, 18).

### A.5 Draft payments
Excluded — the query filters `status = 'posted'`. Verified by `PaymentOutstandingTest.php` (test 2) and `PaymentIntegrityTest.php`.

### A.6 Return value
A **string** denominated in the **payable's native currency** (the purchase's `currency`, e.g. `"600"` = 600 USD for a USD purchase). It is **not** a base-currency figure.

### A.7 Negativity / clamping
Can be negative only transiently inside the loop; the method clamps the final result to `'0'` if `outstanding < 0`. A post-write guard in `recordPayment()` also throws if outstanding goes negative after an insert (concurrent-overpayment protection). So a persisted overpayment is rejected, never silently negative.

---

## B. Exact general-ledger payable calculation

### B.1 Purchase posting — `PurchaseService::postPurchase()`
**File:** `app/Services/PurchaseService.php`. Account resolution via `resolveAccountId()` (liability for payable, asset/expense for inventory); defaults to the first matching account ordered by `code`.

```
Dr  Inventory / Expense   (asset|expense)   purchase.total   [purchase.currency, rate R₀]
Cr  Supplier Payable      (liability)       purchase.total   [purchase.currency, rate R₀]
```
Lines pass `currency = purchase->currency`, `exchange_rate = purchase->exchange_rate` (R₀), `base_currency = purchase->base_currency`. `base_debit`/`base_credit` are **not** set explicitly — `AccountingService::applyBaseAmounts()` computes `base = amount × exchange_rate` (multiply; base-per-foreign). `AccountingService::postJournal()` enforces `line.currency === financialAccount.currency` and that `Σ base_debit == Σ base_credit`.

So a USD purchase on a SYP-base company at R₀=12000 posts `Cr Payable 1000 USD`, `base_credit = 12,000,000 SYP`.

### B.2 Supplier payment — `PaymentService::recordSupplierPayment()` → `postPaymentJournal()`
Same currency:
```
Dr  Supplier Payable   (liability)   amount   [currency, rate R₁]
Cr  Cash / Bank        (asset)      amount   [currency, rate R₁]
```

### B.3 Cross-currency payment (USD purchase, SYP cash)
`isCrossCurrency = appliedCurrency !== currency`. Carrying value `carryingBase = applied_amount × applied_exchange_rate` (R₀); cash `cashBase = base_amount`; `fxDiff = cashBase − carryingBase` (positive ⇒ loss, negative ⇒ gain).

```
Dr  Supplier Payable  (liability, USD)  applied_amount USD   rate=R₀   base_debit = applied_amount×R₀
Cr  Cash / Bank       (asset,    SYP)   amount SYP            rate=1    base_credit = amount
±   Exchange Loss/Gain (expense/income)  |fxDiff| SYP         rate=1    base = |fxDiff|
```
The payable debit is in the **payable's native currency (USD)** at the **original** rate R₀ — this is the key design choice. The FX line is posted to a **separate** gain/loss account, never to the payable account. Forex accounts default from `company->exchange_gain_account_id` / `exchange_loss_account_id`.

Verified by `tests/Feature/CrossCurrencySettlementTest.php` (loss, gain, and equal-rate cases).

### B.4 Payment reversal
`PaymentService::reversePayment()` → `AccountingService::reverseJournal()`, which iterates every line of the original journal and swaps debit↔credit (and `base_debit`↔`base_credit`), copying `currency`, `exchange_rate`, `party_id`, `base_currency` unchanged. The reversal journal is a **mathematically exact inverse** of the original, including the FX line (a Dr FX Loss becomes a Cr FX Loss). The original payment → `status='reversed'`; original journal gets `reversed_by_journal_id`.

Verified by `PaymentReversalTest.php` (tests 1, 3, 4, 5 — base debits = base credits; FX line inverted).

### B.5 GL payable balance after each event (per-currency payable account)
For a single-currency liability account, `balance() = Σdebit − Σcredit` (negative for a credit balance). The payable account only ever receives lines **at R₀** (purchase credit at R₀; payment debit at R₀; reversal credit at R₀), because the FX diff is quarantined to the gain/loss accounts.

- **(a) After purchase posted:** `Cr payable = total` → balance = `−total` (native), `−total×R₀` (base).
- **(b) After full payment posted:** `Dr payable = total` → balance = `0` (native and base). FX realized in the gain/loss account only.
- **(c) After payment reversed:** the reversal `Cr payable = total` restores the balance to `−total` (native), `−total×R₀` (base) — i.e. the pre-payment state.

### B.6 Which `FinancialAccount` method is correct for multi-currency?
- `baseCurrencyBalance()` sums `base_debit − base_credit` across **all** lines — **safe** because base amounts are one currency. This is the correct aggregate method.
- `nativeCurrencyBalance($currency)` filters by `currency` — correct for a single currency slice.
- `balanceByCurrency()` — per-currency breakdown, correct.
- `balance()`, `debitTotal()`, `creditTotal()` sum **native** amounts across currencies — **deprecated/unsafe in principle**, but in practice every account is single-currency because `postJournal()` enforces `line.currency === account.currency`, so no account ever holds mixed currencies. They remain unsafe because they **do not filter by journal status** (see D3) and do not filter by party.

### B.7 Per-supplier / per-party GL filter
The `journal_lines` table has a nullable `party_id` FK (`2026_10_06_181359` migration; index `journal_lines_account_party_idx`). So per-supplier isolation **is possible** by filtering `journal_lines.party_id`. It is actually used in one place: `PaymentService::calculatePartnerLiabilityBalance(Party, FinancialAccount)` filters `journal_lines.financial_account_id` + `party_id` + `whereHas('journal', status='posted')`.

**However**, no equivalent method exists for supplier payables, and `recordSupplierPayment()` does **not** default `party_id` to `$purchase->party_id` (see D2). So per-supplier GL isolation is currently **not reliable** for supplier payments.

---

## C. Reconciliation analysis

### C.1 Same-currency full payment
Purchase SYP 1000 (posted) → `Cr Payable 1000`. Payment SYP 1000 → `Dr Payable 1000`.
- Operational outstanding = `1000 − 1000 = 0`.
- GL payable native balance = `0 − 1000 + 1000 = 0` (using `debit − credit`).
- **Reconciles: 0 = 0.** ✓ (Trace: `−total` after purchase, `+total` debit on payment → net 0.)

Note: the only test that approximates this (`PaymentAccountingTest.php` test 2) checks `creditTotal() == 800` then `debitTotal() == 800` separately — it never asserts the **net** `balance() == 0`, and the purchase credit is created **manually** (not via `postPurchase`).

### C.2 Same-currency partial payment
Purchase 1000, paid 400 → outstanding = 600. GL: `Cr 1000 − Dr 400 = 600` credit balance (i.e. `balance() = −600`, magnitude 600). **Reconciles** (magnitude). ✓ No test asserts this GL figure against outstanding.

### C.3 Cross-currency with FX loss
Purchase USD 1000 @ R₀=12000 (base SYP). Pay SYP 12,500,000 (R₁=12500), `applied_amount=1000 USD`, `applied_exchange_rate=12000`.
- GL payable (USD account) lines: `Cr 1000 USD` (purchase, base 12,000,000) and `Dr 1000 USD` (payment, base 12,000,000).
  - `nativeCurrencyBalance('USD') = 1000 − 1000 = 0` = outstanding (0). ✓
  - `baseCurrencyBalance() = 12,000,000 − 12,000,000 = 0` = outstanding × R₀ = 0 × 12000 = 0. ✓
- The 500,000 SYP FX loss lives **only** in the FX Loss account; it does **not** touch the payable account.
- **Reconciles in both native (USD) and base (SYP at R₀).** ✓

There is **no mismatch from the FX loss** because the payable account moves exclusively at R₀; the settlement/cash rate difference is fully absorbed by the separate gain/loss account. This is the intended accounting rule and it is implemented correctly.

### C.4 Reversal
After full payment + reversal: reversal `Cr Payable 1000` (base 12,000,000).
- GL payable: `Cr 1000 (purchase) − Dr 1000 (payment) + Cr 1000 (reversal) = 1000` credit → magnitude 1000 USD; base `12,000,000` SYP.
- Outstanding = 1000 USD (restored).
- **Reconciles: 1000 = 1000 (native); 12,000,000 = 1000×12000 (base).** ✓

### C.5 Shared vs. per-supplier payable account
The payable account is resolved **per transaction** and defaults to "the first liability account for the company, ordered by `code`" (`resolveAccountId()`). There is **no enforced supplier→account mapping**. Because `postJournal()` forces `line.currency === account.currency`, accounts are effectively **per-currency** (one USD payable account, one SYP payable account), **not per-supplier**. Multiple suppliers sharing a currency share one payable account.

Consequence: the aggregate GL payable balance for that account is the sum owed across **all** suppliers of that currency. It cannot, by itself, be attributed to one supplier.

### C.6 Per-supplier outstanding from the GL alone
**Not currently possible** for two reasons:
1. No supplier-payable analog of `calculatePartnerLiabilityBalance()` exists.
2. `recordSupplierPayment()` does not tag the payment's journal lines with the supplier `party_id` unless the caller passes it (D2), so the party tag is unreliable. The purchase journal lines **are** tagged (`party_id = $purchase->party_id` in `postPurchase`), but the payment debit lines often are **not**.

Therefore per-supplier outstanding must come from **operational data** (`Purchase` records + `calculateOutstandingFor()`), not from the GL. Per-currency-account GL reconciliation (summing the whole account) is possible and reconciles (C.1–C.4), but it is an aggregate, not per-supplier.

### C.7 Concrete worked example (cross-currency, FX loss, partial then full)

Setup: base = SYP. Purchase = USD 1000 @ R₀ = 12000 SYP/USD ⇒ `base_total = 12,000,000 SYP`.

**Step 1 — post purchase** (`postPurchase`):
- `Dr Inventory (USD) 1000` base_debit `12,000,000`; `Cr Payable (USD) 1000` base_credit `12,000,000`.
- GL Payable(USD): `balance() = −1000`; `baseCurrencyBalance() = −12,000,000`.
- Outstanding = `1000 USD`.

**Step 2 — partial payment** (SYP 6,100,000, R₁=12200, `applied=500 USD`, `applied_rate=12000`):
- `carryingBase = 500×12000 = 6,000,000`; `cashBase = 6,100,000`; `fxDiff = +100,000` (loss).
- `Dr Payable(USD) 500` base `6,000,000`; `Cr Cash(SYP) 6,100,000`; `Dr FX Loss(SYP) 100,000`.
- GL Payable(USD): `nativeCurrencyBalance('USD') = 500 − 1000 = −500` ⇒ 500 owed; `baseCurrencyBalance() = 6,000,000 − 12,000,000 = −6,000,000`.
- Outstanding = `1000 − 500 = 500 USD`.
- Reconcile native: 500 = 500 ✓. Reconcile base: 6,000,000 = 500×12000 ✓.

**Step 3 — second payment** (SYP 5,950,000, R₂=11900, `applied=500 USD`, `applied_rate=12000`):
- `carryingBase = 6,000,000`; `cashBase = 5,950,000`; `fxDiff = −50,000` (gain).
- `Dr Payable(USD) 500` base `6,000,000`; `Cr Cash(SYP) 5,950,000`; `Cr FX Gain(SYP) 50,000`.
- GL Payable(USD): native `1000 − 1000 = 0`; base `12,000,000 − 12,000,000 = 0`.
- Outstanding = 0.
- Reconcile: 0 = 0 (native and base). ✓ FX P&L net = `+100,000 loss − 50,000 gain` realized in the gain/loss accounts, never affecting the payable balance.

---

## D. Defects ranked by severity

### D1 — HIGH: No end-to-end reconciliation test exists (test gap / regression risk)
- **Where:** across the suite; the missing assertion. Every supplier test creates the purchase **directly** with `status='posted'` (helpers `makePurchase`, `makePurchaseForAccounting`, `makeReversalPurchase`, `makeCrossUsdPurchase`, `makePostedPurchase`, `makePostedPurchaseForIntegrity`, `makeHardeningPurchase`). **None** calls `PurchaseService::postPurchase()` to create the `Cr Payable` journal before recording a payment. The only `postPurchase` tests (`AccountingIntegrityPhase1Test.php`, `AccountingEngineTest.php`) assert status/total only — never the GL account balance.
- **Trigger:** any change that breaks the purchase→payment ledger linkage.
- **Effect:** the operational view and the GL view are **never compared** in any test. A silent divergence between `calculateOutstandingFor()` and the payable account balance would not be caught.
- **Tested:** No.

### D2 — HIGH: `recordSupplierPayment()` does not default `party_id` to the purchase's supplier
- **Where:** `app/Services/PaymentService.php`, `recordSupplierPayment()` — the merged payload defaults omit `party_id`; `preparePaymentAttributes()` sets `'party_id' => $attributes['party_id'] ?? null`. (Contrast `recordPartnerPaidPurchase()`, which **requires** `party_id`.)
- **Trigger:** caller omits `party_id` (all `recordSupplierPayment` tests in `PaymentAccountingTest.php` and `CrossCurrencySettlementTest.php` omit it).
- **Effect:** the payment and its payable-debit journal lines get `party_id = NULL`. Per-supplier GL filtering (the only mechanism to isolate one supplier in a shared account) therefore misses the payment side. A per-supplier GL payable balance would equal the full purchase credit with no offsetting debit — overstating what is owed.
- **Tested:** No test asserts `party_id` is set on supplier-payment journal lines, nor that per-supplier GL balances reconcile.

### D3 — MEDIUM: `FinancialAccount` balance methods do not filter by journal status
- **Where:** `app/Models/FinancialAccount.php` — `balance()`, `debitTotal()`, `creditTotal()`, `nativeCurrencyBalance()`, `baseCurrencyBalance()`, `balanceByCurrency()` all read `$this->journalLines()` with **no** `whereHas('journal', status='posted')`.
- **Trigger:** any draft journal that has lines persisted (e.g. a future code path using `createDraftJournal()` + `addJournalLine()` without `postJournal()`, or a partial state not rolled back).
- **Effect:** GL balances would include unposted lines while operational outstanding counts only `status='posted'` payments — the two views use different status filters and could disagree. Currently latent because services post journals inside a single `DB::transaction` (draft journals with lines don't persist on failure). The correct pattern already exists in `calculatePartnerLiabilityBalance()`, which **does** filter `whereHas('journal', status='posted')`.
- **Tested:** No (all test journals are `posted`).

### D4 — MEDIUM: No GL-side method for per-supplier payable balance
- **Where:** missing method (compare `PaymentService::calculatePartnerLiabilityBalance()` / `calculatePartnerLiabilityBalanceByCurrency()`, which exist for the due-to-partner account).
- **Trigger:** any per-supplier report or reconciliation.
- **Effect:** per-supplier payable balance cannot be read from the GL through a service method; it must be hand-computed, and is further broken by D2.
- **Tested:** No.

### D5 — LOW (footgun): `balance()` sign convention is debit−credit (negative for liabilities)
- **Where:** `app/Models/FinancialAccount.php`, `balance()`.
- **Trigger:** a naive reconciliation comparing `payableAccount->balance()` to a positive outstanding.
- **Effect:** liability credit balances are negative; `balance() == outstanding` is always false for an owed amount. The correct liability comparison is `creditTotal() − debitTotal()` (= `−balance()`). Confirmed by `AccountingEngineTest.php` (liability `balance() = -200` for a 200 credit).
- **Tested:** behavior is documented; no reconciliation uses it.

### D6 — LOW (test-scaffolding bug): cross-currency "preserve purchase journal" test posts both lines to the same payable account
- **Where:** `tests/Feature/CrossCurrencySettlementTest.php`, test "preserves the original purchase journal unchanged after cross-currency settlement" — both `addJournalLine()` calls use `financial_account_id => $usdPayable->id` (a debit and a credit on the **same** payable account).
- **Trigger:** reading/running the test.
- **Effect:** the test's "purchase journal" does not model `postPurchase()` (which debits a separate inventory/expense account). It nets to zero on the payable account, so it provides no real coverage of the purchase→payable credit. The test still passes its narrow assertion (line IDs unchanged) but is misleading.
- **Tested:** the test passes; the defect is in the scaffold, not the service.

### D7 — LOW (usability footgun): derived `applied_amount` for cross-currency uses R₀, not the settlement rate
- **Where:** `app/Services/PaymentService.php`, `preparePaymentAttributes()` — when `applied_currency ≠ currency` and no explicit `applied_amount` is given, `applied_amount = amount ÷ applied_exchange_rate` where `applied_exchange_rate` defaults to the payable's **original** rate R₀.
- **Trigger:** cross-currency settlement where the caller omits `applied_amount`.
- **Effect:** e.g. paying 12,500,000 SYP for a 1000 USD purchase derives `applied_amount = 12,500,000 ÷ 12000 = 1041.67 USD` (overpayment), which the guard then rejects. Callers **must** pass `applied_amount` (the USD face value being cleared) for cross-currency; all tests do. Not a correctness bug, but a sharp edge.
- **Tested:** indirectly (tests always supply `applied_amount`).

---

## E. Missing tests and recommended expected values

### E.1 Tests that independently verify outstanding
`PaymentOutstandingTest.php`, `PaymentIntegrityTest.php`, `MultiCurrencyArchitectureTest.php` derive expected values from first principles (e.g. `1000 − 400 = 600`) and set payments via direct row inserts — **independent** of `calculateOutstandingFor()`. These are sound for the **operational** side, same-currency and the divide-direction cross-currency case. They do **not** touch the GL.

### E.2 Tests that verify GL payable account balance
- `AccountingEngineTest.php` verifies `balance()`/`debitTotal()`/`creditTotal()` and the multi-currency balance methods on **manually-created** journals.
- `PaymentAccountingTest.php` test 2 verifies `creditTotal()` then `debitTotal()` after a **manually-created** purchase credit + a real payment — same currency only, never the net balance, never via `postPurchase`.

**There is no test that posts a purchase via `postPurchase()` and then verifies the GL payable account balance.** This is the central gap.

### E.3 Recommended reconciliation tests (independently computed expected values)
All expected values below are computed from first principles, **not** from the service under test. Use a fresh company, a real `PurchaseService::postPurchase()` (with inventory + warehouse), then the payment, then assert GL = operational.

1. **Same-currency full payment**
   - Post purchase SYP 1000 → `Cr Payable 1000`.
   - `recordSupplierPayment` SYP 1000 → `Dr Payable 1000`.
   - Expected: `payable->baseCurrencyBalance() == '0'`; `calculateOutstandingFor() == '0'`; `cash->debitTotal() == '1000'`.

2. **Same-currency partial payment**
   - Post purchase 1000; pay 400.
   - Expected: `payable->balance() == '-600'` (or `creditTotal() − debitTotal() == '600'`); `baseCurrencyBalance() == '-600'`; `calculateOutstandingFor() == '600'`. Assert `|baseCurrencyBalance()| == outstanding`.

3. **Cross-currency with FX loss** (the C.7 example)
   - Post purchase USD 1000 @ R₀=12000 (base SYP) → `Cr Payable(USD) 1000`, base 12,000,000.
   - Pay SYP 12,500,000, `applied=1000 USD`, `applied_rate=12000` (R₁=12500 ⇒ loss).
   - Expected independently: `payable->nativeCurrencyBalance('USD') == '0'`; `payable->baseCurrencyBalance() == '0'`; `calculateOutstandingFor() == '0'`; `fxLoss->debitTotal() == '500000'`; `cash->creditTotal() == '12500000'`.

4. **Cross-currency partial then verify base-currency reconciliation at R₀**
   - Post purchase USD 1000 @ 12000; pay `applied=500 USD` (any SYP cash, any R₁).
   - Expected: `|payable->baseCurrencyBalance()| == '6000000'` (= 500 × 12000); `calculateOutstandingFor() == '500'`; and `|baseCurrencyBalance()| == outstanding × '12000'`.

5. **Reversal restores both views**
   - Full same-currency payment then `reversePayment()`.
   - Expected: `payable->baseCurrencyBalance() == '-1000'` (restored); `calculateOutstandingFor() == '1000'` (restored); `cash->balance() == '0'` (Dr then Cr cancel).

6. **Per-supplier isolation (after fixing D2)**
   - Two suppliers, same SYP payable account, one purchase each (1000 and 800), each paid in full.
   - Expected: `payable->baseCurrencyBalance() == '0'` (account nets to zero) **and** each `calculateOutstandingFor() == '0'`. Then, filtering `journal_lines` by each `party_id` (once tagging is fixed), each supplier's `credit − debit == '0'`.

### E.4 Independently computed expected values (summary)
The values above are arithmetic from the postings, not from `calculateOutstandingFor()` or `baseCurrencyBalance()`. The reconciliation invariant being tested is:
- Native: `creditTotal(payable) − debitTotal(payable) == Σ outstanding over purchases in that currency` (per-currency account).
- Base: `|baseCurrencyBalance(payable)| == Σ (outstanding × R₀)` for that account, because every payable line moves at R₀.

---

## F. Reconciliation enforcement recommendation

**Recommendation: both — a diagnostic report method first, graduating to an enforced invariant on the write path for the per-document case.**

### The data relationships that make reconciliation possible
- The payable account is **per-currency** (enforced by `line.currency === account.currency`), so an account's native balance is a single-currency sum.
- Every payable line moves at the **original purchase rate R₀** (purchase credit at R₀; payment debit at R₀; reversal at R₀). FX diffs are isolated to gain/loss accounts. Therefore **`|baseCurrencyBalance(payable)| == Σ(outstanding × R₀)`** holds by construction, **provided**:
  1. every purchase is posted via `postPurchase()` (creates the `Cr Payable`), and
  2. every payment's payable-debit uses `applied_exchange_rate = R₀` (it does, by default), and
  3. balances are computed over **posted** journals only (currently false in the model methods — D3), and
  4. the same payable account is used consistently for a given currency (no enforced mapping today).

### Trade-offs
- **Enforced invariant (throw on disagreement):** strongest guarantee, catches divergence at write time. But it requires the GL query to be cheap and the relationship to be exact. Per-**document** it is cheap and exact (`outstanding(purchase)` ↔ the purchase's payable credit − its payment debits, filterable by `payable_id`-equivalent via the payment's `payable_type`/`payable_id` on the **operational** side, but the GL has no `payable_id` on journal lines — only `party_id`). So a per-document GL invariant is **not directly expressible** today because `journal_lines` carry no link back to the source `Purchase`/`Payment` row. Only `accounting_journals.sourceable_id` links to the document, and aggregating through journals is heavier.
- **Per-currency-account invariant** (sum of outstanding for all purchases using that account × R₀ vs `baseCurrencyBalance`): expressible and cheap, but requires knowing which purchases map to which payable account — which is not stored (accounts are chosen per-call). So it is only safe if a single payable account per currency is the enforced convention.
- **Per-supplier invariant:** requires `party_id` on every line (D2) plus a supplier-payable balance method (D4). Not currently enforceable.

### Recommended path
1. **Diagnostic report method** (low risk, high value): a read-only `PaymentService::reconcileSupplierPayables(Company): array` that, per currency/payable account, compares `|baseCurrencyBalance()|` against `Σ(outstanding × R₀)` over posted purchases, returning per-account and per-supplier (via `party_id`) deltas with the expected values from E.3. This surfaces divergence without blocking writes and is the right first step given the missing per-document GL linkage and the D2/D3 gaps.
2. **Enforced invariant (later):** once (a) `party_id` is always set on supplier-payment lines, (b) the model balance methods filter posted journals, and (c) a payable-account convention is fixed (ideally one payable account per currency, or a stored supplier→account map), enforce the **per-supplier, per-currency** invariant inside `recordSupplierPayment()`/`reversePayment()`: throw if the resulting per-supplier GL payable balance disagrees with that supplier's summed outstanding. Do not enforce before those prerequisites exist, or the invariant will fire spuriously.

---

## G. Go/no-go for UI

**Conditional GO** for a first UI phase that is **read-only / display-oriented**. **NO-GO** for any UI that creates, edits, or reverses supplier payments until the conditions below are met.

### Conditions for a read-only UI (supplier balances, outstanding, payable listings)
- The operational outstanding (`calculateOutstandingFor`) is correct and well-tested for posted/draft/reversed/partial/cross-currency cases. Displaying per-purchase and per-supplier outstanding from operational data is safe.
- **Caveat (must surface in the UI, not hide):** a per-supplier "payable balance" pulled from the GL is **not reliable today** because of D2 (un-tagged payment lines) and D4 (no method). A UI must not present a single "supplier GL payable balance" as authoritative until D2/D4 are fixed; it should show operational outstanding as the source of truth and label any GL figure as aggregate/unreconciled.
- The aggregate per-currency-account GL balance does reconcile (C.1–C.4) and is safe to display as an account-level total.

### Conditions that must be met before any write/UI that posts or reverses supplier payments
1. Fix **D2**: default `party_id` to `$purchase->party_id` in `recordSupplierPayment()` (and keep requiring it for partner-paid).
2. Fix **D3**: make `FinancialAccount` balance methods filter `whereHas('journal', status='posted')` (or add posted-only variants and use them).
3. Add the **E.3 reconciliation tests** so the purchase→payment→GL linkage is regression-protected.
4. Decide and enforce the **payable-account convention** (one per currency, or a stored supplier→account mapping) so reconciliation has a stable target.
5. Provide the **diagnostic reconciliation report** (F.1) so operators can detect divergence.

### Unverified items
- I did not run the test suite (read-only constraint; running it would migrate the test DB). The 227/607/0 baseline is taken as reported.
- No live database state was inspected; all GL-balance reasoning is derived from the code paths and the journal-line values the services produce, cross-checked against the assertions in the existing tests.
- SQLite-vs-MySQL FK behavior on reversal columns (`nullOnDelete` vs `RESTRICT`) is compensated by application-level guards in `reversePayment()`/`reverseJournal()` per the code comments; I did not execute reversals to confirm runtime behavior on this machine — **UNVERIFIED at runtime**, but the guards are present and unit-tested (`PaymentReversalTest.php` tests 12, 13, 22, 23).
