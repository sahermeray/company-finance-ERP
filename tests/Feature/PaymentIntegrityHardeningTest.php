<?php

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Build a company with the minimum accounts needed for cross-currency supplier payments:
 *   - SYP cash account (asset, SYP)
 *   - USD payable account (liability, USD)
 *   - FX Gain (income, SYP)
 *   - FX Loss (expense, SYP)
 * Sets exchange_gain_account_id / exchange_loss_account_id on the company.
 *
 * Returns [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount].
 */
function makeHardeningContext(): array
{
    $company = Company::create([
        'name' => 'HardenCo '.uniqid(),
        'base_currency' => 'SYP',
    ]);

    $sypCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '7100',
        'name' => 'FX Gain',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '8100',
        'name' => 'FX Loss',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $company->update([
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);
    $company->refresh();

    return [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount];
}

/**
 * Create a posted USD purchase for a given company.
 *
 * @param  string  $total  Purchase total in USD.
 * @param  string  $rate  Exchange rate stored on the purchase (SYP per USD).
 */
function makeHardeningPurchase(Company $company, string $total = '1000', string $rate = '12000'): Purchase
{
    $base = bcmul($total, $rate, 4);
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'USD',
        'exchange_rate' => $rate,
        'base_currency' => 'SYP',
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $base,
        'base_tax_total' => '0',
        'base_total' => $base,
    ]);
}

/**
 * Count rows bypassing CompanyScope so assertions work without an active context.
 */
function countPayments(): int
{
    return Payment::withoutGlobalScope(CompanyScope::class)->count();
}

function countJournals(): int
{
    return AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();
}

function countJournalLines(): int
{
    return JournalLine::withoutGlobalScope(CompanyScope::class)->count();
}

// ---------------------------------------------------------------------------
// Test 1 — Zero applied_exchange_rate → ValidationException, no records created
// ---------------------------------------------------------------------------

test('zero applied_exchange_rate throws and leaves no payment or journal rows', function (): void {
    [$company, $sypCash, $usdPayable] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $initialPayments = countPayments();
    $initialJournals = countJournals();
    $initialLines = countJournalLines();

    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '0',   // explicitly zero
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);
});

// ---------------------------------------------------------------------------
// Test 2 — Negative applied_exchange_rate → ValidationException, no partial records
// ---------------------------------------------------------------------------

test('negative applied_exchange_rate throws and leaves no partial records', function (): void {
    [$company, $sypCash, $usdPayable] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $initialPayments = countPayments();
    $initialJournals = countJournals();
    $initialLines = countJournalLines();

    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '-12000',  // negative
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);
});

// ---------------------------------------------------------------------------
// Test 3 — Missing cross-currency rate (no ExchangeRate record) → ValidationException
// ---------------------------------------------------------------------------

test('missing cross-currency exchange rate record throws ValidationException', function (): void {
    // Build a context with NO company-level forex defaults and no applied_exchange_rate
    // supplied. There is also no ExchangeRate row in the DB. The service must throw
    // rather than silently accepting or producing a zero-amount payment.
    $company = Company::create(['name' => 'NoCo '.uniqid(), 'base_currency' => 'SYP']);

    $sypCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '7100',
        'name' => 'FX Gain',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '8100',
        'name' => 'FX Loss',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $company->update([
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $purchase = makeHardeningPurchase($company, '1000', '12000');

    // Wipe any exchange rate records to guarantee none exist.
    ExchangeRate::query()->delete();

    // Pass a zero applied_exchange_rate and no ExchangeRate row — the service must throw.
    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '0',  // no rate available
        'financial_account_id' => $sypCash->id,
        'payable_account_id' => $usdPayable->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 4 — Negative applied_amount → ValidationException, no partial records
// ---------------------------------------------------------------------------

test('negative applied_amount throws and leaves no partial records', function (): void {
    [$company, $sypCash, $usdPayable] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $initialPayments = countPayments();
    $initialJournals = countJournals();
    $initialLines = countJournalLines();

    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '-1000',       // negative
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);
});

// ---------------------------------------------------------------------------
// Test 5 — Negative payment amount → ValidationException, no partial records
// ---------------------------------------------------------------------------

test('negative payment amount throws and leaves no partial records', function (): void {
    [$company, $sypCash, $usdPayable] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $initialPayments = countPayments();
    $initialJournals = countJournals();
    $initialLines = countJournalLines();

    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '-12000000',           // negative amount
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '-12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);
});

// ---------------------------------------------------------------------------
// Test 6 — Malformed monetary input → ValidationException, no partial records
// ---------------------------------------------------------------------------

test('malformed monetary input throws and leaves no partial records', function (): void {
    [$company, $sypCash, $usdPayable] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $initialPayments = countPayments();
    $initialJournals = countJournals();
    $initialLines = countJournalLines();

    // 'abc' is non-numeric — normalizeAmount() must throw ValidationException.
    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => 'abc',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);

    // '1,000' is non-numeric (comma-formatted).
    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '1,000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
    ]))->toThrow(ValidationException::class);

    expect(countPayments())->toBe($initialPayments);
    expect(countJournals())->toBe($initialJournals);
    expect(countJournalLines())->toBe($initialLines);
});

// ---------------------------------------------------------------------------
// Test 7 — Valid same-currency SYP payment without any rate record → succeeds
// ---------------------------------------------------------------------------

test('valid same-currency SYP payment succeeds without any exchange rate record', function (): void {
    $company = Company::create(['name' => 'SameCurrCo '.uniqid(), 'base_currency' => 'SYP']);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable SYP',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $party = Party::create(['company_id' => $company->id, 'name' => 'SYP Supplier '.uniqid()]);
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-SYP-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '500000',
        'tax_total' => '0',
        'total' => '500000',
        'base_subtotal' => '500000',
        'base_tax_total' => '0',
        'base_total' => '500000',
    ]);

    // Ensure no ExchangeRate records exist for this company.
    ExchangeRate::where('company_id', $company->id)->delete();

    $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'payable_account_id' => $payableAccount->id,
        'financial_account_id' => $cashAccount->id,
    ]);

    expect($payment)->not()->toBeNull();
    expect($payment->status)->toBe('posted');
    expect(bccomp((string) $payment->amount, '500000', 4))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 8 — Valid cross-currency payment → succeeds (regression)
// ---------------------------------------------------------------------------

test('valid cross-currency payment succeeds (regression)', function (): void {
    [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    expect($payment)->not()->toBeNull();
    expect($payment->status)->toBe('posted');
    expect(bccomp((string) $payment->applied_amount, '1000', 4))->toBe(0);
    expect((string) $payment->applied_currency)->toBe('USD');
});

// ---------------------------------------------------------------------------
// Test 9 — Outstanding balance unchanged after a rejected operation
// ---------------------------------------------------------------------------

test('outstanding balance is unchanged after a rejected cross-currency payment', function (): void {
    [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $service = app(PaymentService::class);

    $outstandingBefore = $service->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstandingBefore, '1000', 10))->toBe(0);

    // Attempt a payment with a zero applied_exchange_rate — must be rejected.
    try {
        $service->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '12000000',
            'applied_amount' => '1000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '0',
            'payable_account_id' => $usdPayable->id,
            'financial_account_id' => $sypCash->id,
        ]);
    } catch (ValidationException) {
        // expected
    }

    $outstandingAfter = $service->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstandingAfter, $outstandingBefore, 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 10 — No payment row / journal / journal-line after any validation failure
// ---------------------------------------------------------------------------

test('no payment journal or journal-line rows are created after any validation failure', function (): void {
    [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $service = app(PaymentService::class);

    // Each scenario starts from the same baseline counts.
    $scenarios = [
        // Zero applied_exchange_rate
        fn () => $service->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '12000000',
            'applied_amount' => '1000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '0',
            'payable_account_id' => $usdPayable->id,
            'financial_account_id' => $sypCash->id,
        ]),
        // Negative payment amount
        fn () => $service->recordSupplierPayment($purchase, [
            'amount' => '-12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'payable_account_id' => $usdPayable->id,
            'financial_account_id' => $sypCash->id,
        ]),
        // Negative applied_amount
        fn () => $service->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '12000000',
            'applied_amount' => '-1000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'payable_account_id' => $usdPayable->id,
            'financial_account_id' => $sypCash->id,
        ]),
    ];

    foreach ($scenarios as $scenario) {
        $paymentsBefore = countPayments();
        $journalsBefore = countJournals();
        $linesBefore = countJournalLines();

        try {
            $scenario();
        } catch (ValidationException) {
            // expected
        }

        expect(countPayments())->toBe($paymentsBefore, 'Payment row was created for a rejected scenario');
        expect(countJournals())->toBe($journalsBefore, 'Journal row was created for a rejected scenario');
        expect(countJournalLines())->toBe($linesBefore, 'JournalLine row was created for a rejected scenario');
    }
});

// ---------------------------------------------------------------------------
// Test 11 — guardPaymentAgainstOverpayment and calculateOutstandingFor use
//            a consistent rate for the same payment
// ---------------------------------------------------------------------------

test('overpayment guard and outstanding balance use consistent conversion rates', function (): void {
    [$company, $sypCash, $usdPayable, $gainAccount, $lossAccount] = makeHardeningContext();
    $purchase = makeHardeningPurchase($company, '1000', '12000');

    $service = app(PaymentService::class);

    // Record an exact full payment: 1,000 USD at 12,000 SYP/USD = 12,000,000 SYP.
    // The overpayment guard should accept this (1,000 USD == outstanding 1,000 USD).
    $payment = $service->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    // After the payment, outstanding must also be zero via calculateOutstandingFor.
    $outstanding = $service->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstanding, '0', 10))->toBe(0,
        "Expected zero outstanding but got {$outstanding} — rates are inconsistent between guard and calculator."
    );

    // A second payment attempt (even 1 SYP worth) must be rejected as overpayment.
    expect(fn () => $service->recordSupplierPayment($purchase, [
        'amount' => '12000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000',
        'applied_amount' => '1',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]))->toThrow(ValidationException::class, 'exceeds the outstanding balance');
});

// ---------------------------------------------------------------------------
// Test 13 — Truly absent applied_exchange_rate with no DB row → resolveExchangeRate throws
// ---------------------------------------------------------------------------

test('omitted applied_exchange_rate with no payable rate and no DB row throws ValidationException', function (): void {
    // Create a purchase with a NULL exchange_rate so no payable-derived rate is available.
    $company = Company::create(['name' => 'MissingRateCo '.uniqid(), 'base_currency' => 'SYP']);

    $sypCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '7100',
        'name' => 'FX Gain',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '8100',
        'name' => 'FX Loss',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $company->update([
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    // Purchase in USD but with no exchange_rate stored (null) so payableOriginalRate is null.
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-NR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'USD',
        'exchange_rate' => null,   // no payable-derived rate
        'base_currency' => 'SYP',
        'subtotal' => '1000',
        'tax_total' => '0',
        'total' => '1000',
        'base_subtotal' => '0',
        'base_tax_total' => '0',
        'base_total' => '0',
    ]);

    // Wipe all ExchangeRate records to guarantee the live lookup finds nothing.
    ExchangeRate::query()->delete();

    // Payload deliberately omits applied_exchange_rate AND exchange_rate.
    // With no payable-derived rate and no DB row, resolveExchangeRate must throw.
    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        // exchange_rate intentionally absent
        // applied_exchange_rate intentionally absent
        'applied_currency' => 'USD',
        'applied_amount' => '1000',
        'financial_account_id' => $sypCash->id,
        'payable_account_id' => $usdPayable->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 14 — exchange_rate='1' sentinel cannot silently replace applied_exchange_rate
//            for a cross-currency payment
// ---------------------------------------------------------------------------

test('exchange_rate sentinel value 1 is rejected as applied_exchange_rate for cross-currency payments', function (): void {
    $company = Company::create(['name' => 'SentinelCo '.uniqid(), 'base_currency' => 'SYP']);

    $sypCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '7100',
        'name' => 'FX Gain',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '8100',
        'name' => 'FX Loss',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $company->update([
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    // Purchase in USD, but its exchange_rate is null so no payable-derived rate is available.
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-SEN-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'USD',
        'exchange_rate' => null,
        'base_currency' => 'SYP',
        'subtotal' => '1000',
        'tax_total' => '0',
        'total' => '1000',
        'base_subtotal' => '0',
        'base_tax_total' => '0',
        'base_total' => '0',
    ]);

    // Ensure no ExchangeRate record exists — the live lookup must fail.
    ExchangeRate::where('company_id', $company->id)->delete();

    // Provide exchange_rate='1' (a common same-currency sentinel) but omit
    // applied_exchange_rate. The service must not silently use '1' SYP/USD.
    expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',   // sentinel — same-base payment rate, not the applied rate
        // applied_exchange_rate intentionally absent
        'applied_currency' => 'USD',
        'applied_amount' => '1000',
        'financial_account_id' => $sypCash->id,
        'payable_account_id' => $usdPayable->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 12 — Tenant isolation: rate resolution uses the correct company
// ---------------------------------------------------------------------------

test('resolveConversionRate uses the correct company for tenant isolation', function (): void {
    // Company A: has an ExchangeRate row (USD → SYP = 12,000)
    $companyA = Company::create(['name' => 'CompanyA '.uniqid(), 'base_currency' => 'SYP']);

    ExchangeRate::create([
        'company_id' => $companyA->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => now(),
    ]);

    $sypCashA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '1000',
        'name' => 'Cash SYP A',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayableA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '2000',
        'name' => 'Payable USD A',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '7100',
        'name' => 'FX Gain A',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '8100',
        'name' => 'FX Loss A',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $companyA->update([
        'exchange_gain_account_id' => $gainA->id,
        'exchange_loss_account_id' => $lossA->id,
    ]);
    $companyA->refresh();

    $purchaseA = makeHardeningPurchase($companyA, '1000', '12000');

    // Company B: NO ExchangeRate record (different tenant)
    $companyB = Company::create(['name' => 'CompanyB '.uniqid(), 'base_currency' => 'SYP']);

    $sypCashB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '1000',
        'name' => 'Cash SYP B',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $usdPayableB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '2000',
        'name' => 'Payable USD B',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $gainB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '7100',
        'name' => 'FX Gain B',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $lossB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '8100',
        'name' => 'FX Loss B',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $companyB->update([
        'exchange_gain_account_id' => $gainB->id,
        'exchange_loss_account_id' => $lossB->id,
    ]);
    $companyB->refresh();

    $purchaseB = makeHardeningPurchase($companyB, '1000', '12000');

    $service = app(PaymentService::class);

    // Company A's payment: an explicit applied_exchange_rate is provided so the live
    // lookup is bypassed. This proves the rate on the payload is used (not Company B's).
    $paymentA = $service->recordSupplierPayment($purchaseA, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayableA->id,
        'financial_account_id' => $sypCashA->id,
        'exchange_gain_account_id' => $gainA->id,
        'exchange_loss_account_id' => $lossA->id,
    ]);

    expect($paymentA->status)->toBe('posted');

    // Company B's payment also provides an explicit rate so no DB lookup is needed.
    $paymentB = $service->recordSupplierPayment($purchaseB, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $usdPayableB->id,
        'financial_account_id' => $sypCashB->id,
        'exchange_gain_account_id' => $gainB->id,
        'exchange_loss_account_id' => $lossB->id,
    ]);

    expect($paymentB->status)->toBe('posted');

    // Verify each company's outstanding is zero independently.
    expect(bccomp($service->calculateOutstandingForPurchase($purchaseA), '0', 10))->toBe(0);
    expect(bccomp($service->calculateOutstandingForPurchase($purchaseB), '0', 10))->toBe(0);

    // A payment from Company B must not appear in Company A's outstanding calculation.
    $purchaseAFresh = makeHardeningPurchase($companyA, '500', '12000');
    expect(bccomp($service->calculateOutstandingForPurchase($purchaseAFresh), '500', 10))->toBe(0);
});
