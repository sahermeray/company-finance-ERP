# Implementation Plan — Payment & Journal Reversal Workflow

## Codebase findings that drive every decision

- **Payments table** has no `reversal_of_payment_id` or `reversed_by_payment_id` columns yet.  
  Existing indexes: `payments_company_number_unique` (unique), `payments_company_status_idx`, `payments_company_date_idx`, `payments_company_id_currency_index`, `payments_financial_account_id_index`, `payments_party_id_index`, `payments_payable_type_payable_id_index`, `payments_project_id_index`. No index on either reversal column yet.

- **accounting_journals table** has `reversal_of_journal_id` and `reversed_by_journal_id` columns from migration `2026_10_10_000001_add_reversal_support_to_accounting_journals.php`, but **both have only plain (non-unique) indexes** — `accounting_journals_reversal_of_journal_id_index` and `accounting_journals_reversed_by_journal_id_index`, both `unique=0`. The unique constraint on `reversed_by_journal_id` does not exist yet.

- **Payment.php updating hook** throws on ANY attribute change when `getOriginal('status') === 'posted'`. Must be narrowed.

- **AccountingJournal.php updating hook** already whitelists `['status', 'posted_at', 'reversed_by_journal_id']` — updating `reversed_by_journal_id` on a posted journal works without DB bypass. `sourceable_type` and `sourceable_id` are NOT in the allowed list, so updating sourceable on a posted journal via Eloquent will throw. Use a raw DB update to set sourceable on the reversal journal after it is posted.

- **reverseJournal()** has no `DB::transaction` wrapper, no double-reversal guard, no `lockForUpdate`, and inherits the original journal's `sourceable_type/sourceable_id` (which we need to replace with the reversal payment's).

- **JournalLine** has NO `booted()` hook — lines belonging to posted journals can be edited or deleted directly. Must add one.

- **Revenue.php** already has an `updating` hook with `$allowed = ['status']`. DO NOT touch it.

- **calculateOutstandingFor()** filters `WHERE status = 'posted'` AND matches `payable_type/payable_id`. Reversal payments have no `payable_type/payable_id` and the original becomes `'reversed'`, so no changes to that method are needed.

- **generatePaymentNumber()** is a private method in `PaymentService` — available for use inside `reversePayment()`.

- **CompanyContextService::requireCurrent()** calls `abort(409, ...)` when no context — matching the pattern in other service methods.

- Test helper conventions (from existing tests): `RefreshDatabase`, helper functions defined in the test file, `Company::create(...)` directly (not a factory), `Party::create(...)`, `Purchase::create(...)` / `Revenue::create(...)` directly, `FinancialAccount::create(...)`, `withoutGlobalScope(CompanyScope::class)` to bypass tenant scope in assertions. Tests use `expect(...)->toThrow(ValidationException::class)` for error assertions.

---

## Step 1 — Migration A: add reversal columns to payments table

**Decision:** Use `nullOnDelete()` on both FKs (matching the journal convention). Use `unique()` indexes on both columns to prevent duplicate reversals at DB level. Before creating, we've confirmed neither column nor its index exists yet on `payments`.

- [ ] 1. Create migration `2026_10_12_000001_add_reversal_columns_to_payments.php`.
      Add to `payments`:
      - `reversal_of_payment_id` — nullable unsignedBigInteger FK → payments.id, nullOnDelete()
      - `reversed_by_payment_id` — nullable unsignedBigInteger FK → payments.id, nullOnDelete()
      - Unique index on `reversed_by_payment_id` (prevents two payments pointing to the same original at DB level).
      - Plain index on `reversal_of_payment_id` (no uniqueness needed — a reversal can only reference one original, but multiple might claim the same original without the constraint; the unique constraint on `reversed_by_payment_id` covers one direction).
      
      The `down()` method drops indexes first, then the FK columns.

      File: `database/migrations/2026_10_12_000001_add_reversal_columns_to_payments.php`
      
      Verify: `php artisan migrate --no-interaction` exits 0, then `php artisan tinker` shows both columns and that `reversed_by_payment_id` has a unique index.

---

## Step 2 — Migration B: unique constraint on accounting_journals.reversed_by_journal_id

**Decision:** The existing `accounting_journals_reversed_by_journal_id_index` is plain (`unique=0`). Replace it with a unique index. On SQLite, we cannot alter an index in place; we must drop the old one and add a new unique one.

- [ ] 2. Create migration `2026_10_12_000002_unique_reversed_by_journal_id.php`.
      In `up()`: 
      - `$table->dropIndex('accounting_journals_reversed_by_journal_id_index');`
      - `$table->unique('reversed_by_journal_id', 'journals_reversed_by_journal_id_unique');`
      
      In `down()`:
      - `$table->dropUnique('journals_reversed_by_journal_id_unique');`
      - `$table->index('reversed_by_journal_id', 'accounting_journals_reversed_by_journal_id_index');`

      File: `database/migrations/2026_10_12_000002_unique_reversed_by_journal_id.php`
      
      Verify: `php artisan migrate --no-interaction` exits 0, then tinker confirms `journals_reversed_by_journal_id_unique` has `unique=1`.

---

## Step 3 — Payment model: add fillable fields, relationships, and narrow the updating hook

**Decision:** Whitelist `['status', 'reversed_by_payment_id']` when the original status is `'posted'`, with an additional check that the only allowed target status is `'reversed'`. Everything else remains immutable.

- [ ] 3. Edit `app/Models/Payment.php`:
  
      a. Add to `$fillable`:
         - `'reversal_of_payment_id'`
         - `'reversed_by_payment_id'`
  
      b. Add two BelongsTo relationships:
         ```php
         public function reversalOf(): BelongsTo
         {
             return $this->belongsTo(Payment::class, 'reversal_of_payment_id');
         }
         
         public function reversedBy(): BelongsTo
         {
             return $this->belongsTo(Payment::class, 'reversed_by_payment_id');
         }
         ```
  
      c. Replace the `updating` hook with this narrower version:
         ```php
         static::updating(function (self $payment): void {
             if ($payment->getOriginal('status') === 'posted') {
                 // Whitelist: the only mutations allowed on a posted payment are the
                 // two fields the reversal workflow needs to set atomically.
                 //   - 'status': transitions the original from 'posted' to 'reversed'.
                 //   - 'reversed_by_payment_id': links the original to its reversal record.
                 // All other financial fields remain permanently immutable once posted.
                 $allowed = ['status', 'reversed_by_payment_id'];
                 $dirty   = array_keys($payment->getDirty());
         
                 if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
                     throw ValidationException::withMessages([
                         'payment' => 'Posted payments are immutable. Create a reversal record instead of editing historical financial data.',
                     ]);
                 }
         
                 // Guard: the only permitted status transition from 'posted' is to 'reversed'.
                 // Any other target status (e.g. 'draft', 'cancelled') must still throw.
                 if ($payment->isDirty('status') && $payment->status !== 'reversed') {
                     throw ValidationException::withMessages([
                         'payment' => 'The only permitted status transition for a posted payment is to \'reversed\'.',
                     ]);
                 }
             }
         });
         ```
  
      File: `app/Models/Payment.php`
      
      Verify: `php vendor/bin/pest --no-coverage` — all 178 existing tests still pass.

---

## Step 4 — JournalLine model: add immutability protection for posted journal lines

**Decision:** Mirror AccountingJournal's pattern. Block `update` and `delete` on JournalLine when the parent journal is `'posted'`. The check loads the journal relation (already available as `$line->journal` once set, or fetched without the global scope to match how `postJournal()` works).

- [ ] 4. Edit `app/Models/JournalLine.php`. Add a `booted()` method:
      ```php
      public static function booted(): void
      {
          static::updating(function (self $line): void {
              // Load the parent journal bypassing CompanyScope so this check
              // works inside service calls that have no active company context.
              $journal = $line->journal ?? AccountingJournal::withoutGlobalScope(
                  \App\Eloquent\CompanyScope::class
              )->find($line->accounting_journal_id);
      
              if ($journal !== null && $journal->status === 'posted') {
                  throw \Illuminate\Validation\ValidationException::withMessages([
                      'journal_line' => 'Journal lines belonging to a posted journal are immutable.',
                  ]);
              }
          });
      
          static::deleting(function (self $line): void {
              $journal = $line->journal ?? AccountingJournal::withoutGlobalScope(
                  \App\Eloquent\CompanyScope::class
              )->find($line->accounting_journal_id);
      
              if ($journal !== null && $journal->status === 'posted') {
                  throw \Illuminate\Validation\ValidationException::withMessages([
                      'journal_line' => 'Journal lines belonging to a posted journal cannot be deleted.',
                  ]);
              }
          });
      }
      ```
      
      Add `use App\Eloquent\CompanyScope;` to the imports (check whether it is already present).
      
      **IMPORTANT:** `postJournal()` in `AccountingService` calls `$line->save()` while building lines — but at that point the journal is still `'draft'`. The hook only fires when `status === 'posted'`, so existing posting logic is unaffected.
      
      File: `app/Models/JournalLine.php`
      
      Verify: `php vendor/bin/pest --no-coverage` — all 178 existing tests still pass.

---

## Step 5 — AccountingService::reverseJournal(): add double-reversal guard, DB lock, and transaction wrapper

**Decision:** Wrap the entire method in `DB::transaction`. Add `lockForUpdate()` inside the transaction when refreshing the journal. Add the double-reversal guard immediately after re-reading the journal. The `sourceable_type/sourceable_id` fields on the reversal journal will be overwritten by `reversePayment()` via a raw DB update after this method returns (since the journal is posted by the time it returns and the hook does not allow changing sourceable on a posted journal). `reverseJournal()` itself should keep passing through whatever sourceable is provided in `$attributes` so that future callers (not payment-related) still work.

- [ ] 5. Edit `app/Services/AccountingService.php`. Replace the `reverseJournal()` method with:
  
      ```php
      public function reverseJournal(AccountingJournal $journal, array $attributes = []): AccountingJournal
      {
          return DB::transaction(function () use ($journal, $attributes): AccountingJournal {
              // Re-read the journal with a row-level write lock so concurrent
              // reversal requests are serialised.  lockForUpdate() is a no-op on
              // SQLite but provides serialisation on MySQL/PostgreSQL.
              $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                  ->with(['lines', 'company'])
                  ->lockForUpdate()
                  ->find($journal->getKey());
      
              if ($journal === null) {
                  throw ValidationException::withMessages([
                      'journal' => 'The journal could not be found.',
                  ]);
              }
      
              if ($journal->status !== 'posted') {
                  throw ValidationException::withMessages([
                      'journal' => 'Only posted journals can be reversed.',
                  ]);
              }
      
              // Double-reversal guard: reject if this journal has already been reversed.
              if ($journal->reversed_by_journal_id !== null) {
                  throw ValidationException::withMessages([
                      'journal' => 'This journal has already been reversed.',
                  ]);
              }
      
              $reversal = $this->createDraftJournal([
                  'company_id'               => $journal->company_id,
                  'description'              => $attributes['description'] ?? 'Reversal of '.$journal->journal_number,
                  'entry_date'               => $attributes['entry_date'] ?? now()->toDateString(),
                  'project_id'               => $attributes['project_id'] ?? $journal->project_id,
                  'sourceable_type'          => $attributes['sourceable_type'] ?? $journal->sourceable_type,
                  'sourceable_id'            => $attributes['sourceable_id'] ?? $journal->sourceable_id,
                  'reversal_of_journal_id'   => $journal->getKey(),
              ], $journal->company);
      
              foreach ($journal->lines()->orderBy('line_number')->get() as $line) {
                  $reversed = [
                      'financial_account_id' => $line->financial_account_id,
                      'party_id'             => $line->party_id,
                      'project_id'           => $line->project_id,
                      'description'          => $line->description,
                      'currency'             => $line->currency,
                      'exchange_rate'        => $line->exchange_rate,
                      'base_currency'        => $line->base_currency,
                  ];
      
                  if (bccomp((string) $line->debit, '0', 10) > 0) {
                      $reversed['credit']      = $line->debit;
                      $reversed['base_credit'] = $line->base_debit ?? $line->debit;
                  } else {
                      $reversed['debit']      = $line->credit;
                      $reversed['base_debit'] = $line->base_credit ?? $line->credit;
                  }
      
                  $this->addJournalLine($reversal, $reversed);
              }
      
              $this->postJournal($reversal);
      
              $journal->reversed_by_journal_id = $reversal->getKey();
              $journal->save();
      
              return $reversal->fresh(['lines']);
          });
      }
      ```
      
      File: `app/Services/AccountingService.php`
      
      Verify: `php vendor/bin/pest --no-coverage` — all 178 existing tests still pass.

---

## Step 6 — PaymentService::reversePayment()

**Decision for sourceable update:** After `reverseJournal()` returns a posted journal, updating `sourceable_type/sourceable_id` on it via Eloquent would trigger the AccountingJournal updating hook (which only allows `['status', 'posted_at', 'reversed_by_journal_id']`). Use a direct `DB::table()` update (bypasses Eloquent model events) with a comment explaining why.

**Decision for company context:** Follow the `$company ?? $this->companyContext->requireCurrent()` pattern used in `recordPartnerReimbursement()` and others.

**Decision for payment number:** Use the existing private `generatePaymentNumber($company)` method.

**Decision on which fields to copy:** Copy all monetary/identity fields present in `$fillable` except `payable_type`, `payable_id`, and `reversed_by_payment_id`. The reversal payment is not a payment against a document; it has no payable link. `reversed_by_payment_id` is for the original, not the reversal.

- [ ] 6. Add `reversePayment()` to `app/Services/PaymentService.php`.
      Place it after `recordPartnerReimbursement()` and before the private methods. Add `use App\Models\Payment;` and the `Company` import if not already present (they are).

      ```php
      /**
       * Reverse a posted payment and its posted accounting journal.
       *
       * Creates an equal-and-opposite reversal payment record (without a payable link)
       * and calls AccountingService::reverseJournal() to produce the mirror journal.
       * Updates the original payment to 'reversed' status.
       *
       * @throws ValidationException when the payment cannot be reversed.
       */
      public function reversePayment(
          Payment $payment,
          array $attributes = [],
          ?Company $company = null,
      ): Payment {
          // 1. Resolve company — explicit arg or active context; never silently skip isolation.
          $company ??= $this->companyContext->requireCurrent();
      
          // 2. Verify payment belongs to the resolved company.
          if ((int) $payment->company_id !== (int) $company->getKey()) {
              throw ValidationException::withMessages([
                  'payment' => 'The payment does not belong to the active company.',
              ]);
          }
      
          // 3. Only posted payments can be reversed.
          if ($payment->status !== 'posted') {
              throw ValidationException::withMessages([
                  'payment' => 'Only posted payments can be reversed. Draft payments should be deleted instead.',
              ]);
          }
      
          // 4. Reject already-reversed payments.
          if ($payment->reversed_by_payment_id !== null) {
              throw ValidationException::withMessages([
                  'payment' => 'This payment has already been reversed.',
              ]);
          }
      
          // 5. Locate the single posted journal for this payment.
          $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
              ->where('sourceable_type', Payment::class)
              ->where('sourceable_id', $payment->getKey())
              ->where('status', 'posted')
              ->get();
      
          if ($journals->isEmpty()) {
              throw ValidationException::withMessages([
                  'payment' => 'No posted accounting journal was found for this payment.',
              ]);
          }
      
          if ($journals->count() > 1) {
              throw ValidationException::withMessages([
                  'payment' => 'Multiple posted journals exist for this payment; cannot determine which to reverse.',
              ]);
          }
      
          $originalJournal = $journals->first();
      
          // 6. Reject if the journal has already been reversed.
          if ($originalJournal->reversed_by_journal_id !== null) {
              throw ValidationException::withMessages([
                  'journal' => 'The accounting journal for this payment has already been reversed.',
              ]);
          }
      
          return DB::transaction(function () use ($payment, $company, $attributes, $originalJournal): Payment {
              // 7a. Lock the original payment row to prevent concurrent reversals.
              //     lockForUpdate() is a no-op on SQLite but serialises on MySQL/PostgreSQL.
              $lockedPayment = Payment::withoutGlobalScope(CompanyScope::class)
                  ->lockForUpdate()
                  ->find($payment->getKey());
      
              // 7b. Re-read status and reversed_by_payment_id inside the transaction (TOCTOU guard).
              if ($lockedPayment === null || $lockedPayment->status !== 'posted') {
                  throw ValidationException::withMessages([
                      'payment' => 'Only posted payments can be reversed. Draft payments should be deleted instead.',
                  ]);
              }
      
              if ($lockedPayment->reversed_by_payment_id !== null) {
                  throw ValidationException::withMessages([
                      'payment' => 'This payment has already been reversed.',
                  ]);
              }
      
              // 7c. Create the reversal payment.
              //     Copy all monetary / identity fields but omit:
              //       - payable_type / payable_id   → reversal is not a new payment against a document.
              //       - reversed_by_payment_id       → that field belongs to the original, not the reversal.
              //     Set reversal_of_payment_id to link back to the original.
              $reversalPayment = Payment::create([
                  'company_id'              => $payment->company_id,
                  'party_id'                => $payment->party_id,
                  'financial_account_id'    => $payment->financial_account_id,
                  'currency'                => $payment->currency,
                  'amount'                  => $payment->amount,
                  'exchange_rate'           => $payment->exchange_rate,
                  'base_currency'           => $payment->base_currency,
                  'base_amount'             => $payment->base_amount,
                  'direction'               => $payment->direction,
                  'paid_at'                 => $attributes['paid_at'] ?? now(),
                  'applied_amount'          => $payment->applied_amount,
                  'applied_currency'        => $payment->applied_currency,
                  'applied_exchange_rate'   => $payment->applied_exchange_rate,
                  'applied_base_amount'     => $payment->applied_base_amount,
                  'status'                  => 'posted',
                  'posted_at'               => now(),
                  'reversal_of_payment_id'  => $payment->getKey(),
                  'payment_number'          => $this->generatePaymentNumber($company),
              ]);
      
              // 7d. Reverse the accounting journal.
              //     reverseJournal() wraps itself in a DB::transaction — nesting is safe in Laravel.
              //     We pass sourceable_type/sourceable_id pointing to the ORIGINAL payment here;
              //     the reversal journal's sourceable is updated in step 7e below once we have
              //     the reversal payment's ID.
              $reversalJournal = $this->accounting->reverseJournal($originalJournal, [
                  'description' => $attributes['description'] ?? 'Reversal of payment #'.$payment->getKey(),
                  'entry_date'  => $attributes['entry_date'] ?? now()->toDateString(),
              ]);
      
              // 7e. Re-point the reversal journal's sourceable to the reversal payment.
              //     We cannot use Eloquent here: AccountingJournal's updating hook only allows
              //     ['status', 'posted_at', 'reversed_by_journal_id'] on a posted journal, and
              //     sourceable_type/sourceable_id are not in that whitelist.
              //     A direct DB update bypasses model events intentionally — the data is correct
              //     and the immutability hook exists to prevent accidental edits, not this
              //     deliberate, audited re-association performed immediately after creation.
              DB::table('accounting_journals')
                  ->where('id', $reversalJournal->getKey())
                  ->update([
                      'sourceable_type' => Payment::class,
                      'sourceable_id'   => $reversalPayment->getKey(),
                  ]);
      
              // 7f. Transition original payment to 'reversed' and link it to the reversal.
              //     This succeeds because the Payment updating hook whitelists
              //     ['status', 'reversed_by_payment_id'] when transitioning from 'posted',
              //     and 'reversed' is the only allowed target status.
              $lockedPayment->status                  = 'reversed';
              $lockedPayment->reversed_by_payment_id = $reversalPayment->getKey();
              $lockedPayment->save();
      
              // 7g. Return the fresh reversal payment.
              return $reversalPayment->fresh();
          });
      }
      ```

      Also ensure `use App\Models\Payment;` is already imported (it is, confirmed by reading the file).
      No new imports needed beyond what is already at the top of PaymentService.php.
      
      File: `app/Services/PaymentService.php`
      
      Verify: `php vendor/bin/pest --no-coverage` — all 178 existing tests still pass.

---

## Step 7 — Tests: tests/Feature/PaymentReversalTest.php

**Decision on test conventions:** Match the patterns from `PartnerPaidPurchaseTest.php` and `CrossCurrencySettlementTest.php`: `RefreshDatabase`, `uses(RefreshDatabase::class)`, `Company::create(...)` directly, `FinancialAccount::create(...)` directly, helper functions at the top of the file, `expect(...)->toThrow(ValidationException::class)` for rejections, `withoutGlobalScope(CompanyScope::class)` in DB assertions.

**Test 16 (journal belongs to correct company) and Test 17 (atomic rollback)** are critical correctness checks. For rollback, mock `AccountingService::reverseJournal()` to throw mid-transaction, then assert no payment row was created and the original payment status is unchanged.

Create `tests/Feature/PaymentReversalTest.php` with all 23 test cases below. The file must include helpers for creating the standard context (company + accounts) and for counting records bypassing scopes.

### Helpers

```php
/**
 * Create a company with all accounts needed for reversal tests:
 *   - SYP Cash (asset, SYP)
 *   - SYP Supplier Payable (liability, SYP)
 *   - USD Supplier Payable (liability, USD)
 *   - Customer Receivable (asset, SYP)
 *   - Due to Partner (liability, SYP)
 *   - FX Gain (income, SYP)
 *   - FX Loss (expense, SYP)
 *   company->exchange_gain_account_id and exchange_loss_account_id set.
 */
function makeReversalContext(): array { ... }

/**
 * Post a SYP supplier payment and return [$payment, $journal].
 */
function makePostedSupplierPayment(Company $company, ...): array { ... }

/**
 * Post a cross-currency (USD purchase, SYP payment) payment and return [$payment, $journal].
 */
function makePostedCrossPayment(Company $company, ...): array { ... }
```

### Tests (23 total)

- [ ] 7. Create `tests/Feature/PaymentReversalTest.php` with:

  1. `test('reverse a posted same-currency SYP supplier payment')` — Assert: reversal payment created with `reversal_of_payment_id = $original->id`, original status = `'reversed'`, original journal `reversed_by_journal_id` set, reversal journal has 2 lines with swapped debit/credit vs. original.

  2. `test('outstanding balance is restored after same-currency reversal')` — Record a partial payment against a purchase, reverse it, assert outstanding returns to the original purchase total.

  3. `test('reverse cross-currency payment with FX loss produces 3-line reversal journal')` — USD purchase, SYP payment at higher rate than original (FX loss scenario). Assert reversal journal has exactly 3 lines, FX loss line becomes FX credit, debit/credit swapped.

  4. `test('reverse cross-currency payment with FX gain produces 3-line reversal journal')` — As above but lower settlement rate (FX gain scenario). Assert 3 lines.

  5. `test('base-currency debit equals base-currency credit in cross-currency reversal journal')` — For both FX loss and FX gain scenarios, sum base_debit across all lines = sum base_credit across all lines.

  6. `test('no live exchange rate lookup occurs during reversal')` — Delete all ExchangeRate rows before calling `reversePayment()`. Assert no exception is thrown (reversal copies stored amounts, never recalculates).

  7. `test('reverse a partner-paid purchase payment restores partner liability')` — Record a `recordPartnerPaidPurchase()` payment. Reverse it. Assert the reversal journal has the Dr/Cr on the supplier payable and due-to-partner accounts swapped back.

  8. `test('reverse a partner reimbursement restores cash and partner liability')` — Record a `recordPartnerReimbursement()` payment. Reverse it. Assert reversal journal restores both sides.

  9. `test('reverse a customer receipt restores customer receivable')` — Record a `recordCustomerReceipt()` payment. Reverse it. Assert reversal journal debits receivable / credits cash (reversed of Dr Cash / Cr Receivable).

  10. `test('reversing P1 partial payment with P2 also posted leaves P2 outstanding intact')` — Two partial payments P1, P2 on the same purchase. Reverse P1 only. Assert outstanding = `purchase.total - P2.applied_amount`, P2 status still `'posted'`.

  11. `test('reversing a draft payment throws ValidationException')` — Call `reversePayment()` on a draft payment. Expect `ValidationException`.

  12. `test('reversing an already-reversed payment throws ValidationException')` — Reverse a payment once (succeeds). Call `reversePayment()` on the same original again. Expect `ValidationException`.

  13. `test('reversing a journal that already has reversed_by_journal_id throws ValidationException')` — Directly set `reversed_by_journal_id` on the journal via `DB::table(...)` (bypassing the Eloquent hook) to simulate a pre-existing reversal. Then call `reversePayment()`. Expect `ValidationException`.

  14. `test('reversing a payment belonging to another company throws ValidationException')` — Create two companies with separate payments. Pass company B's payment with company A as the explicit `$company` argument. Expect `ValidationException`.

  15. `test('reversing with unresolvable company context throws')` — Pass a payment with `company_id = companyB->id` but `$company = companyA`. Expect `ValidationException`.

  16. `test('reversal journal belongs to the correct company')` — Assert `$reversalJournal->company_id === $company->id` after reversal.

  17. `test('atomic rollback: if reverseJournal fails no reversal payment row remains')` — Spy on `AccountingService` and make `reverseJournal()` throw. Wrap the call in a try/catch. Assert the reversal Payment row does not exist and original payment status is still `'posted'`.  
      Implementation: use `$this->mock(AccountingService::class, ...)` or `$this->partialMock(...)` to throw a `RuntimeException` inside `reverseJournal()`. Assert `Payment::withoutGlobalScope(CompanyScope::class)->where('reversal_of_payment_id', $original->id)->doesntExist()` and `$original->fresh()->status === 'posted'`.

  18. `test('reversal payment is not counted in outstanding calculation')` — Reverse a payment. Assert `calculateOutstandingFor($purchase)` returns the same amount as before the original payment was made (i.e., outstanding is fully restored), and that the reversal payment itself (which has no `payable_type/payable_id`) does not accidentally count.

  19. `test('posted payment financial fields are immutable')` — Directly attempt `$payment->amount = '9999'; $payment->save()` on a posted payment. Expect `ValidationException`.

  20. `test('posted payment arbitrary status transition is blocked')` — Directly attempt `$payment->status = 'cancelled'; $payment->save()`. Expect `ValidationException` with message matching `'reversed'`.

  21. `test('posted journal lines cannot be edited or deleted via direct Eloquent operations')` — Get a line from a posted journal. Attempt `$line->description = 'hack'; $line->save()`. Expect `ValidationException`. Also attempt `$line->delete()`. Expect `ValidationException`.

  22. `test('DB unique constraint on reversed_by_payment_id prevents duplicate reversal payment')` — Use `DB::table('payments')->insert(...)` to create a second payment row with the same `reversed_by_payment_id`. Expect a database exception (unique constraint violation). This verifies the DB-level guard independently of the service layer.

  23. `test('DB unique constraint on reversed_by_journal_id prevents duplicate reversal journal')` — Use `DB::table('accounting_journals')->insert(...)` to create a second journal row with the same `reversed_by_journal_id`. Expect a database exception.

  File: `tests/Feature/PaymentReversalTest.php`
  
  Verify:
  ```
  php vendor/bin/pest --filter PaymentReversal --no-coverage
  ```
  All 23 new tests pass, 0 failures.

---

## Step 8 — Full suite verification

- [ ] 8. Run the complete test suite:
      ```
      php vendor/bin/pest --no-coverage
      ```
      Must pass with:
      - At least 201 tests (178 existing + 23 new)
      - At least 498 assertions (likely well over)
      - 0 failures
      - Exit code 0
      
      If any existing test fails, it must be because of an interaction with the new JournalLine immutability hook. Check whether any test directly calls `$line->save()` on a posted journal's lines and fix the hook to not fire on lines being initially saved (it must only fire on `updating` — which is a subsequent save, not the first `create`). The `creating` event is not hooked, so initial creation is always safe.

---

## Step 9 — Write final report

- [ ] 9. Write `{workspaceRoot}/.agents/tasks/final-report.md` covering:
      - Files changed and purpose of each change
      - Both migrations and the constraints they add
      - Step-by-step reversal workflow description
      - Double-reversal prevention mechanisms (service guard + DB unique constraint on both tables)
      - Company isolation enforcement (explicit company check, `requireCurrent()` fallback)
      - How outstanding calculations exclude reversal records (no `payable_type/payable_id`, original becomes `'reversed'`)
      - Revenue/JournalLine immutability: what was added (JournalLine booted hook) and what remains a gap (draft→posted status transition not fully guarded at the model layer)
      - Test names and total count
      - Full-suite result: tests, assertions, failures, exit code
      - Unresolved limitations or policy assumptions

---

## Key facts for the implementer

| Fact | Value |
|------|-------|
| `reversed_by_journal_id` current index | plain, not unique — must be replaced |
| `reversed_by_payment_id` current index | does not exist yet |
| `reversal_of_payment_id` current index | does not exist yet |
| AccountingJournal hook allowed dirty keys | `['status', 'posted_at', 'reversed_by_journal_id']` |
| Sourceable update on posted journal | requires raw `DB::table()->update()` — Eloquent hook blocks it |
| `reverseJournal()` nested transaction | Laravel nested transactions are safe (savepoints on MySQL; single TX on SQLite) |
| `generatePaymentNumber()` visibility | `private` — accessible inside PaymentService methods |
| JournalLine `save()` in `postJournal()` | called while journal is still `draft` — JournalLine hook does NOT fire (journal.status ≠ 'posted' at that point) |
| Revenue updating hook | `$allowed = ['status']` — DO NOT modify |
| Test DB engine | SQLite (in-memory) — `lockForUpdate()` is silently ignored |
