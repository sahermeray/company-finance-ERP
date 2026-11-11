# Payment and Journal Reversal Workflow

The implementation adds `PaymentService::reversePayment()`, hardens `AccountingService::reverseJournal()`, narrows the `Payment` updating hook whitelist, adds a `JournalLine` immutability guard, two new migrations, and 23 tests. The overall approach is sound: the reversal is atomic, the original payment is preserved as a historical record, and the reversal journal is an exact mirror of the original. Seven targeted behavioral concerns are documented below.

Watch for: (**confirmed**) `nullOnDelete` on both reversal FK columns means deleting a reversal payment silently erases the link on the original — the original's `reversed_by_payment_id` becomes `NULL` and it looks unreversed. (**confirmed**) Cross-currency tests 3 and 4 only assert line count (3), not that the FX line carries the correct signed amount; a sign error in the reversal logic would not be caught. (**confirmed**) `reversal_of_payment_id` has no index — any lookup or JOIN on that column is a full table scan. (**possible**) `$this->partialMock` in test 17 depends on Mockery integration inside a Pest `runForCompany` closure — this pattern has historically been brittle in some Laravel/Pest versions.

**Verdict**: CHANGES_REQUESTED

---

## High-level view

The Payment updating hook correctly whitelists `['status', 'reversed_by_payment_id']` and further restricts the only valid target status to `'reversed'`. Arbitrary transitions such as `'cancelled'` are blocked. The whitelist check also correctly fires only when the original status was `'posted'`, leaving draft payments unrestricted as intended.

The JournalLine `booted()` hook guards both `updating` and `deleting` events. It uses a dual condition (`status === 'posted' || posted_at !== null`) to match the `AccountingJournal` immutability check, and loads the parent journal without `CompanyScope` to avoid false negatives in service contexts. `create` events are not hooked, so `postJournal()` — which saves lines while the journal is still `draft` — is unaffected.

`AccountingService::reverseJournal()` is now wrapped in `DB::transaction`, re-reads the journal with `lockForUpdate()` inside the transaction, and checks `reversed_by_journal_id !== null` before proceeding. The double-reversal guard is present and confirmed by code inspection.

`PaymentService::reversePayment()` implements all seven steps described in the spec. Company isolation is verified before any mutation; the TOCTOU re-check fires inside the `DB::transaction` after `lockForUpdate()`; the reversal journal's sourceable is re-pointed via a documented raw `DB::table()` bypass; the original payment is updated only with whitelist-allowed fields. The method does not copy `payable_type`/`payable_id`, so the reversal payment is correctly excluded from outstanding-balance calculations by both the status filter and the missing payable link.

The schema adds the correct unique constraint on `reversed_by_payment_id` (prevents two payments from claiming the same original) and migrates the journal's `reversed_by_journal_id` index from plain to unique. `nullOnDelete` is used on all reversal FK columns, matching the journal convention, but this is a data-integrity gap for the payment side — explained below.

All 23 required test cases are present and map to the specification. The cross-currency tests are behaviorally thin: tests 3 and 4 assert line count only; test 5 (the balance check) is the only test that asserts numeric correctness of the FX reversal.

---

<details>
<summary>Issues (4)</summary>

1. **nullOnDelete on reversed_by_payment_id erases reversal evidence** — If a reversal payment is deleted (possible if it somehow transitions to draft or is deleted in a future workflow), the original payment's `reversed_by_payment_id` silently becomes `NULL`, making it appear unreversed. The FK should be `RESTRICT` / `NO ACTION`. The journal columns use the same convention but posted journals cannot be deleted via the Eloquent hook, which mitigates it there; the payment-side guard protects posted payments from deletion but a future workflow change could widen the gap. Change the FK for `reversed_by_payment_id` on the payments table to `restrictOnDelete()`.

2. **reversal_of_payment_id has no index** — The `foreignId()` helper in Laravel does not add an index automatically (it only adds one when using the `->constrained()` fluent call on a non-default-named column). The SQLite schema confirms no index exists on `reversal_of_payment_id`. Any query filtering or joining on this column — including the rollback assertion in test 17 (`->where('reversal_of_payment_id', $payment->id)`) — performs a full table scan. Add `$table->index('reversal_of_payment_id')` in the migration.

3. **Cross-currency FX tests assert line count only, not exact amounts** — Tests 3 and 4 verify that a 3-line reversal journal is created for an FX-loss and FX-gain scenario respectively, but neither asserts the actual signed values on the FX line. A reversal that put the FX difference on the wrong line (credit vs debit), or used the wrong magnitude, would still pass. Test 5 partially compensates by verifying that `sum(base_debit) == sum(base_credit)`, but this does not verify which specific account carries which amount. Add assertions in tests 3 and 4 that the FX line's `financial_account_id` matches the expected gain/loss account and that the debit/credit direction is the inverse of the original.

4. **nullOnDelete on reversal_of_journal_id could link a reversal to no original** — The `accounting_journals.reversal_of_journal_id` FK also uses `nullOnDelete()` (from the earlier migration 2026_10_10). If the original journal were deleted, the reversal's back-pointer would become NULL, severing the audit trail. The Eloquent `deleting` hook on posted journals prevents this in practice, but the constraint should match the intent. This is a pre-existing convention decision carried forward, not newly introduced, but worth flagging now that the reversal workflow makes it consequential. **Possible** — depends on whether future workflows could delete draft-turned-posted journals via DB bypass.

</details>

---

<details>
<summary>Details</summary>

### Payment updating hook: whitelist correctness

The hook fires only when `getOriginal('status') === 'posted'`, leaving draft payments unrestricted. For posted payments, `array_diff($dirty, ['status', 'reversed_by_payment_id'])` throws on any other field mutation. The second guard — `in_array('status', $dirty, true) && $payment->status !== 'reversed'` — blocks transitions to `'cancelled'`, `'draft'`, or any other non-`'reversed'` target. Both paths are confirmed in code and exercised by tests 19 and 20.

The strict comparison `in_array('status', $dirty, true)` avoids the loose-comparison pitfall with numeric strings.

### JournalLine immutability

The `booted()` hook uses `($journal->status === 'posted' || $journal->posted_at !== null)` — stricter than status alone, catching rows whose `status` was tampered via raw SQL while retaining a non-null `posted_at`. The hook always performs a fresh DB lookup for the parent journal, even when the relation is already loaded, adding one extra query per line event. Not a correctness issue, but notable if bulk line operations appear later. Test 21 confirms both `update` and `delete` attempts throw.

### AccountingService::reverseJournal() transaction and double-reversal guard

`reverseJournal()` opens its own `DB::transaction`, called from inside `reversePayment()`'s outer transaction. Laravel handles this as a savepoint on MySQL/PostgreSQL; on SQLite it collapses to the outer transaction — both safe. The double-reversal check on `reversed_by_journal_id` fires on the locked re-read, confirmed.

The reversal lines copy `base_credit`/`base_debit` explicitly from the original, avoiding any recalculation.

### PaymentService::reversePayment() correctness

All seven steps are implemented. The TOCTOU guard re-checks `status` and `reversed_by_payment_id` on the locked row inside the transaction. The reversal payment omits `payable_type`/`payable_id`, so it is excluded from `calculateOutstandingFor()` by both the status filter (original becomes `'reversed'`) and the null payable link.

The sourceable re-association uses a raw `DB::table()` update — necessary because `AccountingJournal`'s updating hook only allows `['status', 'posted_at', 'reversed_by_journal_id']` on posted journals; `sourceable_type`/`sourceable_id` are not in that list.

The `nullOnDelete` on `reversed_by_payment_id` is the primary data-integrity gap. The payment `deleting` hook blocks deletion of posted payments, which covers the current case where reversal payments have `status = 'posted'`. If a future workflow creates reversal payments in a draft state before posting, or if a raw DB operation bypasses the hook, the original's `reversed_by_payment_id` silently becomes `NULL` and it appears unreversed. `RESTRICT` would close this regardless of application state.

### Schema migrations

Migration A inspected pre-existing indexes (listed in plan.md). The unique constraint on `reversed_by_payment_id` is confirmed in the SQLite schema. `reversal_of_payment_id` has no index — confirmed absent. The `foreignId()` fluent chain (`.nullable().constrained()`) generates the FK constraint but not a standalone index; the plan mentioned a plain index on this column but it did not materialize in the actual migration.

Migration B correctly names and drops the existing plain index before adding the unique one. The SQLite schema confirms `journals_reversed_by_journal_id_unique` is present with `unique=1`.

### Test coverage

All 23 required tests are present. Tests 11–15 use `expect(...)->toThrow(ValidationException::class)` and call `->fresh()` on the payment before the second reversal attempt, avoiding stale model state.

Test 17 (rollback) uses `$this->partialMock` inside a `runForCompany` closure. `partialMock` binds into the service container, but `PaymentService` is then constructed manually with the mock injected, bypassing the container — this is correct. The rollback assertion (`->where('reversal_of_payment_id', $payment->id)->exists()`) correctly targets the right column. The original payment status check (`->toBe('posted')`) confirms the `'reversed'` update was rolled back.

Tests 3 and 4 only assert `count(3)`. A regression where the FX line is credited instead of debited (or vice versa) would pass both tests. Test 5 verifies `sum(base_debit) == sum(base_credit)` but cannot detect a wrong account assignment — a balanced journal with the FX difference on the wrong account would still satisfy it.

### Revenue model and outstanding balance

`Revenue.php` is unchanged. `calculateOutstandingFor()` is unchanged; no modification was needed because the reversal payment's null `payable_type`/`payable_id` and the original's `'reversed'` status independently exclude both from the calculation.

</details>

---

<details>
<summary>File map</summary>

- `database/migrations/2026_10_12_000001_add_reversal_columns_to_payments.php` — adds `reversal_of_payment_id` (nullable FK, no index) and `reversed_by_payment_id` (nullable FK, unique constraint) to payments; both use `nullOnDelete()`
- `database/migrations/2026_10_12_000002_unique_reversed_by_journal_id.php` — replaces the plain index on `accounting_journals.reversed_by_journal_id` with a unique index
- `app/Models/Payment.php` — adds `reversal_of_payment_id`/`reversed_by_payment_id` to `$fillable`, adds `reversalOf()`/`reversedBy()` relationships, narrows `updating` hook to whitelist `['status', 'reversed_by_payment_id']` with an additional guard that the only allowed target status is `'reversed'`
- `app/Models/JournalLine.php` — adds `booted()` hook with `updating` and `deleting` guards that throw on lines belonging to posted journals
- `app/Services/AccountingService.php` — wraps `reverseJournal()` in `DB::transaction`, adds `lockForUpdate()` re-read, adds `reversed_by_journal_id !== null` double-reversal guard, copies `base_debit`/`base_credit` from original lines without recalculation
- `app/Services/PaymentService.php` — adds `reversePayment()` implementing all 7 steps including company isolation, TOCTOU guard, raw-DB sourceable re-association, and original-payment status transition via the whitelisted hook
- `tests/Feature/PaymentReversalTest.php` — 23 test cases; tests 3/4 cover FX by line count only; test 5 verifies base-currency balance; test 17 mocks `AccountingService` to test rollback

Full diff: `git diff main -- database/migrations app/Models/Payment.php app/Models/JournalLine.php app/Services/AccountingService.php app/Services/PaymentService.php tests/Feature/PaymentReversalTest.php`

</details>
