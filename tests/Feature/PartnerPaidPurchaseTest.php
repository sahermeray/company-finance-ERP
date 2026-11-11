<?php

/**
 * PartnerPaidPurchaseTest
 *
 * Tests for purchases paid personally by a partner on behalf of the company.
 *
 * Accounting entries used:
 *
 * 1. Purchase posting (PurchaseService — unchanged):
 *    Dr Inventory / Expense    (asset/expense)
 *    Cr Supplier Payable       (liability)
 *
 * 2. Partner pays the supplier on the company's behalf:
 *    Dr Supplier Payable       (liability)  — clears what was owed to supplier
 *    Cr Due to Partner         (liability)  — records what is now owed to partner
 *
 * 3. Company reimburses the partner:
 *    Dr Due to Partner         (liability)  — reduces what is owed to partner
 *    Cr Cash / Bank            (asset)      — company cash goes out
 */

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a company with the four accounts used across partner-payment tests:
 *   - Supplier Payable    (liability, code 2000)
 *   - Due to Partner      (liability, code 2100)
 *   - Cash                (asset,     code 1000)
 *   - Inventory           (asset,     code 1500)
 */
function makePartnerPaymentContext(string $currency = 'SYP', string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'PartnerCo '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);

    $supplierPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable',
        'account_type' => 'liability',
        'currency' => $currency,
    ]);

    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2100',
        'name' => 'Due to Partner',
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

    return [$company, $supplierPayable, $dueToPartner, $cashAccount];
}

/**
 * Create a posted purchase directly (no PurchaseService / no inventory).
 */
function makePostedPurchase(Company $company, string $total = '1000', string $currency = 'SYP'): Purchase
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
        'base_currency' => $currency,
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

// ---------------------------------------------------------------------------
// 1. A purchase paid entirely by a partner
// ---------------------------------------------------------------------------

it('records a partner-paid purchase and creates the correct journal entries', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '1000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Alice']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner,
    ): void {
        $payment = app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '1000',
            'applied_amount' => '1000',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        expect($payment->status)->toBe('posted');
        // financial_account_id must be null — no company cash involved.
        expect($payment->financial_account_id)->toBeNull();
        // party_id must reference the partner.
        expect((int) $payment->party_id)->toBe($partner->id);

        // One posted journal linked to this payment.
        $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->where('status', 'posted')
            ->first();

        expect($journal)->not->toBeNull();
        expect($payment->accountingJournals()->count())->toBe(1);

        $lines = $journal->lines()->get();
        expect($lines)->toHaveCount(2);

        // Journal must balance.
        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit);
        expect($totalDebit)->toBe($totalCredit);

        // Debit side: Supplier Payable (clears liability to supplier).
        $debitLine = $lines->firstWhere('debit', '>', 0);
        expect((int) $debitLine->financial_account_id)->toBe($supplierPayable->id);

        // Credit side: Due to Partner (creates liability to partner).
        $creditLine = $lines->firstWhere('credit', '>', 0);
        expect((int) $creditLine->financial_account_id)->toBe($dueToPartner->id);
    });
});

// ---------------------------------------------------------------------------
// 2. Outstanding balance drops after partner payment
// ---------------------------------------------------------------------------

it('outstanding balance reaches zero after a full partner payment', function (): void {
    [$company, $supplierPayable, $dueToPartner] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '800');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Bob']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner,
    ): void {
        app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '800',
            'applied_amount' => '800',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// 3. Partially partner-funded purchase
// ---------------------------------------------------------------------------

it('supports a partially partner-funded purchase with a remaining balance', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '1000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Carol']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner, $cashAccount,
    ): void {
        $service = app(PaymentService::class);

        // Partner pays 600.
        $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '600',
            'applied_amount' => '600',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        // 400 still outstanding.
        $outstanding = $service->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '400', 10))->toBe(0);

        // Company pays the remaining 400.
        $service->recordSupplierPayment($purchase, [
            'amount' => '400',
            'applied_amount' => '400',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $supplierPayable->id,
        ]);

        // Now fully settled.
        $outstanding = $service->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// 4. Partner liability balance is recorded correctly
// ---------------------------------------------------------------------------

it('records the correct partner liability balance after a partner payment', function (): void {
    [$company, $supplierPayable, $dueToPartner] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '900');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Dave']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner,
    ): void {
        app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '900',
            'applied_amount' => '900',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        $liability = app(PaymentService::class)->calculatePartnerLiabilityBalance(
            $partner,
            $dueToPartner,
        );

        expect(bccomp($liability, '900', 10))->toBe(0);
    });
});

it('excludes partner liability journal lines owned by another company', function (): void {
    [$company, , $dueToPartner] = makePartnerPaymentContext();
    $otherCompany = Company::create(['name' => 'Other Partner Company']);
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Isolated Partner']);

    $journalId = DB::table('accounting_journals')->insertGetId([
        'company_id' => $otherCompany->id,
        'journal_number' => 'FOREIGN-'.uniqid(),
        'entry_date' => now()->toDateString(),
        'status' => 'posted',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('journal_lines')->insert([
        'accounting_journal_id' => $journalId,
        'financial_account_id' => $dueToPartner->id,
        'party_id' => $partner->id,
        'line_number' => 1,
        'credit' => '400',
        'debit' => '0',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_credit' => '400',
        'base_debit' => '0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(CompanyContextService::class)->runForCompany($otherCompany, function () use (
        $partner,
        $dueToPartner,
    ): void {
        $service = app(PaymentService::class);

        expect(bccomp($service->calculatePartnerLiabilityBalance($partner, $dueToPartner), '0', 10))->toBe(0)
            ->and($service->calculatePartnerLiabilityBalanceByCurrency($partner, $dueToPartner))->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// 5. Company reimbursement reduces the partner liability
// ---------------------------------------------------------------------------

it('reduces the partner liability after a company reimbursement', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '1000');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Eve']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company, $purchase, $partner, $supplierPayable, $dueToPartner, $cashAccount,
    ): void {
        $service = app(PaymentService::class);

        // Partner pays the full purchase.
        $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '1000',
            'applied_amount' => '1000',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        // Liability is 1000 before reimbursement.
        expect(bccomp(
            $service->calculatePartnerLiabilityBalance($partner, $dueToPartner),
            '1000', 10,
        ))->toBe(0);

        // Company reimburses 600.
        $service->recordPartnerReimbursement($company, [
            'amount' => '600',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'partner_liability_account_id' => $dueToPartner->id,
            'financial_account_id' => $cashAccount->id,
        ]);

        // Remaining liability is 400.
        $remaining = $service->calculatePartnerLiabilityBalance($partner, $dueToPartner);
        expect(bccomp($remaining, '400', 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// 6. Reimbursement journal entries are correct
// ---------------------------------------------------------------------------

it('creates the correct balanced journal for a company reimbursement to a partner', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Frank']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company, $partner, $dueToPartner, $cashAccount,
    ): void {
        $reimbursement = app(PaymentService::class)->recordPartnerReimbursement($company, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'partner_liability_account_id' => $dueToPartner->id,
            'financial_account_id' => $cashAccount->id,
        ]);

        $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $reimbursement->id)
            ->where('status', 'posted')
            ->first();

        expect($journal)->not->toBeNull();
        expect($reimbursement->accountingJournals()->count())->toBe(1);

        $lines = $journal->lines()->get();
        expect($lines)->toHaveCount(2);

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit);
        expect($totalDebit)->toBe($totalCredit);

        // Debit: Due to Partner (reduces liability to partner).
        $debitLine = $lines->firstWhere('debit', '>', 0);
        expect((int) $debitLine->financial_account_id)->toBe($dueToPartner->id);

        // Credit: Cash (company cash goes out).
        $creditLine = $lines->firstWhere('credit', '>', 0);
        expect((int) $creditLine->financial_account_id)->toBe($cashAccount->id);
    });
});

// ---------------------------------------------------------------------------
// 7. USD partner payment preserves currency and exchange rate
// ---------------------------------------------------------------------------

it('preserves USD amounts and exchange rates for a USD partner payment', function (): void {
    $company = Company::create(['name' => 'USDPartnerCo '.uniqid(), 'base_currency' => 'SYP']);

    $supplierPayable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2100',
        'name' => 'Due to Partner USD',
        'account_type' => 'liability',
        'currency' => 'USD',
    ]);

    $purchase = makePostedPurchase($company, '1000', 'USD');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'USD Partner']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner,
    ): void {
        $payment = app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '1000',
            'applied_amount' => '1000',
            'currency' => 'USD',
            'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_amount' => '12000000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'applied_base_amount' => '12000000',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->id)
            ->where('status', 'posted')
            ->first();

        expect($journal)->not->toBeNull();

        foreach ($journal->lines()->get() as $line) {
            expect($line->currency)->toBe('USD')
                ->and((string) $line->exchange_rate)->toBe('12000.0000000000');
        }

        $debitLine = $journal->lines()->where('debit', '>', 0)->first();
        $creditLine = $journal->lines()->where('credit', '>', 0)->first();
        expect((string) $debitLine->base_debit)->toBe('12000000.0000');
        expect((string) $creditLine->base_credit)->toBe('12000000.0000');
    });
});

// ---------------------------------------------------------------------------
// 8. No stock movements created by partner payment or reimbursement
// ---------------------------------------------------------------------------

it('does not create stock movements for a partner payment or reimbursement', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '700');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner Grace']);

    $movementsBefore = StockMovement::withoutGlobalScope(CompanyScope::class)->count();

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company, $purchase, $partner, $supplierPayable, $dueToPartner, $cashAccount,
    ): void {
        $service = app(PaymentService::class);

        $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '700',
            'applied_amount' => '700',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        $service->recordPartnerReimbursement($company, [
            'amount' => '700',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'partner_liability_account_id' => $dueToPartner->id,
            'financial_account_id' => $cashAccount->id,
        ]);
    });

    $movementsAfter = StockMovement::withoutGlobalScope(CompanyScope::class)->count();
    expect($movementsAfter)->toBe($movementsBefore);
});

// ---------------------------------------------------------------------------
// 9. Cross-company isolation
// ---------------------------------------------------------------------------

it('rejects a partner from another company', function (): void {
    [$companyA, $supplierPayableA, $dueToPartnerA] = makePartnerPaymentContext();
    [$companyB] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($companyA, '500');
    $partnerOfB = Party::create(['company_id' => $companyB->id, 'name' => 'Foreign Partner']);

    app(CompanyContextService::class)->runForCompany($companyA, function () use (
        $purchase, $partnerOfB, $supplierPayableA, $dueToPartnerA,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partnerOfB->id, // belongs to company B
            'payable_account_id' => $supplierPayableA->id,
            'partner_liability_account_id' => $dueToPartnerA->id,
        ]))->toThrow(ValidationException::class);
    });
});

it('rejects a partner liability account from another company', function (): void {
    [$companyA, $supplierPayableA] = makePartnerPaymentContext();
    [, , $dueToPartnerB] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($companyA, '500');
    $partner = Party::create(['company_id' => $companyA->id, 'name' => 'Partner A']);

    app(CompanyContextService::class)->runForCompany($companyA, function () use (
        $purchase, $partner, $supplierPayableA, $dueToPartnerB,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayableA->id,
            'partner_liability_account_id' => $dueToPartnerB->id, // belongs to company B
        ]))->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// 10. Transaction rollback on failure
// ---------------------------------------------------------------------------

it('rolls back the partner payment when the partner liability account does not exist', function (): void {
    [$company, $supplierPayable] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '500');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner H']);

    $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
    $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => 99999, // does not exist
        ]))->toThrow(ValidationException::class);
    });

    expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
});

// ---------------------------------------------------------------------------
// 11. Prevents duplicate posting
// ---------------------------------------------------------------------------

it('prevents posting a second journal for the same partner payment', function (): void {
    [$company, $supplierPayable, $dueToPartner] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '500');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner I']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner,
    ): void {
        $service = app(PaymentService::class);

        $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'applied_amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]);

        // Purchase is fully settled — trying again hits the overpayment guard.
        expect(fn () => $service->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'applied_amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]))->toThrow(ValidationException::class);

        // Exactly one journal for this purchase's payments.
        $count = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('status', 'posted')
            ->whereIn(
                'sourceable_id',
                Payment::withoutGlobalScope(CompanyScope::class)
                    ->where('payable_type', Purchase::class)
                    ->where('payable_id', $purchase->id)
                    ->pluck('id'),
            )
            ->count();

        expect($count)->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// 12. Regression — existing company-funded supplier payment still works
// ---------------------------------------------------------------------------

it('company-funded supplier payment still works after partner-payment feature is added', function (): void {
    [$company, $supplierPayable, , $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '600');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $supplierPayable, $cashAccount,
    ): void {
        $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '600',
            'applied_amount' => '600',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $supplierPayable->id,
        ]);

        expect($payment->status)->toBe('posted');
        expect($payment->financial_account_id)->not->toBeNull();

        $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// 13. Cannot use a company cash account for a partner-paid purchase
// ---------------------------------------------------------------------------

it('rejects a partner-paid purchase that accidentally references a cash account', function (): void {
    [$company, $supplierPayable, $dueToPartner, $cashAccount] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '500');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner J']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $supplierPayable, $dueToPartner, $cashAccount,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
            'financial_account_id' => $cashAccount->id, // must be rejected
        ]))->toThrow(ValidationException::class);
    });
});

// ---------------------------------------------------------------------------
// 14. party_id is required for partner payments
// ---------------------------------------------------------------------------

it('rejects a partner-paid purchase with no party_id', function (): void {
    [$company, $supplierPayable, $dueToPartner] = makePartnerPaymentContext();
    $purchase = makePostedPurchase($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $supplierPayable, $dueToPartner,
    ): void {
        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            // party_id deliberately omitted
            'payable_account_id' => $supplierPayable->id,
            'partner_liability_account_id' => $dueToPartner->id,
        ]))->toThrow(ValidationException::class);
    });
});
