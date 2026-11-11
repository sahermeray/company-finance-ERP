<?php

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Create a minimal purchase with an explicit total.  The purchase is created
 * directly (bypassing PurchaseService) so these tests do not depend on
 * inventory, accounting, or any other subsystem — they focus purely on
 * outstanding-balance semantics.
 */
function makePurchase(Company $company, string $total = '1000', string $currency = 'SYP'): Purchase
{
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-TEST-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => $currency,
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

/**
 * Create a payment row directly, bypassing PaymentService, so we can set
 * status and applied amounts precisely for each scenario.
 */
function makePayment(
    Company $company,
    Purchase $purchase,
    string $amount,
    string $status = 'posted',
    ?string $currency = null,
    ?string $appliedAmount = null,
    ?string $appliedCurrency = null,
    string $appliedExchangeRate = '1',
    bool $withPostedJournal = true,
): Payment {
    $currency ??= $purchase->currency;
    $appliedAmount ??= $amount;
    $appliedCurrency ??= $currency;

    $payment = Payment::create([
        'company_id' => $company->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'status' => $status,
        'posted_at' => $status === 'posted' ? now() : null,
        'paid_at' => now(),
        'amount' => $amount,
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => $currency,
        'base_amount' => $amount,
        'applied_amount' => $appliedAmount,
        'applied_currency' => $appliedCurrency,
        'applied_exchange_rate' => $appliedExchangeRate,
        'applied_base_amount' => $amount,
    ]);

    if ($status === 'posted' && $withPostedJournal) {
        app(CompanyContextService::class)->runForCompany($company, function () use (
            $company,
            $payment,
            $amount,
            $currency,
        ): void {
            $payableAccount = FinancialAccount::create([
                'company_id' => $company->id,
                'code' => 'TEST-PAYABLE-'.$payment->id,
                'name' => 'Test Payable',
                'account_type' => 'liability',
                'currency' => $currency,
            ]);
            $cashAccount = FinancialAccount::create([
                'company_id' => $company->id,
                'code' => 'TEST-CASH-'.$payment->id,
                'name' => 'Test Cash',
                'account_type' => 'asset',
                'currency' => $currency,
            ]);

            $accounting = app(AccountingService::class);
            $journal = $accounting->createDraftJournal([
                'sourceable_type' => Payment::class,
                'sourceable_id' => $payment->id,
                'description' => 'Test payment journal',
            ], $company);

            $accounting->addJournalLine($journal, [
                'financial_account_id' => $payableAccount->id,
                'debit' => $amount,
                'currency' => $currency,
                'base_currency' => $currency,
                'exchange_rate' => '1',
            ]);
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $cashAccount->id,
                'credit' => $amount,
                'currency' => $currency,
                'base_currency' => $currency,
                'exchange_rate' => '1',
            ]);
            $accounting->postJournal($journal);
        });
    }

    return $payment;
}

// ---------------------------------------------------------------------------
// Test 1 — A purchase with no payments has outstanding equal to its total
// ---------------------------------------------------------------------------

it('returns the full total when no payments exist', function (): void {
    $company = Company::create(['name' => 'Co A']);
    $purchase = makePurchase($company, '1000');

    $service = app(PaymentService::class);
    $outstanding = $service->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '1000', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 2 — A draft payment does not reduce outstanding
// ---------------------------------------------------------------------------

it('excludes draft payments from the outstanding balance', function (): void {
    $company = Company::create(['name' => 'Co B']);
    $purchase = makePurchase($company, '1000');
    makePayment($company, $purchase, '400', 'draft');

    $service = app(PaymentService::class);
    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 3 — A posted payment reduces outstanding correctly (partial payment)
// ---------------------------------------------------------------------------

it('reduces outstanding by a posted payment', function (): void {
    $company = Company::create(['name' => 'Co C']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400', 'posted');

    $service = app(PaymentService::class);
    $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->get();

    expect($journals)->toHaveCount(1)
        ->and($journals->first()->status)->toBe('posted')
        ->and((int) $journals->first()->company_id)->toBe((int) $company->id);
    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '600', 10))->toBe(0);
});

it('does not reduce outstanding for a draft payment with a posted journal', function (): void {
    $company = Company::create(['name' => 'Co C0']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400', 'draft');
    AccountingJournal::create([
        'company_id' => $company->id,
        'sourceable_type' => Payment::class,
        'sourceable_id' => $payment->id,
        'journal_number' => 'DRAFT-PAYMENT-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding for a posted payment without a journal', function (): void {
    $company = Company::create(['name' => 'Co C1']);
    $purchase = makePurchase($company, '1000');
    makePayment($company, $purchase, '400', 'posted', withPostedJournal: false);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding for a posted payment without a posting timestamp', function (): void {
    $company = Company::create(['name' => 'Co C1A']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400');
    DB::table('payments')->where('id', $payment->id)->update(['posted_at' => null]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding when the only associated journal is draft', function (): void {
    $company = Company::create(['name' => 'Co C2']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400', 'posted', withPostedJournal: false);
    AccountingJournal::create([
        'company_id' => $company->id,
        'sourceable_type' => Payment::class,
        'sourceable_id' => $payment->id,
        'journal_number' => 'DRAFT-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding when the only associated journal is reversed', function (): void {
    $company = Company::create(['name' => 'Co C2A']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400', 'posted', withPostedJournal: false);
    AccountingJournal::create([
        'company_id' => $company->id,
        'sourceable_type' => Payment::class,
        'sourceable_id' => $payment->id,
        'journal_number' => 'REVERSED-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'reversed',
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding when its posted journal belongs to another company', function (): void {
    $company = Company::create(['name' => 'Co C3']);
    $otherCompany = Company::create(['name' => 'Co C4']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400', 'posted', withPostedJournal: false);
    DB::table('accounting_journals')->insert([
        'company_id' => $otherCompany->id,
        'sourceable_type' => Payment::class,
        'sourceable_id' => $payment->id,
        'journal_number' => 'OTHER-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'posted',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('does not reduce outstanding when a posted payment has more than one associated journal', function (): void {
    $company = Company::create(['name' => 'Co C5']);
    $purchase = makePurchase($company, '1000');
    $payment = makePayment($company, $purchase, '400');
    AccountingJournal::create([
        'company_id' => $company->id,
        'sourceable_type' => Payment::class,
        'sourceable_id' => $payment->id,
        'journal_number' => 'DUPLICATE-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'draft',
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

it('uses applied currency amounts without mixing currencies across posted payments', function (): void {
    $company = Company::create(['name' => 'Co C6', 'base_currency' => 'SYP']);
    $purchase = makePurchase($company, '1000', 'USD');
    makePayment(
        $company,
        $purchase,
        '3600000',
        'posted',
        'SYP',
        '3600000',
        'SYP',
        '12000',
    );
    makePayment($company, $purchase, '200', 'posted', 'USD');

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '500', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 4 — A payment that fully settles the purchase leaves outstanding zero
// ---------------------------------------------------------------------------

it('returns zero outstanding when the purchase is fully settled', function (): void {
    $company = Company::create(['name' => 'Co D']);
    $purchase = makePurchase($company, '1000');
    makePayment($company, $purchase, '1000', 'posted');

    $service = app(PaymentService::class);
    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '0', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 5 — A reversed payment does not reduce outstanding
// ---------------------------------------------------------------------------

it('excludes reversed payments from the outstanding balance', function (): void {
    $company = Company::create(['name' => 'Co E']);
    $purchase = makePurchase($company, '1000');
    makePayment($company, $purchase, '400', 'reversed');

    $service = app(PaymentService::class);
    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 6 — Payments belonging to another company are never included
// ---------------------------------------------------------------------------

it('never counts payments belonging to another company', function (): void {
    $companyA = Company::create(['name' => 'Co A']);
    $companyB = Company::create(['name' => 'Co B']);

    // Company A has a purchase for 1000 with no payments.
    $purchaseA = makePurchase($companyA, '1000');

    // Company B has its own completely separate purchase, with a posted payment.
    // This payment must never show up in Company A's outstanding calculation.
    $purchaseB = makePurchase($companyB, '500');
    makePayment($companyB, $purchaseB, '500', 'posted');

    $service = app(PaymentService::class);

    // Company A's purchase should show 1000 outstanding — none of Company B's
    // payments should bleed across.
    $outstanding = $service->calculateOutstandingForPurchase($purchaseA);
    expect(bccomp($outstanding, '1000', 10))->toBe(0);

    // Also confirm Company B's own outstanding is zero.
    $outstandingB = $service->calculateOutstandingForPurchase($purchaseB);
    expect(bccomp($outstandingB, '0', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 7 — Works correctly with no active company context when the payable
//           has an explicit company_id
// ---------------------------------------------------------------------------

it('calculates outstanding correctly without an active company context', function (): void {
    $company = Company::create(['name' => 'Co G']);
    $purchase = makePurchase($company, '1000');
    makePayment($company, $purchase, '400', 'posted');

    // Explicitly clear any company context override — simulate a background job
    // or service-layer call with no session.
    $ctx = app(CompanyContextService::class);
    $ctx->runForCompany($company, function (): void {
        // Establish a posted payment inside the context so the model saves cleanly.
    });
    // Now call with zero context by not using runForCompany.
    // The CompanyContextService singleton will have no override set after the
    // runForCompany closure above returns, and there is no session in tests.

    $service = app(PaymentService::class);

    // The purchase already has company_id set; calculateOutstandingFor should
    // bypass CompanyScope and use the explicit company_id from the payable.
    $outstanding = $service->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '600', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// Test 8 — Fails safely when company ownership cannot be established
// ---------------------------------------------------------------------------

it('throws when the payable has no company_id', function (): void {
    // Build a plain object that looks like a payable but has no company_id.
    $fakePurchase = new stdClass;
    $fakePurchase->total = '1000';
    $fakePurchase->currency = 'SYP';
    $fakePurchase->base_currency = 'SYP';
    // Deliberately omit company_id.

    $service = app(PaymentService::class);

    expect(fn () => $service->calculateOutstandingFor($fakePurchase))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 9 — Posted payments cannot be updated
// ---------------------------------------------------------------------------

it('prevents updating a posted payment', function (): void {
    $company = Company::create(['name' => 'Co I']);
    $purchase = makePurchase($company, '500');
    $payment = makePayment($company, $purchase, '500', 'posted');

    expect(fn () => tap($payment)->update(['amount' => '999']))
        ->toThrow(ValidationException::class);

    // Confirm the amount was not changed.
    expect($payment->fresh()->amount)->toBe('500.0000');
});

// ---------------------------------------------------------------------------
// Test 10 — Posted payments cannot be deleted
// ---------------------------------------------------------------------------

it('prevents deleting a posted payment', function (): void {
    $company = Company::create(['name' => 'Co J']);
    $purchase = makePurchase($company, '500');
    $payment = makePayment($company, $purchase, '500', 'posted');

    expect(fn () => $payment->delete())->toThrow(ValidationException::class);

    // Confirm the payment still exists.
    expect(Payment::withoutGlobalScope(CompanyScope::class)->find($payment->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 11 — Draft payments can be edited and deleted
// ---------------------------------------------------------------------------

it('allows editing and deleting a draft payment', function (): void {
    $company = Company::create(['name' => 'Co K']);
    $purchase = makePurchase($company, '800');
    $payment = makePayment($company, $purchase, '200', 'draft');

    // Should not throw — draft payments are mutable.
    $payment->notes = 'Updated note';
    $payment->save();
    expect($payment->fresh()->notes)->toBe('Updated note');

    $id = $payment->id;
    $payment->delete();
    expect(Payment::withoutGlobalScope(CompanyScope::class)->find($id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 12 — PurchaseService and PaymentService return consistent balances
// ---------------------------------------------------------------------------

it('returns identical outstanding from PurchaseService and PaymentService', function (): void {
    $company = Company::create(['name' => 'Co L']);
    $purchase = makePurchase($company, '1200');
    makePayment($company, $purchase, '300', 'posted');
    makePayment($company, $purchase, '200', 'draft');   // must not count
    makePayment($company, $purchase, '100', 'reversed'); // must not count

    // PurchaseService requires an active company context.
    $ctx = app(CompanyContextService::class);
    $paymentService = app(PaymentService::class);
    $purchaseService = app(PurchaseService::class);

    $ctx->runForCompany($company, function () use ($purchase, $paymentService, $purchaseService): void {
        $fromPaymentService = $paymentService->calculateOutstandingForPurchase($purchase);
        $fromPurchaseService = $purchaseService->calculateOutstanding($purchase);

        expect(bccomp($fromPaymentService, '900', 10))->toBe(0)
            ->and(bccomp($fromPurchaseService, '900', 10))->toBe(0)
            ->and($fromPaymentService)->toBe($fromPurchaseService);
    });
});
