<?php

/**
 * PaymentReversalTest
 *
 * 23 test cases covering the PaymentService::reversePayment() workflow.
 *
 * Conventions:
 *   - RefreshDatabase for isolation
 *   - Company/accounts created directly (not via factories)
 *   - CompanyScope bypassed in assertions via withoutGlobalScope(CompanyScope::class)
 *   - ValidationException expected via expect(...)->toThrow(ValidationException::class)
 */

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a company with all accounts needed for reversal tests:
 *   - SYP Cash              (asset,     code 1000, SYP)
 *   - SYP Supplier Payable  (liability, code 2000, SYP)
 *   - USD Supplier Payable  (liability, code 2001, USD)
 *   - Customer Receivable   (asset,     code 1100, SYP)
 *   - Due to Partner        (liability, code 2100, SYP)
 *   - FX Gain               (income,    code 7100, SYP)
 *   - FX Loss               (expense,   code 8100, SYP)
 *   company->exchange_gain_account_id and exchange_loss_account_id set.
 */
function makeReversalContext(): array
{
    $company = Company::create(['name' => 'RevCo '.uniqid(), 'base_currency' => 'SYP']);

    $sypCash = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'SYP Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $sypPayable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Supplier Payable', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);
    $usdPayable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2001',
        'name' => 'USD Supplier Payable', 'account_type' => 'liability', 'currency' => 'USD',
    ]);
    $receivable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1100',
        'name' => 'Customer Receivable', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partner', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);
    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '7100',
        'name' => 'FX Gain', 'account_type' => 'income', 'currency' => 'SYP',
    ]);
    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '8100',
        'name' => 'FX Loss', 'account_type' => 'expense', 'currency' => 'SYP',
    ]);

    $company->update([
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);
    $company->refresh();

    return compact(
        'company', 'sypCash', 'sypPayable', 'usdPayable',
        'receivable', 'dueToPartner', 'gainAccount', 'lossAccount'
    );
}

/**
 * Create a posted SYP purchase (no inventory, no PurchaseService).
 */
function makeReversalPurchase(Company $company, string $total = '5000', string $currency = 'SYP'): Purchase
{
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

/**
 * Create a posted USD purchase at the given rate R₀.
 */
function makeReversalUsdPurchase(Company $company, string $total = '1000', string $rate = '12000'): Purchase
{
    $base = bcmul($total, $rate, 4);
    $party = Party::create(['company_id' => $company->id, 'name' => 'USD Supplier '.uniqid()]);

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
 * Create a posted Revenue document.
 */
function makeReversalRevenue(Company $company, string $total = '3000', string $currency = 'SYP'): Revenue
{
    $party = Party::create(['company_id' => $company->id, 'name' => 'Customer '.uniqid()]);

    return Revenue::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'revenue_number' => 'REV-'.uniqid(),
        'revenue_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

/**
 * Post a SYP supplier payment and return [$payment, $journal].
 */
function makePostedSupplierPaymentRev(
    Company $company,
    Purchase $purchase,
    FinancialAccount $payableAccount,
    FinancialAccount $cashAccount,
    string $amount = '5000',
): array {
    $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => $amount,
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => $amount,
        'applied_amount' => $amount,
        'applied_currency' => 'SYP',
        'payable_account_id' => $payableAccount->id,
        'financial_account_id' => $cashAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->where('status', 'posted')
        ->firstOrFail();

    return [$payment, $journal];
}

/**
 * Post a cross-currency (USD purchase, SYP payment) and return [$payment, $journal].
 */
function makePostedCrossPaymentRev(
    Company $company,
    Purchase $purchase,
    FinancialAccount $usdPayable,
    FinancialAccount $sypCash,
    FinancialAccount $gainAccount,
    FinancialAccount $lossAccount,
    string $sypAmount,
    string $usdAmount = '1000',
    string $appliedRate = '12000',
): array {
    $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
        'amount' => $sypAmount,
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => $sypAmount,
        'applied_amount' => $usdAmount,
        'applied_currency' => 'USD',
        'applied_exchange_rate' => $appliedRate,
        'payable_account_id' => $usdPayable->id,
        'financial_account_id' => $sypCash->id,
        'exchange_gain_account_id' => $gainAccount->id,
        'exchange_loss_account_id' => $lossAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->where('status', 'posted')
        ->firstOrFail();

    return [$payment, $journal];
}

// ---------------------------------------------------------------------------
// Test 1: reverse a posted same-currency SYP supplier payment
// ---------------------------------------------------------------------------

test('reverse a posted same-currency SYP supplier payment', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        // Reversal payment links back to original
        expect((int) $reversalPayment->reversal_of_payment_id)->toBe($payment->id);
        expect($reversalPayment->status)->toBe('posted');

        // Original payment transitions to 'reversed'
        $original = $payment->fresh();
        expect($original->status)->toBe('reversed');
        expect((int) $original->reversed_by_payment_id)->toBe($reversalPayment->id);

        // Original journal now has reversed_by_journal_id set
        $originalJournal = $journal->fresh();
        expect($originalJournal->reversed_by_journal_id)->not()->toBeNull();

        // Reversal journal exists and has swapped debit/credit
        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->first();
        expect($reversalJournal)->not()->toBeNull();

        $origLines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $journal->id)->orderBy('line_number')->get();
        $revLines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)->orderBy('line_number')->get();

        expect($revLines)->toHaveCount(2);

        // Each original debit becomes a credit in the reversal and vice versa
        foreach ($origLines as $i => $origLine) {
            $revLine = $revLines[$i];
            if (bccomp((string) $origLine->debit, '0', 4) > 0) {
                expect($revLine->credit)->toBe($origLine->debit);
                expect(bccomp((string) $revLine->debit, '0', 4))->toBe(0);
            } else {
                expect($revLine->debit)->toBe($origLine->credit);
                expect(bccomp((string) $revLine->credit, '0', 4))->toBe(0);
            }
        }
    });
});

// ---------------------------------------------------------------------------
// Test 2: outstanding balance is restored after same-currency reversal
// ---------------------------------------------------------------------------

test('outstanding balance is restored after same-currency reversal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // After full payment outstanding = 0
        expect(bccomp($service->calculateOutstandingForPurchase($purchase), '0', 4))->toBe(0);

        $service->reversePayment($payment, [], $ctx['company']);

        // After reversal outstanding restored to purchase total
        $outstanding = $service->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '5000', 4))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Test 3: reverse cross-currency payment with FX loss produces 3-line reversal journal
// ---------------------------------------------------------------------------

test('reverse cross-currency payment with FX loss produces 3-line reversal journal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalUsdPurchase($company, '1000', '12000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        // FX loss: paid 12,500,000 SYP (more than 12,000,000 carrying value)
        [$payment, $journal] = makePostedCrossPaymentRev(
            $ctx['company'], $purchase,
            $ctx['usdPayable'], $ctx['sypCash'],
            $ctx['gainAccount'], $ctx['lossAccount'],
            '12500000', '1000', '12000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)
            ->orderBy('line_number')->get();

        expect($lines)->toHaveCount(3);

        // The FX loss line in the original was a DEBIT on the loss account.
        // The reversal must CREDIT the loss account (exact inverse).
        $fxLine = $lines->first(
            fn ($l) => (int) $l->financial_account_id === (int) $ctx['lossAccount']->id
        );
        expect($fxLine)->not()->toBeNull('FX loss account line must appear in the reversal journal');
        // Must be a credit (reversal of original debit), not a debit
        expect(bccomp((string) ($fxLine->credit ?? '0'), '0', 4))->toBe(1, 'FX loss line reversal must be a credit');
        expect(bccomp((string) ($fxLine->debit ?? '0'), '0', 4))->toBe(0, 'FX loss line reversal must not have a debit');
        // Magnitude: original FX loss = 12,500,000 − 12,000,000 = 500,000 SYP
        expect(bccomp((string) $fxLine->credit, '500000.0000', 4))->toBe(0, 'FX loss reversal credit must equal 500000');
    });
});

// ---------------------------------------------------------------------------
// Test 4: reverse cross-currency payment with FX gain produces 3-line reversal journal
// ---------------------------------------------------------------------------

test('reverse cross-currency payment with FX gain produces 3-line reversal journal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalUsdPurchase($company, '1000', '12000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        // FX gain: paid 11,800,000 SYP (less than 12,000,000 carrying value)
        [$payment] = makePostedCrossPaymentRev(
            $ctx['company'], $purchase,
            $ctx['usdPayable'], $ctx['sypCash'],
            $ctx['gainAccount'], $ctx['lossAccount'],
            '11800000', '1000', '12000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)
            ->orderBy('line_number')->get();

        expect($lines)->toHaveCount(3);

        // The FX gain line in the original was a CREDIT on the gain account.
        // The reversal must DEBIT the gain account (exact inverse).
        $fxLine = $lines->first(
            fn ($l) => (int) $l->financial_account_id === (int) $ctx['gainAccount']->id
        );
        expect($fxLine)->not()->toBeNull('FX gain account line must appear in the reversal journal');
        // Must be a debit (reversal of original credit), not a credit
        expect(bccomp((string) ($fxLine->debit ?? '0'), '0', 4))->toBe(1, 'FX gain line reversal must be a debit');
        expect(bccomp((string) ($fxLine->credit ?? '0'), '0', 4))->toBe(0, 'FX gain line reversal must not have a credit');
        // Magnitude: original FX gain = 12,000,000 − 11,800,000 = 200,000 SYP
        expect(bccomp((string) $fxLine->debit, '200000.0000', 4))->toBe(0, 'FX gain reversal debit must equal 200000');
    });
});

// ---------------------------------------------------------------------------
// Test 5: base-currency debit equals base-currency credit in cross-currency reversal journal
// ---------------------------------------------------------------------------

test('base-currency debit equals base-currency credit in cross-currency reversal journal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalUsdPurchase($company, '1000', '12000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedCrossPaymentRev(
            $ctx['company'], $purchase,
            $ctx['usdPayable'], $ctx['sypCash'],
            $ctx['gainAccount'], $ctx['lossAccount'],
            '12500000', '1000', '12000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)->get();

        $totalBaseDebit = '0';
        $totalBaseCredit = '0';

        foreach ($lines as $line) {
            $totalBaseDebit = bcadd($totalBaseDebit, (string) ($line->base_debit ?? '0'), 10);
            $totalBaseCredit = bcadd($totalBaseCredit, (string) ($line->base_credit ?? '0'), 10);
        }

        expect(bccomp($totalBaseDebit, $totalBaseCredit, 4))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Test 6: no live exchange rate lookup occurs during reversal
// ---------------------------------------------------------------------------

test('no live exchange rate lookup occurs during reversal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // Delete all exchange rates — reversal must not query them
        DB::table('exchange_rates')->delete();

        // Should not throw (copies stored amounts, never recalculates)
        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);
        expect($reversalPayment)->not()->toBeNull();
        expect($reversalPayment->status)->toBe('posted');
    });
});

// ---------------------------------------------------------------------------
// Test 7: reverse a partner-paid purchase payment restores partner liability
// ---------------------------------------------------------------------------

test('reverse a partner-paid purchase payment restores partner liability', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase, $partner): void {
        $service = app(PaymentService::class);

        $payment = $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '5000',
            'applied_amount' => '5000',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $ctx['sypPayable']->id,
            'partner_liability_account_id' => $ctx['dueToPartner']->id,
        ]);

        $reversalPayment = $service->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)->get();

        expect($lines)->toHaveCount(2);

        // Reversal journal should credit the supplier payable (restoring the liability)
        $creditLine = $lines->firstWhere(fn ($l) => bccomp((string) ($l->credit ?? '0'), '0', 4) > 0);
        expect($creditLine)->not()->toBeNull();

        // Reversal journal should debit the due-to-partner (reducing partner liability)
        $debitLine = $lines->firstWhere(fn ($l) => bccomp((string) ($l->debit ?? '0'), '0', 4) > 0);
        expect($debitLine)->not()->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Test 8: reverse a partner reimbursement restores cash and partner liability
// ---------------------------------------------------------------------------

test('reverse a partner reimbursement restores cash and partner liability', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $partner): void {
        $service = app(PaymentService::class);

        $payment = $service->recordPartnerReimbursement($ctx['company'], [
            'amount' => '2000',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'partner_liability_account_id' => $ctx['dueToPartner']->id,
            'financial_account_id' => $ctx['sypCash']->id,
        ]);

        $reversalPayment = $service->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)->get();

        expect($lines)->toHaveCount(2);

        // Reversal should debit Cash (restores cash) and credit Due to Partner (restores liability)
        $cashLine = $lines->first(fn ($l) => (int) $l->financial_account_id === (int) $ctx['sypCash']->id);
        expect($cashLine)->not()->toBeNull();
        expect(bccomp((string) ($cashLine->debit ?? '0'), '0', 4))->toBeGreaterThan(0);

        $partnerLine = $lines->first(fn ($l) => (int) $l->financial_account_id === (int) $ctx['dueToPartner']->id);
        expect($partnerLine)->not()->toBeNull();
        expect(bccomp((string) ($partnerLine->credit ?? '0'), '0', 4))->toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// Test 9: reverse a customer receipt restores customer receivable
// ---------------------------------------------------------------------------

test('reverse a customer receipt restores customer receivable', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $revenue = makeReversalRevenue($company, '3000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $revenue): void {
        $service = app(PaymentService::class);

        $payment = $service->recordCustomerReceipt($revenue, [
            'amount' => '3000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '3000',
            'applied_amount' => '3000',
            'applied_currency' => 'SYP',
            'financial_account_id' => $ctx['sypCash']->id,
            'receivable_account_id' => $ctx['receivable']->id,
        ]);

        $reversalPayment = $service->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversalJournal->id)->get();

        expect($lines)->toHaveCount(2);

        // Original receipt: Dr Cash, Cr Receivable
        // Reversal: Dr Receivable, Cr Cash
        $receivableLine = $lines->first(fn ($l) => (int) $l->financial_account_id === (int) $ctx['receivable']->id);
        expect($receivableLine)->not()->toBeNull();
        expect(bccomp((string) ($receivableLine->debit ?? '0'), '0', 4))->toBeGreaterThan(0);

        $cashLine = $lines->first(fn ($l) => (int) $l->financial_account_id === (int) $ctx['sypCash']->id);
        expect($cashLine)->not()->toBeNull();
        expect(bccomp((string) ($cashLine->credit ?? '0'), '0', 4))->toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// Test 10: reversing P1 partial payment with P2 also posted leaves P2 outstanding intact
// ---------------------------------------------------------------------------

test('reversing P1 partial payment with P2 also posted leaves P2 outstanding intact', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        // P1 = 2000, P2 = 1500 → total paid = 3500, outstanding = 1500
        [$p1] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '2000'
        );
        [$p2] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '1500'
        );

        // Reverse P1 only
        $service->reversePayment($p1, [], $ctx['company']);

        // P2 should still be posted
        expect($p2->fresh()->status)->toBe('posted');

        // Outstanding = 5000 - 1500 = 3500
        $outstanding = $service->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '3500', 4))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Test 11: reversing a draft payment throws ValidationException
// ---------------------------------------------------------------------------

test('reversing a draft payment throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx): void {
        $service = app(PaymentService::class);
        $party = Party::create(['company_id' => $ctx['company']->id, 'name' => 'Party '.uniqid()]);

        $draftPayment = Payment::create([
            'company_id' => $ctx['company']->id,
            'party_id' => $party->id,
            'financial_account_id' => $ctx['sypCash']->id,
            'currency' => 'SYP',
            'amount' => '1000',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '1000',
            'applied_amount' => '1000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '1',
            'applied_base_amount' => '1000',
            'direction' => 'outbound',
            'payment_number' => 'PAY-DRAFT-'.uniqid(),
            'paid_at' => now(),
            'status' => 'draft',
            'posted_at' => null,
        ]);

        expect(fn () => $service->reversePayment($draftPayment, [], $ctx['company']))
            ->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 12: reversing an already-reversed payment throws ValidationException
// ---------------------------------------------------------------------------

test('reversing an already-reversed payment throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // First reversal should succeed
        $service->reversePayment($payment, [], $ctx['company']);

        // Second reversal of same payment must fail
        expect(fn () => $service->reversePayment($payment->fresh(), [], $ctx['company']))
            ->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 13: reversing a journal that already has reversed_by_journal_id throws
// ---------------------------------------------------------------------------

test('reversing a journal that already has reversed_by_journal_id throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        [$payment, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // Simulate a pre-existing reversal by directly setting reversed_by_journal_id
        // on the journal (bypassing Eloquent to avoid the immutability hook).
        DB::table('accounting_journals')
            ->where('id', $journal->id)
            ->update(['reversed_by_journal_id' => $journal->id]); // self-referential stub

        expect(fn () => $service->reversePayment($payment->fresh(), [], $ctx['company']))
            ->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 14: reversing a payment belonging to another company throws ValidationException
// ---------------------------------------------------------------------------

test('reversing a payment belonging to another company throws ValidationException', function (): void {
    $ctxA = makeReversalContext();
    $ctxB = makeReversalContext();

    $purchaseA = makeReversalPurchase($ctxA['company'], '5000');
    $purchaseB = makeReversalPurchase($ctxB['company'], '3000');

    // Create a posted payment for company B
    app(CompanyContextService::class)->runForCompany($ctxB['company'], function () use ($ctxA, $ctxB, $purchaseB): void {
        [$paymentB] = makePostedSupplierPaymentRev(
            $ctxB['company'], $purchaseB, $ctxB['sypPayable'], $ctxB['sypCash'], '3000'
        );

        // Attempt to reverse company B's payment passing company A as context
        expect(fn () => app(PaymentService::class)->reversePayment($paymentB, [], $ctxA['company']))
            ->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 15: reversing with wrong company context throws
// ---------------------------------------------------------------------------

test('reversing with unresolvable company context throws', function (): void {
    $ctxA = makeReversalContext();
    $ctxB = makeReversalContext();

    $purchaseB = makeReversalPurchase($ctxB['company'], '3000');

    app(CompanyContextService::class)->runForCompany($ctxB['company'], function () use ($ctxA, $ctxB, $purchaseB): void {
        [$paymentB] = makePostedSupplierPaymentRev(
            $ctxB['company'], $purchaseB, $ctxB['sypPayable'], $ctxB['sypCash'], '3000'
        );

        // Passing company A as explicit $company while payment belongs to company B
        expect(fn () => app(PaymentService::class)->reversePayment($paymentB, [], $ctxA['company']))
            ->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 16: reversal journal belongs to the correct company
// ---------------------------------------------------------------------------

test('reversal journal belongs to the correct company', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        expect((int) $reversalJournal->company_id)->toBe((int) $ctx['company']->id);
    });
});

// ---------------------------------------------------------------------------
// Test 17: atomic rollback: if reverseJournal fails no reversal payment row remains
// ---------------------------------------------------------------------------

test('atomic rollback: if reverseJournal fails no reversal payment row remains', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // Mock AccountingService to throw inside reverseJournal
        $mockAccounting = $this->partialMock(AccountingService::class, function ($mock) {
            $mock->shouldReceive('reverseJournal')
                ->once()
                ->andThrow(new RuntimeException('Simulated journal reversal failure'));
        });

        // Build a PaymentService with the mocked accounting service
        $paymentService = new PaymentService(
            app(CompanyContextService::class),
            $mockAccounting,
        );

        try {
            $paymentService->reversePayment($payment, [], $ctx['company']);
        } catch (RuntimeException) {
            // expected
        }

        // No reversal payment row should exist
        $reversalExists = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('reversal_of_payment_id', $payment->id)
            ->exists();
        expect($reversalExists)->toBeFalse();

        // Original payment status unchanged
        expect($payment->fresh()->status)->toBe('posted');
    });
});

// ---------------------------------------------------------------------------
// Test 18: reversal payment is not counted in outstanding calculation
// ---------------------------------------------------------------------------

test('reversal payment is not counted in outstanding calculation', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        $outstandingBefore = $service->calculateOutstandingForPurchase($purchase);

        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $service->reversePayment($payment, [], $ctx['company']);

        $outstandingAfter = $service->calculateOutstandingForPurchase($purchase);

        // Outstanding fully restored — reversal payment itself does not count against doc
        expect(bccomp($outstandingAfter, $outstandingBefore, 4))->toBe(0);

        // The reversal payment has no payable link
        $reversalPayment = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('reversal_of_payment_id', $payment->id)
            ->firstOrFail();
        expect($reversalPayment->payable_type)->toBeNull();
        expect($reversalPayment->payable_id)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Test 19: posted payment financial fields are immutable
// ---------------------------------------------------------------------------

test('posted payment financial fields are immutable', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        expect(function () use ($payment) {
            $payment->amount = '9999';
            $payment->save();
        })->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 20: posted payment arbitrary status transition is blocked
// ---------------------------------------------------------------------------

test('posted payment arbitrary status transition is blocked', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        expect(function () use ($payment) {
            $payment->status = 'cancelled';
            $payment->save();
        })->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 21: posted journal lines cannot be edited or deleted via direct Eloquent
// ---------------------------------------------------------------------------

test('posted journal lines cannot be edited or deleted via direct Eloquent operations', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $line = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $journal->id)
            ->first();

        // Attempt edit
        expect(function () use ($line) {
            $line->description = 'tampered';
            $line->save();
        })->toThrow(ValidationException::class);

        // Attempt delete
        expect(fn () => $line->delete())->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 22: DB unique constraint on reversed_by_payment_id prevents duplicate reversal payment
// ---------------------------------------------------------------------------

test('DB unique constraint on reversed_by_payment_id prevents duplicate reversal payment', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );
        $party = Party::create(['company_id' => $ctx['company']->id, 'name' => 'P '.uniqid()]);

        // Insert a first reversal row pointing to the original payment
        DB::table('payments')->insert([
            'company_id' => $ctx['company']->id,
            'party_id' => $party->id,
            'payment_number' => 'REV-1-'.uniqid(),
            'direction' => 'outbound',
            'currency' => 'SYP',
            'amount' => '5000',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '5000',
            'applied_amount' => '5000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '1',
            'applied_base_amount' => '5000',
            'status' => 'posted',
            'reversed_by_payment_id' => $payment->id,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to insert a second row with the same reversed_by_payment_id
        expect(fn () => DB::table('payments')->insert([
            'company_id' => $ctx['company']->id,
            'party_id' => $party->id,
            'payment_number' => 'REV-2-'.uniqid(),
            'direction' => 'outbound',
            'currency' => 'SYP',
            'amount' => '5000',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '5000',
            'applied_amount' => '5000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '1',
            'applied_base_amount' => '5000',
            'status' => 'posted',
            'reversed_by_payment_id' => $payment->id,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

// ---------------------------------------------------------------------------
// Test 23: DB unique constraint on reversed_by_journal_id prevents duplicate reversal journal
// ---------------------------------------------------------------------------

test('DB unique constraint on reversed_by_journal_id prevents duplicate reversal journal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // Insert a first reversal journal row
        DB::table('accounting_journals')->insert([
            'company_id' => $ctx['company']->id,
            'journal_number' => 'REV-J-1-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'description' => 'Reversal test 1',
            'reversed_by_journal_id' => $journal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to insert a second row with the same reversed_by_journal_id
        expect(fn () => DB::table('accounting_journals')->insert([
            'company_id' => $ctx['company']->id,
            'journal_number' => 'REV-J-2-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'description' => 'Reversal test 2',
            'reversed_by_journal_id' => $journal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

// ---------------------------------------------------------------------------
// Fix 1 — Test 24: reversing a reversal payment throws ValidationException
// A reversal payment has reversal_of_payment_id set (status='posted').
// This is distinct from Test 12 which tests reversing an already-REVERSED
// original (status='reversed', reversed_by_payment_id set).
// ---------------------------------------------------------------------------

test('reversing a reversal payment throws ValidationException and creates no extra rows', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        // Create and reverse a posted payment to obtain a reversal payment
        [$originalPayment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );
        $reversalPayment = $service->reversePayment($originalPayment, [], $ctx['company']);

        // The reversal payment has reversal_of_payment_id set and status='posted'
        expect($reversalPayment->reversal_of_payment_id)->not()->toBeNull();
        expect($reversalPayment->status)->toBe('posted');

        $paymentCountBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
        $journalCountBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

        // Attempting to reverse the reversal payment must throw
        expect(fn () => $service->reversePayment($reversalPayment, [], $ctx['company']))
            ->toThrow(ValidationException::class);

        // No extra payment or journal row was created
        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentCountBefore);
        expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalCountBefore);
    });
});

// ---------------------------------------------------------------------------
// Fix 2 — Test 25: deleting a reversed original payment throws ValidationException
// A reversed payment has status='reversed'. The Eloquent deleting hook must
// block this to avoid orphaning its reversal_of_payment_id FK on the reversal.
// ---------------------------------------------------------------------------

test('deleting a reversed original payment throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        [$originalPayment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $service->reversePayment($originalPayment, [], $ctx['company']);

        // Reload — original payment is now 'reversed'
        $reversed = $originalPayment->fresh();
        expect($reversed->status)->toBe('reversed');

        // Attempting to delete a reversed payment must throw
        expect(fn () => $reversed->delete())->toThrow(ValidationException::class);

        // The row must still exist
        expect(Payment::withoutGlobalScope(CompanyScope::class)->find($reversed->id))->not()->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Fix 2 — Test 26: deleting a reversal payment (status='posted', reversal_of_payment_id set)
// throws ValidationException — these are posted records and must not be deleted.
// ---------------------------------------------------------------------------

test('deleting a reversal payment throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        $service = app(PaymentService::class);

        [$originalPayment] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $reversalPayment = $service->reversePayment($originalPayment, [], $ctx['company']);

        // The reversal payment has status='posted' and reversal_of_payment_id set
        $freshReversal = $reversalPayment->fresh();
        expect($freshReversal->status)->toBe('posted');
        expect($freshReversal->reversal_of_payment_id)->not()->toBeNull();

        // Attempting to delete it must throw (it is a posted payment)
        expect(fn () => $freshReversal->delete())->toThrow(ValidationException::class);

        // The row must still exist
        expect(Payment::withoutGlobalScope(CompanyScope::class)->find($freshReversal->id))->not()->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Fix 2 — Test 27: deleting a draft payment still succeeds (preserving existing behavior)
// ---------------------------------------------------------------------------

test('deleting a draft payment succeeds and existing allowed behavior is preserved', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx): void {
        $party = Party::create(['company_id' => $ctx['company']->id, 'name' => 'P '.uniqid()]);

        $draftPayment = Payment::create([
            'company_id' => $ctx['company']->id,
            'party_id' => $party->id,
            'financial_account_id' => $ctx['sypCash']->id,
            'currency' => 'SYP',
            'amount' => '1000',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '1000',
            'applied_amount' => '1000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '1',
            'applied_base_amount' => '1000',
            'direction' => 'outbound',
            'payment_number' => 'PAY-DRAFT-'.uniqid(),
            'paid_at' => now(),
            'status' => 'draft',
            'posted_at' => null,
        ]);

        $draftId = $draftPayment->id;

        // Draft payment must be deletable
        $draftPayment->delete();

        expect(Payment::withoutGlobalScope(CompanyScope::class)->find($draftId))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Fix 3 — Test 28: attempting to change posted_at on a posted journal throws ValidationException
// ---------------------------------------------------------------------------

test('changing posted_at on a posted journal throws ValidationException', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $postedJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($journal->id);
        expect($postedJournal->status)->toBe('posted');
        $originalPostedAt = $postedJournal->posted_at;

        // Attempting to change posted_at on an already-posted journal must throw
        expect(function () use ($postedJournal): void {
            $postedJournal->posted_at = now()->addDay();
            $postedJournal->save();
        })->toThrow(ValidationException::class);

        // posted_at must remain unchanged
        $reloaded = AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($postedJournal->id);
        expect($reloaded->posted_at->toDateTimeString())->toBe($originalPostedAt->toDateTimeString());
    });
});

// ---------------------------------------------------------------------------
// Fix 3 — Test 29: draft-to-posted transition via postJournal() sets posted_at correctly
// Verifies the initial draft→posted save is NOT blocked by the whitelist guard.
// ---------------------------------------------------------------------------

test('draft to posted transition via postJournal sets posted_at correctly', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx): void {
        $accounting = app(AccountingService::class);

        // Create a draft journal manually
        $draft = $accounting->createDraftJournal([
            'company_id' => $ctx['company']->id,
            'description' => 'Draft-to-posted test',
            'entry_date' => now()->toDateString(),
        ], $ctx['company']);

        expect($draft->status)->toBe('draft');
        expect($draft->posted_at)->toBeNull();

        // Add two balanced lines so the journal can be posted
        $accounting->addJournalLine($draft, [
            'financial_account_id' => $ctx['sypPayable']->id,
            'debit' => '1000',
            'currency' => 'SYP',
        ]);
        $accounting->addJournalLine($draft, [
            'financial_account_id' => $ctx['sypCash']->id,
            'credit' => '1000',
            'currency' => 'SYP',
        ]);

        $posted = $accounting->postJournal($draft);

        expect($posted->status)->toBe('posted');
        expect($posted->posted_at)->not()->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// T-A: Original journal lines are unchanged after reversal
// ---------------------------------------------------------------------------

test('original journal lines are unchanged after reversal', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment, $journal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        // Capture original line values before reversal
        $linesBefore = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $journal->id)
            ->orderBy('line_number')
            ->get()
            ->map(fn ($l) => [
                'financial_account_id' => $l->financial_account_id,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'base_debit' => $l->base_debit,
                'base_credit' => $l->base_credit,
                'currency' => $l->currency,
            ])
            ->toArray();

        app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        // Reload lines after reversal
        $linesAfter = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $journal->id)
            ->orderBy('line_number')
            ->get()
            ->map(fn ($l) => [
                'financial_account_id' => $l->financial_account_id,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'base_debit' => $l->base_debit,
                'base_credit' => $l->base_credit,
                'currency' => $l->currency,
            ])
            ->toArray();

        expect($linesAfter)->toBe($linesBefore);
    });
});

// ---------------------------------------------------------------------------
// T-B: Reversal journal's reversal_of_journal_id equals the exact original journal ID
// ---------------------------------------------------------------------------

test('reversal journal reversal_of_journal_id equals original journal id', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase): void {
        [$payment, $originalJournal] = makePostedSupplierPaymentRev(
            $ctx['company'], $purchase, $ctx['sypPayable'], $ctx['sypCash'], '5000'
        );

        $reversalPayment = app(PaymentService::class)->reversePayment($payment, [], $ctx['company']);

        $reversalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reversalPayment->id)
            ->where('status', 'posted')
            ->firstOrFail();

        // Exact numeric equality — not just "not null"
        expect((int) $reversalJournal->reversal_of_journal_id)->toBe((int) $originalJournal->id);
    });
});

// ---------------------------------------------------------------------------
// T-C: Partner liability balance is zero after reversing a partner-paid purchase payment
// ---------------------------------------------------------------------------

test('partner liability balance is zero after reversing a partner-paid purchase payment', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $purchase = makeReversalPurchase($company, '5000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner '.uniqid()]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $purchase, $partner): void {
        $service = app(PaymentService::class);

        // Record a partner-paid purchase: Dr Supplier Payable, Cr Due to Partner
        $payment = $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '5000',
            'applied_amount' => '5000',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $ctx['sypPayable']->id,
            'partner_liability_account_id' => $ctx['dueToPartner']->id,
        ]);

        // After payment the partner liability should be 5000
        $balanceAfterPayment = $service->calculatePartnerLiabilityBalance($partner, $ctx['dueToPartner']);
        expect(bccomp($balanceAfterPayment, '0', 4))->toBeGreaterThan(0);

        // Reverse the payment
        $service->reversePayment($payment, [], $ctx['company']);

        // After reversal the liability balance must be zero
        $balanceAfterReversal = $service->calculatePartnerLiabilityBalance($partner, $ctx['dueToPartner']);
        expect(bccomp($balanceAfterReversal, '0', 4))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// T-D: Revenue outstanding is restored after reversing a customer receipt
// ---------------------------------------------------------------------------

test('revenue outstanding is restored after reversing a customer receipt', function (): void {
    $ctx = makeReversalContext();
    $company = $ctx['company'];
    $revenue = makeReversalRevenue($company, '3000');

    app(CompanyContextService::class)->runForCompany($company, function () use ($ctx, $revenue): void {
        $service = app(PaymentService::class);

        // Record a full customer receipt
        $payment = $service->recordCustomerReceipt($revenue, [
            'amount' => '3000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_amount' => '3000',
            'applied_amount' => '3000',
            'applied_currency' => 'SYP',
            'financial_account_id' => $ctx['sypCash']->id,
            'receivable_account_id' => $ctx['receivable']->id,
        ]);

        // After receipt the outstanding should be zero
        $outstandingAfterReceipt = $service->calculateOutstandingForRevenue($revenue);
        expect(bccomp($outstandingAfterReceipt, '0', 4))->toBe(0);

        // Reverse the receipt
        $service->reversePayment($payment, [], $ctx['company']);

        // After reversal the outstanding should be restored to the revenue total
        $outstandingAfterReversal = $service->calculateOutstandingForRevenue($revenue);
        expect(bccomp($outstandingAfterReversal, '3000', 4))->toBe(0);
    });
});
