<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseLandedCostAllocation;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCutoverService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

uses(RefreshDatabase::class);

function purchaseValuationContext(string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'Purchase Valuation '.uniqid(),
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
        'opening_equity' => ['equity', $baseCurrency],
        'payable_syp' => ['liability', 'SYP'],
        'payable_usd' => ['liability', 'USD'],
        'expense_syp' => ['expense', 'SYP'],
        'expense_usd' => ['expense', 'USD'],
        'tax_asset_syp' => ['asset', 'SYP'],
        'tax_asset_usd' => ['asset', 'USD'],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
        ]);
    }

    $party = Party::create([
        'company_id' => $company->getKey(),
        'name' => 'Supplier',
    ]);

    return compact(
        'company',
        'user',
        'category',
        'unit',
        'warehouseA',
        'warehouseB',
        'accounts',
        'party',
    );
}

function purchaseValuationProduct(array $context, string $sku, bool $tracksInventory = true): Product
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

function createValuationPurchase(array $context, array $lines, array $attributes = []): Purchase
{
    $service = app(PurchaseService::class);
    $currency = strtoupper((string) ($attributes['currency'] ?? $context['company']->base_currency));
    $purchase = $service->createDraftPurchase([
        'party_id' => $context['party']->getKey(),
        'purchase_date' => $attributes['purchase_date'] ?? now()->toDateString(),
        'currency' => $currency,
        'exchange_rate' => $attributes['exchange_rate'] ?? ($currency === $context['company']->base_currency ? '1' : null),
        'base_currency' => $attributes['base_currency'] ?? $context['company']->base_currency,
        'tax_total' => $attributes['tax_total'] ?? '0',
    ]);

    foreach ($lines as $line) {
        $taxes = $line['taxes'] ?? [];
        unset($line['taxes']);
        $item = $service->addPurchaseItem($purchase, [
            'unit_of_measure_id' => $context['unit']->getKey(),
            ...$line,
        ]);

        foreach ($taxes as $tax) {
            $service->addPurchaseTaxLine($purchase, $item, $tax);
        }
    }

    return $purchase->fresh(['items']);
}

function purchasePostingOptions(array $context, string $currency = 'SYP', bool $withDefaultWarehouse = true): array
{
    $options = [
        'inventory_account_id' => $context['accounts']['inventory']->getKey(),
        'payable_account_id' => $context['accounts']['payable_'.strtolower($currency)]->getKey(),
    ];

    if ($withDefaultWarehouse) {
        $options['warehouse_id'] = $context['warehouseA']->getKey();
    }

    return $options;
}

function postZeroBalanceCutover(array $context, array $products): InventoryCutover
{
    $service = app(InventoryCutoverService::class);
    $cutover = $service->createDraft([
        'effective_at' => now()->subDay()->toDateTimeString(),
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'opening_equity_account_id' => $context['accounts']['opening_equity']->getKey(),
    ]);

    foreach ($products as $product) {
        foreach ([$context['warehouseA'], $context['warehouseB']] as $warehouse) {
            $service->setOpeningBalance(
                $cutover,
                $product,
                $warehouse,
                '0',
                '0',
                'No opening inventory',
            );
        }
    }

    return $service->post($cutover);
}

it('posts a tracked SYP purchase as a valued base-currency receipt after cutover', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'TRACKED-SYP');
    postZeroBalanceCutover($context, [$product]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '10',
        'unit_price' => '20',
    ]]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $movement = $posted->stockMovements()->firstOrFail();
    $journal = $posted->accountingJournals()->firstOrFail();
    $lines = $journal->lines;

    expect($posted->status)->toBe('posted')
        ->and($movement->quantity)->toBe('10.0000')
        ->and($movement->base_cost_amount)->toBe('200.0000')
        ->and($movement->base_unit_cost)->toBe('20.0000000000')
        ->and(app(InventoryCostingService::class)->currentValuation($product))->toBe([
            'quantity' => '10.0000',
            'inventory_value' => '200.0000',
            'average_unit_cost' => '20.0000000000',
        ])
        ->and($lines)->toHaveCount(2)
        ->and($lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->debit)->toBe('200.0000')
        ->and($lines->firstWhere('financial_account_id', $context['accounts']['payable_syp']->getKey())->credit)->toBe('200.0000');
});

it('posts multiple tracked products into their specified warehouses', function (): void {
    $context = purchaseValuationContext();
    $first = purchaseValuationProduct($context, 'MULTI-A');
    $second = purchaseValuationProduct($context, 'MULTI-B');
    postZeroBalanceCutover($context, [$first, $second]);

    $purchase = createValuationPurchase($context, [
        [
            'product_id' => $first->getKey(),
            'warehouse_id' => $context['warehouseA']->getKey(),
            'quantity' => '2',
            'unit_price' => '10',
        ],
        [
            'product_id' => $second->getKey(),
            'warehouse_id' => $context['warehouseB']->getKey(),
            'quantity' => '3',
            'unit_price' => '15',
        ],
    ]);

    $posted = app(PurchaseService::class)->postPurchase(
        $purchase,
        purchasePostingOptions($context, 'SYP', false),
    );

    expect(app(InventoryService::class)->getProductStockInWarehouse($first, $context['warehouseA']))->toBe(2.0)
        ->and(app(InventoryService::class)->getProductStockInWarehouse($second, $context['warehouseB']))->toBe(3.0)
        ->and($posted->stockMovements)->toHaveCount(2)
        ->and($posted->stockMovements->firstWhere('product_id', $first->getKey())->base_cost_amount)->toBe('20.0000')
        ->and($posted->stockMovements->firstWhere('product_id', $second->getKey())->base_cost_amount)->toBe('45.0000');
});

it('uses the stored USD exchange rate for stock cost and payable journal lines', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'USD-STORED-RATE');
    postZeroBalanceCutover($context, [$product]);
    $rate = '1.2345678901';
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '2',
    ]], [
        'currency' => 'USD',
        'exchange_rate' => $rate,
    ]);

    $posted = app(PurchaseService::class)->postPurchase(
        $purchase,
        purchasePostingOptions($context, 'USD'),
    );
    $movement = $posted->stockMovements()->firstOrFail();
    $journal = $posted->accountingJournals()->firstOrFail();
    $inventoryLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey());
    $payableLine = $journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_usd']->getKey());

    expect($posted->exchange_rate)->toBe($rate)
        ->and($movement->base_cost_amount)->toBe('2.4691')
        ->and($inventoryLine->currency)->toBe('SYP')
        ->and($inventoryLine->debit)->toBe('2.4691')
        ->and($payableLine->currency)->toBe('USD')
        ->and($payableLine->exchange_rate)->toBe($rate)
        ->and($payableLine->credit)->toBe('2.0000')
        ->and($payableLine->base_credit)->toBe('2.4691');
});

it('updates moving weighted average across purchases at different costs', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'WAC-PURCHASE');
    postZeroBalanceCutover($context, [$product]);
    $service = app(PurchaseService::class);

    foreach (['10', '20'] as $unitPrice) {
        $purchase = createValuationPurchase($context, [[
            'product_id' => $product->getKey(),
            'quantity' => '2',
            'unit_price' => $unitPrice,
        ]]);
        $service->postPurchase($purchase, purchasePostingOptions($context));
    }

    expect(app(InventoryCostingService::class)->currentValuation($product))->toBe([
        'quantity' => '4.0000',
        'inventory_value' => '60.0000',
        'average_unit_cost' => '15.0000000000',
    ]);
});

it('splits tracked inventory and non-tracked expense debits without stock for the expense item', function (): void {
    $context = purchaseValuationContext();
    $tracked = purchaseValuationProduct($context, 'MIXED-TRACKED');
    $nonTracked = purchaseValuationProduct($context, 'MIXED-EXPENSE', false);
    postZeroBalanceCutover($context, [$tracked]);
    $purchase = createValuationPurchase($context, [
        [
            'product_id' => $tracked->getKey(),
            'quantity' => '1',
            'unit_price' => '100',
        ],
        [
            'product_id' => $nonTracked->getKey(),
            'quantity' => '1',
            'unit_price' => '40',
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        ],
    ]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->stockMovements)->toHaveCount(1)
        ->and($posted->stockMovements->first()->product_id)->toBe($tracked->getKey())
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->debit)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['expense_syp']->getKey())->debit)->toBe('40.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_syp']->getKey())->credit)->toBe('140.0000');
});

it('excludes recoverable tax from inventory cost and debits its tax asset account', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'RECOVERABLE-TAX');
    postZeroBalanceCutover($context, [$product]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
        'taxes' => [[
            'treatment' => 'recoverable',
            'amount' => '10',
            'tax_asset_account_id' => $context['accounts']['tax_asset_syp']->getKey(),
        ]],
    ]]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->total)->toBe('110.0000')
        ->and($posted->stockMovements->first()->base_cost_amount)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['tax_asset_syp']->getKey())->debit)->toBe('10.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->debit)->toBe('100.0000');
});

it('capitalizes eligible non-recoverable tax into inventory cost', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'NONRECOVERABLE-TAX');
    postZeroBalanceCutover($context, [$product]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
        'taxes' => [[
            'treatment' => 'non_recoverable',
            'amount' => '10',
        ]],
    ]]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->stockMovements->first()->base_cost_amount)->toBe('110.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->debit)->toBe('110.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_syp']->getKey())->credit)->toBe('110.0000');
});

it('expenses non-recoverable tax assigned to a non-tracked purchase line', function (): void {
    $context = purchaseValuationContext();
    $tracked = purchaseValuationProduct($context, 'MIXED-TAX-TRACKED');
    $nonTracked = purchaseValuationProduct($context, 'MIXED-TAX-EXPENSE', false);
    postZeroBalanceCutover($context, [$tracked]);
    $purchase = createValuationPurchase($context, [
        [
            'product_id' => $tracked->getKey(),
            'quantity' => '1',
            'unit_price' => '100',
        ],
        [
            'product_id' => $nonTracked->getKey(),
            'quantity' => '1',
            'unit_price' => '40',
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'taxes' => [[
                'treatment' => 'non_recoverable',
                'amount' => '5',
            ]],
        ],
    ]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->total)->toBe('145.0000')
        ->and($posted->stockMovements)->toHaveCount(1)
        ->and($posted->stockMovements->first()->base_cost_amount)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['expense_syp']->getKey())->debit)->toBe('45.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_syp']->getKey())->credit)->toBe('145.0000');
});

it('rejects tax totals without explicit treatment and leaves the purchase unposted', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'UNCLASSIFIED-TAX');
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]], ['tax_total' => '10']);

    expect(fn () => app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect($purchase->fresh()->status)->toBe('draft')
        ->and($purchase->accountingJournals()->count())->toBe(0)
        ->and($purchase->stockMovements()->count())->toBe(0);
});

it('allocates foreign-currency freight proportionally and persists the allocation audit trail', function (): void {
    $context = purchaseValuationContext();
    $first = purchaseValuationProduct($context, 'FREIGHT-A');
    $second = purchaseValuationProduct($context, 'FREIGHT-B');
    postZeroBalanceCutover($context, [$first, $second]);
    $purchase = createValuationPurchase($context, [
        ['product_id' => $first->getKey(), 'quantity' => '1', 'unit_price' => '100'],
        ['product_id' => $second->getKey(), 'quantity' => '1', 'unit_price' => '300'],
    ]);
    $landedCost = app(PurchaseService::class)->addLandedCost($purchase, [
        'cost_type' => 'direct_freight',
        'amount' => '2',
        'currency' => 'USD',
        'exchange_rate' => '13000',
        'payable_account_id' => $context['accounts']['payable_usd']->getKey(),
        'description' => 'Inbound freight',
    ]);

    $posted = app(PurchaseService::class)->postPurchase(
        $purchase,
        purchasePostingOptions($context),
    );
    $allocations = PurchaseLandedCostAllocation::query()
        ->where('purchase_landed_cost_id', $landedCost->getKey())
        ->orderBy('purchase_item_id')
        ->get()
        ->keyBy('purchase_item_id');
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($landedCost->currency)->toBe('USD')
        ->and($landedCost->amount)->toBe('2.0000')
        ->and($landedCost->exchange_rate)->toBe('13000.0000000000')
        ->and($landedCost->base_amount)->toBe('26000.0000')
        ->and($allocations)->toHaveCount(2)
        ->and($allocations[$posted->items[0]->getKey()]->allocated_amount)->toBe('0.5000')
        ->and($allocations[$posted->items[0]->getKey()]->base_amount)->toBe('6500.0000')
        ->and($allocations[$posted->items[1]->getKey()]->allocated_amount)->toBe('1.5000')
        ->and($allocations[$posted->items[1]->getKey()]->base_amount)->toBe('19500.0000')
        ->and($posted->stockMovements->firstWhere('product_id', $first->getKey())->base_cost_amount)->toBe('6600.0000')
        ->and($posted->stockMovements->firstWhere('product_id', $second->getKey())->base_cost_amount)->toBe('19800.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_usd']->getKey())->exchange_rate)->toBe('13000.0000000000');
});

it('expenses administrative costs rather than capitalizing them', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'ADMIN-COST');
    postZeroBalanceCutover($context, [$product]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]]);
    app(PurchaseService::class)->addLandedCost($purchase, [
        'cost_type' => 'administrative',
        'amount' => '10',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        'payable_account_id' => $context['accounts']['payable_syp']->getKey(),
    ]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));
    $journal = $posted->accountingJournals()->firstOrFail();

    expect($posted->stockMovements->first()->base_cost_amount)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['expense_syp']->getKey())->debit)->toBe('10.0000')
        ->and(PurchaseLandedCostAllocation::query()->count())->toBe(0);
});

it('rejects a purchase total that does not reconcile with its classified tax', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'TOTAL-RECONCILIATION');
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
        'taxes' => [[
            'treatment' => 'non_recoverable',
            'amount' => '10',
        ]],
    ]]);
    $purchase->total = '999';
    $purchase->save();

    expect(fn () => app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect($purchase->fresh()->status)->toBe('draft')
        ->and($purchase->accountingJournals()->count())->toBe(0);
});

it('rejects posting a historical purchase dated before the cutover boundary', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'PRE-CUTOVER-PURCHASE');
    $cutoverService = app(InventoryCutoverService::class);
    $cutover = $cutoverService->createDraft([
        'effective_at' => now()->subDay()->toDateTimeString(),
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'opening_equity_account_id' => $context['accounts']['opening_equity']->getKey(),
    ]);
    foreach ([$context['warehouseA'], $context['warehouseB']] as $warehouse) {
        $cutoverService->setOpeningBalance($cutover, $product, $warehouse, '0', '0', 'No opening inventory');
    }
    $cutoverService->post($cutover);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]], ['purchase_date' => now()->subDays(3)->toDateString()]);

    expect(fn () => app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect($purchase->fresh()->status)->toBe('draft')
        ->and($purchase->stockMovements()->count())->toBe(0)
        ->and($purchase->accountingJournals()->count())->toBe(0);
});

it('rolls back a journal when the inventory ledger rejects a backdated receipt', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'BACKDATED-PURCHASE');
    $firstPurchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '10',
    ]]);
    app(PurchaseService::class)->postPurchase($firstPurchase, purchasePostingOptions($context));

    $backdatedPurchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '20',
    ]], ['purchase_date' => now()->subDay()->toDateString()]);

    expect(fn () => app(PurchaseService::class)->postPurchase($backdatedPurchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect($backdatedPurchase->fresh()->status)->toBe('draft')
        ->and($backdatedPurchase->accountingJournals()->count())->toBe(0)
        ->and($backdatedPurchase->stockMovements()->count())->toBe(0)
        ->and(StockMovement::query()->where('sourceable_id', $firstPurchase->getKey())->count())->toBe(1);
});

it('rejects duplicate posting without creating additional journals or movements', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'DUPLICATE-PURCHASE');
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '10',
    ]]);
    $service = app(PurchaseService::class);
    $service->postPurchase($purchase, purchasePostingOptions($context));

    expect(fn () => $service->postPurchase($purchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect($purchase->accountingJournals()->count())->toBe(1)
        ->and($purchase->stockMovements()->count())->toBe(1);
});

it('keeps pre-cutover purchases unvalued when inventory cutover is not complete', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'LEGACY-PURCHASE');
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '3',
        'unit_price' => '25',
    ]]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, purchasePostingOptions($context));

    expect($posted->status)->toBe('posted')
        ->and($posted->stockMovements)->toHaveCount(1)
        ->and($posted->stockMovements->first()->base_cost_amount)->toBeNull()
        ->and(app(InventoryCostingService::class)->valuationHistoryStatus($product))->toBe('unvalued');
});

it('rejects an inventory account with the wrong company currency', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'INVALID-INVENTORY-CURRENCY');
    $wrongCurrencyAsset = FinancialAccount::create([
        'company_id' => $context['company']->getKey(),
        'code' => 'WRONG-CURRENCY-'.uniqid(),
        'name' => 'Wrong Currency Asset',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '10',
    ]]);
    $options = purchasePostingOptions($context);
    $options['inventory_account_id'] = $wrongCurrencyAsset->getKey();

    expect(fn () => app(PurchaseService::class)->postPurchase($purchase, $options))
        ->toThrow(ValidationException::class);

    expect($purchase->fresh()->status)->toBe('draft')
        ->and($purchase->accountingJournals()->count())->toBe(0);
});

it('rejects a purchase from another company without posting it', function (): void {
    $context = purchaseValuationContext();
    $foreignCompany = Company::create(['name' => 'Foreign Purchase Company']);
    $foreignParty = app(CompanyContextService::class)->runForCompany($foreignCompany, function () use ($foreignCompany): Party {
        return Party::create([
            'company_id' => $foreignCompany->getKey(),
            'name' => 'Foreign Supplier',
        ]);
    });
    $foreignPurchase = app(CompanyContextService::class)->runForCompany($foreignCompany, function () use (
        $foreignCompany,
        $foreignParty,
    ): Purchase {
        return Purchase::create([
            'company_id' => $foreignCompany->getKey(),
            'party_id' => $foreignParty->getKey(),
            'purchase_number' => 'FOREIGN-'.uniqid(),
            'purchase_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'subtotal' => '10',
            'tax_total' => '0',
            'total' => '10',
        ]);
    });

    expect(fn () => app(PurchaseService::class)->postPurchase($foreignPurchase, purchasePostingOptions($context)))
        ->toThrow(ValidationException::class);

    expect(AccountingJournal::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rolls back purchase status, journal, tax valuation, and stock on accounting failure', function (): void {
    $context = purchaseValuationContext();
    $product = purchaseValuationProduct($context, 'ACCOUNTING-ROLLBACK');
    postZeroBalanceCutover($context, [$product]);
    $purchase = createValuationPurchase($context, [[
        'product_id' => $product->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
        'taxes' => [[
            'treatment' => 'non_recoverable',
            'amount' => '10',
        ]],
    ]]);
    $accounting = Mockery::mock(AccountingService::class, [app(CompanyContextService::class)])->makePartial();
    $accounting->shouldReceive('postJournal')
        ->once()
        ->andThrow(new RuntimeException('Simulated accounting failure.'));
    $purchaseService = new PurchaseService(
        app(CompanyContextService::class),
        app(InventoryService::class),
        app(InventoryCostingService::class),
        $accounting,
        app(PaymentService::class),
    );

    expect(fn () => $purchaseService->postPurchase($purchase, purchasePostingOptions($context)))
        ->toThrow(RuntimeException::class);

    expect($purchase->fresh()->status)->toBe('draft')
        ->and($purchase->accountingJournals()->count())->toBe(0)
        ->and($purchase->stockMovements()->count())->toBe(0)
        ->and($purchase->taxLines()->first()->base_amount)->toBeNull()
        ->and(PurchaseLandedCostAllocation::query()->count())->toBe(0);
});
