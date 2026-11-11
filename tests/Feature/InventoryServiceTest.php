<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Inventory Co']);
    $this->user = User::factory()->create();
    $this->company->users()->attach($this->user);

    $this->category = ProductCategory::create([
        'company_id' => $this->company->id,
        'name' => 'Construction',
    ]);

    $this->unit = UnitOfMeasure::create([
        'company_id' => $this->company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);

    $this->product = Product::create([
        'company_id' => $this->company->id,
        'product_category_id' => $this->category->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'CEMENT-001',
        'name' => 'Cement',
    ]);

    $this->warehouseA = Warehouse::create([
        'company_id' => $this->company->id,
        'code' => 'A',
        'name' => 'Warehouse A',
    ]);

    $this->warehouseB = Warehouse::create([
        'company_id' => $this->company->id,
        'code' => 'B',
        'name' => 'Warehouse B',
    ]);

    app(CompanyContextService::class)->switchTo($this->user, $this->company->id);
    Auth::login($this->user);

    $this->inventory = app(InventoryService::class);
});

it('records opening balances as stock movements and increases stock', function () {
    $movement = $this->inventory->recordOpeningBalance(
        $this->product,
        $this->warehouseA,
        100,
        $this->unit,
        ['reference' => 'opening-inventory'],
    );

    expect($movement->movement_type)->toBe('opening_balance')
        ->and($movement->direction)->toBe('in')
        ->and($movement->quantity)->toBe('100.0000')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and($this->inventory->getProductStock($this->product))->toBe(100.0)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(100.0);
});

it('receives stock and increases the warehouse quantity', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 50, $this->unit);

    $this->inventory->receiveStock($this->product, $this->warehouseA, 25, $this->unit, ['allow_unvalued_legacy' => true]);

    expect($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(75.0)
        ->and($this->inventory->getTotalProductStock($this->product))->toBe(75.0)
        ->and(StockMovement::query()->where('movement_type', 'receipt')->count())->toBe(1);
});

it('issues stock and decreases the warehouse quantity', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 50, $this->unit);

    $this->inventory->issueStock($this->product, $this->warehouseA, 20, $this->unit, ['allow_unvalued_legacy' => true]);

    expect($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(30.0)
        ->and($this->inventory->getTotalProductStock($this->product))->toBe(30.0);
});

it('records supplier returns as moving-average issues and customer returns as historical-cost receipts', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        '10',
        $this->unit,
        ['base_cost_amount' => '100'],
    );

    $supplierReturn = $this->inventory->returnStock(
        $this->product,
        $this->warehouseA,
        '2',
        'purchase_return',
        $this->unit,
    );
    $customerReturn = $this->inventory->returnStock(
        $this->product,
        $this->warehouseA,
        '1',
        'sales_return',
        $this->unit,
        ['base_cost_amount' => '25'],
    );

    expect($supplierReturn->direction)->toBe('out')
        ->and($supplierReturn->base_cost_amount)->toBe('20.0000')
        ->and($customerReturn->direction)->toBe('in')
        ->and($customerReturn->base_cost_amount)->toBe('25.0000')
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(9.0);
});

it('rejects issuing stock that would make quantity negative', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 10, $this->unit);

    $this->expectException(ValidationException::class);

    $this->inventory->issueStock($this->product, $this->warehouseA, 11, $this->unit, ['allow_unvalued_legacy' => true]);
});

it('transfers stock between warehouses without changing total company quantity', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 100, $this->unit);

    $this->inventory->transferStock($this->product, $this->warehouseA, $this->warehouseB, 20, $this->unit);

    expect($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(80.0)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseB))->toBe(20.0)
        ->and($this->inventory->getTotalProductStock($this->product))->toBe(100.0)
        ->and(StockMovement::query()->where('movement_type', 'transfer')->count())->toBe(2);
});

it('rejects transfer to the same warehouse', function () {
    $this->expectException(ValidationException::class);

    $this->inventory->transferStock($this->product, $this->warehouseA, $this->warehouseA, 10, $this->unit);
});

it('rejects quantities at or below zero', function () {
    $this->expectException(ValidationException::class);

    $this->inventory->receiveStock($this->product, $this->warehouseA, 0, $this->unit, ['allow_unvalued_legacy' => true]);
});

it('rejects quantities that round to zero at stock precision', function () {
    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        0.00001,
        $this->unit,
        ['allow_unvalued_legacy' => true],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(0);
});

it('rejects negative quantities', function () {
    $this->expectException(ValidationException::class);

    $this->inventory->receiveStock($this->product, $this->warehouseA, -1, $this->unit, ['allow_unvalued_legacy' => true]);
});

it('rejects a warehouse from another company', function () {
    $otherCompany = Company::create(['name' => 'Other Co']);
    $otherWarehouse = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany) {
        return Warehouse::create([
            'company_id' => $otherCompany->id,
            'code' => 'C',
            'name' => 'Other Warehouse',
        ]);
    });

    $this->expectException(ValidationException::class);

    $this->inventory->receiveStock($this->product, $otherWarehouse, 5, $this->unit, ['allow_unvalued_legacy' => true]);
});

it('rejects a product from another company', function () {
    $otherCompany = Company::create(['name' => 'Other Co']);
    $otherUnit = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany) {
        return UnitOfMeasure::create([
            'company_id' => $otherCompany->id,
            'code' => 'BOX',
            'name' => 'Box',
        ]);
    });
    $otherProduct = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany, $otherUnit) {
        return Product::create([
            'company_id' => $otherCompany->id,
            'unit_of_measure_id' => $otherUnit->id,
            'sku' => 'OTHER-1',
            'name' => 'Other product',
        ]);
    });

    $this->expectException(ValidationException::class);

    $this->inventory->receiveStock($otherProduct, $this->warehouseA, 5, $this->unit, ['allow_unvalued_legacy' => true]);
});

it('requires a matching unit for the product', function () {
    $otherUnit = UnitOfMeasure::create([
        'company_id' => $this->company->id,
        'code' => 'BOX',
        'name' => 'Box',
    ]);

    $this->expectException(ValidationException::class);

    $this->inventory->receiveStock($this->product, $this->warehouseA, 5, $otherUnit, ['allow_unvalued_legacy' => true]);
});

it('does not leave partial stock movements after a failed transfer', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 10, $this->unit);

    $this->expectException(ValidationException::class);

    $this->inventory->transferStock($this->product, $this->warehouseA, $this->warehouseB, 25, $this->unit);

    expect(StockMovement::query()->where('movement_type', 'transfer')->count())->toBe(0)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(10.0)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseB))->toBe(0.0);
});

it('protects stock balance queries from cross-company leakage', function () {
    $otherCompany = Company::create(['name' => 'Other Co']);
    $otherUser = User::factory()->create();
    $otherCompany->users()->attach($otherUser);
    Auth::login($otherUser);
    app(CompanyContextService::class)->switchTo($otherUser, $otherCompany->id);

    $otherUnit = UnitOfMeasure::create([
        'company_id' => $otherCompany->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $otherProduct = Product::create([
        'company_id' => $otherCompany->id,
        'unit_of_measure_id' => $otherUnit->id,
        'sku' => 'OTHER-2',
        'name' => 'Other product',
    ]);
    $otherWarehouse = Warehouse::create([
        'company_id' => $otherCompany->id,
        'code' => 'X',
        'name' => 'Other Warehouse',
    ]);

    $otherInventory = app(InventoryService::class);
    $otherInventory->recordOpeningBalance($otherProduct, $otherWarehouse, 99, $otherUnit);

    Auth::login($this->user);
    app(CompanyContextService::class)->switchTo($this->user, $this->company->id);

    expect($this->inventory->getTotalProductStock($this->product))->toBe(0.0)
        ->and($this->inventory->getWarehouseStock($this->warehouseA))->toBe(0.0);
});

it('prevents historical stock movements from being edited or deleted', function () {
    $movement = $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 5, $this->unit);

    $this->expectException(ValidationException::class);
    $movement->delete();
});

it('calculates moving weighted average from valued receipts', function () {
    $firstReceipt = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
        ['base_cost_amount' => '1000'],
    );
    $secondReceipt = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseB,
        10,
        $this->unit,
        ['base_cost_amount' => '3000'],
    );

    $valuation = app(InventoryCostingService::class)->currentValuation($this->product);

    expect($firstReceipt->base_cost_amount)->toBe('1000.0000')
        ->and($firstReceipt->base_unit_cost)->toBe('100.0000000000')
        ->and($secondReceipt->base_unit_cost)->toBe('300.0000000000')
        ->and($valuation)->toBe([
            'quantity' => '20.0000',
            'inventory_value' => '4000.0000',
            'average_unit_cost' => '200.0000000000',
        ]);
});

it('values a stock issue at the current company-wide moving average', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
        ['base_cost_amount' => '1000'],
    );
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseB,
        10,
        $this->unit,
        ['base_cost_amount' => '3000'],
    );

    $issue = $this->inventory->issueStock(
        $this->product,
        $this->warehouseB,
        5,
        $this->unit,
        ['value_at_moving_average' => true],
    );

    expect($issue->base_cost_amount)->toBe('1000.0000')
        ->and($issue->base_unit_cost)->toBe('200.0000000000')
        ->and(app(InventoryCostingService::class)->currentValuation($this->product))->toBe([
            'quantity' => '15.0000',
            'inventory_value' => '3000.0000',
            'average_unit_cost' => '200.0000000000',
        ]);
});

it('preserves company-wide inventory value and average during a valued warehouse transfer', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
        ['base_cost_amount' => '1000'],
    );
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseB,
        10,
        $this->unit,
        ['base_cost_amount' => '3000'],
    );

    [$outMovement, $inMovement] = $this->inventory->transferStock(
        $this->product,
        $this->warehouseA,
        $this->warehouseB,
        5,
        $this->unit,
    );

    expect($outMovement->base_cost_amount)->toBe('1000.0000')
        ->and($inMovement->base_cost_amount)->toBe('1000.0000')
        ->and($outMovement->transfer_group)->toBe($inMovement->transfer_group)
        ->and(app(InventoryCostingService::class)->currentValuation($this->product))->toBe([
            'quantity' => '20.0000',
            'inventory_value' => '4000.0000',
            'average_unit_cost' => '200.0000000000',
        ]);
});

it('returns no average cost when inventory quantity is zero', function () {
    $valuation = app(InventoryCostingService::class)->currentValuation($this->product);

    expect($valuation)->toBe([
        'quantity' => '0.0000',
        'inventory_value' => '0.0000',
        'average_unit_cost' => null,
    ]);
});

it('rejects valued movements when their required cost information is missing', function () {
    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        5,
        $this->unit,
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        5,
        $this->unit,
        ['base_cost_amount' => null],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(0);
});

it('does not mix unvalued movements into a valued inventory history', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        5,
        $this->unit,
        ['base_cost_amount' => '50'],
    );

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['allow_unvalued_legacy' => true],
    ))
        ->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(1);
});

it('links a valued movement to its cost source movement', function () {
    $source = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        2,
        $this->unit,
        ['base_cost_amount' => '20'],
    );
    $derived = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        2,
        $this->unit,
        [
            'base_cost_amount' => '20',
            'cost_source_stock_movement_id' => $source->getKey(),
        ],
    );

    expect($derived->cost_source_stock_movement_id)->toBe($source->getKey())
        ->and($derived->costSourceStockMovement->is($source))->toBeTrue();
});

it('does not assign valuation to non-inventory-tracked products', function () {
    $product = Product::create([
        'company_id' => $this->company->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'SERVICE-001',
        'name' => 'Service',
        'tracks_inventory' => false,
    ]);

    $movement = $this->inventory->receiveStock($product, $this->warehouseA, 3, $this->unit, ['allow_unvalued_legacy' => true]);

    expect($movement->base_cost_amount)->toBeNull()
        ->and($movement->base_unit_cost)->toBeNull();

    expect(fn () => $this->inventory->receiveStock(
        $product,
        $this->warehouseA,
        1,
        $this->unit,
        ['base_cost_amount' => '20'],
    ))->toThrow(ValidationException::class);
});

it('does not calculate valuation from historical unvalued stock movements', function () {
    $historicalMovement = $this->inventory->recordOpeningBalance(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
    );

    expect($historicalMovement->base_cost_amount)->toBeNull()
        ->and($historicalMovement->base_unit_cost)->toBeNull();

    expect(fn () => app(InventoryCostingService::class)->currentValuation($this->product))
        ->toThrow(ValidationException::class);
});

it('rejects a valued issue when the available stock history has no recorded valuation', function () {
    $this->inventory->recordOpeningBalance(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
    );
    $movementCount = StockMovement::query()->count();

    expect(fn () => $this->inventory->issueStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['value_at_moving_average' => true],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe($movementCount);
});

it('rejects valued stock operations for products from another company', function () {
    $otherCompany = Company::create(['name' => 'Valuation Isolation Co']);
    $otherUnit = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany) {
        return UnitOfMeasure::create([
            'company_id' => $otherCompany->id,
            'code' => 'EA',
            'name' => 'Each',
        ]);
    });
    $otherProduct = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany, $otherUnit) {
        return Product::create([
            'company_id' => $otherCompany->id,
            'unit_of_measure_id' => $otherUnit->id,
            'sku' => 'OTHER-VALUED',
            'name' => 'Other valued product',
        ]);
    });

    expect(fn () => app(InventoryCostingService::class)->currentValuation($otherProduct))
        ->toThrow(ValidationException::class);
});

it('rejects stock movements dated before the latest movement for the product', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        5,
        $this->unit,
        [
            'base_cost_amount' => '50',
            'occurred_at' => now()->subHour(),
        ],
    );
    $movementCount = StockMovement::query()->count();

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        [
            'base_cost_amount' => '10',
            'occurred_at' => now()->subDay(),
        ],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe($movementCount);
});

it('rounds moving-average issue costs to four decimal places without float money', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        3,
        $this->unit,
        ['base_cost_amount' => '1'],
    );

    $issue = $this->inventory->issueStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['value_at_moving_average' => true],
    );

    expect($issue->base_cost_amount)->toBe('0.3333')
        ->and($issue->base_unit_cost)->toBe('0.3333000000')
        ->and(app(InventoryCostingService::class)->currentValuation($this->product))->toBe([
            'quantity' => '2.0000',
            'inventory_value' => '0.6667',
            'average_unit_cost' => '0.3333500000',
        ]);

    $finalIssue = $this->inventory->issueStock(
        $this->product,
        $this->warehouseA,
        2,
        $this->unit,
        ['value_at_moving_average' => true],
    );

    expect($finalIssue->base_cost_amount)->toBe('0.6667')
        ->and(app(InventoryCostingService::class)->currentValuation($this->product)['average_unit_cost'])->toBeNull();
});

it('rejects a valued issue that exceeds available stock without recording a movement', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        2,
        $this->unit,
        ['base_cost_amount' => '20'],
    );
    $movementCount = StockMovement::query()->count();

    expect(fn () => $this->inventory->issueStock(
        $this->product,
        $this->warehouseA,
        3,
        $this->unit,
        ['value_at_moving_average' => true],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe($movementCount)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(2.0);
});

it('rolls back both movements when a transfer fails after its outbound movement', function () {
    $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        10,
        $this->unit,
        ['base_cost_amount' => '100'],
    );
    $shouldFail = true;

    StockMovement::creating(function (StockMovement $movement) use (&$shouldFail): void {
        if ($shouldFail && $movement->movement_type === 'transfer' && $movement->direction === 'in') {
            throw ValidationException::withMessages(['stock_movement' => 'Simulated destination movement failure.']);
        }
    });

    try {
        expect(fn () => $this->inventory->transferStock(
            $this->product,
            $this->warehouseA,
            $this->warehouseB,
            2,
            $this->unit,
        ))->toThrow(ValidationException::class);
    } finally {
        $shouldFail = false;
    }

    expect(StockMovement::query()->where('movement_type', 'transfer')->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($this->product))->toBe([
            'quantity' => '10.0000',
            'inventory_value' => '100.0000',
            'average_unit_cost' => '10.0000000000',
        ]);
});

it('keeps valuation fields immutable after a stock movement is created', function () {
    $movement = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        2,
        $this->unit,
        ['base_cost_amount' => '20'],
    );
    $movement->base_cost_amount = '999';

    expect(fn () => $movement->save())->toThrow(ValidationException::class);
});

it('keeps stock movements auditable with a user reference and notes', function () {
    $movement = $this->inventory->recordOpeningBalance(
        $this->product,
        $this->warehouseA,
        8,
        $this->unit,
        [
            'reference' => '2019-01-01',
            'notes' => 'Initial stock count',
        ],
    );

    expect($movement->creator->is($this->user))->toBeTrue()
        ->and($movement->reference)->toBe('2019-01-01')
        ->and($movement->notes)->toBe('Initial stock count');
});

it('supports concurrent issue attempts without overselling when practical', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 10, $this->unit);

    $this->expectException(ValidationException::class);

    $this->inventory->issueStock($this->product, $this->warehouseA, 11, $this->unit, ['allow_unvalued_legacy' => true]);
    $this->inventory->issueStock($this->product, $this->warehouseA, 1, $this->unit, ['allow_unvalued_legacy' => true]);
});
