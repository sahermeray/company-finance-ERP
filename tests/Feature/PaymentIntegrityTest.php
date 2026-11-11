<?php

/**
 * PaymentIntegrityTest
 *
 * Focused tests for payment concurrency safety, overpayment validation,
 * cross-currency validation, atomicity, and duplicate-posting prevention.
 *
 * Database note: Tests run against SQLite (in-memory, per the explicit test command).
 * SQLite does not support SELECT … FOR UPDATE; the lockForUpdate() call in
 * PaymentService is silently ignored at the driver level. Payment workflows
 * validate against the payable's pre-payment outstanding balance; row-level
 * serialization is provided on MySQL/PostgreSQL.
 */

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Bootstrap a company with accounts ready for supplier-payment scenarios.
 * Returns [$company, $payableAccount (liability), $cashAccount (asset)].
 */
function makePaymentContext(string $currency = 'SYP', string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'IntegCo '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable',
        'account_type' => 'liability',
        'currency' => $currency,
    ]);

    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => $currency,
    ]);

    return [$company, $payableAccount, $cashAccount];
}

/**
 * Create a posted purchase directly (no PurchaseService / no inventory).
 */
function makePurchaseRecord(
    Company $company,
    string $total = '1000',
    string $currency = 'SYP',
): Purchase {
    $party = Party::create(['company_id' => $company->id, 'name' => 'Sup '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
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
 * Insert a payment row directly, bypassing PaymentService — used to set up
 * a known starting state without triggering business-logic side effects.
 */
function insertPaymentRow(
    Company $company,
    Purchase $purchase,
    string $amount,
    string $status = 'posted',
    ?string $currency = null,
    ?string $appliedCurrency = null,
    string $appliedExchangeRate = '1',
    bool $withPostedJournal = true,
): Payment {
    $currency ??= $purchase->currency;
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
        'applied_amount' => $amount,
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
// 1. Outstanding-amount validation
// ---------------------------------------------------------------------------

it('outstanding is the full total when no payments exist', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '1000', 10))->toBe(0);
});

it('outstanding decreases only by posted payments', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    insertPaymentRow($company, $purchase, '300', 'posted');
    insertPaymentRow($company, $purchase, '200', 'draft');    // must not count
    insertPaymentRow($company, $purchase, '100', 'reversed'); // must not count

    $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '700', 10))->toBe(0);
});

it('outstanding is zero when the purchase is fully settled', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    insertPaymentRow($company, $purchase, '500', 'posted');

    $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '0', 10))->toBe(0);
});

it('outstanding never goes negative — clamped to zero on data anomaly', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    // Insert two posted payments that together exceed the total — anomalous
    // data that should never happen through the service but we defend against.
    insertPaymentRow($company, $purchase, '400', 'posted');
    insertPaymentRow($company, $purchase, '400', 'posted');

    $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '0', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// 2. Draft vs posted distinction
// ---------------------------------------------------------------------------

it('a draft payment does not create a journal entry', function (): void {
    [$company, , $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '600');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount,
    ): void {
        $draft = app(PaymentService::class)->createDraftPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'direction' => 'outbound',
            'amount' => '200',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
        ]);

        expect($draft->status)->toBe('draft');

        $count = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $draft->id)
            ->count();

        expect($count)->toBe(0);
    });
});

it('a draft payment does not reduce outstanding balance', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '600');

    insertPaymentRow($company, $purchase, '200', 'draft');

    $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);

    expect(bccomp($outstanding, '600', 10))->toBe(0);
});

// ---------------------------------------------------------------------------
// 3. Overpayment rejection — same-currency
// ---------------------------------------------------------------------------

it('rejects a payment that exceeds the outstanding balance', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    // Partially settle 300.
    insertPaymentRow($company, $purchase, '300', 'posted');
    $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
    $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        // Attempt to pay 300 more when only 200 remains.
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '300',
            'applied_amount' => '300',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class, 'exceeds the outstanding balance');
    });

    expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
});

it('accepts a partial payment within outstanding and posts one journal', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    $payment = app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): Payment {
        return app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '300',
            'applied_amount' => '300',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]);
    });

    $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->get();

    expect($payment->status)->toBe('posted')
        ->and($journals)->toHaveCount(1)
        ->and($journals->first()->status)->toBe('posted');
    expect(bccomp(app(PaymentService::class)->calculateOutstandingForPurchase($purchase), '200', 10))->toBe(0);
});

it('accepts a payment that exactly matches the outstanding balance', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    insertPaymentRow($company, $purchase, '400', 'posted');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '600',
            'applied_amount' => '600',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]);

        expect($payment->status)->toBe('posted');

        $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// 4. Cross-currency overpayment validation
// ---------------------------------------------------------------------------

it('rejects a cross-currency payment that exceeds outstanding after conversion', function (): void {
    // Purchase is in USD (total 1000 USD).
    $company = Company::create(['name' => 'CrossCo '.uniqid(), 'base_currency' => 'SYP']);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $cashAccountSYP = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    // Store an exchange rate: 1 USD = 12000 SYP.
    ExchangeRate::create([
        'company_id' => $company->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-01 00:00:00',
    ]);

    $purchase = makePurchaseRecord($company, '1000', 'USD');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccountSYP,
    ): void {
        // Attempt to pay 1100 USD worth in SYP (13,200,000 SYP at 12,000/USD)
        // when only 1000 USD outstanding.  This exceeds outstanding.
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '13200000',    // SYP amount
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '13200000',
            'applied_amount' => '1100',        // USD applied — exceeds 1000 USD
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'applied_base_amount' => '13200000',
            'financial_account_id' => $cashAccountSYP->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);
    });
});

it('accepts a cross-currency payment within the outstanding balance', function (): void {
    $company = Company::create(['name' => 'CrossCo2 '.uniqid(), 'base_currency' => 'SYP']);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $cashAccountSYP = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    ExchangeRate::create([
        'company_id' => $company->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-01 00:00:00',
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

    $purchase = makePurchaseRecord($company, '1000', 'USD');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase,
        $payableAccount,
        $cashAccountSYP,
        $gainAccount,
        $lossAccount,
    ): void {
        // Pay exactly 1000 USD in SYP (12,000,000 SYP at 12,000/USD).
        $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '12000000',
            'applied_amount' => '1000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'applied_base_amount' => '12000000',
            'financial_account_id' => $cashAccountSYP->id,
            'payable_account_id' => $payableAccount->id,
            'exchange_gain_account_id' => $gainAccount->id,
            'exchange_loss_account_id' => $lossAccount->id,
        ]);

        expect($payment->status)->toBe('posted');

        expect($payment->accountingJournals()->where('status', 'posted')->count())->toBe(1);

        // Outstanding should now be zero (1000 USD fully applied).
        $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

it('rejects generic posted customer receipts without changing revenue outstanding', function (): void {
    $company = Company::create(['name' => 'GenericReceipt '.uniqid(), 'base_currency' => 'SYP']);
    $customer = Party::create(['company_id' => $company->id, 'name' => 'Customer '.uniqid()]);
    $revenue = Revenue::create([
        'company_id' => $company->id,
        'party_id' => $customer->id,
        'revenue_number' => 'REV-'.uniqid(),
        'revenue_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'SYP',
        'subtotal' => '1000',
        'tax_total' => '0',
        'total' => '1000',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($revenue): void {
        expect(fn () => app(PaymentService::class)->recordPayment([
            'payable_type' => Revenue::class,
            'payable_id' => $revenue->id,
            'direction' => 'inbound',
            'amount' => '250',
            'currency' => 'SYP',
        ]))->toThrow(ValidationException::class, 'Generic payment recording only supports explicit drafts.');

        expect(bccomp(app(PaymentService::class)->calculateOutstandingForRevenue($revenue), '1000', 10))->toBe(0);
    });
});

it('keeps a generic draft payment out of outstanding balances and journals', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($purchase): void {
        $payment = app(PaymentService::class)->recordPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'direction' => 'outbound',
            'amount' => '250',
            'currency' => 'SYP',
            'status' => 'draft',
        ]);
        $service = app(PaymentService::class);

        expect($payment->status)->toBe('draft')
            ->and($payment->accountingJournals()->count())->toBe(0)
            ->and(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);
    });
});

it('rejects draft status supplied to any posted payment workflow', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');
    $customer = Party::create(['company_id' => $company->id, 'name' => 'Customer '.uniqid()]);
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);
    $revenue = Revenue::create([
        'company_id' => $company->id,
        'party_id' => $customer->id,
        'revenue_number' => 'REV-'.uniqid(),
        'revenue_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'SYP',
        'subtotal' => '1000',
        'tax_total' => '0',
        'total' => '1000',
    ]);
    $receivableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1100',
        'name' => 'Accounts Receivable',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $partnerLiabilityAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2100',
        'name' => 'Due to Partner',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase,
        $revenue,
        $company,
        $partner,
        $payableAccount,
        $cashAccount,
        $receivableAccount,
        $partnerLiabilityAccount,
    ): void {
        $service = app(PaymentService::class);
        $postedAttributes = [
            'amount' => '300',
            'currency' => 'SYP',
            'status' => 'draft',
        ];

        expect(fn () => $service->recordSupplierPayment($purchase, [
            ...$postedAttributes,
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class, 'A posted payment workflow only accepts status=posted.');

        expect(fn () => $service->recordCustomerReceipt($revenue, [
            ...$postedAttributes,
            'financial_account_id' => $cashAccount->id,
            'receivable_account_id' => $receivableAccount->id,
        ]))->toThrow(ValidationException::class, 'A posted payment workflow only accepts status=posted.');

        expect(fn () => $service->recordPartnerPaidPurchase($purchase, [
            ...$postedAttributes,
            'party_id' => $partner->id,
            'payable_account_id' => $payableAccount->id,
            'partner_liability_account_id' => $partnerLiabilityAccount->id,
        ]))->toThrow(ValidationException::class, 'A posted payment workflow only accepts status=posted.');

        expect(fn () => $service->recordPartnerReimbursement($company, [
            ...$postedAttributes,
            'party_id' => $partner->id,
            'financial_account_id' => $cashAccount->id,
            'partner_liability_account_id' => $partnerLiabilityAccount->id,
        ]))->toThrow(ValidationException::class, 'A posted payment workflow only accepts status=posted.');

        expect(fn () => $service->recordCustomerReceipt($revenue, [
            ...$postedAttributes,
            'status' => 'cancelled',
            'financial_account_id' => $cashAccount->id,
            'receivable_account_id' => $receivableAccount->id,
        ]))->toThrow(ValidationException::class, 'A posted payment workflow only accepts status=posted.');

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe(0)
            ->and(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0)
            ->and(bccomp($service->calculateOutstandingForRevenue($revenue), '1000', 10))->toBe(0);
    });
});

it('rejects caller-supplied payment journal links and other service-controlled fields', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $purchase,
        $payableAccount,
        $cashAccount,
    ): void {
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '100',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
            'posted_at' => now()->subDay(),
            'sourceable_type' => Payment::class,
            'sourceable_id' => 999,
        ]))->toThrow(ValidationException::class, 'This field is controlled by the payment service.');

        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '100',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
            'company_id' => $company->id + 1,
        ]))->toThrow(ValidationException::class, 'This field is controlled by the payment workflow.');

        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '100',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
            'created_by' => 999,
        ]))->toThrow(ValidationException::class, 'This field is controlled by the payment service.');
    });
});

it('rejects generic posted and invalid-status payment requests without persisting them', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($purchase): void {
        expect(fn () => app(PaymentService::class)->recordPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'direction' => 'outbound',
            'amount' => '100',
            'currency' => 'SYP',
        ]))->toThrow(ValidationException::class, 'Generic payment recording only supports explicit drafts.');

        expect(fn () => app(PaymentService::class)->recordPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'direction' => 'outbound',
            'amount' => '100',
            'currency' => 'SYP',
            'status' => 'cancelled',
        ]))->toThrow(ValidationException::class, 'Generic payment recording only supports explicit drafts.');

        expect(fn () => app(PaymentService::class)->recordPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'direction' => 'outbound',
            'amount' => '100',
            'currency' => 'SYP',
            'status' => 'posted',
        ]))->toThrow(ValidationException::class, 'Generic payment recording only supports explicit drafts.');
    });
});

it('rejects generic posted partner payments without changing partner liability balances', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);
    $partnerLiabilityAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2100',
        'name' => 'Due to Partner',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase,
        $partner,
        $partnerLiabilityAccount,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPayment([
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->id,
            'party_id' => $partner->id,
            'direction' => 'outbound',
            'amount' => '300',
            'currency' => 'SYP',
        ]))->toThrow(ValidationException::class, 'Generic payment recording only supports explicit drafts.');
        $service = app(PaymentService::class);

        expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0)
            ->and(bccomp($service->calculatePartnerLiabilityBalance($partner, $partnerLiabilityAccount), '0', 10))->toBe(0);
    });
});

it('cannot reverse a generic posted payment that has no posted journal', function (): void {
    [$company] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($company, $purchase): void {
        $payment = insertPaymentRow($company, $purchase, '250', withPostedJournal: false);

        expect(fn () => app(PaymentService::class)->reversePayment($payment, [], $company))
            ->toThrow(ValidationException::class, 'No posted accounting journal was found for this payment.');
    });
});

// ---------------------------------------------------------------------------
// 5. Transaction rollback — atomicity
// ---------------------------------------------------------------------------

it('rolls back the payment when the payable account does not exist', function (): void {
    [$company, , $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '800');

    $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
    $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount,
    ): void {
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '800',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => 99999, // non-existent
        ]))->toThrow(ValidationException::class);
    });

    expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
});

it('rolls back the payment when the cash account does not exist', function (): void {
    [$company, $payableAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '800');

    $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount,
    ): void {
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '800',
            'currency' => 'SYP',
            'financial_account_id' => 99999, // non-existent
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);
    });

    expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
});

// ---------------------------------------------------------------------------
// 6. Duplicate journal posting prevention
// ---------------------------------------------------------------------------

it('prevents a second journal from being created for the same payment', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        $service = app(PaymentService::class);

        // First payment succeeds and creates exactly one journal.
        $payment = $service->recordSupplierPayment($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]);

        $journalCount = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->where('status', 'posted')
            ->count();

        expect($journalCount)->toBe(1);
        expect($payment->accountingJournals()->count())->toBe(1);

        // The payment model is now immutable (posted). Any attempt to update
        // it (e.g. via a replay of the posting logic) must throw.
        expect(fn () => $payment->update(['notes' => 'replay attempt']))
            ->toThrow(ValidationException::class);
    });

    it('rolls back a payment when accounting journal creation fails', function (): void {
        [$company, $payableAccount, $cashAccount] = makePaymentContext();
        $purchase = makePurchaseRecord($company, '500');
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
        $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

        $accounting = $this->partialMock(AccountingService::class, function ($mock): void {
            $mock->shouldReceive('createDraftJournal')
                ->once()
                ->andThrow(new RuntimeException('Simulated journal creation failure'));
        });

        app(CompanyContextService::class)->runForCompany($company, function () use (
            $purchase,
            $payableAccount,
            $cashAccount,
            $accounting,
        ): void {
            $service = new PaymentService(app(CompanyContextService::class), $accounting);

            expect(fn () => $service->recordSupplierPayment($purchase, [
                'amount' => '500',
                'currency' => 'SYP',
                'financial_account_id' => $cashAccount->id,
                'payable_account_id' => $payableAccount->id,
            ]))->toThrow(RuntimeException::class, 'Simulated journal creation failure');
        });

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore)
            ->and(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
    });
});

// ---------------------------------------------------------------------------
// 7. Overpayment guard after another payment has posted
// ---------------------------------------------------------------------------

/**
 * Simulate a second payment attempt after the first payment has committed.
 * The second amount must be checked against the now-current outstanding balance.
 *
 * This verifies the pre-payment guard, not true concurrent execution.
 */
it('rejects a payment that would overpay after another payment has posted', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '1000');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company, $purchase, $payableAccount, $cashAccount,
    ): void {
        // Simulate the first concurrent payment having already committed 800.
        insertPaymentRow($company, $purchase, '800', 'posted');

        // Only 200 remains, so the pre-payment guard must reject 700.
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '700',
            'applied_amount' => '700',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);

        // Confirm only the original 800 payment exists.
        $count = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('payable_type', Purchase::class)
            ->where('payable_id', $purchase->id)
            ->where('status', 'posted')
            ->count();

        expect($count)->toBe(1);
    });
});

it('[concurrency] two sequential full-amount payments are both rejected after the first', function (): void {
    [$company, $payableAccount, $cashAccount] = makePaymentContext();
    $purchase = makePurchaseRecord($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        $service = app(PaymentService::class);

        // First full payment must succeed.
        $first = $service->recordSupplierPayment($purchase, [
            'amount' => '500',
            'applied_amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]);

        expect($first->status)->toBe('posted');

        // Second full payment must be rejected — outstanding is zero.
        expect(fn () => $service->recordSupplierPayment($purchase, [
            'amount' => '500',
            'applied_amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// 8. Company isolation preserved
// ---------------------------------------------------------------------------

it('rejects a payment whose purchase belongs to a different company', function (): void {
    [$companyA, $payableA, $cashA] = makePaymentContext();
    [$companyB] = makePaymentContext();

    $purchaseB = makePurchaseRecord($companyB, '500');

    app(CompanyContextService::class)->runForCompany($companyA, function () use (
        $purchaseB, $payableA, $cashA,
    ): void {
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchaseB, [
            'amount' => '200',
            'currency' => 'SYP',
            'financial_account_id' => $cashA->id,
            'payable_account_id' => $payableA->id,
        ]))->toThrow(ValidationException::class);
    });
});
