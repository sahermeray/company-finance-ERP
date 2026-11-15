<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\BusinessInsightsService;
use App\Services\CompanyContextService;
use App\Services\FinancialReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function reportingAccountForTest(
    Company $company,
    string $code,
    string $type,
    string $currency = 'SYP',
    ?string $classification = null,
): FinancialAccount {
    return FinancialAccount::create([
        'company_id' => $company->getKey(),
        'code' => $code.'-'.uniqid(),
        'name' => $code,
        'account_type' => $type,
        'statement_classification' => $classification,
        'currency' => $currency,
    ]);
}

/**
 * @param  array<int, array{account: FinancialAccount, debit?: string, credit?: string, exchange_rate?: string, party_id?: int}>  $lines
 */
function postReportingJournal(
    Company $company,
    string $date,
    array $lines,
    ?string $sourceType = null,
    ?int $sourceId = null,
): AccountingJournal {
    return app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $date,
        $lines,
        $sourceType,
        $sourceId,
    ): AccountingJournal {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'sourceable_type' => $sourceType,
            'sourceable_id' => $sourceId,
            'journal_number' => 'FIN-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Financial reporting scenario',
        ], $company);
        foreach ($lines as $line) {
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $line['account']->getKey(),
                'party_id' => $line['party_id'] ?? null,
                'debit' => $line['debit'] ?? '0',
                'credit' => $line['credit'] ?? '0',
                'currency' => $line['account']->currency,
                'exchange_rate' => $line['exchange_rate'] ?? '1',
                'base_currency' => $company->base_currency,
            ]);
        }

        return $accounting->postJournal($journal);
    });
}

it('reports trial balance openings and movements without mixing native currencies or companies', function () {
    $company = Company::create(['name' => 'Trial balance company', 'base_currency' => 'SYP']);
    $otherCompany = Company::create(['name' => 'Isolated company', 'base_currency' => 'SYP']);
    $cash = reportingAccountForTest($company, '1000', 'asset');
    $usdBank = reportingAccountForTest($company, '1010', 'asset', 'USD');
    $equity = reportingAccountForTest($company, '3000', 'equity');
    $sypRevenue = reportingAccountForTest($company, '4000', 'revenue', 'SYP', 'revenue');
    $usdRevenue = reportingAccountForTest($company, '4010', 'revenue', 'USD', 'revenue');
    $foreignCash = reportingAccountForTest($otherCompany, '1000', 'asset');

    postReportingJournal($company, '2026-10-01', [
        ['account' => $cash, 'debit' => '100'],
        ['account' => $equity, 'credit' => '100'],
    ]);
    postReportingJournal($company, '2026-10-10', [
        ['account' => $cash, 'debit' => '20'],
        ['account' => $sypRevenue, 'credit' => '20'],
    ]);
    postReportingJournal($company, '2026-10-12', [
        ['account' => $usdBank, 'debit' => '10', 'exchange_rate' => '20'],
        ['account' => $usdRevenue, 'credit' => '10', 'exchange_rate' => '20'],
    ]);
    postReportingJournal($otherCompany, '2026-10-11', [
        ['account' => $foreignCash, 'debit' => '999'],
        ['account' => reportingAccountForTest($otherCompany, '3000', 'equity'), 'credit' => '999'],
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($company, $cash, $sypRevenue): void {
        $accounting = app(AccountingService::class);
        $draft = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'journal_number' => 'DRAFT-'.uniqid(),
            'entry_date' => '2026-10-15',
        ], $company);
        $accounting->addJournalLine($draft, ['financial_account_id' => $cash->getKey(), 'debit' => '5000']);
        $accounting->addJournalLine($draft, ['financial_account_id' => $sypRevenue->getKey(), 'credit' => '5000']);
    });

    $report = app(FinancialReportingService::class)->trialBalance($company, '2026-10-10', '2026-10-31');
    $cashBalance = collect($report['accounts'])->firstWhere('account_id', $cash->getKey());
    $usdBalance = collect($report['accounts'])->firstWhere('account_id', $usdBank->getKey());

    expect($report['totals']['opening'])->toBe(['debit' => '100.0000', 'credit' => '100.0000'])
        ->and($report['totals']['period'])->toBe(['debit' => '220.0000', 'credit' => '220.0000'])
        ->and($report['totals']['closing'])->toBe(['debit' => '320.0000', 'credit' => '320.0000'])
        ->and($cashBalance['closing']['debit'])->toBe('120.0000')
        ->and($usdBalance['native_balances']['USD']['period'])->toBe(['debit' => '10.0000', 'credit' => '0.0000'])
        ->and($report['native_currency_totals']['USD']['closing'])->toBe(['debit' => '10.0000', 'credit' => '10.0000'])
        ->and($report['status'])->toBe('complete');
});

it('reports account ledger running balances and keeps stored historical base values', function () {
    $company = Company::create(['name' => 'Ledger company', 'base_currency' => 'SYP']);
    $usdBank = reportingAccountForTest($company, '1010', 'asset', 'USD');
    $equity = reportingAccountForTest($company, '3000', 'equity');
    $firstJournal = postReportingJournal($company, '2026-10-10', [
        ['account' => $usdBank, 'debit' => '10', 'exchange_rate' => '20'],
        ['account' => $equity, 'credit' => '200'],
    ]);
    $secondJournal = postReportingJournal($company, '2026-10-12', [
        ['account' => $usdBank, 'credit' => '2', 'exchange_rate' => '20'],
        ['account' => $equity, 'debit' => '40'],
    ]);
    ExchangeRate::create([
        'company_id' => $company->getKey(),
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '99',
        'effective_at' => '2026-10-13 00:00:00',
        'source' => 'test',
    ]);

    $report = app(FinancialReportingService::class)->generalLedger($company, '2026-10-10', '2026-10-31', (int) $usdBank->getKey());

    expect($report['transactions'])->toHaveCount(2)
        ->and($report['transactions'][0]['journal_id'])->toBe($firstJournal->getKey())
        ->and($report['transactions'][0]['base_debit'])->toBe('200.0000')
        ->and($report['transactions'][0]['running_balance_base'])->toBe('200.0000')
        ->and($report['transactions'][1]['journal_reference'])->toBe($secondJournal->journal_number)
        ->and($report['transactions'][1]['running_balance_base'])->toBe('160.0000')
        ->and($report['transactions'][1]['running_balance_native'])->toBe('8.0000')
        ->and($report['transactions'][0]['currency'])->toBe('USD');
});

it('nets posted reversals once, excludes drafts, and compares income statement periods', function () {
    $company = Company::create(['name' => 'Income statement company']);
    $cash = reportingAccountForTest($company, '1000', 'asset');
    $revenue = reportingAccountForTest($company, '4000', 'revenue', 'SYP', 'revenue');
    postReportingJournal($company, '2026-09-10', [
        ['account' => $cash, 'debit' => '20'],
        ['account' => $revenue, 'credit' => '20'],
    ]);
    $original = postReportingJournal($company, '2026-10-10', [
        ['account' => $cash, 'debit' => '100'],
        ['account' => $revenue, 'credit' => '100'],
    ]);
    app(AccountingService::class)->reverseJournal($original, ['entry_date' => '2026-10-20']);

    $draft = app(AccountingService::class)->createDraftJournal([
        'company_id' => $company->getKey(),
        'journal_number' => 'DRAFT-'.uniqid(),
        'entry_date' => '2026-10-25',
    ], $company);
    app(CompanyContextService::class)->runForCompany($company, function () use ($draft, $cash, $revenue): void {
        $accounting = app(AccountingService::class);
        $accounting->addJournalLine($draft, ['financial_account_id' => $cash->getKey(), 'debit' => '1000']);
        $accounting->addJournalLine($draft, ['financial_account_id' => $revenue->getKey(), 'credit' => '1000']);
    });

    $report = app(FinancialReportingService::class)->incomeStatement(
        $company,
        '2026-10-01',
        '2026-10-31',
        '2026-09-01',
        '2026-09-30',
    );
    $beforeReversal = app(FinancialReportingService::class)->incomeStatement($company, '2026-10-01', '2026-10-15');

    expect($report['metrics']['revenue']['value'])->toBe('0.0000')
        ->and($report['metrics']['net_profit']['value'])->toBe('0.0000')
        ->and($report['metrics']['revenue']['comparison']['value'])->toBe('20.0000')
        ->and($report['metrics']['revenue']['comparison']['difference'])->toBe('-20.0000')
        ->and($beforeReversal['metrics']['revenue']['value'])->toBe('100.0000')
        ->and($draft->fresh()->status)->toBe('draft');
});

it('presents current earnings in equity and reports the balance sheet equation as of the selected date', function () {
    $company = Company::create(['name' => 'Balance sheet company']);
    $asset = reportingAccountForTest($company, '1000', 'asset');
    $liability = reportingAccountForTest($company, '2000', 'liability');
    $equity = reportingAccountForTest($company, '3000', 'equity');
    $revenue = reportingAccountForTest($company, '4000', 'revenue', 'SYP', 'revenue');
    $expense = reportingAccountForTest($company, '5000', 'expense', 'SYP', 'operating_expense');
    postReportingJournal($company, '2026-06-01', [
        ['account' => $asset, 'debit' => '100'],
        ['account' => $expense, 'debit' => '20'],
        ['account' => $liability, 'credit' => '30'],
        ['account' => $equity, 'credit' => '50'],
        ['account' => $revenue, 'credit' => '40'],
    ]);
    postReportingJournal($company, '2026-07-01', [
        ['account' => $asset, 'debit' => '10'],
        ['account' => $revenue, 'credit' => '10'],
    ]);

    $report = app(FinancialReportingService::class)->balanceSheet($company, '2026-06-30');

    expect($report['assets']['total'])->toBe('100.0000')
        ->and($report['liabilities']['total'])->toBe('30.0000')
        ->and($report['equity']['current_period_earnings'])->toBe('20.0000')
        ->and($report['equity']['total'])->toBe('70.0000')
        ->and($report['equation_difference'])->toBe('0.0000')
        ->and($report['equation_balances'])->toBeTrue()
        ->and($report['status'])->toBe('balanced');
});

it('requires valid explicit classifications and leaves unclassified account results unavailable', function () {
    $company = Company::create(['name' => 'Classification company']);
    $cash = reportingAccountForTest($company, '1000', 'asset');
    $unclassifiedExpense = reportingAccountForTest($company, '5000', 'expense');

    expect(fn () => reportingAccountForTest($company, 'BAD', 'expense', 'SYP', 'revenue'))
        ->toThrow(ValidationException::class);

    postReportingJournal($company, '2026-10-01', [
        ['account' => $cash, 'debit' => '15'],
        ['account' => $unclassifiedExpense, 'credit' => '15'],
    ]);

    $report = app(FinancialReportingService::class)->incomeStatement($company, '2026-10-01', '2026-10-31');

    expect($report['status'])->toBe('incomplete')
        ->and($report['metrics']['net_profit']['value'])->toBeNull()
        ->and($report['metrics']['net_profit']['availability'])->toBe('unavailable')
        ->and($report['unresolved'])->toContain([
            'issue' => 'profit_and_loss_account_unclassified',
            'account_id' => (int) $unclassifiedExpense->getKey(),
            'account_code' => $unclassifiedExpense->code,
        ]);
});

it('provides read-only business insights with returns, inventory valuation, and subledger status', function () {
    $company = Company::create(['name' => 'Insights company', 'base_currency' => 'SYP']);
    $customer = Party::create([
        'company_id' => $company->getKey(),
        'code' => 'C-'.uniqid(),
        'name' => 'Retail customer',
    ]);
    $cash = reportingAccountForTest($company, '1000', 'asset');
    $receivable = reportingAccountForTest($company, '1100', 'asset');
    $inventory = reportingAccountForTest($company, '1200', 'asset');
    $equity = reportingAccountForTest($company, '3000', 'equity');
    $revenueAccount = reportingAccountForTest($company, '4000', 'revenue', 'SYP', 'revenue');
    $cogs = reportingAccountForTest($company, '5000', 'expense', 'SYP', 'cost_of_goods_sold');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
        'financial_account_id' => $receivable->getKey(),
    ]);
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::INVENTORY_ASSET,
        'financial_account_id' => $inventory->getKey(),
    ]);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->getKey(),
        'code' => 'EA-'.uniqid(),
        'name' => 'Each',
    ]);
    $warehouse = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'WH-'.uniqid(),
        'name' => 'Main warehouse',
    ]);
    $product = Product::create([
        'company_id' => $company->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Widget',
        'tracks_inventory' => true,
    ]);
    $revenue = Revenue::create([
        'company_id' => $company->getKey(),
        'party_id' => $customer->getKey(),
        'receivable_account_id' => $receivable->getKey(),
        'inventory_asset_account_id' => $inventory->getKey(),
        'revenue_number' => 'REV-'.uniqid(),
        'revenue_date' => '2026-10-10',
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '100',
        'tax_total' => '0',
        'total' => '100',
        'base_subtotal' => '100',
        'base_tax_total' => '0',
        'base_total' => '100',
    ]);
    $revenueItem = RevenueItem::create([
        'revenue_id' => $revenue->getKey(),
        'product_id' => $product->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'line_number' => 1,
        'description' => 'Widget sale',
        'quantity' => '1',
        'unit_price' => '100',
        'line_total' => '100',
    ]);
    $revenue->status = 'posted';
    $revenue->save();

    StockMovement::create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => 'opening',
        'direction' => 'in',
        'quantity' => '3',
        'base_cost_amount' => '60',
        'base_unit_cost' => '20',
        'occurred_at' => '2026-10-01 09:00:00',
    ]);
    postReportingJournal($company, '2026-10-01', [
        ['account' => $inventory, 'debit' => '60'],
        ['account' => $equity, 'credit' => '60'],
    ]);
    $saleJournal = postReportingJournal($company, '2026-10-10', [
        ['account' => $receivable, 'debit' => '100', 'party_id' => $customer->getKey()],
        ['account' => $revenueAccount, 'credit' => '100', 'party_id' => $customer->getKey()],
        ['account' => $cogs, 'debit' => '20'],
        ['account' => $inventory, 'credit' => '20'],
    ], Revenue::class, (int) $revenue->getKey());
    StockMovement::create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => 'sale',
        'direction' => 'out',
        'quantity' => '1',
        'base_cost_amount' => '20',
        'base_unit_cost' => '20',
        'sourceable_type' => Revenue::class,
        'sourceable_id' => $revenue->getKey(),
        'occurred_at' => '2026-10-10 10:00:00',
    ]);
    $payment = Payment::create([
        'company_id' => $company->getKey(),
        'party_id' => $customer->getKey(),
        'financial_account_id' => $cash->getKey(),
        'payable_type' => Revenue::class,
        'payable_id' => $revenue->getKey(),
        'payment_number' => 'PAY-'.uniqid(),
        'direction' => 'inbound',
        'paid_at' => '2026-10-11 12:00:00',
        'amount' => '25',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '25',
        'applied_amount' => '25',
        'applied_currency' => 'SYP',
        'applied_exchange_rate' => '1',
        'applied_base_amount' => '25',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    postReportingJournal($company, '2026-10-11', [
        ['account' => $cash, 'debit' => '25', 'party_id' => $customer->getKey()],
        ['account' => $receivable, 'credit' => '25', 'party_id' => $customer->getKey()],
    ], Payment::class, (int) $payment->getKey());

    $return = CustomerReturn::create([
        'company_id' => $company->getKey(),
        'original_revenue_id' => $revenue->getKey(),
        'party_id' => $customer->getKey(),
        'refund_account_id' => $receivable->getKey(),
        'revenue_account_id' => $revenueAccount->getKey(),
        'inventory_asset_account_id' => $inventory->getKey(),
        'cogs_account_id' => $cogs->getKey(),
        'return_number' => 'RET-'.uniqid(),
        'return_date' => '2026-10-12',
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '10',
        'tax_total' => '0',
        'total' => '10',
        'base_subtotal' => '10',
        'base_tax_total' => '0',
        'base_total' => '10',
        'reason' => 'Customer return',
    ]);
    CustomerReturnLine::create([
        'customer_return_id' => $return->getKey(),
        'revenue_item_id' => $revenueItem->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'quantity' => '0.5',
        'native_amount' => '10',
        'base_amount' => '10',
        'inventory_cost_amount' => '10',
    ]);
    $return->status = 'posted';
    $return->posted_at = now();
    $return->save();
    $returnJournal = postReportingJournal($company, '2026-10-12', [
        ['account' => $revenueAccount, 'debit' => '10', 'party_id' => $customer->getKey()],
        ['account' => $receivable, 'credit' => '10', 'party_id' => $customer->getKey()],
        ['account' => $inventory, 'debit' => '10'],
        ['account' => $cogs, 'credit' => '10'],
    ], CustomerReturn::class, (int) $return->getKey());
    DB::table('customer_returns')->where('id', $return->getKey())->update([
        'accounting_journal_id' => $returnJournal->getKey(),
    ]);
    StockMovement::create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => 'customer_return',
        'direction' => 'in',
        'quantity' => '0.5',
        'base_cost_amount' => '10',
        'base_unit_cost' => '20',
        'sourceable_type' => CustomerReturn::class,
        'sourceable_id' => $return->getKey(),
        'occurred_at' => '2026-10-12 10:00:00',
    ]);

    $countsBefore = [
        'journals' => AccountingJournal::withoutGlobalScopes()->count(),
        'payments' => Payment::withoutGlobalScopes()->count(),
        'movements' => StockMovement::withoutGlobalScopes()->count(),
    ];
    $insights = app(BusinessInsightsService::class)->insights($company, '2026-10-01', '2026-10-31');
    $countsAfter = [
        'journals' => AccountingJournal::withoutGlobalScopes()->count(),
        'payments' => Payment::withoutGlobalScopes()->count(),
        'movements' => StockMovement::withoutGlobalScopes()->count(),
    ];

    expect($insights['sales_and_purchases']['sales_total']['value'])->toBe('100.0000')
        ->and($insights['sales_and_purchases']['sales_returns']['value'])->toBe('10.0000')
        ->and($insights['sales_and_purchases']['net_sales']['value'])->toBe('90.0000')
        ->and($insights['sales_and_purchases']['sales_trend']['months']['2026-10']['net_sales'])->toBe('90.0000')
        ->and($insights['sales_and_purchases']['top_customers']['value'][0]['sales_base_amount'])->toBe('90.0000')
        ->and($insights['sales_and_purchases']['top_products']['value'][0]['sales_base_amount'])->toBe('90.0000')
        ->and($insights['financial_performance']['metrics']['net_profit']['value'])->toBe('80.0000')
        ->and($insights['inventory']['total_valued_inventory']['value'])->toBe('50.0000')
        ->and($insights['inventory']['total_valued_inventory']['currency'])->toBe('SYP')
        ->and($insights['receivables_and_payables']['customer_receivables']['operational_balance'])->toBe('65.0000')
        ->and($insights['receivables_and_payables']['customer_receivables']['general_ledger_balance'])->toBe('65.0000')
        ->and($insights['cash_and_bank']['availability'])->toBe('unsupported')
        ->and($insights['inventory']['low_stock_indicators']['availability'])->toBe('unsupported')
        ->and($countsAfter)->toBe($countsBefore)
        ->and($saleJournal->fresh()->status)->toBe('posted');
});
