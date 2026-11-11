<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCutoverService;
use App\Services\InventoryService;
use App\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function inventoryPhaseFourContext(string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'Inventory Adjustments '.$baseCurrency.' '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->getKey());
    Auth::login($user);

    $category = ProductCategory::create([
        'company_id' => $company->getKey(),
        'name' => 'Materials',
    ]);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->getKey(),
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $product = Product::create([
        'company_id' => $company->getKey(),
        'product_category_id' => $category->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'PHASE-4-'.uniqid(),
        'name' => 'Tracked material',
        'tracks_inventory' => true,
    ]);
    $warehouseA = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'A',
        'name' => 'Warehouse A',
    ]);
    $warehouseB = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'B',
        'name' => 'Warehouse B',
    ]);

    $accounts = [];
    foreach ([
        'inventory' => ['asset', $baseCurrency],
        'gain' => ['revenue', $baseCurrency],
        'equity' => ['equity', $baseCurrency],
        'expense' => ['expense', $baseCurrency],
        'expense_usd' => ['expense', 'USD'],
        'inventory_usd' => ['asset', 'USD'],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
        ]);
    }

    return compact('company', 'user', 'category', 'unit', 'product', 'warehouseA', 'warehouseB', 'accounts');
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $overrides
 */
function createPhaseFourAdjustment(array $context, array $overrides = []): InventoryAdjustment
{
    return app(InventoryAdjustmentService::class)->createDraft([
        'product_id' => $context['product']->getKey(),
        'warehouse_id' => $context['warehouseA']->getKey(),
        'adjustment_type' => 'positive',
        'quantity' => '3',
        'base_unit_cost' => '2.3333333333',
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'offset_account_id' => $context['accounts']['gain']->getKey(),
        'reason' => 'Approved inventory correction',
        ...$overrides,
    ]);
}

function postPhaseFourCutover(array $context): void
{
    $service = app(InventoryCutoverService::class);
    $cutover = $service->createDraft([
        'effective_at' => now()->toDateTimeString(),
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'opening_equity_account_id' => $context['accounts']['equity']->getKey(),
    ]);

    foreach ([$context['warehouseA'], $context['warehouseB']] as $warehouse) {
        $service->setOpeningBalance($cutover, $context['product'], $warehouse, '0', '0', 'No opening inventory');
    }

    $service->post($cutover);
}

it('posts an approved positive adjustment with the exact base-currency value and balanced journal', function (): void {
    $context = inventoryPhaseFourContext();
    $service = app(InventoryAdjustmentService::class);
    $draft = createPhaseFourAdjustment($context);

    expect(fn () => $service->post($draft))->toThrow(ValidationException::class);

    $approved = $service->approve($draft);
    $posted = $service->post($approved);
    $journal = $posted->journal;
    $assetLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey());
    $gainLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['gain']->getKey());

    expect($posted->status)->toBe('posted')
        ->and($posted->base_unit_cost)->toBe('2.3333333333')
        ->and($posted->base_cost_amount)->toBe('7.0000')
        ->and($posted->movement->movement_type)->toBe('adjustment_in')
        ->and($posted->movement->base_cost_amount)->toBe('7.0000')
        ->and(app(InventoryCostingService::class)->currentValuation($context['product']))->toBe([
            'quantity' => '3.0000',
            'inventory_value' => '7.0000',
            'average_unit_cost' => '2.3333333333',
        ])
        ->and($journal->status)->toBe('posted')
        ->and($assetLine->debit)->toBe('7.0000')
        ->and($gainLine->credit)->toBe('7.0000')
        ->and($assetLine->currency)->toBe('SYP')
        ->and($assetLine->base_currency)->toBe('SYP')
        ->and(bccomp((string) $assetLine->base_debit, (string) $gainLine->base_credit, 4))->toBe(0);
});

it('values negative adjustments at the company-wide moving weighted average', function (): void {
    $context = inventoryPhaseFourContext();
    $inventory = app(InventoryService::class);
    $inventory->receiveStock($context['product'], $context['warehouseA'], '10', null, ['base_cost_amount' => '100']);
    $inventory->receiveStock($context['product'], $context['warehouseB'], '10', null, ['base_cost_amount' => '300']);

    $adjustment = createPhaseFourAdjustment($context, [
        'adjustment_type' => 'negative',
        'quantity' => '4',
        'base_unit_cost' => null,
        'offset_account_id' => $context['accounts']['expense']->getKey(),
    ]);
    $posted = app(InventoryAdjustmentService::class)->post(app(InventoryAdjustmentService::class)->approve($adjustment));
    $journal = $posted->journal;
    $expenseLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['expense']->getKey());
    $assetLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey());

    expect($posted->base_cost_amount)->toBe('80.0000')
        ->and($posted->movement->base_unit_cost)->toBe('20.0000000000')
        ->and($posted->movement->warehouse_id)->toBe($context['warehouseA']->getKey())
        ->and($expenseLine->debit)->toBe('80.0000')
        ->and($assetLine->credit)->toBe('80.0000')
        ->and(app(InventoryCostingService::class)->currentValuation($context['product']))->toBe([
            'quantity' => '16.0000',
            'inventory_value' => '320.0000',
            'average_unit_cost' => '20.0000000000',
        ]);
});

it('posts damaged and lost stock as write-offs rather than sales', function (string $reasonType): void {
    $context = inventoryPhaseFourContext();
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseA'], '5', null, ['base_cost_amount' => '25']);

    $adjustment = createPhaseFourAdjustment($context, [
        'adjustment_type' => $reasonType,
        'quantity' => '2',
        'base_unit_cost' => null,
        'offset_account_id' => $context['accounts']['expense']->getKey(),
        'reason' => ucfirst($reasonType).' stock identified during inspection',
    ]);
    $posted = app(InventoryAdjustmentService::class)->post(app(InventoryAdjustmentService::class)->approve($adjustment));
    $expenseLine = $posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['expense']->getKey());
    $assetLine = $posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey());

    expect($posted->movement->movement_type)->toBe('adjustment_out')
        ->and($posted->movement->direction)->toBe('out')
        ->and($posted->movement->base_cost_amount)->toBe('10.0000')
        ->and($posted->journal->description)->toContain('Inventory write-off')
        ->and($expenseLine->debit)->toBe('10.0000')
        ->and($assetLine->credit)->toBe('10.0000')
        ->and(StockMovement::query()->whereIn('movement_type', ['sales_return', 'issue'])->count())->toBe(0);
})->with([
    'damaged' => 'damaged',
    'lost' => 'lost',
]);

it('posts adjustments only in SYP or USD company base currency', function (string $currency): void {
    $context = inventoryPhaseFourContext($currency);
    $posted = app(InventoryAdjustmentService::class)->post(
        app(InventoryAdjustmentService::class)->approve(createPhaseFourAdjustment($context)),
    );
    $line = $posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey());

    expect($line->currency)->toBe($currency)
        ->and($line->base_currency)->toBe($currency)
        ->and($line->exchange_rate)->toBe('1.0000000000')
        ->and($posted->journal->lines->pluck('currency')->unique()->all())->toBe([$currency]);
})->with([
    'Syrian pound' => 'SYP',
    'US dollar' => 'USD',
]);

it('rejects positive adjustments without an explicit unit cost', function (): void {
    $context = inventoryPhaseFourContext();

    expect(fn () => createPhaseFourAdjustment($context, ['base_unit_cost' => null]))
        ->toThrow(ValidationException::class);

    expect(InventoryAdjustment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rejects missing and invalid adjustment accounts', function (): void {
    $context = inventoryPhaseFourContext();
    $service = app(InventoryAdjustmentService::class);

    expect(fn () => createPhaseFourAdjustment($context, ['inventory_asset_account_id' => null]))
        ->toThrow(ValidationException::class);

    expect(fn () => createPhaseFourAdjustment($context, ['offset_account_id' => $context['accounts']['expense']->getKey()]))
        ->toThrow(ValidationException::class);

    expect(fn () => createPhaseFourAdjustment($context, ['inventory_asset_account_id' => $context['accounts']['expense']->getKey()]))
        ->toThrow(ValidationException::class);

    expect(fn () => createPhaseFourAdjustment($context, ['inventory_asset_account_id' => $context['accounts']['inventory_usd']->getKey()]))
        ->toThrow(ValidationException::class);

    expect(fn () => createPhaseFourAdjustment($context, [
        'adjustment_type' => 'negative',
        'quantity' => '1',
        'base_unit_cost' => null,
        'offset_account_id' => $context['accounts']['expense_usd']->getKey(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->createDraft([
        'product_id' => $context['product']->getKey(),
        'warehouse_id' => $context['warehouseA']->getKey(),
        'adjustment_type' => 'negative',
        'quantity' => '1',
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'offset_account_id' => $context['accounts']['gain']->getKey(),
        'reason' => 'Invalid expense account type',
    ]))->toThrow(ValidationException::class);

    $otherCompany = Company::create(['name' => 'Foreign account owner', 'base_currency' => 'SYP']);
    $foreignAccount = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany): FinancialAccount {
        return FinancialAccount::create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'FOREIGN-EXPENSE',
            'name' => 'Foreign expense',
            'account_type' => 'expense',
            'currency' => 'SYP',
        ]);
    });

    expect(fn () => createPhaseFourAdjustment($context, ['offset_account_id' => $foreignAccount->getKey()]))
        ->toThrow(ValidationException::class);
});

it('rejects a negative adjustment when the warehouse has insufficient stock', function (): void {
    $context = inventoryPhaseFourContext();
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseA'], '1', null, ['base_cost_amount' => '10']);
    $adjustment = createPhaseFourAdjustment($context, [
        'adjustment_type' => 'negative',
        'quantity' => '2',
        'base_unit_cost' => null,
        'offset_account_id' => $context['accounts']['expense']->getKey(),
    ]);
    $service = app(InventoryAdjustmentService::class);
    $approved = $service->approve($adjustment);

    expect(fn () => $service->post($approved))->toThrow(ValidationException::class);

    expect($approved->fresh()->status)->toBe('approved')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->quantityInWarehouse($context['product'], $context['warehouseA']))->toBe('1.0000');
});

it('rejects duplicate posting without creating duplicate movements or journals', function (): void {
    $context = inventoryPhaseFourContext();
    $service = app(InventoryAdjustmentService::class);
    $posted = $service->post($service->approve(createPhaseFourAdjustment($context)));

    expect(fn () => $service->post($posted))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(1)
        ->and(AccountingJournal::query()->count())->toBe(1)
        ->and($posted->fresh()->status)->toBe('posted');
});

it('rejects backdated adjustments without changing inventory or accounting', function (): void {
    $context = inventoryPhaseFourContext();
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseA'], '2', null, ['base_cost_amount' => '20']);
    $service = app(InventoryAdjustmentService::class);
    $adjustment = createPhaseFourAdjustment($context, [
        'occurred_at' => now()->subDay()->toDateTimeString(),
    ]);
    $approved = $service->approve($adjustment);

    expect(fn () => $service->post($approved))->toThrow(ValidationException::class);

    expect($approved->fresh()->status)->toBe('approved')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($context['product'])['inventory_value'])->toBe('20.0000');
});

it('preserves the cutover boundary and refuses valued adjustments over unvalued legacy history', function (): void {
    $context = inventoryPhaseFourContext();
    postPhaseFourCutover($context);

    expect(fn () => app(InventoryService::class)->adjustStock(
        $context['product'],
        $context['warehouseA'],
        '1',
        'adjustment_in',
    ))->toThrow(ValidationException::class);

    $posted = app(InventoryAdjustmentService::class)->post(
        app(InventoryAdjustmentService::class)->approve(createPhaseFourAdjustment($context)),
    );
    expect($posted->movement->base_cost_amount)->toBe('7.0000');

    $legacy = inventoryPhaseFourContext();
    app(InventoryService::class)->recordOpeningBalance($legacy['product'], $legacy['warehouseA'], '3');

    expect(fn () => createPhaseFourAdjustment($legacy))
        ->toThrow(ValidationException::class);
});

it('keeps supported pre-cutover direct adjustments unvalued instead of inventing cost', function (): void {
    $context = inventoryPhaseFourContext();
    $inventory = app(InventoryService::class);
    $inventory->recordOpeningBalance($context['product'], $context['warehouseA'], '5');
    $movement = $inventory->adjustStock($context['product'], $context['warehouseA'], '1', 'adjustment_in');

    expect($movement->base_cost_amount)->toBeNull()
        ->and($movement->base_unit_cost)->toBeNull()
        ->and($inventory->getProductStock($context['product']))->toBe(6.0);
});

it('isolates adjustments from products in another company', function (): void {
    $context = inventoryPhaseFourContext();
    $other = inventoryPhaseFourContext('USD');
    app(CompanyContextService::class)->switchTo($context['user'], $context['company']->getKey());
    Auth::login($context['user']);

    expect(fn () => createPhaseFourAdjustment($context, ['product_id' => $other['product']->getKey()]))
        ->toThrow(ValidationException::class);

    expect(InventoryAdjustment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rolls back the movement and draft journal if journal posting fails', function (): void {
    $context = inventoryPhaseFourContext();
    $accounting = Mockery::mock(app(AccountingService::class))->makePartial();
    $accounting->shouldReceive('postJournal')->once()->andThrow(new RuntimeException('Simulated posting failure.'));
    app()->instance(AccountingService::class, $accounting);
    $service = app(InventoryAdjustmentService::class);
    $approved = $service->approve(createPhaseFourAdjustment($context));

    expect(fn () => $service->post($approved))->toThrow(RuntimeException::class);

    expect($approved->fresh()->status)->toBe('approved')
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($context['product'])['quantity'])->toBe('0.0000');
});

it('keeps posted adjustments immutable', function (): void {
    $context = inventoryPhaseFourContext();
    $service = app(InventoryAdjustmentService::class);
    $posted = $service->post($service->approve(createPhaseFourAdjustment($context)));

    expect(fn () => $posted->update(['reason' => 'tampered']))
        ->toThrow(ValidationException::class);

    expect(fn () => $posted->delete())
        ->toThrow(ValidationException::class);

    expect($posted->fresh()->reason)->toBe('Approved inventory correction');
});

it('does not allow an adjustment journal to be reversed without a compensating stock adjustment', function (): void {
    $context = inventoryPhaseFourContext();
    $service = app(InventoryAdjustmentService::class);
    $posted = $service->post($service->approve(createPhaseFourAdjustment($context)));

    expect(fn () => app(AccountingService::class)->reverseJournal($posted->journal))
        ->toThrow(ValidationException::class);

    expect(AccountingJournal::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1)
        ->and($posted->fresh()->status)->toBe('posted');
});

it('records and posts a positive physical stock-count variance only after approval', function (): void {
    $context = inventoryPhaseFourContext();
    $counts = app(StockCountService::class);
    $count = $counts->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['expense']->getKey(),
        'description' => 'Quarterly physical stock count',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'counted_quantity' => '3',
            'approved_unit_cost' => '4',
        ]],
    ]);

    expect($count->status)->toBe('draft')
        ->and($count->lines->first()->system_quantity)->toBe('0.0000')
        ->and($count->lines->first()->variance_quantity)->toBe('3.0000')
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(fn () => $counts->post($count))->toThrow(ValidationException::class);

    $posted = $counts->post($counts->approve($count));

    expect($posted->status)->toBe('posted')
        ->and($posted->approver)->not->toBeNull()
        ->and($posted->lines->first()->adjustment->status)->toBe('posted')
        ->and($posted->lines->first()->adjustment->movement->base_cost_amount)->toBe('12.0000')
        ->and(app(InventoryCostingService::class)->quantityInWarehouse($context['product'], $context['warehouseA']))->toBe('3.0000');
});

it('posts a negative physical stock-count variance at company moving average', function (): void {
    $context = inventoryPhaseFourContext();
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseA'], '10', null, ['base_cost_amount' => '100']);
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseB'], '10', null, ['base_cost_amount' => '300']);
    $counts = app(StockCountService::class);
    $count = $counts->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['expense']->getKey(),
        'description' => 'Quarterly physical stock count',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'counted_quantity' => '6',
        ]],
    ]);
    $posted = $counts->post($counts->approve($count));
    $adjustment = $posted->lines->first()->adjustment;

    expect($posted->lines->first()->variance_quantity)->toBe('-4.0000')
        ->and($adjustment->base_cost_amount)->toBe('80.0000')
        ->and($adjustment->journal->lines->firstWhere('financial_account_id', $context['accounts']['expense']->getKey())->debit)->toBe('80.0000')
        ->and(app(InventoryCostingService::class)->quantityInWarehouse($context['product'], $context['warehouseA']))->toBe('6.0000');
});

it('counts and posts variances independently across multiple warehouses', function (): void {
    $context = inventoryPhaseFourContext();
    $inventory = app(InventoryService::class);
    $inventory->receiveStock($context['product'], $context['warehouseA'], '10', null, ['base_cost_amount' => '100']);
    $inventory->receiveStock($context['product'], $context['warehouseB'], '5', null, ['base_cost_amount' => '150']);
    $counts = app(StockCountService::class);
    $count = $counts->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['expense']->getKey(),
        'description' => 'Two-warehouse physical stock count',
        'lines' => [
            [
                'product_id' => $context['product']->getKey(),
                'warehouse_id' => $context['warehouseA']->getKey(),
                'counted_quantity' => '8',
            ],
            [
                'product_id' => $context['product']->getKey(),
                'warehouse_id' => $context['warehouseB']->getKey(),
                'counted_quantity' => '7',
                'approved_unit_cost' => '20',
            ],
        ],
    ]);
    $posted = $counts->post($counts->approve($count));
    $lines = $posted->lines->keyBy('warehouse_id');

    expect($lines[$context['warehouseA']->getKey()]->variance_quantity)->toBe('-2.0000')
        ->and($lines[$context['warehouseB']->getKey()]->variance_quantity)->toBe('2.0000')
        ->and($lines[$context['warehouseA']->getKey()]->adjustment->base_cost_amount)->toBe('33.3333')
        ->and($lines[$context['warehouseB']->getKey()]->adjustment->base_cost_amount)->toBe('40.0000')
        ->and($inventory->getProductStockInWarehouse($context['product'], $context['warehouseA']))->toBe(8.0)
        ->and($inventory->getProductStockInWarehouse($context['product'], $context['warehouseB']))->toBe(7.0)
        ->and(StockMovement::query()->where('movement_type', 'adjustment_out')->count())->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'adjustment_in')->count())->toBe(1);
});

it('rejects a stock count when inventory changes after its snapshot', function (): void {
    $context = inventoryPhaseFourContext();
    $counts = app(StockCountService::class);
    $count = $counts->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['expense']->getKey(),
        'description' => 'Physical count that became stale',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'counted_quantity' => '2',
            'approved_unit_cost' => '4',
        ]],
    ]);
    $approved = $counts->approve($count);
    app(InventoryService::class)->receiveStock($context['product'], $context['warehouseA'], '1', null, ['base_cost_amount' => '4']);

    expect(fn () => $counts->post($approved))->toThrow(ValidationException::class);

    expect($approved->fresh()->status)->toBe('approved')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(StockCountLine::query()->first()->adjustment)->toBeNull();
});

it('rejects duplicate stock-count posting and preserves the posted count', function (): void {
    $context = inventoryPhaseFourContext();
    $counts = app(StockCountService::class);
    $count = $counts->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['expense']->getKey(),
        'description' => 'One-time physical count',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'counted_quantity' => '1',
            'approved_unit_cost' => '4',
        ]],
    ]);
    $posted = $counts->post($counts->approve($count));

    expect(fn () => $counts->post($posted))->toThrow(ValidationException::class);

    expect($posted->fresh()->status)->toBe('posted')
        ->and(InventoryAdjustment::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(AccountingJournal::query()->count())->toBe(1);
});

it('rejects invalid stock-count variance accounts', function (): void {
    $context = inventoryPhaseFourContext();

    expect(fn () => app(StockCountService::class)->createDraft([
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'positive_variance_account_id' => $context['accounts']['gain']->getKey(),
        'negative_variance_account_id' => $context['accounts']['gain']->getKey(),
        'description' => 'Invalid account configuration',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'counted_quantity' => '1',
            'approved_unit_cost' => '4',
        ]],
    ]))->toThrow(ValidationException::class);

    expect(StockCount::query()->count())->toBe(0);
});
