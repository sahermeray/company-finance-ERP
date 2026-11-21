<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     company: Company,
 *     user: User,
 *     supplier: Party,
 *     customer: Party,
 *     product: Product,
 *     nonTrackedProduct: Product,
 *     unit: UnitOfMeasure,
 *     warehouse: Warehouse,
 *     accounts: array<string, FinancialAccount>
 * }
 */
function commercialUiSetUp(): array
{
    $company = Company::query()->create([
        'name' => 'Commercial Shop '.uniqid(),
        'base_currency' => 'SYP',
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $category = ProductCategory::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'General goods',
    ]);
    $unit = UnitOfMeasure::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'EA',
        'name' => 'Each',
        'symbol' => 'ea',
    ]);
    $warehouse = Warehouse::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'MAIN',
        'name' => 'Main warehouse',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'product_category_id' => $category->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'TRACK-'.uniqid(),
        'name' => 'Tracked product',
        'tracks_inventory' => true,
        'is_active' => true,
    ]);
    $nonTrackedProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'product_category_id' => $category->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'SERVICE-'.uniqid(),
        'name' => 'Non-tracked product',
        'tracks_inventory' => false,
        'is_active' => true,
    ]);
    $supplier = Party::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Main supplier',
    ]);
    $customer = Party::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Main customer',
    ]);

    $accounts = [];
    foreach ([
        'asset_syp' => ['asset', 'SYP'],
        'liability_syp' => ['liability', 'SYP'],
        'expense_syp' => ['expense', 'SYP'],
        'tax_asset_syp' => ['asset', 'SYP'],
        'revenue_syp' => ['revenue', 'SYP'],
        'cogs_syp' => ['expense', 'SYP'],
        'asset_usd' => ['asset', 'USD'],
        'liability_usd' => ['liability', 'USD'],
        'expense_usd' => ['expense', 'USD'],
        'revenue_usd' => ['revenue', 'USD'],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::query()->create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
            'is_active' => true,
        ]);
    }

    return compact('company', 'user', 'supplier', 'customer', 'product', 'nonTrackedProduct', 'unit', 'warehouse', 'accounts');
}

function commercialUiDraftPurchase(array $context, ?Party $supplier = null, ?string $date = null): Purchase
{
    $service = app(PurchaseService::class);
    $purchase = $service->createDraftPurchase([
        'party_id' => ($supplier ?? $context['supplier'])->getKey(),
        'purchase_date' => $date ?? now()->toDateString(),
        'currency' => 'SYP',
        'base_currency' => 'SYP',
    ]);
    $service->addPurchaseItem($purchase, [
        'product_id' => $context['product']->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'unit_of_measure_id' => $context['unit']->getKey(),
        'quantity' => '1',
        'unit_price' => '100',
    ]);

    return $purchase->fresh();
}

function commercialUiAs(User $user, Company $company): void
{
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());
}

it('filters purchase listings by supplier, currency, status and date', function (): void {
    $context = commercialUiSetUp();
    $postedPurchase = commercialUiDraftPurchase($context);
    app(PurchaseService::class)->postPurchase($postedPurchase, [
        'inventory_account_id' => $context['accounts']['asset_syp']->getKey(),
        'payable_account_id' => $context['accounts']['liability_syp']->getKey(),
    ]);
    $otherSupplier = Party::query()->create([
        'company_id' => $context['company']->getKey(),
        'name' => 'Other supplier',
    ]);
    $draftPurchase = commercialUiDraftPurchase($context, $otherSupplier, now()->subMonth()->toDateString());

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('purchases.index', [
            'search' => 'Main supplier',
            'currency' => 'SYP',
            'status' => 'posted',
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee($postedPurchase->purchase_number)
        ->assertDontSee($draftPurchase->purchase_number);
});

it('creates and posts a purchase with tax treatment and foreign-currency landed cost', function (): void {
    $context = commercialUiSetUp();

    $response = $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->post(route('purchases.store'), [
            'party_id' => $context['supplier']->getKey(),
            'purchase_date' => now()->toDateString(),
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $context['product']->getKey(),
                    'warehouse_id' => $context['warehouse']->getKey(),
                    'quantity' => '2',
                    'unit_price' => '100',
                    'tax_amount' => '10',
                    'tax_treatment' => 'recoverable',
                    'tax_asset_account_id' => $context['accounts']['tax_asset_syp']->getKey(),
                ],
                [
                    'product_id' => $context['nonTrackedProduct']->getKey(),
                    'quantity' => '1',
                    'unit_price' => '50',
                    'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
                    'tax_amount' => '5',
                    'tax_treatment' => 'non_recoverable',
                ],
            ],
            'landed_costs' => [[
                'cost_type' => 'direct_freight',
                'amount' => '2',
                'currency' => 'USD',
                'exchange_rate' => '13000',
                'payable_account_id' => $context['accounts']['liability_usd']->getKey(),
                'description' => 'Inbound freight',
            ]],
        ]);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/purchases/');
    $response->assertSessionHasNoErrors();

    $purchase = Purchase::withoutGlobalScopes()
        ->where('company_id', $context['company']->getKey())
        ->latest('id')
        ->firstOrFail();
    $this->assertModelExists($purchase);
    expect($purchase->status)->toBe('draft')
        ->and($purchase->subtotal)->toBe('250.0000')
        ->and($purchase->tax_total)->toBe('15.0000')
        ->and($purchase->total)->toBe('265.0000')
        ->and($purchase->items->first()->unit_of_measure_id)->toBe($context['unit']->getKey())
        ->and($purchase->landedCosts->first()->base_amount)->toBe('26000.0000');

    $this->get(route('purchases.show', $purchase))
        ->assertOk()
        ->assertSee('Recoverable')
        ->assertSee('Non-recoverable')
        ->assertSee('Inbound freight');

    $this->post(route('purchases.post', $purchase), [
        'inventory_account_id' => $context['accounts']['asset_syp']->getKey(),
        'payable_account_id' => $context['accounts']['liability_syp']->getKey(),
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
    ])->assertSessionHasNoErrors();

    expect($purchase->fresh()->status)->toBe('posted')
        ->and($purchase->stockMovements()->count())->toBe(1)
        ->and($purchase->landedCosts()->first()->allocations()->count())->toBe(1)
        ->and($purchase->accountingJournals()->count())->toBe(1);

    $this->get(route('purchases.print', $purchase))
        ->assertOk()
        ->assertSee($context['company']->name)
        ->assertSee($purchase->purchase_number)
        ->assertSee('Inbound freight')
        ->assertSee('2.0000 USD');
});

it('filters sales and creates a multi-currency draft invoice', function (): void {
    $context = commercialUiSetUp();

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->post(route('sales.store'), [
            'party_id' => $context['customer']->getKey(),
            'revenue_date' => now()->toDateString(),
            'currency' => 'USD',
            'exchange_rate' => '12500',
            'lines' => [[
                'product_id' => $context['nonTrackedProduct']->getKey(),
                'quantity' => '2',
                'unit_price' => '10',
            ]],
        ])
        ->assertSessionHasNoErrors();

    $revenue = Revenue::query()->latest('id')->firstOrFail();
    expect($revenue->status)->toBe('draft')
        ->and($revenue->currency)->toBe('USD')
        ->and($revenue->exchange_rate)->toBe('12500.0000000000')
        ->and($revenue->total)->toBe('20.0000')
        ->and($revenue->base_total)->toBe('250000.0000');

    $this->get(route('sales.index', [
        'search' => 'Main customer',
        'currency' => 'USD',
        'status' => 'draft',
        'date_from' => now()->subDay()->toDateString(),
        'date_to' => now()->addDay()->toDateString(),
    ]))
        ->assertOk()
        ->assertSee($revenue->revenue_number)
        ->assertSee('20.0000 USD');

    $this->get(route('sales.print', $revenue))
        ->assertOk()
        ->assertSee($context['company']->name)
        ->assertSee('Main customer')
        ->assertSee('Non-tracked product')
        ->assertSee('250000.0000 SYP');
});

it('shows warehouse availability and posts valued sales with moving-average COGS', function (): void {
    $context = commercialUiSetUp();
    app(InventoryService::class)->receiveStock(
        $context['product'],
        $context['warehouse'],
        '10',
        $context['unit'],
        ['base_cost_amount' => '200'],
    );

    $availabilityResponse = $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('sales.availability', [
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
        ]));
    $availabilityResponse->assertOk()->assertSee('"quantity":"10.0000"', false);

    $this->post(route('sales.store'), [
        'party_id' => $context['customer']->getKey(),
        'revenue_date' => now()->toDateString(),
        'currency' => 'SYP',
        'lines' => [[
            'product_id' => $context['product']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'quantity' => '2',
            'unit_price' => '150',
        ]],
    ])->assertSessionHasNoErrors();
    $revenue = Revenue::query()->latest('id')->firstOrFail();

    $this->from(route('sales.show', $revenue))->post(route('sales.post', $revenue), [
        'settlement_type' => 'receivable',
        'settlement_account_id' => $context['accounts']['asset_syp']->getKey(),
        'revenue_account_id' => $context['accounts']['revenue_syp']->getKey(),
        'inventory_account_id' => $context['accounts']['asset_syp']->getKey(),
        'cogs_account_id' => $context['accounts']['cogs_syp']->getKey(),
    ])->assertSessionHasNoErrors();

    $movement = $revenue->stockMovements()->firstOrFail();
    $journal = $revenue->accountingJournals()->firstOrFail();
    $cogsLine = $journal->lines()->where('financial_account_id', $context['accounts']['cogs_syp']->getKey())->firstOrFail();

    expect($revenue->fresh()->status)->toBe('posted')
        ->and($movement->base_cost_amount)->toBe('40.0000')
        ->and($cogsLine->debit)->toBe('40.0000')
        ->and(app(InventoryService::class)->getProductStockInWarehouse($context['product'], $context['warehouse']))->toBe(8.0);

    $this->get(route('sales.show', $revenue))
        ->assertOk()
        ->assertSee('Cost of goods sold is recorded in company base currency')
        ->assertSee('40.0000 SYP');
});

it('keeps an insufficient-stock sale in draft and does not create financial or stock effects', function (): void {
    $context = commercialUiSetUp();
    app(InventoryService::class)->recordOpeningBalance(
        $context['product'],
        $context['warehouse'],
        1,
        $context['unit'],
        ['reference' => 'opening-stock'],
    );

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->post(route('sales.store'), [
            'party_id' => $context['customer']->getKey(),
            'revenue_date' => now()->toDateString(),
            'currency' => 'SYP',
            'lines' => [[
                'product_id' => $context['product']->getKey(),
                'warehouse_id' => $context['warehouse']->getKey(),
                'quantity' => '2',
                'unit_price' => '100',
            ]],
        ])
        ->assertSessionHasNoErrors();
    $revenue = Revenue::query()->latest('id')->firstOrFail();

    $this->followingRedirects()->from(route('sales.show', $revenue))->post(route('sales.post', $revenue), [
        'settlement_type' => 'cash',
        'settlement_account_id' => $context['accounts']['asset_syp']->getKey(),
        'revenue_account_id' => $context['accounts']['revenue_syp']->getKey(),
    ])
        ->assertSee('Insufficient stock for this warehouse.');

    expect($revenue->fresh()->status)->toBe('draft')
        ->and($revenue->accountingJournals()->count())->toBe(0)
        ->and($revenue->stockMovements()->count())->toBe(0)
        ->and(AccountingJournal::query()->where('sourceable_type', Revenue::class)->where('sourceable_id', $revenue->getKey())->count())->toBe(0)
        ->and(StockMovement::query()->where('sourceable_type', Revenue::class)->where('sourceable_id', $revenue->getKey())->count())->toBe(0);
});

it('isolates commercial documents by company', function (): void {
    $context = commercialUiSetUp();
    $otherCompany = Company::query()->create([
        'name' => 'Private commercial company',
        'base_currency' => 'SYP',
    ]);
    $otherCompany->users()->attach($context['user']);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $otherCompany->id)->where('user_id', $context['user']->id)
        ->update(['is_owner' => true]);
    commercialUiAs($context['user'], $otherCompany);

    $otherParty = Party::query()->create([
        'company_id' => $otherCompany->getKey(),
        'name' => 'Private party',
    ]);
    $foreignPurchase = Purchase::query()->create([
        'company_id' => $otherCompany->getKey(),
        'party_id' => $otherParty->getKey(),
        'purchase_number' => 'PUR-PRIVATE',
        'purchase_date' => now()->toDateString(),
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '0',
        'tax_total' => '0',
        'total' => '0',
        'base_subtotal' => '0',
        'base_tax_total' => '0',
        'base_total' => '0',
    ]);
    $foreignRevenue = Revenue::query()->create([
        'company_id' => $otherCompany->getKey(),
        'party_id' => $otherParty->getKey(),
        'revenue_number' => 'REV-PRIVATE',
        'revenue_date' => now()->toDateString(),
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '0',
        'tax_total' => '0',
        'total' => '0',
        'base_subtotal' => '0',
        'base_tax_total' => '0',
        'base_total' => '0',
    ]);
    commercialUiAs($context['user'], $context['company']);

    $this->actingAs($context['user'])
        ->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('purchases.show', $foreignPurchase))
        ->assertNotFound();

    $this->get(route('sales.show', $foreignRevenue))->assertNotFound();
});

it('requires a linked company before showing commercial screens', function (): void {
    $unlinkedUser = User::factory()->create();

    $this->get(route('purchases.index'))->assertRedirect(route('login'));

    $this->actingAs($unlinkedUser)
        ->withSession(['locale' => 'en'])
        ->get(route('sales.index'))
        ->assertForbidden();
});
