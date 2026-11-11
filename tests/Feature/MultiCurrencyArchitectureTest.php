<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults Syrian companies to SYP while allowing another base currency', function () {
    $syrianCompany = Company::create(['name' => 'Damascus Trading']);
    $otherCompany = Company::create([
        'name' => 'Regional Trading',
        'base_currency' => 'USD',
    ]);

    expect($syrianCompany->base_currency)->toBe('SYP')
        ->and($otherCompany->base_currency)->toBe('USD');
});

it('stores USD and SYP transactions with original and base currency values', function () {
    $company = Company::create(['name' => 'Aleppo Manufacturing']);
    $supplier = Party::create([
        'company_id' => $company->id,
        'name' => 'Steel Supplier',
    ]);
    $expenseAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '6100',
        'name' => 'Local Supplies',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);

    $usdPurchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'purchase_number' => 'PUR-USD-001',
        'purchase_date' => '2026-10-08',
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'base_currency' => 'SYP',
        'subtotal' => '1000',
        'tax_total' => '0',
        'total' => '1000',
        'base_subtotal' => '12000000',
        'base_tax_total' => '0',
        'base_total' => '12000000',
    ]);
    $sypExpense = Expense::create([
        'company_id' => $company->id,
        'financial_account_id' => $expenseAccount->id,
        'expense_number' => 'EXP-SYP-001',
        'expense_date' => '2026-10-08',
        'amount' => '5000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '5000000',
        'description' => 'Local materials',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $sypExpense,
        $usdPurchase,
    ): void {
        $usdPurchase->refresh();
        $sypExpense->refresh();

        expect($usdPurchase->total)->toBe('1000.0000')
            ->and($usdPurchase->currency)->toBe('USD')
            ->and($usdPurchase->exchange_rate)->toBe('12000.0000000000')
            ->and($usdPurchase->base_currency)->toBe('SYP')
            ->and($usdPurchase->base_total)->toBe('12000000.0000')
            ->and($sypExpense->amount)->toBe('5000000.0000')
            ->and($sypExpense->currency)->toBe('SYP')
            ->and($sypExpense->exchange_rate)->toBe('1.0000000000')
            ->and($sypExpense->base_amount)->toBe('5000000.0000');
    });
});

it('keeps party balances separated by transaction currency', function () {
    $company = Company::create(['name' => 'Homs Services']);
    $usdCustomer = Party::create([
        'company_id' => $company->id,
        'name' => 'USD Customer',
    ]);
    $sypCustomer = Party::create([
        'company_id' => $company->id,
        'name' => 'SYP Customer',
    ]);
    $journal = AccountingJournal::create([
        'company_id' => $company->id,
        'journal_number' => 'JRN-MULTI-001',
        'entry_date' => '2026-10-08',
        'status' => 'posted',
        'description' => 'Opening customer balances',
        'posted_at' => '2026-10-08 09:00:00',
    ]);
    $usdReceivable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1100-USD',
        'name' => 'Receivable - USD',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $sypReceivable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1100-SYP',
        'name' => 'Receivable - SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $usdRevenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000-USD',
        'name' => 'Revenue - USD',
        'account_type' => 'income',
        'currency' => 'USD',
    ]);
    $sypRevenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000-SYP',
        'name' => 'Revenue - SYP',
        'account_type' => 'income',
        'currency' => 'SYP',
    ]);

    $usdLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $usdReceivable->id,
        'party_id' => $usdCustomer->id,
        'line_number' => 1,
        'debit' => '2000',
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'base_currency' => 'SYP',
        'base_debit' => '24000000',
        'base_credit' => '0',
    ]);
    $sypLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $sypReceivable->id,
        'party_id' => $sypCustomer->id,
        'line_number' => 2,
        'debit' => '5000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_debit' => '5000000',
        'base_credit' => '0',
    ]);
    $usdOffsetLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $usdRevenue->id,
        'line_number' => 3,
        'credit' => '2000',
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'base_currency' => 'SYP',
        'base_debit' => '0',
        'base_credit' => '24000000',
    ]);
    $sypOffsetLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $sypRevenue->id,
        'line_number' => 4,
        'credit' => '5000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_debit' => '0',
        'base_credit' => '5000000',
    ]);
    app(CompanyContextService::class)->runForCompany($company, function () use (

        $journal,
        $sypCustomer,
        $sypLine,
        $sypOffsetLine,
        $usdCustomer,
        $usdLine,
        $usdOffsetLine,
    ): void {
        $balancesByCurrency = JournalLine::query()
            ->whereIn('party_id', [$usdCustomer->id, $sypCustomer->id])
            ->get()
            ->groupBy('currency');

        expect($usdLine->currency)->toBe('USD')
            ->and($usdLine->debit)->toBe('2000.0000')
            ->and($sypLine->currency)->toBe('SYP')
            ->and($sypLine->debit)->toBe('5000000.0000')
            ->and($usdOffsetLine->base_credit)->toBe('24000000.0000')
            ->and($sypOffsetLine->base_credit)->toBe('5000000.0000')
            ->and((int) $journal->lines()->sum('base_debit'))->toBe(29000000)
            ->and((int) $journal->lines()->sum('base_credit'))->toBe(29000000)
            ->and($balancesByCurrency->keys()->all())->toEqualCanonicalizing(['USD', 'SYP'])
            ->and($balancesByCurrency['USD']->first()->debit)->toBe('2000.0000')
            ->and($balancesByCurrency['SYP']->first()->debit)->toBe('5000000.0000');
    });
});

it('allows separate cash accounts to be denominated in USD and SYP', function () {
    $company = Company::create(['name' => 'Latakia Imports']);

    $usdCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000-USD',
        'name' => 'Cash - USD',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $sypCash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000-SYP',
        'name' => 'Cash - SYP',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $sypCash,
        $usdCash,
    ): void {
        expect($usdCash->currency)->toBe('USD')
            ->and($sypCash->currency)->toBe('SYP')
            ->and($company->financialAccounts()->where('currency', 'USD')->count())->toBe(1)
            ->and($company->financialAccounts()->where('currency', 'SYP')->count())->toBe(1);
    });
});

it('preserves prior exchange rates when a newer effective rate is entered', function () {
    $company = Company::create(['name' => 'Exchange History Company']);
    $priorRate = ExchangeRate::create([
        'company_id' => $company->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '10000',
        'effective_at' => '2026-10-07 09:00:00',
        'source' => 'manual',
    ]);
    ExchangeRate::create([
        'company_id' => $company->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-08 09:00:00',
        'source' => 'manual',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($company, $priorRate): void {
        $priorRate->refresh();

        expect($company->exchangeRates()->count())->toBe(2)
            ->and($priorRate->rate)->toBe('10000.0000000000')
            ->and($priorRate->effective_at->toDateTimeString())->toBe('2026-10-07 09:00:00');
    });
});

it('excludes draft payments from the outstanding balance', function () {
    $company = Company::create(['name' => 'Draft Payment Company']);
    $supplier = Party::create([
        'company_id' => $company->id,
        'name' => 'Draft Supplier',
    ]);
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'purchase_number' => 'PUR-DRAFT-001',
        'purchase_date' => '2026-10-01',
        'currency' => 'USD',
        'exchange_rate' => '10000',
        'base_currency' => 'SYP',
        'total' => '1000',
        'base_total' => '10000000',
    ]);

    Payment::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'status' => 'draft',
        'paid_at' => '2026-10-08 12:00:00',
        'amount' => '400',
        'currency' => 'USD',
        'exchange_rate' => '10000',
        'base_currency' => 'SYP',
        'base_amount' => '4000000',
        'applied_amount' => '400',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '10000',
        'applied_base_amount' => '4000000',
    ]);

    $service = app(PaymentService::class);

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '1000', 10))->toBe(0);

    $payment = Payment::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'status' => 'posted',
        'posted_at' => now(),
        'paid_at' => '2026-10-09 12:00:00',
        'amount' => '400',
        'currency' => 'USD',
        'exchange_rate' => '10000',
        'base_currency' => 'SYP',
        'base_amount' => '4000000',
        'applied_amount' => '400',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '10000',
        'applied_base_amount' => '4000000',
    ]);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);
    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash USD',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $payment,
        $payableAccount,
        $cashAccount,
    ): void {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'sourceable_type' => Payment::class,
            'sourceable_id' => $payment->id,
            'description' => 'Posted payment outstanding fixture',
        ], $company);

        $accounting->addJournalLine($journal, [
            'financial_account_id' => $payableAccount->id,
            'debit' => '400',
            'currency' => 'USD',
            'exchange_rate' => '10000',
            'base_currency' => 'SYP',
            'base_debit' => '4000000',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $cashAccount->id,
            'credit' => '400',
            'currency' => 'USD',
            'exchange_rate' => '10000',
            'base_currency' => 'SYP',
            'base_credit' => '4000000',
        ]);
        $accounting->postJournal($journal);
    });

    expect(bccomp($service->calculateOutstandingForPurchase($purchase), '600', 10))->toBe(0);
});

it('represents an SYP payment applied to a USD invoice without changing the invoice currency', function () {
    $company = Company::create(['name' => 'Cross Currency Company']);
    $supplier = Party::create([
        'company_id' => $company->id,
        'name' => 'International Supplier',
    ]);
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'purchase_number' => 'PUR-CROSS-001',
        'purchase_date' => '2026-10-01',
        'currency' => 'USD',
        'exchange_rate' => '10000',
        'base_currency' => 'SYP',
        'total' => '1000',
        'base_total' => '10000000',
    ]);
    $payment = Payment::create([
        'company_id' => $company->id,
        'party_id' => $supplier->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'paid_at' => '2026-10-08 12:00:00',
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'applied_base_amount' => '12000000',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($payment, $purchase): void {
        $purchase->refresh();
        $payment->refresh();

        expect($payment->payable->is($purchase))->toBeTrue()
            ->and($payment->currency)->toBe('SYP')
            ->and($payment->amount)->toBe('12000000.0000')
            ->and($payment->applied_currency)->toBe('USD')
            ->and($payment->applied_amount)->toBe('1000.0000')
            ->and($purchase->currency)->toBe('USD')
            ->and($purchase->total)->toBe('1000.0000')
            ->and($purchase->exchange_rate)->toBe('10000.0000000000');
    });
});
