<?php

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCutoverService;
use App\Services\InventoryService;
use App\Services\RevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function revenueValuationContext(string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'Revenue Valuation '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->getKey());
    Auth::login($user);

    $category = ProductCategory::create([
        'company_id' => $company->getKey(),
        'name' => 'Products',
    ]);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->getKey(),
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $warehouseA = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'WH-A',
        'name' => 'Warehouse A',
    ]);
    $warehouseB = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'WH-B',
        'name' => 'Warehouse B',
    ]);
    $party = Party::create([
        'company_id' => $company->getKey(),
        'name' => 'Customer',
    ]);

    $accounts = [];
    foreach ([
        'receivable_syp' => ['asset', 'SYP'],
        'cash_syp' => ['asset', 'SYP'],
        'revenue_syp' => ['revenue', 'SYP'],
        'receivable_usd' => ['asset', 'USD'],
        'cash_usd' => ['asset', 'USD'],
        'revenue_usd' => ['revenue', 'USD'],
        'inventory' => ['asset', $baseCurrency],
        'cogs' => ['expense', $baseCurrency],
        'equity' => ['equity', $baseCurrency],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
        ]);
    }

    return compact('company', 'user', 'category', 'unit', 'warehouseA', 'warehouseB', 'party', 'accounts');
}

function revenueValuationProduct(array $context, string $sku, bool $tracksInventory = true): Product
{
    return Product::create([
        'company_id' => $context['company']->getKey(),
        'product_category_id' => $context['category']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'sku' => $sku,
        'name' => $sku,
        'tracks_inventory' => $tracksInventory,
    ]);
}

function createRevenueForValuation(array $context, array $lines, array $attributes = []): Revenue
{
    $currency = strtoupper((string) ($attributes['currency'] ?? $context['company']->base_currency));
    $service = app(RevenueService::class);
    $revenue = $service->createDraftRevenue([
        'party_id' => $context['party']->getKey(),
        'revenue_date' => $attributes['revenue_date'] ?? now()->toDateString(),
        'currency' => $currency,
        'exchange_rate' => $attributes['exchange_rate'] ?? ($currency === $context['company']->base_currency ? '1' : null),
        'base_currency' => $attributes['base_currency'] ?? $context['company']->base_currency,
    ]);

    foreach ($lines as $line) {
        $service->addRevenueItem($revenue, [
            'unit_of_measure_id' => $context['unit']->getKey(),
            ...$line,
        ]);
    }

    return $revenue->fresh(['items']);
}

function revenueValuationOptions(array $context, string $currency = 'SYP', bool $cashSale = false): array
{
    $currencyKey = strtolower($currency);

    return [
        'warehouse_id' => $context['warehouseA']->getKey(),
        'cash_account_id' => $cashSale ? $context['accounts']['cash_'.$currencyKey]->getKey() : null,
        'receivable_account_id' => $cashSale ? null : $context['accounts']['receivable_'.$currencyKey]->getKey(),
        'revenue_account_id' => $context['accounts']['revenue_'.$currencyKey]->getKey(),
        'inventory_account_id' => $context['accounts']['inventory']->getKey(),
        'cogs_account_id' => $context['accounts']['cogs']->getKey(),
    ];
}

function receiveValuedRevenueStock(
    array $context,
    Product $product,
    Warehouse $warehouse,
    string $quantity,
    string $baseCost,
): StockMovement {
    return app(InventoryService::class)->receiveStock(
        $product,
        $warehouse,
        $quantity,
        $context['unit'],
        ['base_cost_amount' => $baseCost],
    );
}

function postRevenueCutover(array $context, Product $product, string $quantity, string $openingValue): InventoryCutover
{
    $service = app(InventoryCutoverService::class);
    $cutover = $service->createDraft([
        'effective_at' => now()->subDay()->toDateTimeString(),
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'opening_equity_account_id' => $context['accounts']['equity']->getKey(),
    ]);
    $service->setOpeningBalance($cutover, $product, $context['warehouseA'], $quantity, $openingValue, 'Approved opening valuation');
    $service->setOpeningBalance($cutover, $product, $context['warehouseB'], '0', '0', 'No opening stock');

    return $service->post($cutover);
}

it('posts a cash sale with valued inventory COGS in company base currency', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'CASH-VALUED');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '10', '1000');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '150',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context, cashSale: true));
    $movement = $posted->stockMovements()->firstOrFail();
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->status)->toBe('posted')
        ->and($movement->base_unit_cost)->toBe('100.0000000000')
        ->and($movement->base_cost_amount)->toBe('200.0000')
        ->and($journal->lines)->toHaveCount(4)
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['cash_syp']->getKey())->debit)->toBe('300.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['revenue_syp']->getKey())->credit)->toBe('300.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey())->debit)->toBe('200.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->credit)->toBe('200.0000');
});

it('posts a credit sale with the original receivable and revenue accounting entries', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'CREDIT-VALUED');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '4', '80');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '250',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($journal->lines->firstWhere('financial_account_id', $context['accounts']['receivable_syp']->getKey())->debit)->toBe('250.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['revenue_syp']->getKey())->credit)->toBe('250.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey())->currency)->toBe('SYP')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->credit)->toBe('20.0000');
});

it('does not create inventory movements or COGS for non-tracked sales', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'NON-TRACKED', false);
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '3',
        'unit_price' => '10',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->stockMovements)->toBeEmpty()
        ->and($journal->lines)->toHaveCount(2)
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['revenue_syp']->getKey())->credit)->toBe('30.0000');
});

it('uses moving weighted average immediately before a sale issue', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'WAC-ISSUE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '10', '1000');
    receiveValuedRevenueStock($context, $product, $context['warehouseB'], '20', '2000');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '5',
        'unit_price' => '100',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));
    $movement = $posted->stockMovements()->firstOrFail();

    expect($movement->base_cost_amount)->toBe('500.0000')
        ->and(app(InventoryCostingService::class)->currentValuation($product))->toBe([
            'quantity' => '25.0000',
            'inventory_value' => '2500.0000',
            'average_unit_cost' => '100.0000000000',
        ]);
});

it('issues multiple tracked products and values each from its moving average', function (): void {
    $context = revenueValuationContext();
    $first = revenueValuationProduct($context, 'MULTI-SALE-A');
    $second = revenueValuationProduct($context, 'MULTI-SALE-B');
    receiveValuedRevenueStock($context, $first, $context['warehouseA'], '2', '20');
    receiveValuedRevenueStock($context, $second, $context['warehouseA'], '3', '90');
    $revenue = createRevenueForValuation($context, [
        ['product_id' => $first->getKey(), 'quantity' => '1', 'unit_price' => '50'],
        ['product_id' => $second->getKey(), 'quantity' => '2', 'unit_price' => '60'],
    ]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));

    expect($posted->stockMovements)->toHaveCount(2)
        ->and($posted->stockMovements->firstWhere('product_id', $first->getKey())->base_cost_amount)->toBe('10.0000')
        ->and($posted->stockMovements->firstWhere('product_id', $second->getKey())->base_cost_amount)->toBe('60.0000')
        ->and($posted->accountingJournals()->firstOrFail()->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey())->debit)->toBe('70.0000');
});

it('uses line warehouses and company-wide product valuation for multiple-warehouse sales', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'MULTI-WAREHOUSE-SALE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '10', '1000');
    receiveValuedRevenueStock($context, $product, $context['warehouseB'], '20', '2000');
    $revenue = createRevenueForValuation($context, [
        [
            'product_id' => $product->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'quantity' => '2',
            'unit_price' => '200',
        ],
        [
            'product_id' => $product->getKey(),
            'warehouse_id' => $context['warehouseB']->getKey(),
            'quantity' => '3',
            'unit_price' => '200',
        ],
    ]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));

    expect($posted->stockMovements->where('warehouse_id', $context['warehouseA']->getKey())->first()->base_cost_amount)->toBe('200.0000')
        ->and($posted->stockMovements->where('warehouse_id', $context['warehouseB']->getKey())->first()->base_cost_amount)->toBe('300.0000')
        ->and(app(InventoryCostingService::class)->currentValuation($product)['inventory_value'])->toBe('2500.0000');
});

it('keeps USD revenue lines in USD and posts inventory COGS in SYP', function (): void {
    $context = revenueValuationContext('SYP');
    $product = revenueValuationProduct($context, 'USD-SALE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '5', '500');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '3',
    ]], [
        'currency' => 'USD',
        'exchange_rate' => '13000',
    ]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context, 'USD'));
    $journal = $posted->accountingJournals()->firstOrFail();
    $receivableLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['receivable_usd']->getKey());
    $cogsLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey());

    expect($receivableLine->currency)->toBe('USD')
        ->and($receivableLine->exchange_rate)->toBe('13000.0000000000')
        ->and($receivableLine->base_debit)->toBe('78000.0000')
        ->and($cogsLine->currency)->toBe('SYP')
        ->and($cogsLine->debit)->toBe('200.0000')
        ->and($cogsLine->exchange_rate)->toBe('1.0000000000');
});

it('rejects insufficient stock without posting the revenue, journal, or issue', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'INSUFFICIENT-SALE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '1', '10');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '10',
    ]]);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context)))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($product)['quantity'])->toBe('1.0000');
});

it('prevents duplicate revenue posting and duplicate COGS movements', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'DUPLICATE-SALE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '4', '40');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '20',
    ]]);
    $service = app(RevenueService::class);
    $posted = $service->postRevenue($revenue, revenueValuationOptions($context));
    $movementCount = $posted->stockMovements()->count();

    expect(fn () => $service->postRevenue($posted, revenueValuationOptions($context)))
        ->toThrow(ValidationException::class);

    expect($posted->stockMovements()->count())->toBe($movementCount)
        ->and($posted->accountingJournals()->count())->toBe(1);
});

it('rejects a revenue date earlier than the latest stock movement', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'BACKDATED-SALE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '4', '40');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '20',
    ]], ['revenue_date' => now()->subDays(2)->toDateString()]);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context)))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0);
});

it('posts valued issues after cutover and rejects sales dated before its boundary', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'CUTOVER-SALE');
    postRevenueCutover($context, $product, '10', '500');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '100',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));
    $movement = $posted->stockMovements()->firstOrFail();

    expect($movement->base_cost_amount)->toBe('100.0000')
        ->and($posted->accountingJournals()->firstOrFail()->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey())->debit)->toBe('100.0000');

    $backdated = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]], ['revenue_date' => now()->subDays(2)->toDateString()]);

    expect(fn () => app(RevenueService::class)->postRevenue($backdated, revenueValuationOptions($context)))
        ->toThrow(ValidationException::class);
});

it('preserves unvalued legacy inventory history without fabricating COGS', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'LEGACY-SALE');
    app(InventoryService::class)->recordOpeningBalance(
        $product,
        $context['warehouseA'],
        5,
        $context['unit'],
    );
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '100',
    ]]);

    $posted = app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context));
    $issue = $posted->stockMovements()->where('direction', 'out')->firstOrFail();
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($issue->base_cost_amount)->toBeNull()
        ->and($journal->lines)->toHaveCount(2)
        ->and($journal->lines->whereIn('financial_account_id', [
            $context['accounts']['inventory']->getKey(),
            $context['accounts']['cogs']->getKey(),
        ]))->toBeEmpty();
});

it('requires explicitly configured inventory and COGS accounts for valued issues', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'MISSING-COGS-ACCOUNTS');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '2', '20');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    $options = revenueValuationOptions($context);
    unset($options['cogs_account_id']);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, $options))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0);
});

it('rejects inventory and COGS accounts with an invalid type or currency', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'INVALID-VALUATION-ACCOUNT');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '2', '20');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    $wrongCurrency = FinancialAccount::create([
        'company_id' => $context['company']->getKey(),
        'code' => 'INV-USD-'.uniqid(),
        'name' => 'Wrong Currency Inventory',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $options = revenueValuationOptions($context);
    $options['inventory_account_id'] = $wrongCurrency->getKey();

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, $options))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0);
});

it('rejects a COGS account that is not an expense account', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'INVALID-COGS-TYPE');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '2', '20');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    $options = revenueValuationOptions($context);
    $options['cogs_account_id'] = $context['accounts']['inventory']->getKey();

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, $options))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0);
});

it('rejects valuation accounts owned by another company', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'CROSS-COMPANY-COGS');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '2', '20');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    $otherCompany = Company::create([
        'name' => 'Other Revenue Company '.uniqid(),
        'base_currency' => 'SYP',
    ]);
    $otherCogs = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany): FinancialAccount {
        return FinancialAccount::create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'COGS',
            'name' => 'Other Company COGS',
            'account_type' => 'expense',
            'currency' => 'SYP',
        ]);
    });
    $options = revenueValuationOptions($context);
    $options['cogs_account_id'] = $otherCogs->getKey();

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, $options))
        ->toThrow(ValidationException::class);
});

it('rolls back stock issue and journal when accounting posting fails', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'ROLLBACK-COGS-JOURNAL');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '5', '100');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '2',
        'unit_price' => '50',
    ]]);
    $initialValuation = app(InventoryCostingService::class)->currentValuation($product);
    $accounting = Mockery::mock(app(AccountingService::class))->makePartial();
    $accounting->shouldReceive('postJournal')
        ->once()
        ->andThrow(ValidationException::withMessages(['journal' => 'Simulated posting failure.']));
    app()->instance(AccountingService::class, $accounting);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, revenueValuationOptions($context)))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($product))->toBe($initialValuation);
});

it('rejects a revenue account whose currency differs from the transaction currency', function (): void {
    $context = revenueValuationContext();
    $product = revenueValuationProduct($context, 'REVENUE-CURRENCY-MISMATCH');
    receiveValuedRevenueStock($context, $product, $context['warehouseA'], '2', '20');
    $revenue = createRevenueForValuation($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    $options = revenueValuationOptions($context);
    $options['revenue_account_id'] = $context['accounts']['revenue_usd']->getKey();

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, $options))
        ->toThrow(ValidationException::class);

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0);
});
