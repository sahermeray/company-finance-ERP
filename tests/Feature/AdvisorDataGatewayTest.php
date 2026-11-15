<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\AdvisorDataGateway;
use App\Services\CompanyContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function advisorAccountForTest(
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
        'currency' => $currency,
        'statement_classification' => $classification,
    ]);
}

/**
 * @param  array<int, array{account: FinancialAccount, debit?: string, credit?: string, exchange_rate?: string, party_id?: int}>  $lines
 */
function postAdvisorJournal(
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
            'journal_number' => 'ADV-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Advisor gateway test entry',
            'sourceable_type' => $sourceType,
            'sourceable_id' => $sourceId,
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

function advisorUserForCompany(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company);

    return $user;
}

it('returns the standard overview contract and distinguishes zero from unsupported values', function () {
    $company = Company::create(['name' => 'Advisor company', 'base_currency' => 'SYP']);
    $user = advisorUserForCompany($company);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->companyOverview(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );

    expect(array_keys($result))->toBe([
        'company_id',
        'reporting_period',
        'generated_at',
        'base_currency',
        'operation',
        'status',
        'data',
        'warnings',
        'unsupported_metrics',
        'source_references',
        'reliability',
    ])
        ->and($result['company_id'])->toBe($company->getKey())
        ->and($result['base_currency'])->toBe('SYP')
        ->and($result['operation'])->toBe('company_overview')
        ->and($result['data']['financial_performance']['metrics']['revenue']['value'])->toBe('0.0000')
        ->and($result['data']['cash_and_bank']['value'])->toBeNull()
        ->and($result['data']['receivables_and_payables']['customer_receivables']['operational_balance'])->toBeNull()
        ->and(collect($result['unsupported_metrics'])->pluck('metric')->all())->toContain('cash_and_bank')
        ->and($result['reliability']['status'])->toBe('partial');
});

it('provides scoped financial reports with historical native and base currency values', function () {
    $company = Company::create(['name' => 'Gateway finance company', 'base_currency' => 'SYP']);
    $user = advisorUserForCompany($company);
    $usdBank = advisorAccountForTest($company, '1010', 'asset', 'USD');
    $equity = advisorAccountForTest($company, '3000', 'equity');
    postAdvisorJournal($company, '2026-10-10', [
        ['account' => $usdBank, 'debit' => '10', 'exchange_rate' => '20'],
        ['account' => $equity, 'credit' => '200'],
    ]);
    $this->actingAs($user);

    $gateway = app(AdvisorDataGateway::class);
    $trialBalance = $gateway->trialBalance(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );
    $usdBalance = collect($trialBalance['data']['accounts'])->firstWhere('account_id', $usdBank->getKey());
    $ledger = $gateway->generalLedger(
        $user,
        (int) $company->getKey(),
        (int) $usdBank->getKey(),
        '2026-10-01',
        '2026-10-31',
    );
    $incomeStatement = $gateway->incomeStatement(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );
    $balanceSheet = $gateway->balanceSheet($user, (int) $company->getKey(), '2026-10-31');

    expect($usdBalance['closing']['debit'])->toBe('200.0000')
        ->and($usdBalance['native_balances']['USD']['closing']['debit'])->toBe('10.0000')
        ->and($ledger['data']['transactions'][0]['base_debit'])->toBe('200.0000')
        ->and($ledger['data']['transactions'][0]['debit'])->toBe('10.0000')
        ->and($ledger['data']['transactions'][0]['currency'])->toBe('USD')
        ->and($incomeStatement['reporting_period']['comparison']['to'])->toBe('2026-09-30')
        ->and($balanceSheet['status'])->toBe('available')
        ->and($balanceSheet['data']['equation_difference'])->toBe('0.0000');
});

it('isolates selected companies and rejects a company outside the authenticated user membership', function () {
    $company = Company::create(['name' => 'Selected company', 'base_currency' => 'SYP']);
    $otherCompany = Company::create(['name' => 'Other company', 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $user->companies()->attach([$company->getKey(), $otherCompany->getKey()]);
    $cash = advisorAccountForTest($company, '1000', 'asset');
    $equity = advisorAccountForTest($company, '3000', 'equity');
    $foreignCash = advisorAccountForTest($otherCompany, '1000', 'asset');
    $foreignEquity = advisorAccountForTest($otherCompany, '3000', 'equity');
    postAdvisorJournal($company, '2026-10-10', [
        ['account' => $cash, 'debit' => '10'],
        ['account' => $equity, 'credit' => '10'],
    ]);
    postAdvisorJournal($otherCompany, '2026-10-10', [
        ['account' => $foreignCash, 'debit' => '999'],
        ['account' => $foreignEquity, 'credit' => '999'],
    ]);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->trialBalance(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );

    expect(collect($result['data']['accounts'])->pluck('account_id')->all())
        ->toContain($cash->getKey(), $equity->getKey())
        ->not->toContain($foreignCash->getKey(), $foreignEquity->getKey());

    $unrelatedUser = User::factory()->create();
    Auth::login($unrelatedUser);

    expect(fn () => app(AdvisorDataGateway::class)->trialBalance(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    ))->toThrow(AuthorizationException::class);

    Auth::login($user);
    $notMemberCompany = Company::create(['name' => 'Not a member company']);

    expect(fn () => app(AdvisorDataGateway::class)->trialBalance(
        $user,
        (int) $notMemberCompany->getKey(),
        '2026-10-01',
        '2026-10-31',
    ))->toThrow(ModelNotFoundException::class);
});

it('returns sales and purchasing analysis with returns-capable metrics and unsupported product profitability', function () {
    $company = Company::create(['name' => 'Sales analysis company']);
    $user = advisorUserForCompany($company);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->salesAndPurchasingAnalysis(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );

    expect($result['operation'])->toBe('sales_and_purchasing_analysis')
        ->and($result['data']['sales_total']['value'])->toBe('0.0000')
        ->and($result['data']['net_sales']['value'])->toBe('0.0000')
        ->and($result['data']['purchases_total']['value'])->toBe('0.0000')
        ->and(collect($result['unsupported_metrics'])->pluck('metric')->all())
        ->toContain('product_profitability');
});

it('reports posted sales returns separately and links analysis to source documents', function () {
    $company = Company::create(['name' => 'Returns analysis company', 'base_currency' => 'SYP']);
    $customer = Party::create([
        'company_id' => $company->getKey(),
        'code' => 'C-'.uniqid(),
        'name' => 'Gateway customer',
    ]);
    $receivable = advisorAccountForTest($company, '1100', 'asset');
    $salesAccount = advisorAccountForTest($company, '4000', 'revenue', 'SYP', 'revenue');
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
        'name' => 'Returnable widget',
        'tracks_inventory' => true,
    ]);
    $revenue = Revenue::create([
        'company_id' => $company->getKey(),
        'party_id' => $customer->getKey(),
        'receivable_account_id' => $receivable->getKey(),
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
    postAdvisorJournal($company, '2026-10-10', [
        ['account' => $receivable, 'debit' => '100'],
        ['account' => $salesAccount, 'credit' => '100', 'party_id' => $customer->getKey()],
    ], Revenue::class, (int) $revenue->getKey());
    $customerReturn = CustomerReturn::create([
        'company_id' => $company->getKey(),
        'original_revenue_id' => $revenue->getKey(),
        'party_id' => $customer->getKey(),
        'refund_account_id' => $receivable->getKey(),
        'revenue_account_id' => $salesAccount->getKey(),
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
        'customer_return_id' => $customerReturn->getKey(),
        'revenue_item_id' => $revenueItem->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'quantity' => '0.5',
        'native_amount' => '10',
        'base_amount' => '10',
        'inventory_cost_amount' => '0',
    ]);
    $customerReturn->status = 'posted';
    $customerReturn->posted_at = now();
    $customerReturn->save();
    postAdvisorJournal($company, '2026-10-12', [
        ['account' => $salesAccount, 'debit' => '10', 'party_id' => $customer->getKey()],
        ['account' => $receivable, 'credit' => '10'],
    ], CustomerReturn::class, (int) $customerReturn->getKey());
    $user = advisorUserForCompany($company);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->salesAndPurchasingAnalysis(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );
    $incomeStatement = app(AdvisorDataGateway::class)->incomeStatement(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );

    expect($result['data']['sales_total']['value'])->toBe('100.0000')
        ->and($result['data']['sales_returns']['value'])->toBe('10.0000')
        ->and($result['data']['net_sales']['value'])->toBe('90.0000')
        ->and($result['data']['top_products']['value'][0]['quantity'])->toBe('0.5000')
        ->and($result['data']['top_products']['value'][0]['sales_base_amount'])->toBe('90.0000')
        ->and($result['data']['top_customers']['value'][0]['sales_base_amount'])->toBe('90.0000')
        ->and($incomeStatement['data']['metrics']['revenue']['value'])->toBe('90.0000')
        ->and($incomeStatement['data']['metrics']['revenue']['comparison']['difference'])->toBe('90.0000')
        ->and($incomeStatement['data']['metrics']['revenue']['comparison']['percentage_change'])->toBeNull()
        ->and(collect($result['source_references'])->pluck('id')->all())
        ->toContain($revenue->getKey(), $customerReturn->getKey());
});

it('returns valued inventory and bounded movements without changing records', function () {
    $company = Company::create(['name' => 'Inventory analysis company', 'base_currency' => 'SYP']);
    $user = advisorUserForCompany($company);
    $inventoryAccount = advisorAccountForTest($company, '1200', 'asset');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::INVENTORY_ASSET,
        'financial_account_id' => $inventoryAccount->getKey(),
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
        'name' => 'Gateway widget',
        'tracks_inventory' => true,
    ]);

    foreach ([
        ['quantity' => '2', 'cost' => '40', 'date' => '2026-10-02 09:00:00'],
        ['quantity' => '1', 'cost' => '30', 'date' => '2026-10-03 09:00:00'],
    ] as $movement) {
        StockMovement::create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'movement_type' => 'opening',
            'direction' => 'in',
            'quantity' => $movement['quantity'],
            'base_cost_amount' => $movement['cost'],
            'base_unit_cost' => bcdiv($movement['cost'], $movement['quantity'], 10),
            'occurred_at' => $movement['date'],
        ]);
    }

    $this->actingAs($user);
    $movementCount = StockMovement::count();
    $journalCount = AccountingJournal::count();
    $result = app(AdvisorDataGateway::class)->inventoryAnalysis(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
        (int) $product->getKey(),
        (int) $warehouse->getKey(),
        1,
    );

    expect($result['data']['current_valued_inventory']['value'])->toBe('70.0000')
        ->and($result['data']['product_balances'][0]['average_unit_cost'])->toBe('23.3333333333')
        ->and($result['data']['warehouse_quantities'][0]['quantity'])->toBe('3.0000')
        ->and($result['data']['inventory_movements'])->toHaveCount(1)
        ->and($result['data']['movement_result']['has_more'])->toBeTrue()
        ->and($result['status'])->toBe('partially_available')
        ->and(StockMovement::count())->toBe($movementCount)
        ->and(AccountingJournal::count())->toBe($journalCount);
});

it('reports missing subledger mappings and keeps unsupported balances null', function () {
    $company = Company::create(['name' => 'Unmapped subledger company']);
    $user = advisorUserForCompany($company);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->receivablesAndPayables($user, (int) $company->getKey());

    expect($result['status'])->toBe('unsupported')
        ->and($result['data']['customer_receivables']['operational_balance'])->toBeNull()
        ->and($result['data']['supplier_payables']['general_ledger_balance'])->toBeNull()
        ->and($result['data']['customer_receivables']['missing_mappings'])
        ->toContain('customer_receivables')
        ->and(collect($result['unsupported_metrics'])->pluck('metric')->all())
        ->toContain('unpaid_expenses_separate_total');
});

it('separates accounting classification limitations from business risks in health results', function () {
    $company = Company::create(['name' => 'Business health company']);
    $user = advisorUserForCompany($company);
    $cash = advisorAccountForTest($company, '1000', 'asset');
    $unclassifiedExpense = advisorAccountForTest($company, '5000', 'expense');
    postAdvisorJournal($company, '2026-10-10', [
        ['account' => $unclassifiedExpense, 'debit' => '25'],
        ['account' => $cash, 'credit' => '25'],
    ]);
    $this->actingAs($user);

    $result = app(AdvisorDataGateway::class)->businessHealth(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
    );

    expect($result['data']['unresolved_account_classifications'])->toHaveCount(1)
        ->and($result['data']['data_quality_limitations'])->not->toBe([])
        ->and($result['data']['actual_business_risks'])->toBe([])
        ->and($result['data']['missing_mappings'])->not->toBe([])
        ->and($result['status'])->toBe('partially_available');
});

it('rejects invalid date ranges, foreign filters, and excessive result limits', function () {
    $company = Company::create(['name' => 'Gateway validation company']);
    $otherCompany = Company::create(['name' => 'Foreign filter company']);
    $user = advisorUserForCompany($company);
    $account = advisorAccountForTest($company, '1000', 'asset');
    $foreignAccount = advisorAccountForTest($otherCompany, '1000', 'asset');
    $unit = UnitOfMeasure::create([
        'company_id' => $otherCompany->getKey(),
        'code' => 'EA-'.uniqid(),
        'name' => 'Each',
    ]);
    $foreignProduct = Product::create([
        'company_id' => $otherCompany->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Foreign product',
        'tracks_inventory' => true,
    ]);
    $foreignWarehouse = Warehouse::create([
        'company_id' => $otherCompany->getKey(),
        'code' => 'WH-'.uniqid(),
        'name' => 'Foreign warehouse',
    ]);
    $this->actingAs($user);

    expect(fn () => app(AdvisorDataGateway::class)->trialBalance(
        $user,
        (int) $company->getKey(),
        '2026-02-31',
        '2026-03-01',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AdvisorDataGateway::class)->trialBalance(
        $user,
        (int) $company->getKey(),
        '2025-01-01',
        '2026-01-02',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AdvisorDataGateway::class)->generalLedger(
        $user,
        (int) $company->getKey(),
        (int) $foreignAccount->getKey(),
        '2026-10-01',
        '2026-10-31',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AdvisorDataGateway::class)->generalLedger(
        $user,
        (int) $company->getKey(),
        (int) $account->getKey(),
        '2026-10-01',
        '2026-10-31',
        201,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AdvisorDataGateway::class)->inventoryAnalysis(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
        (int) $foreignProduct->getKey(),
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AdvisorDataGateway::class)->inventoryAnalysis(
        $user,
        (int) $company->getKey(),
        '2026-10-01',
        '2026-10-31',
        null,
        (int) $foreignWarehouse->getKey(),
    ))->toThrow(ValidationException::class);
});
