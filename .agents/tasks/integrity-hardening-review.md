# Payment integrity hardening: rate validation, negative rejection, and rate-resolution unification

Three validation gaps have been closed in `PaymentService`: cross-currency payments with a zero or negative `applied_exchange_rate` now throw `ValidationException`; negative or malformed monetary inputs are explicitly rejected by `normalizeAmount` before they can reach the database; and the previously duplicated exchange-rate fallback logic inside `guardPaymentAgainstOverpayment` and `calculateOutstandingFor` has been extracted into a single private `resolveConversionRate` helper. Fourteen regression tests covering all required and previously-identified gap scenarios are present in `PaymentIntegrityHardeningTest.php`. Both findings raised in the previous review pass have been addressed: the silent `exchange_rate` sentinel fallback is closed in `preparePaymentAttributes`, and two new tests (13 and 14) verify the live-lookup throw path for genuinely absent rates. No UI files, reversal logic, historical journal edits, or existing validations were touched.

Watch for: (confirmed) `resolveConversionRate` step 2 falls back to the payment-level `exchange_rate` field before hitting the live DB lookup — for an already-posted payment with `exchange_rate='1'` stored, `calculateOutstandingFor` would use that `'1'` as the conversion rate for cross-currency amounts without throwing. This affects read paths for historical payments, not new payment creation, but is worth noting. No blocking concern at this time since the write-path guards in `preparePaymentAttributes` and `ensurePaymentIsPositive` prevent storing an incorrect rate in the first place.

**Verdict**: APPROVED

---

## High-level view

P1 is implemented in `ensurePaymentIsPositive`: when `applied_currency ≠ currency`, the method normalizes `applied_exchange_rate` via `normalizeAmount` (which throws on negatives via the P2 `bccomp < 0` guard) and then checks `bccomp <= 0`, catching zero. Same-currency payments bypass this branch entirely.

P2 lives in `normalizeAmount` itself — a `bccomp($value, '0', 10) < 0` check was added after the `is_numeric` guard and before the regex path. This closes the integer-negative bypass where `-100` is numeric, fails the decimal regex, and would reach `return $value` unmodified. A second check in `ensurePaymentIsPositive` ensures `applied_amount > 0` whenever a payable is linked.

P3 extracts `resolveConversionRate` as a `private` method called by both `guardPaymentAgainstOverpayment` and `calculateOutstandingFor`. The fallback chain is: same-currency → `'1'`; `applied_exchange_rate > 0` → return it; `exchange_rate > 0` → return it; live DB lookup → throw on miss. The finding from the prior review — that `preparePaymentAttributes` silently fell back to `exchange_rate` for cross-currency applied rates — has been fixed: when neither an explicit rate nor a payable-derived rate is available and `appliedCurrency ≠ currency`, the code now calls `resolveExchangeRate` directly, which throws if no DB row is found.

Tests 13 and 14 cover the two previously unaddressed paths. Test 13 omits both `applied_exchange_rate` and `exchange_rate` from the payload with a null-rate purchase and no DB rows; this routes through `preparePaymentAttributes` to `resolveExchangeRate`, which throws. Test 14 provides `exchange_rate='1'` but omits `applied_exchange_rate` with a null-rate purchase and no DB rows; `preparePaymentAttributes` skips the `exchange_rate` fallback for the `applied_exchange_rate` field and calls `resolveExchangeRate`, which throws.

---

<details>
<summary>Issues (0)</summary>

No blocking issues. All prior findings have been addressed.

</details>

---

<details>
<summary>Details</summary>

### P1 guard: applied_exchange_rate validation path

`ensurePaymentIsPositive` checks `applied_currency !== paymentCurrency` and then normalizes `applied_exchange_rate` before comparing against zero with `bccomp`. For the negative-rate case, `normalizeAmount` itself throws first via the P2 guard — the observable result is a `ValidationException` as required, though the throwing site is `normalizeAmount` rather than the subsequent `bccomp` check. Tests 1 and 2 confirm zero and negative rates are rejected and no rows are created.

Test 3 was the prior concern: it passes `applied_exchange_rate: '0'` explicitly and exercises the P1 guard, not the live-lookup path. Tests 13 and 14 now cover the genuinely absent rate path. Together they provide complete coverage of the three distinct missing-rate conditions: explicit zero, truly absent from payload with no DB row, and `exchange_rate='1'` sentinel with no DB row.

### P2: negative rejection in normalizeAmount and ensurePaymentIsPositive

The `bccomp` guard in `normalizeAmount` fires before the regex, closing the integer-negative bypass. Tests 4 (`applied_amount: '-1000'`), 5 (`amount: '-12000000'`), and 6 (non-numeric `'abc'` and comma-formatted `'1,000'`) all exercise this path. The `applied_amount > 0` guard in `ensurePaymentIsPositive` catches a derived zero applied amount when a payable is linked.

### P3: resolveConversionRate unification

Both `guardPaymentAgainstOverpayment` (payload array path) and `calculateOutstandingFor` (Payment model path) call `resolveConversionRate` with identical semantics. The helper accepts either an array or an object and reads fields via a closure, making the dual usage clean. Company isolation is preserved: the `Company` model is passed through to `resolveExchangeRate` at the end of the fallback chain, which scopes its `ExchangeRate` query by `company_id`.

The step-2 fallback to `exchange_rate` in `resolveConversionRate` applies to reads against stored `Payment` models (used by `calculateOutstandingFor`). A payment stored with a correct `applied_exchange_rate` will use it at step 1; the `exchange_rate` fallback only fires if `applied_exchange_rate` is null on the stored row. Because the write-path guards prevent storing an incorrect rate, this is not a practical risk for new payments. Historical payments predating this hardening that lack `applied_exchange_rate` would use `exchange_rate` as a fallback and could produce incorrect outstanding-balance readings — worth a one-time data audit, but not a blocking issue for this phase.

### Test coverage

All 14 tests map to the required scenarios plus the two newly added gap scenarios:

- Tests 1–2: zero and negative `applied_exchange_rate` (confirmed P1 path)
- Test 3: cross-currency with explicit `applied_exchange_rate: '0'` and no DB rate
- Tests 4–5: negative `applied_amount` and negative `amount` (confirmed P2 path)
- Test 6: malformed input — non-numeric and comma-formatted amounts
- Test 7: valid same-currency SYP payment without ExchangeRate record
- Test 8: valid cross-currency regression
- Test 9: outstanding balance unchanged after rejected payment
- Test 10: no partial records after validation failure (three scenarios batched)
- Test 11: consistency between overpayment guard and outstanding-balance calculator
- Test 12: tenant isolation — Company B's rate record does not bleed into Company A
- Test 13 (new): absent `applied_exchange_rate` and `exchange_rate` with null-rate purchase and no DB row → live-lookup throw
- Test 14 (new): `exchange_rate='1'` sentinel in payload with null-rate purchase and no DB row → live-lookup throw

The batching in Test 10 (three scenarios in one `foreach`) means a failure in scenario 2 or 3 will be attributed to the test as a whole. Not blocking, but worth noting if a future failure needs to be localized.

</details>

---

<details>
<summary>File map</summary>

- `app/Services/PaymentService.php` — P1 check in `ensurePaymentIsPositive`; P2 `bccomp` guard in `normalizeAmount`; P2 applied-amount check in `ensurePaymentIsPositive`; `resolveConversionRate` private helper extracted; `preparePaymentAttributes` fallback for `applied_exchange_rate` changed to call `resolveExchangeRate` (throw) instead of reusing `exchange_rate`; both `guardPaymentAgainstOverpayment` and `calculateOutstandingFor` updated to call `resolveConversionRate`.
- `tests/Feature/PaymentIntegrityHardeningTest.php` — 14 regression tests; Tests 13 and 14 added to address prior review findings.

No UI, controller, migration, or other service files modified.

</details>
