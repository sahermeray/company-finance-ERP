<?php

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\InventoryService;
use App\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * @return array{
 *   company: Company,
 *   user: User,
 *   category: ProductCategory,
 *   unit: UnitOfMeasure,
 *   product: Product,
 *   warehouse: Warehouse,
 *   asset: FinancialAccount,
 *   gain: FinancialAccount,
 *   expense: FinancialAccount
 * }
 */
function inventoryUiSetUp(): array
{
    $company = Company::query()->create(['name' => 'Inventory UI Shop', 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $category = ProductCategory::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Materials',
        'code' => 'MAT',
    ]);
    $unit = UnitOfMeasure::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'EA',
        'name' => 'Each',
        'symbol' => 'ea',
        'decimal_places' => 2,
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'product_category_id' => $category->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'SHOP-001',
        'name' => 'Cement',
        'tracks_inventory' => true,
        'is_active' => true,
    ]);
    $warehouse = Warehouse::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'MAIN',
        'name' => 'Main warehouse',
        'is_active' => true,
    ]);

    $asset = inventoryUiAccount($company, 'INV', 'asset');
    $gain = inventoryUiAccount($company, 'GAIN', 'revenue');
    $expense = inventoryUiAccount($company, 'LOSS', 'expense');

    return compact('company', 'user', 'category', 'unit', 'product', 'warehouse', 'asset', 'gain', 'expense');
}

function inventoryUiAccount(Company $company, string $code, string $type): FinancialAccount
{
    return FinancialAccount::query()->create([
        'company_id' => $company->getKey(),
        'code' => $code,
        'name' => $code.' account',
        'account_type' => $type,
        'currency' => $company->base_currency,
        'is_active' => true,
    ]);
}

function inventoryUiRequestAs(User $user, Company $company): void
{
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());
}

it('provides company-scoped product search and filtering and creates and edits products', function (): void {
    $context = inventoryUiSetUp();

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('inventory.products.index', ['search' => 'SHOP-001', 'tracking' => 'tracked']))
        ->assertOk()
        ->assertSee('Cement')
        ->assertDontSee('Another tenant item');

    $otherCompany = Company::query()->create(['name' => 'Another Shop', 'base_currency' => 'SYP']);
    $otherCompany->users()->attach($context['user']);
    app(CompanyContextService::class)->switchTo($context['user'], (int) $otherCompany->getKey());
    $otherUnit = UnitOfMeasure::query()->create([
        'company_id' => $otherCompany->getKey(),
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $otherProduct = Product::query()->create([
        'company_id' => $otherCompany->getKey(),
        'unit_of_measure_id' => $otherUnit->getKey(),
        'sku' => 'SHOP-001',
        'name' => 'Another tenant item',
    ]);
    inventoryUiRequestAs($context['user'], $context['company']);

    $this->post(route('inventory.products.store'), [
        'name' => 'Retail bag',
        'sku' => 'SHOP-002',
        'product_category_id' => $context['category']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'tracks_inventory' => '0',
        'is_active' => '1',
    ])->assertRedirect(route('inventory.products.index'));

    $retail = Product::query()->where('sku', 'SHOP-002')->firstOrFail();
    expect($retail->tracks_inventory)->toBeFalse()
        ->and($retail->company_id)->toBe($context['company']->getKey());

    $this->get(route('inventory.products.index', ['tracking' => 'untracked']))
        ->assertOk()
        ->assertSee('Retail bag')
        ->assertDontSee('Cement');
    $this->get(route('inventory.products.show', $retail))
        ->assertOk()
        ->assertSee('does not track inventory');
    $this->get(route('inventory.products.show', $context['product']))
        ->assertOk()
        ->assertSee('No stock');

    $this->put(route('inventory.products.update', $retail), [
        'name' => 'Retail bag updated',
        'sku' => 'SHOP-003',
        'product_category_id' => $context['category']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'description' => 'A non-tracked sale item',
        'tracks_inventory' => '0',
        'is_active' => '1',
    ])->assertRedirect(route('inventory.products.show', $retail));

    expect($retail->fresh()->name)->toBe('Retail bag updated')
        ->and(Product::query()->whereKey($otherProduct->getKey())->exists())->toBeFalse();

    $this->from(route('inventory.products.create'))
        ->post(route('inventory.products.store'), [
            'name' => 'Duplicate code item',
            'sku' => 'SHOP-001',
            'unit_of_measure_id' => $context['unit']->getKey(),
            'tracks_inventory' => '1',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('sku');
});

it('manages company categories and units with scoped unique codes', function (): void {
    $context = inventoryUiSetUp();

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->post(route('inventory.catalog.categories.store'), ['name' => 'Beverages', 'code' => 'BEV'])
        ->assertRedirect();

    $category = ProductCategory::query()->where('code', 'BEV')->firstOrFail();
    $this->put(route('inventory.catalog.categories.update', $category), ['name' => 'Cold drinks', 'code' => 'COLD'])
        ->assertRedirect();
    $this->post(route('inventory.catalog.units.store'), [
        'code' => 'KG',
        'name' => 'Kilogram',
        'symbol' => 'kg',
        'decimal_places' => '3',
    ])->assertRedirect();

    $unit = UnitOfMeasure::query()->where('code', 'KG')->firstOrFail();
    $this->put(route('inventory.catalog.units.update', $unit), [
        'code' => 'KGM',
        'name' => 'Kilogram',
        'symbol' => 'kg',
        'decimal_places' => '3',
    ])->assertRedirect();

    expect($category->fresh()->name)->toBe('Cold drinks')
        ->and($unit->fresh()->code)->toBe('KGM');

    $this->from(route('inventory.catalog.index'))
        ->post(route('inventory.catalog.units.store'), [
            'code' => 'EA',
            'name' => 'Duplicate unit',
            'decimal_places' => '2',
        ])
        ->assertSessionHasErrors('code');
});

it('renders each inventory management, count, adjustment, and detail screen', function (): void {
    $context = inventoryUiSetUp();
    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en']);

    $count = app(StockCountService::class)->createDraft([
        'inventory_asset_account_id' => $context['asset']->getKey(),
        'positive_variance_account_id' => $context['gain']->getKey(),
        'negative_variance_account_id' => $context['expense']->getKey(),
        'counted_at' => now()->toDateTimeString(),
        'description' => 'Screen rendering count',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'counted_quantity' => '0',
        ]],
    ]);
    $adjustment = app(InventoryAdjustmentService::class)->createDraft([
        'product_id' => $context['product']->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'adjustment_type' => 'positive',
        'quantity' => '1',
        'base_unit_cost' => '2',
        'inventory_asset_account_id' => $context['asset']->getKey(),
        'offset_account_id' => $context['gain']->getKey(),
        'reason' => 'Screen rendering adjustment',
    ]);

    foreach ([
        route('inventory.overview'),
        route('inventory.products.index'),
        route('inventory.products.create'),
        route('inventory.products.edit', $context['product']),
        route('inventory.products.show', $context['product']),
        route('inventory.catalog.index'),
        route('inventory.warehouses.index'),
        route('inventory.warehouses.create'),
        route('inventory.warehouses.edit', $context['warehouse']),
        route('inventory.warehouses.show', $context['warehouse']),
        route('inventory.counts.index'),
        route('inventory.counts.create'),
        route('inventory.counts.show', $count),
        route('inventory.adjustments.index'),
        route('inventory.adjustments.create'),
        route('inventory.adjustments.show', $adjustment),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

it('manages warehouses and displays actual stock summaries without leaking other companies', function (): void {
    $context = inventoryUiSetUp();
    app(InventoryService::class)->receiveStock(
        $context['product'],
        $context['warehouse'],
        '7',
        $context['unit'],
        ['base_cost_amount' => '21'],
    );

    $otherCompany = Company::query()->create(['name' => 'Second shop', 'base_currency' => 'SYP']);
    $otherCompany->users()->attach($context['user']);
    app(CompanyContextService::class)->switchTo($context['user'], (int) $otherCompany->getKey());
    $otherWarehouse = Warehouse::query()->create([
        'company_id' => $otherCompany->getKey(),
        'code' => 'PRIVATE',
        'name' => 'Private warehouse',
        'is_active' => true,
    ]);
    inventoryUiRequestAs($context['user'], $context['company']);

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('inventory.warehouses.index'))
        ->assertOk()
        ->assertSee('Main warehouse')
        ->assertDontSee('Private warehouse');

    $this->post(route('inventory.warehouses.store'), [
        'name' => 'Back room',
        'code' => 'BACK',
        'address' => 'Second floor',
        'is_active' => '1',
    ])->assertRedirect(route('inventory.warehouses.index'));
    $backRoom = Warehouse::query()->where('code', 'BACK')->firstOrFail();

    $this->put(route('inventory.warehouses.update', $backRoom), [
        'name' => 'Back room updated',
        'code' => 'BACK',
        'address' => 'Ground floor',
        'is_active' => '1',
    ])->assertRedirect(route('inventory.warehouses.show', $backRoom));

    $this->get(route('inventory.warehouses.show', $context['warehouse']))
        ->assertOk()
        ->assertSee('Cement')
        ->assertSee('7.0000')
        ->assertDontSee('Private warehouse');

    expect($backRoom->fresh()->address)->toBe('Ground floor')
        ->and(Warehouse::query()->whereKey($otherWarehouse->getKey())->exists())->toBeFalse();
});

it('shows tracked inventory quantities, company-wide moving average valuation, and movement sources', function (): void {
    $context = inventoryUiSetUp();
    $secondWarehouse = Warehouse::query()->create([
        'company_id' => $context['company']->getKey(),
        'code' => 'SECOND',
        'name' => 'Second warehouse',
        'is_active' => true,
    ]);
    $inventory = app(InventoryService::class);
    $inventory->receiveStock($context['product'], $context['warehouse'], '10', $context['unit'], ['base_cost_amount' => '100']);
    $inventory->receiveStock($context['product'], $secondWarehouse, '10', $context['unit'], ['base_cost_amount' => '300']);

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('inventory.overview'))
        ->assertOk()
        ->assertSee('20.0000000000')
        ->assertSee('400.0000')
        ->assertSee('10.0000');

    $this->get(route('inventory.products.show', $context['product']))
        ->assertOk()
        ->assertSee('Stock movement history')
        ->assertSee('Stock receipt')
        ->assertSee('Main warehouse')
        ->assertSee('Second warehouse')
        ->assertSee('20.0000000000')
        ->assertSee('400.0000');

    expect(app(InventoryCostingService::class)->currentValuation($context['product']))->toBe([
        'quantity' => '20.0000',
        'inventory_value' => '400.0000',
        'average_unit_cost' => '20.0000000000',
    ]);
});

it('shows quantities but does not invent a value for unvalued historical movements', function (): void {
    $context = inventoryUiSetUp();
    app(InventoryService::class)->receiveStock(
        $context['product'],
        $context['warehouse'],
        '5',
        $context['unit'],
        ['allow_unvalued_legacy' => true],
    );

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('inventory.overview'))
        ->assertOk()
        ->assertSee('5.0000')
        ->assertSee('Valuation is unavailable')
        ->assertDontSee('0.0000');

    $this->get(route('inventory.products.show', $context['product']))
        ->assertOk()
        ->assertSee('5.0000')
        ->assertSee('Valuation is unavailable');
});

it('creates, approves, and posts a stock count through the existing stock count service', function (): void {
    $context = inventoryUiSetUp();
    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en']);

    $this->post(route('inventory.counts.store'), [
        'inventory_asset_account_id' => $context['asset']->getKey(),
        'positive_variance_account_id' => $context['gain']->getKey(),
        'negative_variance_account_id' => $context['expense']->getKey(),
        'counted_at' => now()->format('Y-m-d\TH:i'),
        'description' => 'Monthly physical count',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'counted_quantity' => '5',
            'approved_unit_cost' => '3',
        ]],
    ])->assertSessionHasNoErrors();

    $count = StockCount::query()->latest('id')->firstOrFail();
    expect($count->status)->toBe('draft')
        ->and($count->lines()->first()->system_quantity)->toBe('0.0000')
        ->and($count->lines()->first()->variance_quantity)->toBe('5.0000');

    $this->post(route('inventory.counts.approve', $count))
        ->assertRedirect()
        ->assertSessionHas('status');
    $this->post(route('inventory.counts.post', $count))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($count->fresh()->status)->toBe('posted')
        ->and(StockMovement::query()->where('product_id', $context['product']->getKey())->count())->toBe(1)
        ->and(app(InventoryCostingService::class)->currentValuation($context['product'])['inventory_value'])->toBe('15.0000');
});

it('creates and posts positive and damaged inventory adjustments through the adjustment service', function (): void {
    $context = inventoryUiSetUp();
    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en']);

    $positive = [
        'product_id' => $context['product']->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'adjustment_type' => 'positive',
        'quantity' => '4',
        'base_unit_cost' => '2.5',
        'inventory_asset_account_id' => $context['asset']->getKey(),
        'offset_account_id' => $context['gain']->getKey(),
        'occurred_at' => now()->format('Y-m-d\TH:i'),
        'reason' => 'Opening correction',
    ];
    $this->post(route('inventory.adjustments.store'), $positive)->assertSessionHasNoErrors();
    $adjustment = InventoryAdjustment::query()->latest('id')->firstOrFail();
    $this->post(route('inventory.adjustments.approve', $adjustment))->assertRedirect();
    $this->post(route('inventory.adjustments.post', $adjustment))->assertRedirect();

    $damaged = [...$positive];
    $damaged['adjustment_type'] = 'damaged';
    $damaged['quantity'] = '1';
    $damaged['base_unit_cost'] = '';
    $damaged['offset_account_id'] = $context['expense']->getKey();
    $damaged['reason'] = 'Damaged goods write-off';
    $this->post(route('inventory.adjustments.store'), $damaged)->assertSessionHasNoErrors();
    $writeOff = InventoryAdjustment::query()->latest('id')->firstOrFail();
    $this->post(route('inventory.adjustments.approve', $writeOff))->assertRedirect();
    $this->post(route('inventory.adjustments.post', $writeOff))->assertRedirect();

    expect($adjustment->fresh()->status)->toBe('posted')
        ->and($writeOff->fresh()->status)->toBe('posted')
        ->and(StockMovement::query()->count())->toBe(2)
        ->and(app(InventoryCostingService::class)->currentValuation($context['product']))->toBe([
            'quantity' => '3.0000',
            'inventory_value' => '7.5000',
            'average_unit_cost' => '2.5000000000',
        ]);
});

it('rejects invalid company records and blocks users without company membership', function (): void {
    $context = inventoryUiSetUp();
    $otherCompany = Company::query()->create(['name' => 'Unlinked company', 'base_currency' => 'SYP']);
    app(CompanyContextService::class)->switchTo($context['user'], (int) $context['company']->getKey());

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->from(route('inventory.adjustments.create'))
        ->post(route('inventory.adjustments.store'), [
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'adjustment_type' => 'positive',
            'quantity' => '1',
            'inventory_asset_account_id' => $context['asset']->getKey(),
            'offset_account_id' => $context['gain']->getKey(),
            'occurred_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Missing approved unit cost',
        ])
        ->assertSessionHasErrors('base_unit_cost');

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->from(route('inventory.adjustments.create'))
        ->post(route('inventory.adjustments.store'), [
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'adjustment_type' => 'positive',
            'quantity' => '1',
            'base_unit_cost' => '1',
            'inventory_asset_account_id' => 99999,
            'offset_account_id' => $context['gain']->getKey(),
            'occurred_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Invalid account',
        ])
        ->assertSessionHasErrors('inventory_asset_account_id');

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('inventory.products.index'))
        ->assertForbidden();

    expect($otherCompany->exists)->toBeTrue();
});
