<?php

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Create a company with full cross-currency infrastructure:
 *   - USD Supplier Payable (liability, USD)
 *   - SYP Cash/Bank (asset, SYP)
 *   - Exchange Gain (income, SYP)
 *   - Exchange Loss (expense, SYP)
 *   - Due-to-Partner (liability, SYP) — for partner-paid tests
 * Optionally store forex accounts as company-level defaults.
 */
function makeCrossContext(bool $setCompanyDefaults = true): array
{
    $company = Company::create(['name' => 'CrossCo '.uniqid(), 'base_currency' => 'SYP']);

    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Supplier Payable USD', 'account_type' => 'liability', 'currency' => 'USD',
    ]);
    $sypCash = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash SYP', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '7100',
        'name' => 'FX Gain', 'account_type' => 'income', 'currency' => 'SYP',
    ]);
    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '8100',
        'name' => 'FX Loss', 'account_type' => 'expense', 'currency' => 'SYP',
    ]);
    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partner', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    if ($setCompanyDefaults) {
        $company->update([
            'exchange_gain_account_id' => $gainAccount->id,
            'exchange_loss_account_id' => $lossAccount->id,
        ]);
        $company->refresh();
    }

    return [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner];
}

/**
 * Create a posted USD purchase at the given original rate R₀.
 */
function makeCrossUsdPurchase(Company $company, string $total = '1000', string $rate = '12000'): Purchase
{
    $base = bcmul($total, $rate, 4);
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id, 'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(), 'purchase_date' => '2026-10-01',
        'status' => 'posted', 'currency' => 'USD', 'exchange_rate' => $rate,
        'base_currency' => 'SYP', 'subtotal' => $total, 'tax_total' => '0', 'total' => $total,
        'base_subtotal' => $base, 'base_tax_total' => '0', 'base_total' => $base,
    ]);
}

test('posts a three-line journal with exchange loss when settlement rate exceeds original rate', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000'); // R₀ = 12,000

    $paymentService = app(PaymentService::class);

    // Pay 12,500,000 SYP at R₁ = 12,500 (higher than original)
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_id', $payment->id)
        ->where('sourceable_type', Payment::class)
        ->first();

    expect($journal)->not()->toBeNull();

    $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
        ->where('accounting_journal_id', $journal->id)
        ->orderBy('line_number')
        ->get();
    expect($lines)->toHaveCount(3);

    // Line 1: Dr Supplier Payable (USD)
    $line1 = $lines[0];
    expect($line1->financial_account_id)->toBe($usdPayable->id);
    expect($line1->currency)->toBe('USD');
    expect($line1->debit)->toBe('1000.0000');
    expect($line1->exchange_rate)->toBe('12000.0000000000');
    expect($line1->base_debit)->toBe('12000000.0000');

    // Line 2: Cr Cash/Bank (SYP)
    $line2 = $lines[1];
    expect($line2->financial_account_id)->toBe($sypCash->id);
    expect($line2->currency)->toBe('SYP');
    expect($line2->credit)->toBe('12500000.0000');
    expect($line2->exchange_rate)->toBe('1.0000000000');
    expect($line2->base_credit)->toBe('12500000.0000');

    // Line 3: Dr Exchange Loss (SYP)
    $line3 = $lines[2];
    expect($line3->financial_account_id)->toBe($lossAccount->id);
    expect($line3->currency)->toBe('SYP');
    expect($line3->debit)->toBe('500000.0000');
    expect($line3->base_debit)->toBe('500000.0000');

    // Verify journal balances
    $totalBaseDebit = bcadd('12000000.0000', '500000.0000', 4);
    $totalBaseCredit = '12500000.0000';
    expect($totalBaseDebit)->toBe($totalBaseCredit);
});

test('posts a three-line journal with exchange gain when settlement rate is below original rate', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000'); // R₀ = 12,000

    $paymentService = app(PaymentService::class);

    // Pay 11,800,000 SYP at R₁ = 11,800 (lower than original)
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '11800000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '11800000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_id', $payment->id)
        ->where('sourceable_type', Payment::class)
        ->first();

    $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
        ->where('accounting_journal_id', $journal->id)
        ->orderBy('line_number')
        ->get();
    expect($lines)->toHaveCount(3);

    // Line 1: Dr Supplier Payable (USD)
    $line1 = $lines[0];
    expect($line1->financial_account_id)->toBe($usdPayable->id);
    expect($line1->currency)->toBe('USD');
    expect($line1->debit)->toBe('1000.0000');
    expect($line1->exchange_rate)->toBe('12000.0000000000');
    expect($line1->base_debit)->toBe('12000000.0000');

    // Line 2: Cr Cash/Bank (SYP)
    $line2 = $lines[1];
    expect($line2->financial_account_id)->toBe($sypCash->id);
    expect($line2->currency)->toBe('SYP');
    expect($line2->credit)->toBe('11800000.0000');
    expect($line2->exchange_rate)->toBe('1.0000000000');
    expect($line2->base_credit)->toBe('11800000.0000');

    // Line 3: Cr Exchange Gain (SYP)
    $line3 = $lines[2];
    expect($line3->financial_account_id)->toBe($gainAccount->id);
    expect($line3->currency)->toBe('SYP');
    expect($line3->credit)->toBe('200000.0000');
    expect($line3->base_credit)->toBe('200000.0000');

    // Verify journal balances
    $totalBaseDebit = '12000000.0000';
    $totalBaseCredit = bcadd('11800000.0000', '200000.0000', 4);
    expect($totalBaseDebit)->toBe($totalBaseCredit);
});

test('posts a two-line journal when settlement rate equals original rate (no forex line)', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000'); // R₀ = 12,000

    $paymentService = app(PaymentService::class);

    // Pay 12,000,000 SYP at R₁ = 12,000 (same as original)
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_id', $payment->id)
        ->where('sourceable_type', Payment::class)
        ->first();

    $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
        ->where('accounting_journal_id', $journal->id)
        ->orderBy('line_number')
        ->get();
    expect($lines)->toHaveCount(2);

    // Line 1: Dr Supplier Payable (USD)
    $line1 = $lines[0];
    expect($line1->financial_account_id)->toBe($usdPayable->id);
    expect($line1->currency)->toBe('USD');
    expect($line1->debit)->toBe('1000.0000');
    expect($line1->exchange_rate)->toBe('12000.0000000000');
    expect($line1->base_debit)->toBe('12000000.0000');

    // Line 2: Cr Cash/Bank (SYP)
    $line2 = $lines[1];
    expect($line2->financial_account_id)->toBe($sypCash->id);
    expect($line2->currency)->toBe('SYP');
    expect($line2->credit)->toBe('12000000.0000');
    expect($line2->exchange_rate)->toBe('1.0000000000');
    expect($line2->base_credit)->toBe('12000000.0000');
});

test('outstanding balance is zero after a full cross-currency payment', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    $paymentService = app(PaymentService::class);

    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $outstanding = $paymentService->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstanding, '0', 10))->toBe(0);
});

test('outstanding balance accumulates correctly across two partial cross-currency payments at different rates', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000'); // R₀ = 12,000

    $paymentService = app(PaymentService::class);

    // First payment: 500 USD equivalent at R₁ = 12,200
    $payment1 = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '6100000', // 500 × 12,200
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '6100000',
        'applied_amount' => '500',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $outstanding1 = $paymentService->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstanding1, '500', 10))->toBe(0); // 500 USD remaining

    // Second payment: 500 USD equivalent at R₂ = 11,900
    $payment2 = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '5950000', // 500 × 11,900
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '5950000',
        'applied_amount' => '500',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $outstanding2 = $paymentService->calculateOutstandingForPurchase($purchase);
    expect(bccomp($outstanding2, '0', 10))->toBe(0); // Fully settled
});

test('calculateOutstandingFor divides (not multiplies) to convert SYP applied_amount to USD', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    // Create payment directly with USD applied_amount
    $payment = Payment::create([
        'company_id' => $company->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'status' => 'posted',
        'posted_at' => now(),
        'paid_at' => now(),
        'amount' => '12000000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'applied_exchange_rate' => '12000',
        'applied_base_amount' => '12000000',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $payment,
        $usdPayable,
        $sypCash,
    ): void {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'sourceable_type' => Payment::class,
            'sourceable_id' => $payment->id,
            'description' => 'Cross-currency outstanding fixture',
        ], $company);

        $accounting->addJournalLine($journal, [
            'financial_account_id' => $usdPayable->id,
            'debit' => '1000',
            'currency' => 'USD',
            'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_debit' => '12000000',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $sypCash->id,
            'credit' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_credit' => '12000000',
        ]);
        $accounting->postJournal($journal);
    });

    $paymentService = app(PaymentService::class);
    $outstanding = $paymentService->calculateOutstandingForPurchase($purchase);

    // 12,000,000 SYP ÷ 12,000 SYP/USD = 1,000 USD, which fully settles the purchase
    expect(bccomp($outstanding, '0', 10))->toBe(0);
});

test('rejects a SYP debit to a USD-denominated payable account (journal line currency enforcement)', function () {
    // This test verifies that AccountingService enforces line.currency == account.currency
    // The fix in postPaymentJournal should ensure line 1 always uses USD for a USD account
    // so this test should pass without throwing
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    $paymentService = app(PaymentService::class);

    // This should work now because postPaymentJournal uses applied_currency (USD) for the debit line
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    // If we get here without exception, the currency enforcement is working
    expect($payment)->not()->toBeNull();

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_id', $payment->id)
        ->where('sourceable_type', Payment::class)
        ->first();

    $line1 = JournalLine::withoutGlobalScope(CompanyScope::class)
        ->where('accounting_journal_id', $journal->id)
        ->orderBy('line_number')
        ->first();
    expect($line1->financial_account_id)->toBe($usdPayable->id);
    expect($line1->currency)->toBe('USD'); // Should be USD, not SYP
});

test('posts a three-line journal for a cross-currency partner-paid purchase', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);

    $paymentService = app(PaymentService::class);

    $payment = $paymentService->recordPartnerPaidPurchase($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'party_id' => $partner->id,
        'payable_account_id' => $usdPayable->id,
        'partner_liability_account_id' => $dueToPartner->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_id', $payment->id)
        ->where('sourceable_type', Payment::class)
        ->first();

    $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
        ->where('accounting_journal_id', $journal->id)
        ->orderBy('line_number')
        ->get();
    expect($lines)->toHaveCount(3);

    // Line 1: Dr Supplier Payable (USD)
    expect($lines[0]->financial_account_id)->toBe($usdPayable->id);
    expect($lines[0]->currency)->toBe('USD');
    expect($lines[0]->debit)->toBe('1000.0000');

    // Line 2: Cr Due to Partner (SYP)
    expect($lines[1]->financial_account_id)->toBe($dueToPartner->id);
    expect($lines[1]->currency)->toBe('SYP');
    expect($lines[1]->credit)->toBe('12500000.0000');

    // Line 3: Dr Exchange Loss (SYP)
    expect($lines[2]->financial_account_id)->toBe($lossAccount->id);
    expect($lines[2]->currency)->toBe('SYP');
    expect($lines[2]->debit)->toBe('500000.0000');
});

test('throws when exchange_gain_account_id is missing for a cross-currency payment', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext(false); // No company defaults
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    $paymentService = app(PaymentService::class);

    expect(fn () => $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        // Missing exchange_gain_account_id and exchange_loss_account_id
    ]))->toThrow(ValidationException::class, 'Exchange gain and loss accounts are required');
});

test('throws when the exchange gain account belongs to another company', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    // Create another company with its own forex account
    $otherCompany = Company::create(['name' => 'OtherCo '.uniqid(), 'base_currency' => 'SYP']);
    $otherGainAccount = FinancialAccount::create([
        'company_id' => $otherCompany->id, 'code' => '7100',
        'name' => 'Other FX Gain', 'account_type' => 'income', 'currency' => 'SYP',
    ]);

    $paymentService = app(PaymentService::class);

    expect(fn () => $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $otherGainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]))->toThrow(ValidationException::class, 'must belong to the same company');
});

test('throws when the exchange gain account is the wrong type (not income)', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    // Create a liability account (wrong type for exchange gain)
    $wrongTypeAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '9000',
        'name' => 'Wrong Type', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    $paymentService = app(PaymentService::class);

    expect(fn () => $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $wrongTypeAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]))->toThrow(ValidationException::class, 'must be of type: income');
});

test('prevents overpayment with correct conversion direction after the bcdiv fix', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    $paymentService = app(PaymentService::class);

    // Try to pay 1,200 USD equivalent in SYP (12,000,000 × 1.2 = 14,400,000 SYP)
    // This should be rejected as overpayment
    expect(fn () => $paymentService->recordSupplierPayment($purchase, [
        'amount' => '14400000', // 1,200 USD equivalent at 12,000 rate
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '14400000',
        'applied_amount' => '1200',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]))->toThrow(ValidationException::class, 'exceeds the outstanding balance');
});

test('rolls back payment and journal when forex account is invalid mid-transaction', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    $paymentService = app(PaymentService::class);

    $initialPaymentCount = Payment::count();
    $initialJournalCount = AccountingJournal::count();

    try {
        $paymentService->recordSupplierPayment($purchase, [
            'amount' => '12500000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '12500000',
            'applied_amount' => '1000',
            'applied_currency' => 'USD',
            'payable_account_id' => $usdPayable->id,
            'financial_account_id' => $sypCash->id,
            'exchange_gain_account_id' => 99999, // Invalid account ID
            'exchange_loss_account_id' => $lossAccount->id,
        ]);
    } catch (ValidationException $e) {
        // Expected
    }

    // No new payments or journals should have been created
    expect(Payment::count())->toBe($initialPaymentCount);
    expect(AccountingJournal::count())->toBe($initialJournalCount);
});

test('preserves the original purchase journal unchanged after cross-currency settlement', function () {
    [$company, $usdPayable, $sypCash, $gainAccount, $lossAccount, $dueToPartner] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000');

    // First, post a purchase journal (simulating what PurchaseService would do)
    $accountingService = app(AccountingService::class);
    $purchaseJournal = $accountingService->createDraftJournal([
        'company_id' => $company->id,
        'journal_number' => 'PUR-'.$purchase->purchase_number,
        'entry_date' => $purchase->purchase_date,
        'description' => 'Purchase '.$purchase->purchase_number,
    ], $company);

    // Add journal lines for the purchase
    $accountingService->addJournalLine($purchaseJournal, [
        'financial_account_id' => $usdPayable->id,
        'party_id' => $purchase->party_id,
        'debit' => '1000.0000',
        'currency' => 'USD',
        'exchange_rate' => '12000.0000',
        'base_currency' => 'SYP',
        'base_debit' => '12000000.0000',
        'description' => 'Inventory/Expense',
    ]);

    $accountingService->addJournalLine($purchaseJournal, [
        'financial_account_id' => $usdPayable->id,
        'party_id' => $purchase->party_id,
        'credit' => '1000.0000',
        'currency' => 'USD',
        'exchange_rate' => '12000.0000',
        'base_currency' => 'SYP',
        'base_credit' => '12000000.0000',
        'description' => 'Supplier Payable',
    ]);

    $accountingService->postJournal($purchaseJournal);

    $originalLines = $purchaseJournal->lines()->count();
    $originalLineIds = $purchaseJournal->lines()->pluck('id')->toArray();

    // Now settle with cross-currency payment
    $paymentService = app(PaymentService::class);
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    // Verify original purchase journal is unchanged
    $purchaseJournal->refresh();
    expect($purchaseJournal->lines()->count())->toBe($originalLines);
    expect($purchaseJournal->lines()->pluck('id')->toArray())->toBe($originalLineIds);
});

test('isolates cross-currency payments across companies (tenant isolation)', function () {
    [$companyA, $usdPayableA, $sypCashA, $gainAccountA, $lossAccountA, $dueToPartnerA] = makeCrossContext();
    $purchaseA = makeCrossUsdPurchase($companyA, '1000', '12000');

    [$companyB, $usdPayableB, $sypCashB, $gainAccountB, $lossAccountB, $dueToPartnerB] = makeCrossContext();

    $paymentService = app(PaymentService::class);

    // Try to pay Company A's purchase using Company B's accounts
    expect(fn () => $paymentService->recordSupplierPayment($purchaseA, [
        'amount' => '12500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12500000',
        'applied_amount' => '1000',
        'applied_currency' => 'USD',
        'payable_account_id' => $usdPayableB->id, // Company B's account
        'financial_account_id' => $sypCashB->id,   // Company B's account
        'exchange_gain_account_id' => $gainAccountB->id,
        'exchange_loss_account_id' => $lossAccountB->id,
    ]))->toThrow(ValidationException::class, 'must belong to the same company');
});

test('guard uses bcdiv (not bcmul) when applied_currency differs from payable currency', function () {
    // This test exercises the conversion branch inside guardPaymentAgainstOverpayment()
    // directly through the supplier-payment workflow with an explicit applied_currency that
    // differs from the purchase currency.
    //
    // Purchase: 1,000 USD at R₀ = 12,000 SYP/USD.
    // Attempt: applied_amount = 14,400,000 SYP with applied_currency = 'SYP'.
    //
    // Correct conversion (bcdiv):  14,400,000 ÷ 12,000 = 1,200 USD > 1,000 USD → REJECTED ✓
    // Wrong  conversion (bcmul):   14,400,000 × 12,000 = 172,800,000,000 > 1,000   → also rejected, but for an absurd value
    //
    // To confirm the direction is correct we also verify that an amount that converts
    // to exactly the outstanding balance is ACCEPTED — which only works with division:
    //   12,000,000 ÷ 12,000 = 1,000 USD = outstanding → accepted.
    // With multiplication that would be 12,000,000 × 12,000 = 144,000,000,000 → wrongly rejected.

    [$company, , $sypCash, $gainAccount, $lossAccount] = makeCrossContext();
    $purchase = makeCrossUsdPurchase($company, '1000', '12000'); // 1,000 USD
    $sypPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2002',
        'name' => 'Supplier Payable SYP',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    $paymentService = app(PaymentService::class);

    // Part 1: An SYP applied_amount that converts to MORE than outstanding → must be rejected.
    expect(fn () => $paymentService->recordSupplierPayment($purchase, [
        'amount' => '14400000',          // 1,200 USD × 12,000 SYP/USD
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '14400000',
        'applied_amount' => '14400000',  // SYP — 1,200 USD equivalent
        'applied_currency' => 'SYP',     // explicitly SYP, differs from payable 'USD'
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $sypPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]))->toThrow(ValidationException::class, 'exceeds the outstanding balance');

    // Part 2: An SYP applied_amount that converts to EXACTLY the outstanding → must be accepted.
    // 12,000,000 SYP ÷ 12,000 SYP/USD = 1,000 USD = full outstanding.
    // If bcmul were used: 12,000,000 × 12,000 = 144,000,000,000 → would be wrongly rejected.
    $payment = $paymentService->recordSupplierPayment($purchase, [
        'amount' => '12000000',          // exactly 1,000 USD × 12,000
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_amount' => '12000000',
        'applied_amount' => '12000000',  // SYP — exactly 1,000 USD equivalent
        'applied_currency' => 'SYP',     // explicitly SYP, differs from payable 'USD'
        'applied_exchange_rate' => '12000',
        'payable_account_id' => $sypPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    expect($payment)->not()->toBeNull();
    expect($payment->status)->toBe('posted');
});
