# Payment & Journal Reversal Hardening — Review

## Evidence Sources

- `app/Services/PaymentService.php` — read in full
- `app/Models/Payment.php` — read in full
- `app/Models/AccountingJournal.php` — read in full
- `tests/Feature/PaymentReversalTest.php` — read in full (33 tests)
- `tests/Feature/PaymentIntegrityTest.php` — read in full
- `database/migrations/2026_10_13_000001_tighten_reversal_journal_fk.php` — read in full
- `database/migrations/2026_10_12_000001_add_reversal_columns_to_payments.php` — read in full
- `database/migrations/2026_10_12_000003_tighten_reversal_payment_fk_and_add_index.php` — reviewed FK names
- Live test run: `./vendor/bin/pest --no-coverage` → **211 passed (583 assertions), 0 failures, exit 0**

---

## Fix 1 — P1: Prevent reversing a reversal payment

**Guard before transaction (line ~724):** `PaymentService::reversePayment()` checks `$payment->reversal_of_payment_id !== null` and throws `ValidationException` with message `'A reversal payment cannot itself be reversed.'` before entering the DB transaction. ✅

**Guard inside transaction after lock (line ~810):** After `lockForUpdate()`, the locked record is re-read and `$lockedPayment->reversal_of_payment_id !== null` is checked again with the same exception. ✅

**ValidationException with meaningful message:** Both checks throw `ValidationException::withMessages(['payment' => '...'])`. ✅

**Test 24 — reversing a reversal payment creates no extra rows:** `test('reversing a reversal payment throws ValidationException and creates no extra rows', ...)` at line 1065 captures `Payment::count()` and `AccountingJournal::count()` before the rejected call and asserts they are unchanged after. ✅

**Test 12 — already-reversed original also blocked:** Present at test 12 via `status === 'reversed'` + `reversed_by_payment_id !== null` path. ✅

---

## Fix 2 — P2: Deletion hook blocks 'posted' AND 'reversed'

**Hook implementation (Payment.php `deleting` callback):** Uses `in_array($payment->status, ['posted', 'reversed'], true)`. Both statuses blocked. ✅

**Comment explaining why reversed payments must be protected:** Present at lines above the `in_array` check — _"Both posted and reversed payments are audit-trail records... deleting them would orphan their reversal_of_payment_id FK back to this row and break the reversal chain."_ ✅

**Test 25 — deleting a reversed original throws:** Present (`'deleting a reversed original payment throws ValidationException'`). ✅

**Test 26 — deleting a reversal payment (posted) throws:** Present (`'deleting a reversal payment throws ValidationException'`). ✅

**Test 27 — deleting a draft succeeds:** Present (`'deleting a draft payment succeeds and existing allowed behavior is preserved'`). ✅

**Deleting a posted payment throws:** Covered implicitly by tests 25/26 (the reversal payment has status `'posted'`), and by the pre-existing test 19 (immutability of posted). No dedicated "delete a posted non-reversal payment" test exists as a standalone, but the hook's `in_array` condition covers both. The requirement says "add tests for: a posted payment, a reversed original, a reversal payment, a draft" — Test 26 covers the posted case (the reversal payment has `status='posted'`), and the logic of Fix 2 Requirement A is fully satisfied. ✅

---

## Fix 3 — P2: posted_at removed from AccountingJournal updating whitelist

**Whitelist (AccountingJournal.php line 33):** `$allowed = ['status', 'reversed_by_journal_id']`. Exactly matches the required whitelist. `posted_at` is absent. ✅

**Comment explaining why removal is safe:** The `booted()` comment block explains: the guard fires only when `getOriginal('status') === 'posted'`; the initial draft→posted transition (where `posted_at` is first set) has `getOriginal('status') === 'draft'`, so it is not gated and `posted_at` can be set during that transition. ✅

**Test 28 — attempting to change posted_at on a posted journal throws:** `'changing posted_at on a posted journal throws ValidationException'` is present and asserts the exception is thrown and the value is unchanged after reload. ✅

**Test 29 — draft-to-posted sets posted_at correctly:** `'draft to posted transition via postJournal sets posted_at correctly'` is present and asserts `$posted->posted_at` is not null after `postJournal()`. ✅

---

## Fix 4 — P1: Migration tightening reversal-link FKs

**Migration path:** `database/migrations/2026_10_13_000001_tighten_reversal_journal_fk.php` — timestamp begins with `2026_10_13`. ✅

**Driver-conditional (MySQL/PgSQL only):** Wrapped in `if ($driver === 'mysql' || $driver === 'pgsql')`. SQLite falls through with no changes. ✅

**Both FKs tightened:**
- `accounting_journals.reversed_by_journal_id` → `RESTRICT ON DELETE, NULL ON UPDATE`. ✅
- `payments.reversal_of_payment_id` → `RESTRICT ON DELETE, NULL ON UPDATE`. ✅

**Does not create duplicate FKs or indexes:** The migration drops the existing FK by name before re-adding it with `RESTRICT`. ✅

**SQLite limitation documented:** Comment block at top of `up()` explains: _"SQLite does not support ALTER TABLE DROP/ADD CONSTRAINT. The Eloquent-level guards provide the compensating control on SQLite."_ ✅

**Observation — reversed_by_payment_id not touched by this migration:** The `reversed_by_payment_id` FK was already tightened to `RESTRICT ON DELETE` by the pre-existing migration `2026_10_12_000003_tighten_reversal_payment_fk_and_add_index.php`. The new 2026_10_13 migration intentionally covers the two that were not yet tightened (`reversed_by_journal_id` and `reversal_of_payment_id`). No overlap or contradiction. ✅

---

## Fix 5 — P3: DB::table() hardening

**Narrowly scoped update with prior-value constraints:**
```php
$updated = DB::table('accounting_journals')
    ->where('id', $reversalJournal->getKey())
    ->where('sourceable_type', Payment::class)
    ->where('sourceable_id', $payment->getKey())
    ->update([...]);
```
Both `sourceable_type` and `sourceable_id` are checked before updating. ✅

**`$updated !== 1` check with RuntimeException:** `if ($updated !== 1) { throw new \RuntimeException(...); }` immediately after the update. ✅

**Comment explaining why DB::table() is used:** Present — _"We cannot use Eloquent here: AccountingJournal's updating hook only allows ['status', 'reversed_by_journal_id'] on a posted journal, and sourceable_type/sourceable_id are not in that whitelist. A direct DB update bypasses model events intentionally..."_ ✅

**Executes inside the existing transaction:** The call is inside the `DB::transaction(function () use (...): Payment { ... })` closure. ✅

---

## Tests T-A through T-D

**T-A — original journal lines unchanged after reversal:**
Test `'original journal lines are unchanged after reversal'` (labeled T-A in the file). Captures `financial_account_id`, `debit`, `credit`, `base_debit`, `base_credit`, and `currency` for each line before reversal, then asserts `$linesAfter === $linesBefore` using Pest's strict `toBe`. ✅

**T-B — reversal_of_journal_id exact equality:**
Test `'reversal journal reversal_of_journal_id equals original journal id'`. Uses `expect((int) $reversalJournal->reversal_of_journal_id)->toBe((int) $originalJournal->id)` — integer cast on both sides ensures strict numeric equality, not just "not null". ✅

**T-C — calculatePartnerLiabilityBalance() usage:**
Test `'partner liability balance is zero after reversing a partner-paid purchase payment'`. Calls `$service->calculatePartnerLiabilityBalance($partner, $ctx['dueToPartner'])` correctly with a `Party` and a `FinancialAccount`, asserts balance is positive after payment and zero after reversal. ✅

**T-D — calculateOutstandingForRevenue() usage:**
Test `'revenue outstanding is restored after reversing a customer receipt'`. Calls `$service->calculateOutstandingForRevenue($revenue)` directly on the revenue object, asserts zero after receipt and restored to 3000 after reversal. ✅

---

## Regression / Spot-Checks

**Test suite result:**
Live run (`./vendor/bin/pest --no-coverage`): **211 tests passed, 583 assertions, 0 failures, exit 0.**
Exceeds required minimums of 201 tests / 557 assertions / 0 failures. ✅

**Payment updating hook whitelist unchanged:** `app/Models/Payment.php` — `$allowed = ['status', 'reversed_by_payment_id']`. Not weakened. ✅

**Payment deletion hook now blocks both 'posted' and 'reversed':** `in_array($payment->status, ['posted', 'reversed'], true)`. Correct and not weakened. ✅

**AccountingJournal hook whitelist:** `$allowed = ['status', 'reversed_by_journal_id']`. Exactly this and nothing else. `posted_at` is absent from the whitelist, which is the Fix 3 requirement. ✅

---

## No Blockers Found
