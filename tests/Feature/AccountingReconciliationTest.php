<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\AccountingReconciliationService;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeReconciliationPayment(Company $company, string $status = 'posted'): Payment
{
    return Payment::create([
        'company_id' => $company->getKey(),
        'payment_number' => 'REC-'.uniqid(),
        'direction' => 'outbound',
        'paid_at' => now(),
        'amount' => '25.00',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '25.00',
        'applied_amount' => '25.00',
        'applied_currency' => 'SYP',
        'applied_exchange_rate' => '1',
        'applied_base_amount' => '25.00',
        'status' => $status,
        'posted_at' => $status === 'posted' ? now() : null,
    ]);
}

it('reports unsupported control balances and clearly excludes unvalued legacy inventory', function () {
    $company = Company::create(['name' => 'Reconciliation Co', 'base_currency' => 'SYP']);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->getKey(),
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $warehouse = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'MAIN',
        'name' => 'Main Warehouse',
    ]);
    $product = Product::create([
        'company_id' => $company->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'LEGACY-1',
        'name' => 'Legacy inventory',
        'tracks_inventory' => true,
    ]);
    StockMovement::create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => 'opening',
        'direction' => 'in',
        'quantity' => '3',
        'occurred_at' => now(),
    ]);

    $report = app(AccountingReconciliationService::class)->reconcile($company);

    expect($report['customer_receivables']['status'])->toBe('unsupported')
        ->and($report['supplier_payables']['status'])->toBe('unsupported')
        ->and($report['cash_and_bank']['status'])->toBe('unsupported')
        ->and($report['inventory']['status'])->toBe('incomplete')
        ->and($report['inventory']['expected_amount'])->toBe('0.0000')
        ->and($report['inventory']['unvalued_products'])->toHaveCount(1)
        ->and($report['inventory']['unvalued_products'][0]['source_id'])->toBe($product->getKey())
        ->and($report['inventory']['unvalued_products'][0]['expected_amount'])->toBeNull();
});

it('detects missing and duplicate posted payment journals without modifying records', function () {
    $company = Company::create(['name' => 'Payment Reconciliation Co', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->getKey(),
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $payable = FinancialAccount::create([
        'company_id' => $company->getKey(),
        'code' => '2000',
        'name' => 'Payable',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);
    $missingJournalPayment = makeReconciliationPayment($company);
    $duplicateJournalPayment = makeReconciliationPayment($company);
    $otherCompany = Company::create(['name' => 'Other Reconciliation Co', 'base_currency' => 'SYP']);
    $foreignAccount = FinancialAccount::create([
        'company_id' => $otherCompany->getKey(),
        'code' => '1000',
        'name' => 'Foreign cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $inconsistentPayment = makeReconciliationPayment($company, 'draft');
    DB::table('payments')
        ->where('id', $inconsistentPayment->getKey())
        ->update(['financial_account_id' => $foreignAccount->getKey()]);
    $accounting = app(AccountingService::class);
    $orphanJournalId = null;

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $accounting,
        $cash,
        $payable,
        $duplicateJournalPayment,
        &$orphanJournalId,
    ): void {
        foreach ([
            ['FIRST', $duplicateJournalPayment->getKey()],
            ['SECOND', $duplicateJournalPayment->getKey()],
            ['ORPHAN', $duplicateJournalPayment->getKey()],
        ] as [$suffix, $sourceId]) {
            $journal = $accounting->createDraftJournal([
                'sourceable_type' => Payment::class,
                'sourceable_id' => $sourceId,
                'description' => 'Payment journal '.$suffix,
            ]);
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $cash->getKey(),
                'debit' => '25',
                'currency' => 'SYP',
                'base_currency' => 'SYP',
                'base_debit' => '25',
                'base_credit' => '0',
            ]);
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $payable->getKey(),
                'credit' => '25',
                'currency' => 'SYP',
                'base_currency' => 'SYP',
                'base_debit' => '0',
                'base_credit' => '25',
            ]);
            $accounting->postJournal($journal);

            if ($suffix === 'ORPHAN') {
                $orphanJournalId = $journal->getKey();
            }
        }
    });

    DB::table('accounting_journals')
        ->where('id', $orphanJournalId)
        ->update(['sourceable_id' => 987654321]);

    $paymentsBefore = Payment::withoutGlobalScopes()->count();
    $journalsBefore = AccountingJournal::withoutGlobalScopes()->count();
    $report = app(AccountingReconciliationService::class)->reconcile($company);
    $issues = collect($report['payment_integrity']['discrepancies'])->pluck('issue');

    expect($issues)->toContain('posted_payment_missing_journal')
        ->and($issues)->toContain('posted_payment_duplicate_journals')
        ->and($issues)->toContain('journal_linked_to_missing_payment')
        ->and($issues)->toContain('payment_account_relationship_mismatch')
        ->and(Payment::withoutGlobalScopes()->count())->toBe($paymentsBefore)
        ->and(AccountingJournal::withoutGlobalScopes()->count())->toBe($journalsBefore)
        ->and($missingJournalPayment->fresh()->status)->toBe('posted')
        ->and($duplicateJournalPayment->fresh()->status)->toBe('posted');
});

it('does not report draft payments as missing posted journals', function () {
    $company = Company::create(['name' => 'Draft Reconciliation Co', 'base_currency' => 'SYP']);
    makeReconciliationPayment($company, 'draft');

    $report = app(AccountingReconciliationService::class)->reconcile($company);

    expect($report['payment_integrity']['discrepancies'])->toBe([]);
});
