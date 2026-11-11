<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\InventoryCutoverLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCutoverService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Cutover Co', 'base_currency' => 'SYP']);
    $this->user = User::factory()->create();
    $this->company->users()->attach($this->user);
    app(CompanyContextService::class)->switchTo($this->user, $this->company->id);
    Auth::login($this->user);

    $this->category = ProductCategory::create([
        'company_id' => $this->company->id,
        'name' => 'Materials',
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
        'sku' => 'CUTOVER-001',
        'name' => 'Cutover Product',
        'tracks_inventory' => true,
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
    $this->inventoryAsset = FinancialAccount::create([
        'company_id' => $this->company->id,
        'code' => '1400',
        'name' => 'Inventory Asset',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $this->openingEquity = FinancialAccount::create([
        'company_id' => $this->company->id,
        'code' => '3000',
        'name' => 'Opening Balance Equity',
        'account_type' => 'equity',
        'currency' => 'SYP',
    ]);

    $this->inventory = app(InventoryService::class);
    $this->cutoverService = app(InventoryCutoverService::class);
    $this->createCutover = function (array $overrides = []): InventoryCutover {
        $cutover = $this->cutoverService->createDraft([
            'effective_at' => now()->toDateTimeString(),
            'inventory_asset_account_id' => $this->inventoryAsset->getKey(),
            'opening_equity_account_id' => $this->openingEquity->getKey(),
            ...$overrides,
        ]);

        foreach (Product::query()->where('tracks_inventory', true)->get() as $product) {
            foreach (Warehouse::query()->get() as $warehouse) {
                $this->cutoverService->setOpeningBalance(
                    $cutover,
                    $product,
                    $warehouse,
                    '0',
                    '0',
                    'No opening inventory',
                );
            }
        }

        return $cutover;
    };
});

it('posts multi-warehouse opening balances and creates a balanced base-currency journal', function () {
    $secondProduct = Product::create([
        'company_id' => $this->company->id,
        'product_category_id' => $this->category->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'CUTOVER-002',
        'name' => 'Second Cutover Product',
        'tracks_inventory' => true,
    ]);
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '10', '1000', 'Approved physical count');
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseB, '20', '4000', 'Approved physical count');
    $this->cutoverService->setOpeningBalance($cutover, $secondProduct, $this->warehouseA, '2', '40', 'Approved physical count');

    $posted = $this->cutoverService->post($cutover);
    $valuation = app(InventoryCostingService::class)->currentValuation($this->product);
    $journal = $posted->journal;
    $linesByWarehouse = $posted->lines->where('product_id', $this->product->getKey())->keyBy('warehouse_id');
    $journalLinesByAccount = $journal->lines->keyBy('financial_account_id');

    expect($posted->status)->toBe('posted')
        ->and($posted->lines)->toHaveCount(4)
        ->and($linesByWarehouse[$this->warehouseA->getKey()]->opening_value)->toBe('1000.0000')
        ->and($linesByWarehouse[$this->warehouseA->getKey()]->base_unit_cost)->toBe('100.0000000000')
        ->and($linesByWarehouse[$this->warehouseB->getKey()]->opening_value)->toBe('4000.0000')
        ->and($linesByWarehouse[$this->warehouseB->getKey()]->base_unit_cost)->toBe('200.0000000000')
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseA))->toBe(10.0)
        ->and($this->inventory->getProductStockInWarehouse($this->product, $this->warehouseB))->toBe(20.0)
        ->and($valuation)->toBe([
            'quantity' => '30.0000',
            'inventory_value' => '5000.0000',
            'average_unit_cost' => '166.6666666667',
        ])
        ->and($journal->status)->toBe('posted')
        ->and($journal->lines)->toHaveCount(2)
        ->and($journalLinesByAccount[$this->inventoryAsset->getKey()]->base_debit)->toBe('5040.0000')
        ->and($journalLinesByAccount[$this->openingEquity->getKey()]->base_credit)->toBe('5040.0000')
        ->and($this->inventoryAsset->fresh()->baseCurrencyBalance())->toBe('5040.0000000000')
        ->and($this->openingEquity->fresh()->baseCurrencyBalance())->toBe('-5040.0000000000')
        ->and(StockMovement::query()->whereNotNull('inventory_cutover_line_id')->count())->toBe(3)
        ->and(app(InventoryCostingService::class)->currentValuation($secondProduct)['average_unit_cost'])->toBe('20.0000000000');
});

it('excludes legacy quantities at the cutover boundary and includes post-cutover movements', function () {
    $legacy = $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 100, $this->unit);
    $cutover = ($this->createCutover)();

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['allow_unvalued_legacy' => true],
    ))->toThrow(ValidationException::class);

    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '5', '500', 'Approved physical count');
    $this->cutoverService->post($cutover);
    $this->inventory->receiveStock($this->product, $this->warehouseB, 3, $this->unit, ['base_cost_amount' => '600']);
    $issue = $this->inventory->issueStock($this->product, $this->warehouseB, 3, $this->unit, ['value_at_moving_average' => true]);

    expect($legacy->base_cost_amount)->toBeNull()
        ->and($legacy->fresh()->quantity)->toBe('100.0000')
        ->and($this->inventory->getProductStock($this->product))->toBe(5.0)
        ->and($issue->base_cost_amount)->toBe('412.5000')
        ->and(app(InventoryCostingService::class)->currentValuation($this->product))->toBe([
            'quantity' => '5.0000',
            'inventory_value' => '687.5000',
            'average_unit_cost' => '137.5000000000',
        ]);
});

it('rejects post-cutover transactions dated before the cutover boundary', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '20', 'Approved physical count');
    $this->cutoverService->post($cutover);

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        [
            'base_cost_amount' => '10',
            'occurred_at' => $cutover->effective_at->copy()->subSecond(),
        ],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(1);
});

it('keeps zero-quantity zero-value rows without creating stock movements or a zero journal', function () {
    $cutover = ($this->createCutover)();
    $line = $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '0', '0', 'Counted empty warehouse');

    $posted = $this->cutoverService->post($cutover);

    expect($posted->status)->toBe('posted')
        ->and($posted->accounting_journal_id)->toBeNull()
        ->and($posted->lines)->toHaveCount(2)
        ->and($posted->lines->contains(fn (InventoryCutoverLine $openingLine): bool => $openingLine->is($line)))->toBeTrue()
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and(app(InventoryCostingService::class)->currentValuation($this->product)['average_unit_cost'])->toBeNull();
});

it('blocks legacy unvalued stock writes after the cutover even when the opening balance is zero', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '0', '0', 'Counted empty warehouse');
    $this->cutoverService->post($cutover);

    expect(fn () => $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['allow_unvalued_legacy' => true],
    ))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(0);
});

it('rejects inventory accounts that are not active base-currency asset accounts', function () {
    $wrongType = FinancialAccount::create([
        'company_id' => $this->company->id,
        'code' => '4100',
        'name' => 'Revenue',
        'account_type' => 'revenue',
        'currency' => 'SYP',
    ]);
    $wrongCurrency = FinancialAccount::create([
        'company_id' => $this->company->id,
        'code' => '1401',
        'name' => 'USD Inventory',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);

    expect(fn () => ($this->createCutover)(['inventory_asset_account_id' => $wrongType->getKey()]))
        ->toThrow(ValidationException::class)
        ->and(fn () => ($this->createCutover)(['inventory_asset_account_id' => $wrongCurrency->getKey()]))
        ->toThrow(ValidationException::class);
});

it('rejects cutover when the selected inventory asset account already has a posted balance', function () {
    $accounting = app(AccountingService::class);
    $legacyJournal = $accounting->createDraftJournal([
        'description' => 'Pre-cutover inventory balance',
    ]);
    $accounting->addJournalLine($legacyJournal, [
        'financial_account_id' => $this->inventoryAsset->getKey(),
        'debit' => '50',
    ]);
    $accounting->addJournalLine($legacyJournal, [
        'financial_account_id' => $this->openingEquity->getKey(),
        'credit' => '50',
    ]);
    $accounting->postJournal($legacyJournal);

    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '200', 'Approved physical count');

    expect(fn () => $this->cutoverService->post($cutover))->toThrow(ValidationException::class);

    expect(StockMovement::query()->count())->toBe(0)
        ->and($cutover->fresh()->status)->toBe('draft')
        ->and($this->inventoryAsset->fresh()->baseCurrencyBalance())->toBe('50.0000000000');

    $cleanInventoryAsset = FinancialAccount::create([
        'company_id' => $this->company->id,
        'code' => '1401',
        'name' => 'Cutover Inventory Asset',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $updatedCutover = $this->cutoverService->updateDraftAccounts(
        $cutover,
        $cleanInventoryAsset->getKey(),
        $this->openingEquity->getKey(),
    );
    $posted = $this->cutoverService->post($updatedCutover);

    expect($posted->status)->toBe('posted')
        ->and($posted->journal->lines->where('financial_account_id', $cleanInventoryAsset->getKey())->first()->base_debit)
        ->toBe('200.0000')
        ->and($cleanInventoryAsset->fresh()->baseCurrencyBalance())->toBe('200.0000000000');
});

it('rejects invalid opening quantities, values, and zero-cost positive stock', function () {
    $cutover = ($this->createCutover)();

    foreach ([
        ['-1', '100'],
        ['1', '-100'],
        ['1.00001', '100'],
        ['0', '1'],
        ['1', '0'],
    ] as [$quantity, $value]) {
        expect(fn () => $this->cutoverService->setOpeningBalance(
            $cutover,
            $this->product,
            $this->warehouseA,
            $quantity,
            $value,
            'Approved physical count',
        ))->toThrow(ValidationException::class);
    }

    expect(InventoryCutoverLine::query()->count())->toBe(2);
});

it('rejects non-tracked products and cross-company products or warehouses', function () {
    $cutover = ($this->createCutover)();
    $serviceProduct = Product::create([
        'company_id' => $this->company->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'SERVICE-1',
        'name' => 'Service',
        'tracks_inventory' => false,
    ]);
    $otherCompany = Company::create(['name' => 'Other Cutover Co']);
    $otherRecords = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany) {
        $otherUnit = UnitOfMeasure::create([
            'company_id' => $otherCompany->id,
            'code' => 'EA',
            'name' => 'Each',
        ]);

        return [
            Product::create([
                'company_id' => $otherCompany->id,
                'unit_of_measure_id' => $otherUnit->id,
                'sku' => 'OTHER-1',
                'name' => 'Other product',
                'tracks_inventory' => true,
            ]),
            Warehouse::create([
                'company_id' => $otherCompany->id,
                'code' => 'OTHER',
                'name' => 'Other warehouse',
            ]),
        ];
    });

    expect(fn () => $this->cutoverService->setOpeningBalance($cutover, $serviceProduct, $this->warehouseA, '1', '1', 'Approved'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->cutoverService->setOpeningBalance($cutover, $otherRecords[0], $this->warehouseA, '1', '1', 'Approved'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->cutoverService->setOpeningBalance($cutover, $this->product, $otherRecords[1], '1', '1', 'Approved'))
        ->toThrow(ValidationException::class);
});

it('prevents duplicate company cutovers while allowing a draft balance to be replaced without duplication', function () {
    $cutover = ($this->createCutover)();

    expect(fn () => ($this->createCutover)())->toThrow(ValidationException::class);

    $firstLine = $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '20', 'First count');
    $updatedLine = $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '3', '30', 'Recount approved');
    $this->cutoverService->post($cutover);

    expect($updatedLine->is($firstLine))->toBeTrue()
        ->and(InventoryCutoverLine::query()->count())->toBe(2)
        ->and(StockMovement::query()->whereNotNull('inventory_cutover_line_id')->count())->toBe(1)
        ->and($this->inventory->getProductStock($this->product))->toBe(3.0);

    expect(fn () => $this->cutoverService->post($cutover))->toThrow(ValidationException::class)
        ->and(StockMovement::query()->whereNotNull('inventory_cutover_line_id')->count())->toBe(1);
});

it('allows an unposted draft cutover to be cancelled without leaving stock operations paused', function () {
    $cutover = ($this->createCutover)();

    $this->cutoverService->cancelDraft($cutover);
    $movement = $this->inventory->receiveStock(
        $this->product,
        $this->warehouseA,
        1,
        $this->unit,
        ['allow_unvalued_legacy' => true],
    );

    expect(InventoryCutover::query()->count())->toBe(0)
        ->and(InventoryCutoverLine::query()->count())->toBe(0)
        ->and($movement->base_cost_amount)->toBeNull()
        ->and(StockMovement::query()->count())->toBe(1);
});

it('requires a zero-count row for every historical tracked product and warehouse pair', function () {
    $this->inventory->recordOpeningBalance($this->product, $this->warehouseA, 5, $this->unit);
    $cutover = $this->cutoverService->createDraft([
        'effective_at' => now()->toDateTimeString(),
        'inventory_asset_account_id' => $this->inventoryAsset->getKey(),
        'opening_equity_account_id' => $this->openingEquity->getKey(),
    ]);

    expect(fn () => $this->cutoverService->post($cutover))->toThrow(ValidationException::class);

    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '0', '0', 'Verified empty at cutover');
    expect(fn () => $this->cutoverService->post($cutover))->toThrow(ValidationException::class);
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseB, '0', '0', 'Verified empty at cutover');

    expect($this->cutoverService->post($cutover)->status)->toBe('posted')
        ->and($this->inventory->getProductStock($this->product))->toBe(0.0);
});

it('rolls back opening movements and journal records when journal posting fails', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '200', 'Approved physical count');
    $accounting = Mockery::mock(app(AccountingService::class))->makePartial();
    $accounting->shouldReceive('postJournal')->once()->andThrow(new RuntimeException('Journal posting failed.'));
    app()->instance(AccountingService::class, $accounting);

    expect(fn () => app(InventoryCutoverService::class)->post($cutover))
        ->toThrow(RuntimeException::class, 'Journal posting failed.');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and($cutover->fresh()->status)->toBe('draft')
        ->and($cutover->fresh()->accounting_journal_id)->toBeNull();
});

it('keeps the cutover draft when journal creation fails', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '200', 'Approved physical count');
    $accounting = Mockery::mock(app(AccountingService::class))->makePartial();
    $accounting->shouldReceive('createDraftJournal')->once()->andThrow(new RuntimeException('Journal creation failed.'));
    app()->instance(AccountingService::class, $accounting);

    expect(fn () => app(InventoryCutoverService::class)->post($cutover))
        ->toThrow(RuntimeException::class, 'Journal creation failed.');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and($cutover->fresh()->status)->toBe('draft');
});

it('rolls back the draft journal when creating an opening movement fails', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '200', 'Approved physical count');
    StockMovement::creating(function (StockMovement $movement): void {
        if ($movement->inventory_cutover_line_id !== null) {
            throw new RuntimeException('Opening movement creation failed.');
        }
    });

    expect(fn () => $this->cutoverService->post($cutover))
        ->toThrow(RuntimeException::class, 'Opening movement creation failed.');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(AccountingJournal::query()->count())->toBe(0)
        ->and($cutover->fresh()->status)->toBe('draft');
});

it('keeps posted cutovers and their opening lines immutable', function () {
    $cutover = ($this->createCutover)();
    $line = $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '20', 'Approved physical count');
    $posted = $this->cutoverService->post($cutover);

    expect(fn () => $posted->update(['description' => 'tampered']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $line->update(['opening_value' => '99']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $line->delete())
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->cutoverService->setOpeningBalance($posted, $this->product, $this->warehouseA, '4', '40', 'Changed'))
        ->toThrow(ValidationException::class);
});

it('prevents an accounting-only reversal of a posted cutover journal', function () {
    $cutover = ($this->createCutover)();
    $this->cutoverService->setOpeningBalance($cutover, $this->product, $this->warehouseA, '2', '20', 'Approved physical count');
    $posted = $this->cutoverService->post($cutover);

    expect(fn () => app(AccountingService::class)->reverseJournal($posted->journal))
        ->toThrow(ValidationException::class);

    expect($posted->journal->fresh()->status)->toBe('posted')
        ->and($posted->journal->fresh()->reversed_by_journal_id)->toBeNull()
        ->and(StockMovement::query()->whereNotNull('inventory_cutover_line_id')->count())->toBe(1)
        ->and($this->inventory->getProductStock($this->product))->toBe(2.0);
});
