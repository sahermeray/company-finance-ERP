<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\CustomerReturnService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCutoverService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\RevenueService;
use App\Services\SupplierReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function returnWorkflowContext(string $baseCurrency = 'SYP'): array
{
    $company = Company::create(['name' => 'Return Co '.uniqid(), 'base_currency' => $baseCurrency]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->getKey());
    Auth::login($user);

    $category = ProductCategory::create(['company_id' => $company->getKey(), 'name' => 'Products']);
    $unit = UnitOfMeasure::create(['company_id' => $company->getKey(), 'code' => 'EA', 'name' => 'Each']);
    $warehouseA = Warehouse::create(['company_id' => $company->getKey(), 'code' => 'A', 'name' => 'A']);
    $warehouseB = Warehouse::create(['company_id' => $company->getKey(), 'code' => 'B', 'name' => 'B']);
    $customer = Party::create(['company_id' => $company->getKey(), 'name' => 'Customer']);
    $supplier = Party::create(['company_id' => $company->getKey(), 'name' => 'Supplier']);

    $accounts = [];
    foreach ([
        'receivable_syp' => ['asset', 'SYP'],
        'cash_syp' => ['asset', 'SYP'],
        'revenue_syp' => ['revenue', 'SYP'],
        'receivable_usd' => ['asset', 'USD'],
        'cash_usd' => ['asset', 'USD'],
        'revenue_usd' => ['revenue', 'USD'],
        'payable_syp' => ['liability', 'SYP'],
        'payable_usd' => ['liability', 'USD'],
        'expense_syp' => ['expense', 'SYP'],
        'expense_usd' => ['expense', 'USD'],
        'inventory' => ['asset', $baseCurrency],
        'cogs' => ['expense', $baseCurrency],
        'opening_equity' => ['equity', $baseCurrency],
        'variance_expense' => ['expense', $baseCurrency],
        'variance_income' => ['income', $baseCurrency],
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

    return compact('company', 'user', 'category', 'unit', 'warehouseA', 'warehouseB', 'customer', 'supplier', 'accounts');
}

function returnWorkflowProduct(array $context, string $sku, bool $tracked = true): Product
{
    return Product::create([
        'company_id' => $context['company']->getKey(),
        'product_category_id' => $context['category']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'sku' => $sku,
        'name' => $sku,
        'tracks_inventory' => $tracked,
    ]);
}

function returnWorkflowSale(array $context, Product $product, string $quantity, string $unitPrice, array $options = []): Revenue
{
    $currency = strtoupper((string) ($options['currency'] ?? $context['company']->base_currency));
    $service = app(RevenueService::class);
    $cashSale = (bool) ($options['cash_sale'] ?? false);
    $revenue = $service->createDraftRevenue([
        'party_id' => $context['customer']->getKey(),
        'revenue_date' => $options['revenue_date'] ?? now()->toDateString(),
        'currency' => $currency,
        'exchange_rate' => $options['exchange_rate'] ?? ($currency === $context['company']->base_currency ? '1' : '2'),
        'base_currency' => $context['company']->base_currency,
        'tax_total' => $options['tax_total'] ?? '0',
    ]);
    $service->addRevenueItem($revenue, [
        'product_id' => $product->getKey(),
        'warehouse_id' => $options['warehouse_id'] ?? $context['warehouseA']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ]);
    $currencyKey = strtolower($currency);
    $posted = $service->postRevenue($revenue, [
        'warehouse_id' => $options['warehouse_id'] ?? $context['warehouseA']->getKey(),
        'receivable_account_id' => $cashSale ? null : $context['accounts']['receivable_'.$currencyKey]->getKey(),
        'cash_account_id' => $cashSale ? $context['accounts']['cash_'.$currencyKey]->getKey() : null,
        'revenue_account_id' => $context['accounts']['revenue_'.$currencyKey]->getKey(),
        'inventory_account_id' => $context['accounts']['inventory']->getKey(),
        'cogs_account_id' => $context['accounts']['cogs']->getKey(),
    ]);

    return $posted;
}

function returnWorkflowPurchase(array $context, Product $product, string $quantity, string $unitPrice, array $options = []): Purchase
{
    $currency = strtoupper((string) ($options['currency'] ?? $context['company']->base_currency));
    $service = app(PurchaseService::class);
    $purchase = $service->createDraftPurchase([
        'party_id' => $context['supplier']->getKey(),
        'purchase_date' => now()->toDateString(),
        'currency' => $currency,
        'exchange_rate' => $options['exchange_rate'] ?? ($currency === $context['company']->base_currency ? '1' : '2'),
        'base_currency' => $context['company']->base_currency,
    ]);
    $item = $service->addPurchaseItem($purchase, [
        'product_id' => $product->getKey(),
        'warehouse_id' => $options['warehouse_id'] ?? $context['warehouseA']->getKey(),
        'expense_account_id' => $options['expense_account_id'] ?? null,
        'unit_of_measure_id' => $context['unit']->getKey(),
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ]);

    if (isset($options['tax'])) {
        $service->addPurchaseTaxLine($purchase, $item, [
            'treatment' => $options['tax']['treatment'],
            'amount' => $options['tax']['amount'],
            'tax_asset_account_id' => $options['tax']['account_id'] ?? null,
        ]);
    }

    $currencyKey = strtolower($currency);

    return $service->postPurchase($purchase, [
        'warehouse_id' => $options['warehouse_id'] ?? $context['warehouseA']->getKey(),
        'inventory_account_id' => $context['accounts']['inventory']->getKey(),
        'payable_account_id' => $context['accounts']['payable_'.$currencyKey]->getKey(),
        'expense_account_id' => $options['expense_account_id'] ?? $context['accounts']['expense_'.$currencyKey]->getKey(),
    ]);
}

it('returns customer stock at its original issue cost across partial returns', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'CUSTOMER-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '10', $context['unit'], ['base_cost_amount' => '1000']);
    app(InventoryService::class)->receiveStock($product, $context['warehouseB'], '10', $context['unit'], ['base_cost_amount' => '3000']);
    $sale = returnWorkflowSale($context, $product, '2', '100', [
        'currency' => 'USD',
        'exchange_rate' => '2',
        'warehouse_id' => $context['warehouseB']->getKey(),
    ]);

    $service = app(CustomerReturnService::class);
    $first = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Partial customer return']);
    $service->addLine($first, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $postedFirst = $service->post($first);

    $second = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Remaining customer return']);
    $service->addLine($second, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $postedSecond = $service->post($second);

    $originalIssue = $sale->stockMovements()->firstOrFail();
    $journal = $postedFirst->journal;
    expect($postedFirst->lines->first()->inventory_cost_amount)->toBe('200.0000')
        ->and($postedSecond->lines->first()->inventory_cost_amount)->toBe('200.0000')
        ->and($postedFirst->lines->first()->movement->warehouse_id)->toBe($context['warehouseB']->getKey())
        ->and($originalIssue->base_cost_amount)->toBe('400.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['revenue_usd']->getKey())->debit)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['receivable_usd']->getKey())->credit)->toBe('100.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->debit)->toBe('200.0000')
        ->and($journal->lines->firstWhere('financial_account_id', $context['accounts']['cogs']->getKey())->credit)->toBe('200.0000')
        ->and(app(InventoryCostingService::class)->currentValuation($product)['average_unit_cost'])->toBe('200.0000000000');
});

it('rejects supplier returns when reliable moving-average history is unavailable', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'UNVALUED-SUPPLIER-RETURN');
    app(InventoryService::class)->recordOpeningBalance($product, $context['warehouseA'], '5', $context['unit']);
    $purchase = returnWorkflowPurchase($context, $product, '1', '10');
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Unvalued legacy carrying history',
        'purchase_return_variance_account_id' => $context['accounts']['variance_expense']->getKey(),
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);

    expect(fn () => $service->post($return))->toThrow(ValidationException::class);
    expect($return->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', SupplierReturn::class)->count())->toBe(0);
});

it('credits the original cash account when a cash customer sale is returned', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'CASH-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $sale = returnWorkflowSale($context, $product, '1', '50', ['cash_sale' => true]);
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Cash sale refund']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['cash_syp']->getKey())->credit)->toBe('50.0000')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['receivable_syp']->getKey()))->toBeNull();
});

it('does not create inventory movements for customer returns of non-tracked products', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'NON-TRACKED-RETURN', false);
    $sale = returnWorkflowSale($context, $product, '2', '25');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Non-stock return']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->status)->toBe('posted')
        ->and($posted->lines->first()->stock_movement_id)->toBeNull()
        ->and($posted->journal->lines)->toHaveCount(2)
        ->and(StockMovement::query()->where('sourceable_type', CustomerReturn::class)->count())->toBe(0);
});

it('rejects customer returns when the original sale issue has no historical valuation', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'UNVALUED-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '3', $context['unit'], ['allow_unvalued_legacy' => true]);
    $sale = returnWorkflowSale($context, $product, '1', '50');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Missing historical cost']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);

    expect(fn () => $service->post($return))->toThrow(ValidationException::class);
    expect($return->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', CustomerReturn::class)->count())->toBe(0);
});

it('rejects customer quantities beyond the original sale and preserves the posted quantities', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'CUSTOMER-OVER-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $sale = returnWorkflowSale($context, $product, '1', '50');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Return original quantity']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $service->post($return);

    $excess = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Excess return']);
    expect(fn () => $service->addLine($excess, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '0.1']))
        ->toThrow(ValidationException::class);
    expect(CustomerReturn::query()->where('status', 'posted')->count())->toBe(1)
        ->and($sale->stockMovements()->where('direction', 'out')->first()->quantity)->toBe('1.0000');
});

it('preserves SYP document currency and USD inventory valuation for a USD-base company', function (): void {
    $context = returnWorkflowContext('USD');
    $product = returnWorkflowProduct($context, 'USD-BASE-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '4', $context['unit'], ['base_cost_amount' => '80']);
    $sale = returnWorkflowSale($context, $product, '2', '30', [
        'currency' => 'SYP',
        'exchange_rate' => '2',
    ]);
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Cross currency return']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->currency)->toBe('SYP')
        ->and($posted->base_currency)->toBe('USD')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['receivable_syp']->getKey())->credit)->toBe('30.0000')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->currency)->toBe('USD')
        ->and($posted->lines->first()->inventory_cost_amount)->toBe('20.0000');
});

it('posts valued customer returns after an inventory cutover', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'CUTOVER-RETURN');
    $cutovers = app(InventoryCutoverService::class);
    $cutover = $cutovers->createDraft([
        'effective_at' => now()->subDay()->toDateTimeString(),
        'inventory_asset_account_id' => $context['accounts']['inventory']->getKey(),
        'opening_equity_account_id' => $context['accounts']['opening_equity']->getKey(),
    ]);
    $cutovers->setOpeningBalance($cutover, $product, $context['warehouseA'], '5', '500', 'Opening stock');
    $cutovers->setOpeningBalance($cutover, $product, $context['warehouseB'], '0', '0', 'No stock');
    $cutovers->post($cutover);
    $sale = returnWorkflowSale($context, $product, '1', '50');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Return after cutover']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->lines->first()->movement->base_cost_amount)->toBe('100.0000')
        ->and($posted->lines->first()->movement->base_unit_cost)->toBe('100.0000000000');
});

it('rejects customer return accounts that do not use the original document currency', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'INVALID-RETURN-ACCOUNT');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $sale = returnWorkflowSale($context, $product, '1', '50');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft([
        'original_revenue_id' => $sale->getKey(),
        'refund_account_id' => $context['accounts']['cash_usd']->getKey(),
        'reason' => 'Wrong-currency refund account',
    ]);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);

    expect(fn () => $service->post($return))->toThrow(ValidationException::class);
    expect($return->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', CustomerReturn::class)->count())->toBe(0);
});

it('rejects supplier returns when no stock remains in the selected warehouse', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'SUPPLIER-RETURN-NO-STOCK');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $purchase = returnWorkflowPurchase($context, $product, '2', '10');
    returnWorkflowSale($context, $product, '4', '20');
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'No physical inventory remains',
        'purchase_return_variance_account_id' => $context['accounts']['variance_income']->getKey(),
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);

    expect(fn () => $service->post($return))->toThrow(ValidationException::class);
    expect($return->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', SupplierReturn::class)->count())->toBe(0);
});

it('requires a valid base-currency supplier return variance account before posting', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'MISSING-VARIANCE-ACCOUNT');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $purchase = returnWorkflowPurchase($context, $product, '1', '10');
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Missing variance setup',
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);

    expect(fn () => $service->post($return))->toThrow(ValidationException::class);
    expect($return->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', SupplierReturn::class)->count())->toBe(0);
});

it('rejects backdated customer returns and isolates returns to their owning company', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'RETURN-BOUNDARY');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], [
        'base_cost_amount' => '20',
        'occurred_at' => now()->subDays(10)->endOfDay(),
    ]);
    $sale = returnWorkflowSale($context, $product, '1', '50', ['revenue_date' => now()->subDays(5)->toDateString()]);
    $service = app(CustomerReturnService::class);
    $backdated = $service->createDraft([
        'original_revenue_id' => $sale->getKey(),
        'return_date' => now()->subDays(8)->toDateString(),
        'reason' => 'Backdated return',
    ]);
    $service->addLine($backdated, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    expect(fn () => $service->post($backdated))->toThrow(ValidationException::class);

    $otherCompany = Company::create(['name' => 'Other Returns Co']);
    $otherUser = User::factory()->create();
    $otherCompany->users()->attach($otherUser);
    app(CompanyContextService::class)->switchTo($otherUser, $otherCompany->getKey());
    Auth::login($otherUser);
    expect(fn () => $service->post($backdated))->toThrow(ValidationException::class);
    app(CompanyContextService::class)->switchTo($context['user'], $context['company']->getKey());
    Auth::login($context['user']);
    expect($backdated->fresh()->status)->toBe('draft')
        ->and(StockMovement::query()->where('sourceable_type', CustomerReturn::class)->count())->toBe(0);
});

it('posts supplier returns at current company-wide WAC and journals the supplier price variance', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'SUPPLIER-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '10', $context['unit'], ['base_cost_amount' => '1000']);
    $purchase = returnWorkflowPurchase($context, $product, '2', '100', [
        'currency' => 'USD',
        'exchange_rate' => '2',
        'warehouse_id' => $context['warehouseB']->getKey(),
    ]);
    $purchaseItem = $purchase->items->first();
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Damaged goods returned to supplier',
        'purchase_return_variance_account_id' => $context['accounts']['variance_income']->getKey(),
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchaseItem->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->lines->first()->inventory_cost_amount)->toBe('116.6667')
        ->and($posted->lines->first()->variance_amount)->toBe('83.3333')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['payable_usd']->getKey())->debit)->toBe('100.0000')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['inventory']->getKey())->credit)->toBe('116.6667')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['variance_income']->getKey())->credit)->toBe('83.3333')
        ->and($posted->lines->first()->movement->direction)->toBe('out');
});

it('posts multiple supplier return partials and prevents over-returning or duplicate posting', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'RETURN-LIMIT');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $purchase = returnWorkflowPurchase($context, $product, '2', '10');
    $service = app(SupplierReturnService::class);
    $first = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'First partial return',
        'purchase_return_variance_account_id' => $context['accounts']['variance_income']->getKey(),
    ]);
    $service->addLine($first, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);
    $postedFirst = $service->post($first);
    $second = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Second partial return',
        'purchase_return_variance_account_id' => $context['accounts']['variance_income']->getKey(),
    ]);
    $service->addLine($second, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);
    $postedSecond = $service->post($second);

    $excess = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Attempt to return more',
        'purchase_return_variance_account_id' => $context['accounts']['variance_expense']->getKey(),
    ]);
    expect(fn () => $service->addLine($excess, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '0.1']))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->post($postedFirst))->toThrow(ValidationException::class);
    expect(fn () => $postedSecond->update(['reason' => 'Tampered']))
        ->toThrow(ValidationException::class);
    expect($postedFirst->fresh()->status)->toBe('posted')
        ->and($postedSecond->fresh()->status)->toBe('posted')
        ->and(SupplierReturn::query()->where('status', 'posted')->count())->toBe(2)
        ->and(AccountingJournal::query()->where('sourceable_type', SupplierReturn::class)->count())->toBe(2);
});

it('reverses recoverable purchase tax and records a supplier return variance', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'RETURN-TAX');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '5', $context['unit'], ['base_cost_amount' => '500']);
    $purchase = returnWorkflowPurchase($context, $product, '1', '100', [
        'currency' => 'USD',
        'exchange_rate' => '2',
        'tax' => ['treatment' => 'recoverable', 'amount' => '10', 'account_id' => $context['accounts']['tax_asset_usd']->getKey()],
    ]);
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Return with its tax',
        'purchase_return_variance_account_id' => $context['accounts']['variance_income']->getKey(),
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->total)->toBe('110.0000')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['tax_asset_usd']->getKey())->credit)->toBe('10.0000')
        ->and($posted->journal->status)->toBe('posted');
});

it('debits the purchase-return variance account when supplier credit is below carrying value', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'SUPPLIER-LOSS-VARIANCE');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '10', $context['unit'], ['base_cost_amount' => '1000']);
    $purchase = returnWorkflowPurchase($context, $product, '1', '10');
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Supplier credit below carrying amount',
        'purchase_return_variance_account_id' => $context['accounts']['variance_expense']->getKey(),
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->lines->first()->variance_amount)->toBe('-81.8182')
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['variance_expense']->getKey())->debit)->toBe('81.8182')
        ->and($posted->journal->status)->toBe('posted');
});

it('reverses non-tracked supplier purchases without creating inventory movements', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'NON-TRACKED-SUPPLIER-RETURN', false);
    $purchase = returnWorkflowPurchase($context, $product, '2', '15', [
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
    ]);
    $service = app(SupplierReturnService::class);
    $return = $service->createDraft([
        'original_purchase_id' => $purchase->getKey(),
        'reason' => 'Return non-inventory service item',
    ]);
    $service->addLine($return, ['purchase_item_id' => $purchase->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect($posted->journal->lines)->toHaveCount(2)
        ->and($posted->journal->lines->firstWhere('financial_account_id', $context['accounts']['expense_syp']->getKey())->credit)->toBe('15.0000')
        ->and($posted->lines->first()->stock_movement_id)->toBeNull();
});

it('keeps posted customer return records immutable', function (): void {
    $context = returnWorkflowContext();
    $product = returnWorkflowProduct($context, 'IMMUTABLE-RETURN');
    app(InventoryService::class)->receiveStock($product, $context['warehouseA'], '2', $context['unit'], ['base_cost_amount' => '20']);
    $sale = returnWorkflowSale($context, $product, '1', '50');
    $service = app(CustomerReturnService::class);
    $return = $service->createDraft(['original_revenue_id' => $sale->getKey(), 'reason' => 'Immutable posting']);
    $service->addLine($return, ['revenue_item_id' => $sale->items->first()->getKey(), 'quantity' => '1']);
    $posted = $service->post($return);

    expect(fn () => $posted->update(['reason' => 'Tampered']))
        ->toThrow(ValidationException::class);
    expect(fn () => $posted->lines()->first()->update(['quantity' => '0.5']))
        ->toThrow(ValidationException::class);
});
