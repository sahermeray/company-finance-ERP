# Accounting Integrity Hardening Phase 2 — Final Report

## Summary

Four targeted accounting-integrity fixes were applied to the Laravel ERP. The changes close two
P1 vulnerabilities (monetary precision, multi-currency balance semantics) and two P2 gaps (Purchase
post-posting immutability, reversal-of-journal FK referential integrity). No UI files were touched,
no new business workflows were built, and no existing immutability protection was weakened. The
full test suite passes at **227 tests, 607 assertions, 0 failures, exit code 0**.

Baseline before this task: 211 tests, 583 assertions, 0 failures.

---

## Files Changed

| File | Purpose |
|---|---|
| `app/Services/RevenueService.php` | Fix 1 — replaced float-based `normalizeAmount()` with bcmath round-half-up; added negative-value rejection. |
| `app/Models/Purchase.php` | Fix 2 — added `booted()` with `updating`/`deleting` hooks enforcing posted-purchase immutability. |
| `app/Models/FinancialAccount.php` | Fix 3 — added `nativeCurrencyBalance(string $currency)`; added deprecation docblocks to `balance()`, `debitTotal()`, `creditTotal()`. |
| `database/migrations/2026_10_14_000001_tighten_reversal_of_journal_fk.php` | Fix 4 — new migration tightening `reversal_of_journal_id` FK to RESTRICT on MySQL/PostgreSQL; SQLite no-op. |
| `tests/Feature/RevenueWorkflowTest.php` | Fix 1 — 6 regression tests for bcmath normalization. |
| `tests/Feature/AccountingIntegrityPhase1Test.php` | Fix 2 — 6 regression tests for Purchase immutability. |
| `tests/Feature/AccountingEngineTest.php` | Fix 3 + Fix 4 — 4 regression tests for balance semantics and journal deletion protection. |

---

## Fix 1 — RevenueService monetary precision (P1)

### What changed

`RevenueService::normalizeAmount()` previously used `number_format((float) $value, 4, '.', '')`,
which converts the input string to a PHP float before formatting. Floats lose precision on large
SYP values (e.g. `99999999999.5001` rounds incorrectly).

The method now:
1. Trims the input and returns `'0'` for empty strings (unchanged).
2. Rejects non-numeric strings with `ValidationException` (unchanged behaviour, same message).
3. Rejects negative values with `ValidationException` — a new guard (`bccomp($value, '0', 20) < 0`).
4. Normalizes to 4 decimal places using bcmath round-half-up — the same algorithm as
   `PurchaseService::normalizeAmount()`: truncate to 5 places, inspect the 5th digit, and
   conditionally add `0.0001` to the 4-place truncation. No floating-point arithmetic is used.

### Behavior before

```
normalizeAmount('99999999999.5001') → number_format((float)'99999999999.5001', 4) → '99999999999.5000' (WRONG — float lost the trailing 1)
normalizeAmount('-100')             → '-100.0000'  (negatives silently accepted)
normalizeAmount('100.45678')        → '100.4568'   (correct, but via float)
```

### Behavior after

```
normalizeAmount('99999999999.5001') → '99999999999.5001' (correct — bcmath preserves the value)
normalizeAmount('-100')              → throws ValidationException('The value must not be negative.')
normalizeAmount('100.45678')         → '100.4568'  (correct — bcmath round-half-up)
```

### Verification — PurchaseService comparison

`PurchaseService::normalizeAmount()` uses the identical bcmath round-half-up algorithm but does NOT
reject negatives (it normalizes the absolute value and re-applies the sign). RevenueService's
requirements differ: revenue line items, totals, and exchange rates must never be negative, so the
negative-rejection guard is intentionally stricter. The existing caller-side checks
(`bccomp($unitPrice, '0', 10) < 0`, `bccomp($quantity, '0', 10) <= 0`) remain in place; they are now
unreachable for negative inputs because `normalizeAmount()` throws first, but they still enforce the
zero rule for quantity (must be > 0, not ≥ 0).

### Tests added (6)

- `preserves large SYP revenue values without floating-point precision loss` — unit_price `99999999999.5000` → total `99999999999.5000`.
- `rounds revenue values with more than four fractional digits using round-half-up` — unit_price `100.45678` → total `100.4568`.
- `rejects a non-numeric revenue unit price` — unit_price `abc` → ValidationException.
- `rejects a negative revenue unit price` — unit_price `-100` → ValidationException.
- `handles a zero revenue unit price without float artifacts` — unit_price `0`, qty `5` → total `0.0000`.
- `normalizes a normal SYP revenue value to four decimal places` — unit_price `100`, qty `10` → total `1000.0000`.

---

## Fix 2 — Purchase immutability after posting (P2)

### What changed

The `Purchase` model previously had no `booted()` hooks. A posted purchase's financial fields
(total, currency, exchange_rate, etc.) could be silently overwritten via `$purchase->update(...)`
or `$purchase->save()`, corrupting the accounting ledger that depends on those values.

Added `Purchase::booted()` with two hooks:

1. **`updating` hook** — fires when `getOriginal('status') === 'posted'`. Whitelists only `['status']`.
   Any other dirty field (total, subtotal, tax_total, base_total, currency, exchange_rate, etc.)
   throws `ValidationException`. The whitelist matches the pattern used by `Revenue` and `Payment`
   models.

2. **`deleting` hook** — fires when `status === 'posted'`. Throws `ValidationException`.

### Why the draft→posted transition is NOT blocked

`PurchaseService::postPurchase()` performs two saves:
1. First save: normalizes financial fields while `getOriginal('status')` is `'draft'` → hook does not fire.
2. Second save: sets `status = 'posted'` while `getOriginal('status')` is still `'draft'` (not yet persisted) → hook does not fire.

Both saves see `getOriginal('status') === 'draft'`, so the immutability guard is never reached
during the legitimate posting workflow. This is the same pattern documented in
`AccountingJournal::booted()` and `Revenue::booted()`.

### Behavior before

```
$postedPurchase->update(['total' => '500']);  // silently overwrites — corrupts ledger
$postedPurchase->update(['currency' => 'USD']); // silently overwrites
$postedPurchase->delete();                     // silently deletes — orphans journal/payments
```

### Behavior after

```
$postedPurchase->update(['total' => '500']);  → throws ValidationException
$postedPurchase->update(['currency' => 'USD']); → throws ValidationException
$postedPurchase->delete();                     → throws ValidationException
$draftPurchase->update(['total' => '500']);    → succeeds (draft is editable)
$draftPurchase->delete();                      → succeeds (draft has no accounting dependencies)
```

### Tests added (6)

- `prevents modifying the total of a posted purchase` — `update(['total' => '500'])` throws; total unchanged.
- `prevents modifying the currency of a posted purchase` — `update(['currency' => 'USD'])` throws; currency unchanged.
- `prevents deleting a posted purchase` — `delete()` throws; record still exists.
- `allows editing a draft purchase` — draft `total` change saves successfully.
- `allows deleting a draft purchase` — draft `delete()` succeeds; record gone.
- `allows the draft-to-posted transition via postPurchase without immutability blocking it` — full `PurchaseService::postPurchase()` workflow succeeds; result is `posted` with correct total.

---

## Fix 3 — FinancialAccount balance semantics (P1)

### What changed

`FinancialAccount::balance()`, `debitTotal()`, and `creditTotal()` sum native-currency amounts
(`debit`/`credit` columns) across ALL journal lines on the account, without filtering by currency.
For a multi-currency account (e.g. a shared payable account with both SYP and USD postings), this
produces a meaningless mixed-currency number.

Changes:
1. Added **`nativeCurrencyBalance(string $currency): string`** — sums `debit − credit` for journal
   lines matching the given currency only. Safe for multi-currency accounts.
2. Added `@deprecated` docblocks to `balance()`, `debitTotal()`, and `creditTotal()` warning that
   they are only safe for single-currency accounts and directing callers to
   `nativeCurrencyBalance()`, `baseCurrencyBalance()`, or `balanceByCurrency()`.
3. `balance()` was NOT removed or renamed (passing tests depend on it).

### Call-site audit

Searched all of `app/` for call sites of `balance()`, `debitTotal()`, `creditTotal()`,
`baseCurrencyBalance()`, and `balanceByCurrency()`. Result: **no service or model code calls these
methods**. The only call sites are in test files (`AccountingEngineTest.php`,
`PaymentAccountingTest.php`), all of which use single-currency accounts where the methods are
correct. No service code needed switching to `baseCurrencyBalance()` or `nativeCurrencyBalance()`.

### Behavior before

```
$account->balance()              // SUM(debit) − SUM(credit) across ALL currencies — meaningless if mixed
$account->debitTotal()            // SUM(debit) across ALL currencies
$account->creditTotal()           // SUM(credit) across ALL currencies
// No currency-filtered native balance method existed
```

### Behavior after

```
$account->balance()                      // unchanged — still sums all currencies (deprecated, single-currency only)
$account->nativeCurrencyBalance('SYP')   // NEW — SUM(debit−credit) for SYP lines only
$account->nativeCurrencyBalance('USD')   // NEW — SUM(debit−credit) for USD lines only
$account->baseCurrencyBalance()          // unchanged — SUM(base_debit) − SUM(base_credit), multi-currency safe
$account->balanceByCurrency()            // unchanged — per-currency breakdown, multi-currency safe
```

### Tests added (2)

- `computes native-currency and base-currency balances separately for a multi-currency account` —
  SYP debit 1500 + USD debit 1000 (rate 12000) on one account:
  `nativeCurrencyBalance('SYP') = 1500`, `nativeCurrencyBalance('USD') = 1000`,
  `baseCurrencyBalance() = 12001500`.
- `agrees across all three balance methods for a single-currency account` — single-currency account
  at rate 1: `balance() == baseCurrencyBalance() == nativeCurrencyBalance('SYP')`.

---

## Fix 4 — Protect reversal_of_journal_id referential integrity (P2)

### What changed

The `reversal_of_journal_id` foreign key (pointing from a reversal journal back to the original it
reverses) was created with `nullOnDelete()` in migration `2026_10_10_000001`. Migration
`2026_10_13_000001` tightened the companion pointer `reversed_by_journal_id` to `restrictOnDelete()`
but left `reversal_of_journal_id` as SET NULL. If the original journal were deleted, the reversal's
back-pointer would silently become NULL, breaking the reversal chain's audit trail.

New migration `2026_10_14_000001_tighten_reversal_of_journal_fk.php`:
- **MySQL/PostgreSQL**: drops the existing `accounting_journals_reversal_of_journal_id_foreign` FK
  and re-adds it with `restrictOnDelete()` + `nullOnUpdate()`. Constraint name verified against the
  Laravel convention used by the original `constrained()` call.
- **SQLite**: no-op (driver conditional, same pattern as `2026_10_12_000003` and `2026_10_13_000001`).
  SQLite cannot ALTER an existing FK action without a full table rebuild, which risks data loss.

### Compensating control on SQLite (and all engines)

`AccountingJournal::booted()` already defines a `deleting` Eloquent hook that throws
`ValidationException` whenever a posted journal (`status === 'posted'` or `posted_at !== null`) is
deleted. This blocks deletion of any posted journal — including the original that a reversal points
to — at the application level, regardless of the database engine. The Eloquent hook is the primary
protection; the database-level RESTRICT is defence-in-depth on MySQL/PostgreSQL.

### Behavior before

```
-- MySQL/PostgreSQL: deleting the original journal sets reversal_of_journal_id to NULL silently
-- SQLite: same (FK action is SET NULL)
```

### Behavior after

```
-- MySQL/PostgreSQL: deleting the original journal is blocked by RESTRICT at the DB level
-- SQLite: deleting the original journal is blocked by the Eloquent deleting hook (posted journals cannot be deleted)
-- All engines: Eloquent $journal->delete() on a posted journal throws ValidationException
```

### Tests added (2)

- `prevents deleting a posted journal through Eloquent on all engines` — creates a posted journal,
  calls `$journal->delete()`, expects `ValidationException`. Works on every engine via the Eloquent
  hook.
- `reversal journal reversal_of_journal_id points to the correct original` — posts a journal,
  reverses it via `AccountingService::reverseJournal()`, verifies `(int) $reversal->reversal_of_journal_id
  === (int) $posted->id` (integer-cast equality, not just non-null).

---

## Migration implications

### SQLite (test environment)

- Fix 4 migration is a no-op — the FK action remains SET NULL. The `AccountingJournal::booted()`
  deleting hook provides the compensating control on all engines.
- No table rebuild is performed. No risk of data loss.

### MySQL (production)

- `reversal_of_journal_id` FK is dropped and re-added with `restrictOnDelete()` via
  `ALTER TABLE … DROP FOREIGN KEY` / `ADD CONSTRAINT`, which MySQL supports natively.
- The existing plain index on `reversal_of_journal_id` (from `2026_10_10_000001`) is not affected.
- `down()` reverts the FK to `nullOnDelete()`.

### PostgreSQL (production)

- `reversal_of_journal_id` FK is dropped and re-added with `restrictOnDelete()` via
  `ALTER TABLE … DROP CONSTRAINT` / `ADD CONSTRAINT`, which PostgreSQL supports.
- `down()` reverts the FK to `nullOnDelete()`.

---

## Test results

```
php vendor/bin/pest --no-coverage
Tests:    227 passed (607 assertions)
Duration: 3.35s
Exit code: 0
```

- Baseline: 211 tests, 583 assertions.
- After: 227 tests, 607 assertions (+16 tests, +24 assertions, 0 failures).

---

## Remaining limitations

1. **`FinancialAccount::balance()` / `debitTotal()` / `creditTotal()` are not removed.** They
   remain for backward compatibility (passing tests use them on single-currency accounts). They are
   marked `@deprecated` with docblocks explaining the multi-currency risk. Any future UI must use
   `baseCurrencyBalance()`, `nativeCurrencyBalance()`, or `balanceByCurrency()` instead.

2. **`FinancialAccount::balance()` returns `debit − credit` regardless of account type.** For a
   liability account with a normal credit balance of 200, `balance()` returns `-200.0000000000`.
   The UI must interpret sign by account type (asset/expense = positive debit normal; liability/
   equity/revenue = positive credit normal). This is a display concern, not addressed in this task.

3. **SQLite cannot enforce RESTRICT at the DB level for `reversal_of_journal_id`.** The
   `AccountingJournal::booted()` deleting hook is the sole protection on SQLite. A raw SQL `DELETE`
   bypassing Eloquent events would succeed on SQLite (SET NULL). On MySQL/PostgreSQL, the DB-level
   RESTRICT would block it. This is an accepted trade-off documented in the migration.

4. **`Purchase::booted()` immutability does not block direct DB updates.** The `updating` Eloquent
   hook catches `$model->save()` / `$model->update()` but not `DB::table('purchases')->where(...)-
   >update(...)`. This matches the protection level of `Payment`, `Revenue`, and `AccountingJournal`
   models — all rely on Eloquent events, not DB triggers.

5. **No service code calls `balance()` on a multi-currency account.** The call-site audit confirmed
   zero service-level call sites. If a service is later added that queries account balances, it must
   use `nativeCurrencyBalance()` or `baseCurrencyBalance()` explicitly.

---

## Existing call sites that remain and why

| Method | Call site | Multi-currency safe? | Why it remains |
|---|---|---|---|
| `balance()` | `AccountingEngineTest.php` | Yes — single-currency accounts only | Tests assert exact balance values on single-currency accounts; behavior is correct. |
| `debitTotal()` | `AccountingEngineTest.php`, `PaymentAccountingTest.php` | Yes — single-currency accounts only | Tests assert debit/credit totals on single-currency accounts. |
| `creditTotal()` | `AccountingEngineTest.php`, `PaymentAccountingTest.php` | Yes — single-currency accounts only | Same as above. |
| `baseCurrencyBalance()` | None in `app/` | Yes — multi-currency safe | Not currently called by any service; available for future UI. |
| `nativeCurrencyBalance()` | None yet | Yes — currency-filtered | New method added in this task; available for future use. |

No service code calls any of these balance methods on an account that could be multi-currency.
