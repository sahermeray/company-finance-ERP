# Payment and Journal Reversal — Design Audit Report

> **Status:** Read-only audit. No files were created, edited, or deleted.
> **Codebase state at audit time:** 178 tests, 498 assertions, 0 failures.

---

## A. Confirmed Current Implementation

### A.1 — Payment model immutability hooks

**File:** `app/Models/Payment.php`, method `booted()`

Two Eloquent lifecycle hooks are registered:

**`updating` hook** (line 62–67):
- Fires on every Eloquent `save()` that is an UPDATE.
- Guard condition: `$payment->getOriginal('status') === 'posted'`.
- Action: throws `ValidationException` with message _"Posted payments are immutable. Create a reversal record instead of editing historical financial data."_
- This checks the **original** (database) value, not the dirty value, so it fires even when `status` itself is being changed.

**`deleting` hook** (line 69–74):
- Fires on every Eloquent `delete()`.
- Guard condition: `$payment->status === 'posted'`.
- Action: throws `ValidationException` with message _"Posted payments cannot be deleted. Create a reversal record instead."_

**Default status:** `$attributes = ['status' => 'posted']` — every new Payment defaults to posted unless explicitly set to `'draft'`.

**Critical gap:** The `updating` hook fires on **any** column change when the original status is `'posted'`. This means transitioning a posted payment to `'reversed'` via `$payment->status = 'reversed'; $payment->save()` will throw. The reversal design must account for this — see Section C.

---

### A.2 — AccountingJournal and JournalLine immutability guards

**File:** `app/Models/AccountingJournal.php`, method `booted()`

**`updating` hook** (lines 13–23):
- Guard condition: `$journal->status === 'posted' || $journal->posted_at !== null`.
- Allowed dirty keys (whitelist): `['status', 'posted_at', 'reversed_by_journal_id']`.
- All other dirty columns trigger `ValidationException` with _"Posted journals are immutable. Create a reversal instead of editing historical entries."_
- **Note:** `reversed_by_journal_id` is explicitly allowed, enabling `reverseJournal()` to write this field back to the original journal after reversal.

**`deleting` hook** (lines 25–30):
- Fires when status is `'posted'` or `posted_at` is not null.
- Throws `ValidationException` with _"Posted journals cannot be deleted. Create a reversal instead."_

**JournalLine** (`app/Models/JournalLine.php`):
- Has **no** immutability hooks. Lines are indirectly protected by the parent journal's guard — once a journal is posted, `AccountingService::addJournalLine()` rejects new lines with _"Only draft journals can accept new lines."_ However, there is no hook preventing direct `JournalLine::save()` or `JournalLine::delete()` on existing posted lines. This is a minor secondary gap.

---

### A.3 — reversal_of_journal_id / reversed_by_journal_id

**Migration:** `database/migrations/2026_10_10_000001_add_reversal_support_to_accounting_journals.php`

Both columns exist on the `accounting_journals` table:

| Column | FK | Constraint behavior | Uniqueness |
|---|---|---|---|
| `reversal_of_journal_id` | `accounting_journals.id` | `nullOnDelete()` | No unique index |
| `reversed_by_journal_id` | `accounting_journals.id` | `nullOnDelete()` | No unique index |

Both have simple (non-unique) indexes.

**Consequences of `nullOnDelete()`:** If a reversal journal is deleted (which would require the reversal to be non-posted), its parent's `reversed_by_journal_id` is silently set to NULL, making the original journal appear un-reversed. This is only a real risk if draft reversals can be abandoned — currently `reverseJournal()` always posts immediately so it's not a practical path today.

**No uniqueness constraint** on either column. This means the same journal can, in theory, have `reversed_by_journal_id` pointing to multiple journals if code writes it more than once (the column is in the allowed-dirty whitelist). The service currently only writes it once, so this is a latent risk, not an active bug.

**Model relationships defined** in `AccountingJournal.php`:
- `reversalOf()` → `belongsTo(AccountingJournal::class, 'reversal_of_journal_id')`
- `reversedBy()` → `belongsTo(AccountingJournal::class, 'reversed_by_journal_id')`

---

### A.4 — Reversal columns on payments

**No such columns exist.** Across all 15 migration files and the entire `app/` directory there is no `reversal_of_payment_id`, `reversed_by_payment_id`, or any equivalent on the `payments` table. The payments table has only `status` and `posted_at` as lifecycle columns (added in `2026_10_10_010000_add_payment_status_columns.php`).

The `'reversed'` status value is used in tests (`PaymentOutstandingTest.php` line 133 and `PaymentIntegrityTest.php` line 152) as a direct DB insert to set up test fixtures, and `calculateOutstandingFor()` in `PaymentService.php` filters to `->where('status', 'posted')`, which causes any payment with status `'reversed'` to be excluded. However, there is no service method that transitions a payment to `'reversed'` status, no FK linking a reversal payment back to the original, and no code that enforces what `'reversed'` means as a state machine transition.

---

### A.5 — Payment and journal status values; enforcement

**Payment statuses** (inferred from code and tests):
- `'draft'` — created by `createDraftPayment()`, no journal, excluded from outstanding.
- `'posted'` — created by `recordPayment()` and all named variants; triggers immutability; counted in outstanding.
- `'reversed'` — referenced in tests as a direct insert, excluded from outstanding via the `->where('status', 'posted')` filter in `calculateOutstandingFor()`.

**Enforcement:** The `status` column is `string(30)` with `default('posted')`. There is **no DB-level enum constraint** — any string value can be stored. The only enforcement is the Eloquent `updating` hook on `Payment`, which prevents any attribute change (including status change) once status is `'posted'`. This means:
- `'draft'` → `'posted'` is possible via the service (sets it in `recordPayment()`).
- `'posted'` → `'reversed'` is **blocked** by the updating hook — it cannot be done with a simple `save()`.

**Journal statuses** (`accounting_journals.status`):
- `'draft'` — created by `createDraftJournal()`.
- `'posted'` — set by `postJournal()`.
There is no `'reversed'` status for journals. A reversed journal remains `'posted'` and gains `reversed_by_journal_id`. The reversal journal is itself a separate `'posted'` journal.

Both are `string(30)` columns; no DB enum.

---

### A.6 — calculateOutstandingFor() — exact query

**File:** `app/Services/PaymentService.php`, method `calculateOutstandingFor(object $payable)`

The query (lines ~555–570):
```php
$payments = Payment::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', $companyId)
    ->where('payable_type', get_class($payable))
    ->where('payable_id', $payable->getKey())
    ->where('status', 'posted')   // ← filters by status
    ->get();
```

**Status filter:** Only `status = 'posted'` payments are counted. Draft and `'reversed'` payments are excluded. This means if a payment is transitioned to `'reversed'`, it will automatically be excluded from the outstanding calculation — no other code change is needed to restore the outstanding balance.

**Cross-currency logic:** For payments where `applied_currency !== payableCurrency`, the method uses `resolveConversionRate()` to get a rate and then divides (`bcdiv`) the applied amount by the rate to convert to the payable's native currency. This ensures a SYP payment against a USD purchase is correctly expressed as USD for the subtraction.

---

### A.7 — Service methods mentioning reversal, void, cancel, or reverse

**`AccountingService::reverseJournal(AccountingJournal $journal, array $attributes = [])`**
(file: `app/Services/AccountingService.php`, line 217)

This method:
1. Checks `$journal->status !== 'posted'` → throws if not posted.
2. Creates a draft journal with `reversal_of_journal_id` set.
3. Adds reversed lines (swaps debit↔credit, uses stored base amounts).
4. Calls `postJournal()` on the reversal.
5. Sets `$journal->reversed_by_journal_id = $reversal->getKey()` and saves (allowed by the whitelist in the updating hook).

**No `reversePayment()` method exists anywhere** in `PaymentService`, `PurchaseService`, or any other service.

---

### A.8 — Are reversal columns actually used in service/model code?

**Journal reversal columns** (`reversal_of_journal_id`, `reversed_by_journal_id`):
- **Yes, actively used** in `AccountingService::reverseJournal()`.
- Both are in `AccountingJournal::$fillable`.
- Both have model relationships (`reversalOf()`, `reversedBy()`).
- `AccountingEngineTest.php` tests the method and asserts `$reversal->reversal_of_journal_id === $posted->id`.

**Payment reversal columns:** None exist.

**Payment `'reversed'` status:**
- Referenced in `PaymentOutstandingTest.php` and `PaymentIntegrityTest.php` as a raw status value inserted directly.
- `calculateOutstandingFor()` implicitly excludes it via the `status = 'posted'` filter.
- A comment in `PurchaseService::calculateOutstanding()` (line 269) reads: _"only posted payments count, draft and reversed payments are excluded"_ — confirming `'reversed'` is a recognized concept in the design intent, but not yet implemented as a service-level operation.

---

## B. Confirmed Reversal Limitations

### B.1 — Can a posted payment be modified through the service API?

**No.** The `Payment::booted()` `updating` hook fires on any `save()` call against a row whose **current database status** is `'posted'`. This throws `ValidationException` unconditionally. There is no service method that bypasses this. The only escape path would be `DB::table('payments')->where(...)...update(...)` (raw query), which is not used anywhere in the service layer.

**Implication for reversal:** A `reversePayment()` method cannot transition the original payment to `'reversed'` via `$payment->status = 'reversed'; $payment->save()`. It must use a raw query or redesign the hook.

---

### B.2 — Can the same journal be reversed twice?

**Yes, currently.** `reverseJournal()` only checks `$journal->status !== 'posted'`. It does not check whether `reversed_by_journal_id` is already set. Calling it twice on the same journal would:
1. Create a second reversal journal.
2. Overwrite `reversed_by_journal_id` on the original (the column is in the allowed-dirty whitelist).
3. Leave the first reversal journal orphaned (its `reversal_of_journal_id` still points to the original, but nothing prevents this).

**Gap:** There is no uniqueness constraint on `reversed_by_journal_id` and no guard checking it before creating a reversal.

---

### B.3 — Can Company A's payment be reversed by a Company B request?

**Partially protected, but not fully.** `AccountingService::reverseJournal()` calls `assertJournalBelongsToActiveCompany($journal)`, which checks against the active `CompanyContextService` context. If a context is set for Company B and a Company A journal is passed, it throws. However:
- If no company context is set (e.g., `$activeCompanyId === null`), the check passes silently (`if ($activeCompanyId !== null && ...)`).
- For payments, there is no `reversePayment()` at all, so this protection is not yet wired up on the payment side.

---

### B.4 — With P1+P2 posted against a purchase, if P1 is reversed, does calculateOutstandingFor() restore the full outstanding?

**Yes, automatically**, if P1 is given status `'reversed'`. The method filters `->where('status', 'posted')` so only P2 is counted. The outstanding becomes `purchase.total − P2.applied_amount`, correctly restoring the full balance.

**Trace:**
```
purchase.total = 1000 USD
P1.applied_amount = 400 USD (status = 'reversed' after reversal)
P2.applied_amount = 300 USD (status = 'posted')

calculateOutstandingFor():
  paid = 300   (only P2 counted)
  outstanding = 1000 - 300 = 700 USD  ✓
```

No explicit handling is needed — the filter does it automatically.

---

### B.5 — Does any mechanism exist to reverse an FX gain/loss journal line?

**Yes, generically.** `AccountingService::reverseJournal()` iterates all lines of the original journal and swaps debit/credit, including FX gain/loss lines. For example, if the original journal had:
```
Dr Supplier Payable 1,000 USD (base 12,000,000 SYP)
Cr Cash             12,500,000 SYP
Dr Exchange Loss       500,000 SYP
```
The reversal creates:
```
Cr Supplier Payable 1,000 USD (base 12,000,000 SYP)
Dr Cash             12,500,000 SYP
Cr Exchange Loss       500,000 SYP
```
This is mechanically correct. The key is that `reverseJournal()` copies `base_debit`/`base_credit` from the original line, so the base-currency amounts are exact inverses.

---

### B.6 — Can a draft payment be reversed? Should it be?

**Current state:** No service method exists for payment reversal at all, so the question is academic. However, the design must decide. A draft payment has no journal entry and has not affected the outstanding balance. "Reversing" it makes no accounting sense — there is nothing to undo. The correct action is simply to **delete** it (the `deleting` hook allows deletion of draft payments). Reversal logic should enforce `status === 'posted'` before proceeding, just as `reverseJournal()` enforces `status === 'posted'`.

---

## C. Recommended Design

### C.1 — Schema changes

**Migration 1: Add reversal columns to payments table**

```sql
ALTER TABLE payments ADD COLUMN reversal_of_payment_id BIGINT UNSIGNED NULL;
ALTER TABLE payments ADD CONSTRAINT fk_payments_reversal_of
    FOREIGN KEY (reversal_of_payment_id) REFERENCES payments(id) ON DELETE SET NULL;
-- Unique index: prevents two payments pointing to the same original
ALTER TABLE payments ADD UNIQUE INDEX payments_reversal_of_unique (reversal_of_payment_id);
-- Regular index for lookups in the other direction
ALTER TABLE payments ADD INDEX payments_reversal_of_idx (reversal_of_payment_id);
```

The companion column `reversed_by_payment_id` is optional (it can be derived by querying `WHERE reversal_of_payment_id = ?`), but it makes the relationship navigable in both directions and mirrors the journal pattern. Include it:

```sql
ALTER TABLE payments ADD COLUMN reversed_by_payment_id BIGINT UNSIGNED NULL;
ALTER TABLE payments ADD CONSTRAINT fk_payments_reversed_by
    FOREIGN KEY (reversed_by_payment_id) REFERENCES payments(id) ON DELETE SET NULL;
ALTER TABLE payments ADD UNIQUE INDEX payments_reversed_by_unique (reversed_by_payment_id);
```

**Why `SET NULL` (nullOnDelete) on both?** Matches the journals convention. If a reversal payment is somehow deleted (only possible while draft), the original is not orphaned. The original's `reversed_by_payment_id` becomes NULL — it appears un-reversed, which is correct.

**Migration 2: Add unique constraint to reversed_by_journal_id**

The existing journal reversal migration lacks a unique constraint on `reversed_by_journal_id`. This allows double-reversal of journals. Add:

```sql
ALTER TABLE accounting_journals 
    ADD UNIQUE INDEX journals_reversed_by_unique (reversed_by_journal_id);
```

**No new tables needed.** See C.2 for the justification.

---

### C.2 — Reversal as a new Payment record vs. a separate model

**Decision: a new Payment record linked to the original via `reversal_of_payment_id`.**

Rationale grounded in the existing architecture:

1. `calculateOutstandingFor()` already queries the `payments` table with `->where('status', 'posted')`. A reversal payment with `status = 'reversed'` is automatically excluded; the reversal payment row with `status = 'posted'` and a negative `applied_amount` is not needed — the exclusion mechanism is simpler.

2. `Payment::accountingJournals()` is a `morphMany` on `sourceable`. A reversal payment can own its own reversal journal, sourced from it, without any model change.

3. Every existing named method (`recordSupplierPayment`, `recordCustomerReceipt`, etc.) creates a Payment + Journal atomically. The reversal follows the same pattern: create a reversal Payment + call `reverseJournal()` on the original journal, all in one transaction.

4. A separate `PaymentReversal` model would require new migrations, new relationships everywhere, and special-casing in `calculateOutstandingFor()`. That cost is not justified when the existing `payments` table can carry the reversal record.

5. **The immutability hook problem** is addressed by allowing a `reversed` status transition via a targeted raw-query step or by modifying the hook's whitelist — see C.3 below.

---

### C.3 — Service method signatures to add or modify

**New method in PaymentService:**

```php
public function reverseSupplierPayment(
    Payment $payment,
    array $attributes = [],
): Payment
```

```php
public function reverseCustomerReceipt(
    Payment $payment,
    array $attributes = [],
): Payment
```

```php
public function reversePartnerPaidPurchase(
    Payment $payment,
    array $attributes = [],
): Payment
```

```php
public function reversePartnerReimbursement(
    Payment $payment,
    array $attributes = [],
): Payment
```

**Or**, a single polymorphic entry point:

```php
public function reversePayment(
    Payment $payment,
    array $attributes = [],
): Payment
```

The single entry point is preferred because the underlying logic (find original journal, call `reverseJournal()`, create reversal payment, update statuses) is the same regardless of payment type. Type-specific behavior (account resolution) is already encoded in the original payment's journal lines, and `reverseJournal()` copies them wholesale.

**Modification to AccountingService::reverseJournal():**

Add a guard checking `reversed_by_journal_id`:
```php
if ($journal->reversed_by_journal_id !== null) {
    throw ValidationException::withMessages([
        'journal' => 'This journal has already been reversed.',
    ]);
}
```

**Modification to Payment::booted() updating hook:**

The `updating` hook currently rejects ALL changes when original status is `'posted'`. The reversal workflow needs to set `status → 'reversed'` and `reversed_by_payment_id`. The hook must be extended with a whitelist, mirroring `AccountingJournal`:

```php
static::updating(function (self $payment): void {
    if ($payment->getOriginal('status') === 'posted') {
        $dirty = array_keys($payment->getDirty());
        $allowed = ['status', 'reversed_by_payment_id'];
        if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
            throw ValidationException::withMessages([
                'payment' => 'Posted payments are immutable. Create a reversal record instead of editing historical financial data.',
            ]);
        }
        // Also guard against transitioning to any status other than 'reversed'
        if (isset($payment->status) && $payment->status !== 'reversed') {
            throw ValidationException::withMessages([
                'payment' => 'A posted payment can only be transitioned to reversed status.',
            ]);
        }
    }
});
```

Add `'reversal_of_payment_id'` and `'reversed_by_payment_id'` to `Payment::$fillable`.

---

### C.4 — Validations that must fire before reversal proceeds

In `reversePayment()`, before any DB write:

1. **Status check:** `$payment->status !== 'posted'` → throw: _"Only posted payments can be reversed."_
2. **Already reversed:** `$payment->reversed_by_payment_id !== null` → throw: _"This payment has already been reversed."_
3. **Tenant isolation:** `$payment->company_id !== $activeCompanyId` → throw: _"The payment must belong to the active company."_ (use `assertJournalBelongsToActiveCompany()` pattern)
4. **Original journal exists:** query for the payment's posted journal (`sourceable_type = Payment::class, sourceable_id = $payment->id, status = 'posted'`); if none found, throw: _"No posted journal found for this payment."_
5. **Journal not already reversed:** checked inside `reverseJournal()` after C.3 guard is added.
6. **[POLICY] Later payments exist:** decide whether to block or allow — see Section E.1.

---

### C.5 — Atomicity: what goes inside one DB transaction

All of the following must succeed together or all must roll back:

```
DB::transaction(function () use ($payment, $attributes) {
    1. Validate all preconditions (no DB writes yet).
    2. Create the reversal Payment record with status='posted', reversal_of_payment_id=$payment->id.
    3. Load the original payment's posted journal ($originalJournal).
    4. Call $this->accounting->reverseJournal($originalJournal, [...]) → creates reversal journal.
    5. Update the original payment: status='reversed', reversed_by_payment_id=$reversalPayment->id.
       (This save() is now allowed by the updated whitelist.)
    6. (Optional) Link the reversal journal's sourceable to the reversal payment
       — already handled by reverseJournal() inheriting sourceable_type/sourceable_id
       unless overridden. Set sourceable to the reversal payment explicitly for clarity.
    7. Return the reversal Payment (fresh).
});
```

Steps 2–5 are atomic. If step 4 throws (unbalanced journal, missing account, etc.) the whole transaction rolls back — no reversal payment is left orphaned.

---

### C.6 — How reversal restores outstanding balance

**Automatically, via calculateOutstandingFor().** Once the original payment's `status` is set to `'reversed'`, the `->where('status', 'posted')` filter in `calculateOutstandingFor()` excludes it. No additional code path is needed.

The reversal payment itself should **not** be linked as a payable payment (i.e., its `payable_type`/`payable_id` should be NULL or match the original purchase, but with `status = 'reversed'` it would also be excluded if linked). The cleanest approach is to not set `payable_type`/`payable_id` on the reversal payment — it is a standalone accounting event, not a new payment against the document.

---

### C.7 — How reversal interacts with resolveConversionRate() and FX accounts

The reversal **must not call resolveConversionRate() or any live rate lookup**. The reversal journal must exactly mirror the original journal at the original rates. `reverseJournal()` already copies `exchange_rate`, `base_debit`, and `base_credit` from original lines — this is correct.

For the reversal Payment record, copy all monetary fields verbatim from the original payment:
- `amount`, `currency`, `exchange_rate`, `base_currency`, `base_amount`
- `applied_amount`, `applied_currency`, `applied_exchange_rate`, `applied_base_amount`
- `direction` (same as original — the journal lines carry the actual debit/credit reversal)
- `financial_account_id` (same as original, for audit trail)
- `party_id`, `project_id` (same as original)

This ensures the reversal payment record is a faithful mirror of the original for audit and reporting purposes. The accounting effect (exactly opposite debits/credits) is carried entirely by the reversal journal.

---

## D. Journal-Entry Examples

### D.1 — Same-currency SYP payment reversal

**Original payment journal** (sourced from `Payment #P1`):
```
Dr  Supplier Payable   SYP 5,000,000    base_debit  5,000,000 SYP
Cr  Cash/Bank          SYP 5,000,000    base_credit 5,000,000 SYP
```

**Reversal journal** (sourced from `Payment #P2`, reversal_of = original journal):
```
Cr  Supplier Payable   SYP 5,000,000    base_credit 5,000,000 SYP
Dr  Cash/Bank          SYP 5,000,000    base_debit  5,000,000 SYP
```

**Effect:** Supplier Payable is re-credited (liability restored), Cash/Bank is re-debited (cash restored). Net ledger effect of P1 + reversal = zero.

---

### D.2 — Cross-currency reversal where original recognized FX loss

**Given:** Original payment settled 1,000 USD liability. R₀ (purchase rate) = 12,000 SYP/USD. Actual cash paid = 12,500,000 SYP (R₁ = 12,500).

**Original payment journal** (3 lines):
```
Line 1: Dr  Supplier Payable  1,000 USD   @ 12,000  base_debit  12,000,000 SYP
Line 2: Cr  Cash/Bank        12,500,000 SYP @ 1     base_credit 12,500,000 SYP
Line 3: Dr  FX Loss            500,000 SYP @ 1     base_debit     500,000 SYP
```

**Reversal journal** (exact mirror — reverseJournal() swaps debit↔credit, copies base amounts):
```
Line 1: Cr  Supplier Payable  1,000 USD   @ 12,000  base_credit 12,000,000 SYP
Line 2: Dr  Cash/Bank        12,500,000 SYP @ 1     base_debit  12,500,000 SYP
Line 3: Cr  FX Loss            500,000 SYP @ 1     base_credit    500,000 SYP
```

**Verification (base-currency balance):**
- Total base debits:  12,500,000
- Total base credits: 12,000,000 + 500,000 = 12,500,000 ✓

**Effect:** Supplier Payable is re-credited 1,000 USD at the original rate (restoring the liability at book value), cash is restored, and the previously recognized FX loss is cancelled by crediting FX Loss (reducing the expense). This is the correct accounting treatment.

---

### D.3 — Cross-currency reversal where original recognized FX gain

**Given:** Original payment settled 1,000 USD liability. R₀ = 12,000 SYP/USD. Actual cash paid = 11,800,000 SYP (R₁ = 11,800).

**Original payment journal** (3 lines):
```
Line 1: Dr  Supplier Payable  1,000 USD   @ 12,000  base_debit  12,000,000 SYP
Line 2: Cr  Cash/Bank        11,800,000 SYP @ 1     base_credit 11,800,000 SYP
Line 3: Cr  FX Gain            200,000 SYP @ 1     base_credit    200,000 SYP
```

**Reversal journal**:
```
Line 1: Cr  Supplier Payable  1,000 USD   @ 12,000  base_credit 12,000,000 SYP
Line 2: Dr  Cash/Bank        11,800,000 SYP @ 1     base_debit  11,800,000 SYP
Line 3: Dr  FX Gain            200,000 SYP @ 1     base_debit     200,000 SYP
```

**Verification:**
- Total base debits:  11,800,000 + 200,000 = 12,000,000
- Total base credits: 12,000,000 ✓

**Effect:** Supplier Payable is re-credited at book value, cash is restored, and the previously recognized FX gain is reversed by debiting FX Gain (the income is un-earned). Economically correct.

---

## E. Edge Cases and Policy Decisions

### E.1 — Reversing when later payments on the same purchase already exist

**Scenario:** P1 (500 USD) and P2 (500 USD) are both posted against a 1,000 USD purchase. The user requests reversal of P1.

**Recommended behavior:** **Allow** the reversal.

Rationale: The reversal of P1 creates a reversal journal that exactly un-does P1's accounting effect. P2 remains valid. After reversal, `calculateOutstandingFor()` returns 500 USD (purchase total − P2). This is correct — the purchase is now half-settled.

The reversal does not create an overpayment risk because it adds to outstanding rather than subtracting from it. The outstanding guard only fires on new payments, not on reversals.

However, if reversing P1 would result in a state where subsequent operations become impossible (e.g., the user wants to reverse all payments in reverse chronological order), that is a user-workflow concern, not a system integrity issue.

**[POLICY DECISION]** Should the system **warn** (not block) when reversing a non-most-recent payment, to help the user understand the ordering implications? A warning is optional but good UX.

---

### E.2 — Reversing an already-reversed payment

**Recommended behavior:** **Reject** with `ValidationException`.

The guard `$payment->reversed_by_payment_id !== null` (added in C.4) catches this. The reversal journal also has its own `reversed_by_journal_id` check (added in C.3). A double-reversal would un-reverse the original, which could be legitimate accounting-theory, but in this system it introduces complexity without clear need. Reject and require the user to record a new payment if they need to re-pay.

**No policy decision required** — rejection is unambiguous.

---

### E.3 — Reversing a partner reimbursement payment

**Scenario:** `recordPartnerReimbursement()` created a payment with:
```
Dr  Due-to-Partner account  (liability)
Cr  Cash/Bank               (asset)
```

**Recommended behavior:** **Allow**, using the generic `reversePayment()`. The reversal journal (via `reverseJournal()`) produces:
```
Cr  Due-to-Partner account  (liability restored — company owes partner again)
Dr  Cash/Bank               (cash restored)
```

This is correct. The partner liability is reinstated. No special casing needed.

**[POLICY DECISION]** Does a reversed partner reimbursement affect any partner-liability-balance calculation? Yes: `calculatePartnerLiabilityBalance()` reads journal lines (not payments), so the reversal journal automatically updates the balance. No additional code is needed, but the business should confirm this is the expected behavior.

---

### E.4 — Reversing a customer receipt

**Scenario:** `recordCustomerReceipt()` created:
```
Dr  Cash/Bank               (asset)
Cr  Customer Receivable     (asset)
```

**Recommended behavior:** **Allow** via a `reverseCustomerReceipt()` method (or the generic `reversePayment()`). The reversal:
```
Cr  Cash/Bank               (asset reduced)
Dr  Customer Receivable     (receivable restored)
```

Outstanding for the Revenue document is automatically restored because the reversal transitions the payment to `'reversed'` status.

**[POLICY DECISION]** Should reversing a customer receipt require confirmation that the customer has not already used the receipt as proof of payment in an external context? This is a business-process question, not a technical one.

---

### E.5 — Reversing a partial payment (not the most recent)

Already addressed in E.1. The recommendation is to allow it. The key technical consideration is that `calculateOutstandingFor()` sums all posted payments — reverting any one of them correctly restores the outstanding.

**[POLICY DECISION]** If the business wants FIFO or LIFO reversal ordering enforced (e.g., "you must reverse the latest payment first"), that is a business rule. Technically the system handles any ordering correctly. Mark as a policy decision.

---

### E.6 — Reversing a draft (unposted) payment

**Recommended behavior:** **Reject** with `ValidationException`: _"Only posted payments can be reversed. Delete draft payments instead."_

A draft payment has no journal entry and no effect on outstanding balance. There is nothing to reverse. The `deleting` hook already allows deletion of draft payments — that is the correct action.

**No policy decision required.**

---

### E.7 — A reversal that would make outstanding exceed the original purchase total

**Scenario:** Data anomaly — a reversal of a payment on a purchase that already had overpayment somehow. Or a concurrent insertion.

**Recommended behavior:** This cannot happen if the existing overpayment guard in `recordPayment()` is working correctly (it is). A reversal does not add a payment to the purchase — it removes one (by marking the original `'reversed'`). Outstanding can only increase from a reversal, never decrease. Outstanding increasing beyond the purchase total is impossible because outstanding is capped at the total:

```php
$outstanding = bcsub($total, $paid, 10);
if (bccomp($outstanding, '0', 10) < 0) { return '0'; }
```

A reversal can at most restore outstanding to the purchase total. No guard is needed here.

**No policy decision required.**

---

## F. Required Changes and Tests

### F.1 — Every migration needed

**Migration 1:** `add_reversal_support_to_payments`
- Add `reversal_of_payment_id` (nullable FK → payments, SET NULL on delete, unique index).
- Add `reversed_by_payment_id` (nullable FK → payments, SET NULL on delete, unique index).

**Migration 2:** `add_unique_constraint_to_reversed_by_journal_id`
- Add `UNIQUE` index on `accounting_journals.reversed_by_journal_id`.
- (Existing non-unique index should be replaced or supplemented — unique implies indexed.)

---

### F.2 — Every model change

**Payment model** (`app/Models/Payment.php`):
- Add `'reversal_of_payment_id'` and `'reversed_by_payment_id'` to `$fillable`.
- Add relationships:
  ```php
  public function reversalOf(): BelongsTo
  public function reversedBy(): BelongsTo
  ```
- Modify `updating` hook to whitelist `['status', 'reversed_by_payment_id']` and enforce that the only allowed status transition from `'posted'` is to `'reversed'`.

**AccountingJournal model** (`app/Models/AccountingJournal.php`):
- No model changes required (relationships and fillable already correct).

**AccountingService** (`app/Services/AccountingService.php`):
- Add double-reversal guard in `reverseJournal()`: check `$journal->reversed_by_journal_id !== null` and throw.

---

### F.3 — Every service method to add/modify with signatures

```php
// PaymentService.php — new public method
public function reversePayment(
    Payment $payment,
    array $attributes = [],
    ?Company $company = null,
): Payment
```

**Parameters:**
- `$payment` — the original posted payment to reverse.
- `$attributes` — optional overrides: `description` (for the reversal journal), `entry_date` (reversal date, defaults to today).
- `$company` — optional; falls back to `$this->companyContext->requireCurrent()`.

**Internal flow:**
1. Validate: `status === 'posted'`.
2. Validate: `reversed_by_payment_id === null`.
3. Validate: company matches active context.
4. Load original journal (`sourceable_type = Payment::class, sourceable_id = $payment->id, status = 'posted'`).
5. Validate: journal found, `reversed_by_journal_id === null`.
6. DB::transaction:
   a. Create reversal Payment (copies all fields, `status = 'posted'`, `reversal_of_payment_id = $payment->id`, `payable_type = null`, `payable_id = null`).
   b. Call `$this->accounting->reverseJournal($originalJournal, ['description' => ..., 'entry_date' => ..., 'sourceable_type' => Payment::class, 'sourceable_id' => $reversalPayment->id])`.
   c. Update original payment: `$payment->status = 'reversed'; $payment->reversed_by_payment_id = $reversalPayment->id; $payment->save();`.
7. Return `$reversalPayment->fresh()`.

```php
// AccountingService.php — modified
public function reverseJournal(AccountingJournal $journal, array $attributes = []): AccountingJournal
// Add: guard against double-reversal (reversed_by_journal_id !== null)
```

---

### F.4 — Every test scenario grouped by category

#### Same-currency reversal
1. Reverse a posted SYP supplier payment → reversal payment created with `reversal_of_payment_id`, original set to `'reversed'`.
2. Reversal journal has exactly 2 lines with swapped debit/credit.
3. Outstanding for the purchase is restored after reversal.
4. Original journal retains `reversed_by_journal_id` pointing to reversal journal.

#### Cross-currency reversal (FX loss original)
5. Original: 1,000 USD at R₀=12,000, paid 12,500,000 SYP (500,000 FX loss).
6. Reversal journal has 3 lines: Cr Payable 1,000 USD, Dr Cash 12,500,000, Cr FX Loss 500,000.
7. Base debit = base credit in reversal journal.
8. Outstanding for the purchase is restored to 1,000 USD.

#### Cross-currency reversal (FX gain original)
9. Original: 1,000 USD at R₀=12,000, paid 11,800,000 SYP (200,000 FX gain).
10. Reversal journal has 3 lines: Cr Payable 1,000 USD, Dr Cash 11,800,000, Dr FX Gain 200,000.
11. Balance verification.

#### Partner paid purchase reversal
12. Reverse a partner-paid purchase payment → Due-to-Partner liability is restored.
13. `calculatePartnerLiabilityBalance()` reflects the reversal.

#### Partner reimbursement reversal
14. Reverse a partner reimbursement → cash is restored, partner liability is reinstated.

#### Customer receipt reversal
15. Reverse a customer receipt → Cash/Bank and Customer Receivable are restored.
16. Outstanding for the Revenue document is restored.

#### Partial payment reversal
17. P1 (partial) + P2 (partial) both posted. Reverse P1 → outstanding = total − P2. P2 remains posted.
18. Reverse P2 after P1 was already reversed → outstanding = full total.

#### Edge cases
19. Attempt to reverse a draft payment → throws ValidationException.
20. Attempt to reverse an already-reversed payment → throws ValidationException.
21. Attempt to reverse a journal that already has `reversed_by_journal_id` set → throws ValidationException (in `AccountingService`).
22. Attempt to reverse a payment belonging to another company → throws ValidationException.
23. Reversal transaction is atomic: if journal reversal fails, payment row is not left orphaned (verify no partial records exist after exception).
24. No live rate lookup occurs during reversal — original `exchange_rate` and `base_amount` are used verbatim.

#### Tenant isolation
25. Company A cannot reverse Company B's payment (even without active context).
26. Reversal journal is scoped to the correct company.

---

## G. Risks and Unresolved Questions

### G.1 — `nullOnDelete()` on reversal FK columns is architecturally weak

Both `reversal_of_journal_id` and `reversed_by_journal_id` on journals use `nullOnDelete()`. If a reversal journal is deleted (theoretically possible if it is somehow in draft state, though `reverseJournal()` always posts immediately), the original journal's `reversed_by_journal_id` silently becomes NULL. The original then appears un-reversed and could be reversed again.

**Risk level:** Low (currently, `reverseJournal()` always posts the reversal immediately, making deletion of the reversal journal blocked by the deleting hook). But the schema should ideally use `RESTRICT` on the FK from the original pointing to the reversal.

**[POLICY DECISION]** Should deletion of a reversal journal be permanently blocked even if draft? Recommend yes. Use `restrictOnDelete()` for `reversed_by_journal_id`.

---

### G.2 — No unique constraint on `reversed_by_journal_id` (currently)

As noted in C.1, nothing prevents the same journal from being assigned multiple reversal journals if `reverseJournal()` is called concurrently or twice in quick succession. The guard in C.3 is application-level only; a concurrent request could pass the guard before either has written the value.

**Mitigation:** The unique index on `reversed_by_journal_id` (Migration 2) provides a DB-level guarantee. The application guard plus the unique index together are sufficient.

---

### G.3 — Inventory impact of reversing a purchase payment

`reversePayment()` reverses only the **payment** journal, not the purchase journal. A purchase that was paid and then had its payment reversed still has inventory received and its purchase journal posted. The outstanding balance is restored (supplier still needs to be paid), but inventory is not reversed.

**This is correct behavior** — a payment reversal means "we didn't pay yet / we want our money back", not "we are returning the goods."

**[POLICY DECISION]** Confirm that reversing a payment does NOT trigger any stock movement reversal. If purchase cancellation (returning goods) is ever needed, that is a separate workflow (purchase return) and must not be conflated with payment reversal.

---

### G.4 — reversePayment() with no company context active

`assertJournalBelongsToActiveCompany()` silently passes when `$activeCompanyId === null`. This means internal service calls (e.g., from a CLI command with no active context) skip company isolation. The payment-level guard should compare `$payment->company_id` against either the active context or the explicitly passed `$company`, and throw if neither can be determined.

**Recommend:** Make the company parameter required or fall back to `$this->companyContext->requireCurrent()`, mirroring how `recordSupplierPayment()` works.

---

### G.5 — Payment status transition from 'posted' to 'reversed' bypasses immutability

The proposed hook change (C.3) adds a whitelist allowing `['status', 'reversed_by_payment_id']` to be modified on a posted payment. This is correct but represents a deliberate relaxation of the immutability invariant. Any future developer could add other columns to that whitelist without realizing the security implication.

**[POLICY DECISION]** Document this whitelist explicitly in code comments. Consider adding an audit log entry whenever a payment is transitioned to `'reversed'` status.

---

### G.6 — No RevenueService status lifecycle

`RevenueService` has `status = 'posted'` after `postRevenue()`, but the `Revenue` model has no immutability hooks (unlike `Payment` and `AccountingJournal`). Reversing a customer receipt uses the payment's journal reversal correctly, but the Revenue document itself has no guard against modification after posting.

**[POLICY DECISION]** Should the `Revenue` model gain immutability hooks before reversal is implemented? Recommend yes, for consistency — the payment reversal restores the outstanding for the Revenue, but if Revenue itself can be edited, the outstanding calculation could be manipulated.

---

### G.7 — Draft payment status transition gap

A draft payment can currently be directly `save()`-d to `'posted'` by setting `$payment->status = 'posted'; $payment->save()`. The `updating` hook only guards against changes when the **original** status is `'posted'`. There is no service-enforced path for draft→posted other than `recordPayment()`. This is a low-risk gap today but could be exploited to create posted payments without journals. Consider a guard that requires status transitions through the service layer.

---

### G.8 — Reversal of partner-paid purchases requires explicit policy on FX accounts

If a cross-currency partner-paid purchase payment is reversed, `reverseJournal()` copies the FX gain/loss lines verbatim. The FX gain/loss accounts remain the same. If those accounts have been deleted or reassigned between the original payment and the reversal, `reverseJournal()` will fail when posting (account not found or wrong company).

**[POLICY DECISION]** Confirm that FX gain/loss accounts are permanent (not deletable while they have journal lines). The current `financial_accounts` FK uses `restrictOnDelete()` on journal_lines, which already prevents this. Document it explicitly for operators.

---

*End of audit report. No source files were modified.*
