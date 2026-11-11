<?php

/**
 * RevenueWorkflowTest
 *
 * Phase 1: Revenue posting (RevenueService)
 * Phase 2: Customer receipts (PaymentService::recordCustomerReceipt)
 * Phase 3: Outstanding balance calculations
 *
 * Accounting entries:
 *
 * Revenue posting — credit sale:
 *   Dr  Accounts Receivable   (asset)          — receivable_account_id
 *   Cr  Revenue               (revenue/income) — revenue_account_id
 *
 * Revenue posting — cash sale:
 *   Dr  Cash / Bank           (asset)          — cash_account_id
 *   Cr  Revenue               (revenue/income) — revenue_account_id
 *
 * Customer receipt (against posted credit-sale revenue):
 *   Dr  Cash / Bank           (asset)          — financial_account_id
 *   Cr  Accounts Receivable   (asset)          — receivable_account_id
 */

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use App\Services\RevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Create a company pre-loaded with the accounts needed for revenue tests.
 * Returns [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit, $user]
 */
function makeRevenueContext(string $currency = 'SYP', string $baseCurrency = 'SYP'): array
{
    $company = Company::create(['name' => 'RevCo '.uniqid(), 'base_currency' => $baseCurrency]);
    $user = User::factory()->create();
    $company->users()->attach($user);

    $receivableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1100',
        'name' => 'Accounts Receivable',
        'account_type' => 'asset',
        'currency' => $currency,
    ]);

    $revenueAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales Revenue',
        'account_type' => 'revenue',
        'currency' => $currency,
    ]);

    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => $currency,
    ]);

    $warehouse = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'WH-MAIN',
        'name' => 'Main Warehouse',
    ]);

    $unit = UnitOfMeasure::create([
        'company_id' => $company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);

    $product = Product::create([
        'company_id' => $company->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'PROD-'.uniqid(),
        'name' => 'Test Product',
    ]);

    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    // Seed 100 units of opening stock so issueStock doesn't fail.
    app(InventoryService::class)->recordOpeningBalance(
        $product, $warehouse, 100, $unit,
        ['reference' => 'opening-stock'],
    );

    return [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit, $user];
}

/**
 * Build a fully-populated draft revenue via RevenueService.
 */
function buildDraftRevenue(
    Company $company,
    Product $product,
    UnitOfMeasure $unit,
    string $quantity = '10',
    string $unitPrice = '100',
    string $currency = 'SYP',
): Revenue {
    $party = Party::create(['company_id' => $company->id, 'name' => 'Customer '.uniqid()]);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue([
        'party_id' => $party->id,
        'currency' => $currency,
        'base_currency' => $currency,
    ]);

    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ]);

    return $revenue->fresh();
}

// ---------------------------------------------------------------------------
// PHASE 1 — Revenue posting
// ---------------------------------------------------------------------------

it('creates a draft revenue with status=draft', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();

    $revenue = buildDraftRevenue($company, $product, $unit);

    expect($revenue->status)->toBe('draft')
        ->and(bccomp((string) $revenue->total, '1000', 4))->toBe(0);
});

it('rejects posting a draft revenue with no items', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Customer']);
    $revenue = app(RevenueService::class)->createDraftRevenue(['party_id' => $party->id]);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]))->toThrow(ValidationException::class);
});

it('posts a credit-sale revenue and creates a balanced journal (Dr Receivable / Cr Revenue)', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '5', '200');

    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect($posted->status)->toBe('posted')
        ->and(bccomp((string) $posted->total, '1000', 4))->toBe(0);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Revenue::class)
        ->where('sourceable_id', $posted->id)
        ->where('status', 'posted')
        ->first();

    expect($journal)->not->toBeNull();

    $lines = $journal->lines()->get();
    expect($lines)->toHaveCount(2);

    $totalDebit = $lines->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lines->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit);

    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect((int) $debitLine->financial_account_id)->toBe($receivableAccount->id);
    expect((int) $creditLine->financial_account_id)->toBe($revenueAccount->id);
});

it('posts a cash-sale revenue and creates a balanced journal (Dr Cash / Cr Revenue)', function (): void {
    [$company, , $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '2', '500');

    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'cash_account_id' => $cashAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect($posted->status)->toBe('posted');

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Revenue::class)
        ->where('sourceable_id', $posted->id)
        ->where('status', 'posted')
        ->first();

    $lines = $journal->lines()->get();
    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect((int) $debitLine->financial_account_id)->toBe($cashAccount->id);
    expect((int) $creditLine->financial_account_id)->toBe($revenueAccount->id);
});

it('issues stock when a revenue is posted', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '3', '100');

    $stockBefore = app(InventoryService::class)
        ->getProductStockInWarehouse($product, $warehouse);

    app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $stockAfter = app(InventoryService::class)
        ->getProductStockInWarehouse($product, $warehouse);

    expect($stockAfter)->toBe($stockBefore - 3.0);
});

it('prevents duplicate posting of the same revenue document', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit);
    $service = app(RevenueService::class);

    $posted = $service->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect(fn () => $service->postRevenue($posted, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]))->toThrow(ValidationException::class);
});

it('rejects providing both receivable_account_id and cash_account_id', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'cash_account_id' => $cashAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// PHASE 1 — Revenue immutability
// ---------------------------------------------------------------------------

it('prevents editing a posted revenue document', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit);
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect(fn () => $posted->update(['notes' => 'tamper']))
        ->toThrow(ValidationException::class);
});

it('prevents deleting a posted revenue document', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit);
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect(fn () => $posted->delete())->toThrow(ValidationException::class);
});

it('allows editing and deleting a draft revenue', function (): void {
    [$company] = makeRevenueContext();

    // Use a plain draft with no items so FK constraints don't block the delete.
    $party = Party::create(['company_id' => $company->id, 'name' => 'CustDraft']);
    $revenue = app(RevenueService::class)->createDraftRevenue([
        'party_id' => $party->id,
    ]);

    $revenue->notes = 'updated';
    $revenue->save();
    expect($revenue->fresh()->notes)->toBe('updated');

    $id = $revenue->id;
    $revenue->delete();
    expect(Revenue::withoutGlobalScope(CompanyScope::class)->find($id))->toBeNull();
});

// ---------------------------------------------------------------------------
// PHASE 1 — USD revenue preserves amounts and exchange rate
// ---------------------------------------------------------------------------

it('preserves USD amounts and exchange rate in a posted revenue journal', function (): void {
    $company = Company::create(['name' => 'USDRevCo '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);

    $receivableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1100',
        'name' => 'AR USD',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $revenueAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Revenue USD',
        'account_type' => 'revenue',
        'currency' => 'USD',
    ]);

    $unit = UnitOfMeasure::create([
        'company_id' => $company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $product = Product::create([
        'company_id' => $company->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'USD-PROD',
        'name' => 'USD Product',
    ]);
    $warehouse = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'WH1',
        'name' => 'Warehouse 1',
    ]);

    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    app(InventoryService::class)->recordOpeningBalance(
        $product, $warehouse, 50, $unit,
    );

    ExchangeRate::create([
        'company_id' => $company->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-01 00:00:00',
    ]);

    $party = Party::create(['company_id' => $company->id, 'name' => 'USD Customer']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue([
        'party_id' => $party->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'exchange_rate' => '12000',
    ]);

    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '2',
        'unit_price' => '500',
    ]);

    $revenue->refresh();

    $posted = $service->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Revenue::class)
        ->where('sourceable_id', $posted->id)
        ->where('status', 'posted')
        ->first();

    foreach ($journal->lines()->get() as $line) {
        expect($line->currency)->toBe('USD')
            ->and((string) $line->exchange_rate)->toBe('12000.0000000000');
    }
});

// ---------------------------------------------------------------------------
// PHASE 1 — Cross-company isolation for posting
// ---------------------------------------------------------------------------

it('rejects a revenue account belonging to another company', function (): void {
    // Set up company A first — this sets the active context to company A.
    [$companyA, $receivableA, , , $warehouseA, $productA, $unitA] = makeRevenueContext();

    // Create company B and its revenue account inside a runForCompany(B) block
    // so we never create company B records while the context is still company A.
    $companyB = Company::create(['name' => 'CrossCo B '.uniqid(), 'base_currency' => 'SYP']);
    $revenueB = app(CompanyContextService::class)->runForCompany($companyB, function () use ($companyB): FinancialAccount {
        return FinancialAccount::create([
            'company_id' => $companyB->id,
            'code' => '4000',
            'name' => 'Revenue B',
            'account_type' => 'revenue',
            'currency' => 'SYP',
        ]);
    });

    // Build the draft inside company A context (context restored to A after runForCompany(B) returned).
    $revenue = buildDraftRevenue($companyA, $productA, $unitA);

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouseA->id,
        'receivable_account_id' => $receivableA->id,
        'revenue_account_id' => $revenueB->id, // belongs to company B
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// PHASE 1 — Transaction rollback on failure
// ---------------------------------------------------------------------------

it('rolls back posting when journal creation fails', function (): void {
    [$company, $receivableAccount, , , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit);

    $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();
    $movementsBefore = StockMovement::withoutGlobalScope(CompanyScope::class)->count();

    expect(fn () => app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => 99999, // does not exist
    ]))->toThrow(ValidationException::class);

    // Revenue stays draft.
    expect($revenue->fresh()->status)->toBe('draft');
    // No journals or stock movements were committed.
    expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
    expect(StockMovement::withoutGlobalScope(CompanyScope::class)->count())->toBe($movementsBefore);
});

// ---------------------------------------------------------------------------
// PHASE 2 — Customer receipt against a posted revenue
// ---------------------------------------------------------------------------

it('records a customer receipt and creates the correct balanced journal', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '5', '200');
    $service = app(RevenueService::class);

    $posted = $service->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $payment = app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '1000',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);

    expect($payment->status)->toBe('posted');

    $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->where('status', 'posted')
        ->first();

    expect($journal)->not->toBeNull();
    $lines = $journal->lines()->get();
    expect($lines)->toHaveCount(2);

    $totalDebit = $lines->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lines->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe($totalCredit);

    // Dr Cash.
    $debitLine = $lines->firstWhere('debit', '>', 0);
    expect((int) $debitLine->financial_account_id)->toBe($cashAccount->id);

    // Cr Accounts Receivable.
    $creditLine = $lines->firstWhere('credit', '>', 0);
    expect((int) $creditLine->financial_account_id)->toBe($receivableAccount->id);
});

it('rejects a customer receipt against a draft revenue', function (): void {
    [$company, , , $cashAccount, , $product, $unit] = makeRevenueContext();
    $receivableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1101',
        'name' => 'AR 2',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    $draft = buildDraftRevenue($company, $product, $unit);

    expect(fn () => app(PaymentService::class)->recordCustomerReceipt($draft, [
        'amount' => '1000',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// PHASE 3 — Outstanding balance calculations
// ---------------------------------------------------------------------------

it('outstanding starts at the revenue total with no receipts', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstandingForRevenue($posted);
    expect(bccomp($outstanding, '1000', 10))->toBe(0);
});

it('a draft receipt does not reduce outstanding', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    // Insert a draft payment directly.
    Payment::create([
        'company_id' => $company->id,
        'payable_type' => Revenue::class,
        'payable_id' => $posted->id,
        'direction' => 'inbound',
        'status' => 'draft',
        'paid_at' => now(),
        'amount' => '400',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '400',
        'applied_amount' => '400',
        'applied_currency' => 'SYP',
        'applied_exchange_rate' => '1',
        'applied_base_amount' => '400',
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstandingForRevenue($posted);
    expect(bccomp($outstanding, '1000', 10))->toBe(0);
});

it('does not reduce customer outstanding for a posted receipt without a journal', function (): void {
    [$company, $receivableAccount, $revenueAccount, , $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    Payment::create([
        'company_id' => $company->id,
        'payable_type' => Revenue::class,
        'payable_id' => $posted->id,
        'direction' => 'inbound',
        'status' => 'posted',
        'posted_at' => now(),
        'paid_at' => now(),
        'amount' => '400',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_amount' => '400',
        'applied_amount' => '400',
        'applied_currency' => 'SYP',
        'applied_exchange_rate' => '1',
        'applied_base_amount' => '400',
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstandingForRevenue($posted);

    expect(bccomp($outstanding, '1000', 10))->toBe(0);
});

it('a partial receipt reduces outstanding by the paid amount', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '400',
        'applied_amount' => '400',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstandingForRevenue($posted);
    expect(bccomp($outstanding, '600', 10))->toBe(0);
});

it('a full receipt leaves outstanding at zero', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '1000',
        'applied_amount' => '1000',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstandingForRevenue($posted);
    expect(bccomp($outstanding, '0', 10))->toBe(0);
});

it('multiple partial receipts accumulate correctly', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $paymentService = app(PaymentService::class);

    $paymentService->recordCustomerReceipt($posted, [
        'amount' => '300',
        'applied_amount' => '300',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);
    $paymentService->recordCustomerReceipt($posted, [
        'amount' => '400',
        'applied_amount' => '400',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);

    $outstanding = $paymentService->calculateOutstandingForRevenue($posted);
    expect(bccomp($outstanding, '300', 10))->toBe(0);
});

it('rejects an overpayment receipt', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '5', '200');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    expect(fn () => app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '2000',
        'applied_amount' => '2000',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// PHASE 3 — Cross-company isolation for receipts
// ---------------------------------------------------------------------------

it('rejects a receipt using a cash account from another company', function (): void {
    // Set up company A — sets active context to A.
    [$companyA, $receivableA, $revenueA, , $warehouseA, $productA, $unitA] = makeRevenueContext();

    // Create company B's cash account inside runForCompany(B) to avoid
    // creating company B records while context is still company A.
    $companyB = Company::create(['name' => 'CrossCo B2 '.uniqid(), 'base_currency' => 'SYP']);
    $cashB = app(CompanyContextService::class)->runForCompany($companyB, function () use ($companyB): FinancialAccount {
        return FinancialAccount::create([
            'company_id' => $companyB->id,
            'code' => '1000',
            'name' => 'Cash B',
            'account_type' => 'asset',
            'currency' => 'SYP',
        ]);
    });

    // Build and post the revenue while company A is still active.
    $revenue = buildDraftRevenue($companyA, $productA, $unitA);
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouseA->id,
        'receivable_account_id' => $receivableA->id,
        'revenue_account_id' => $revenueA->id,
    ]);

    // Attempt receipt with company B's cash account — must be rejected.
    expect(fn () => app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '1000',
        'currency' => 'SYP',
        'financial_account_id' => $cashB->id, // belongs to company B
        'receivable_account_id' => $receivableA->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// PHASE 3 — Duplicate journal prevention for receipts
// ---------------------------------------------------------------------------

it('prevents duplicate journal creation for the same customer receipt', function (): void {
    [$company, $receivableAccount, $revenueAccount, $cashAccount, $warehouse, $product, $unit] = makeRevenueContext();
    $revenue = buildDraftRevenue($company, $product, $unit, '10', '100');
    $posted = app(RevenueService::class)->postRevenue($revenue, [
        'warehouse_id' => $warehouse->id,
        'receivable_account_id' => $receivableAccount->id,
        'revenue_account_id' => $revenueAccount->id,
    ]);

    $payment = app(PaymentService::class)->recordCustomerReceipt($posted, [
        'amount' => '1000',
        'applied_amount' => '1000',
        'currency' => 'SYP',
        'financial_account_id' => $cashAccount->id,
        'receivable_account_id' => $receivableAccount->id,
    ]);

    $count = AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Payment::class)
        ->where('sourceable_id', $payment->id)
        ->where('status', 'posted')
        ->count();

    expect($count)->toBe(1);

    // Immutability prevents any replay.
    expect(fn () => $payment->update(['notes' => 'replay']))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Regression — existing purchase and payment workflows still work
// ---------------------------------------------------------------------------

it('existing purchase payment workflow is unaffected', function (): void {
    $company = Company::create(['name' => 'PurchaseCo '.uniqid()]);
    $user = User::factory()->create();
    $company->users()->attach($user);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);
    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-001',
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '500',
        'tax_total' => '0',
        'total' => '500',
        'base_subtotal' => '500',
        'base_tax_total' => '0',
        'base_total' => '500',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]);

        expect($payment->status)->toBe('posted');
        expect(bccomp(
            app(PaymentService::class)->calculateOutstandingForPurchase($purchase),
            '0', 10,
        ))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// PHASE 4 — RevenueService normalizeAmount (bcmath precision)
// ---------------------------------------------------------------------------

it('preserves large SYP revenue values without floating-point precision loss', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Big Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);
    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => '99999999999.5000',
    ]);

    $revenue->refresh();
    expect((string) $revenue->total)->toBe('99999999999.5000');
    expect((string) $revenue->base_total)->toBe('99999999999.5000');
});

it('rounds revenue values with more than four fractional digits using round-half-up', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Rnd Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);
    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => '100.45678',
    ]);

    $revenue->refresh();
    expect((string) $revenue->total)->toBe('100.4568');
});

it('rejects a non-numeric revenue unit price', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Bad Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);

    expect(fn () => $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => 'abc',
    ]))->toThrow(ValidationException::class);
});

it('rejects a negative revenue unit price', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Neg Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);

    expect(fn () => $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => '-100',
    ]))->toThrow(ValidationException::class);
});

it('handles a zero revenue unit price without float artifacts', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Free Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);
    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '5',
        'unit_price' => '0',
    ]);

    $revenue->refresh();
    expect((string) $revenue->total)->toBe('0.0000');
});

it('normalizes a normal SYP revenue value to four decimal places', function (): void {
    [$company, , , , , $product, $unit] = makeRevenueContext();
    $party = Party::create(['company_id' => $company->id, 'name' => 'Norm Cust']);
    $service = app(RevenueService::class);

    $revenue = $service->createDraftRevenue(['party_id' => $party->id, 'currency' => 'SYP']);
    $service->addRevenueItem($revenue, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '10',
        'unit_price' => '100',
    ]);

    $revenue->refresh();
    expect((string) $revenue->total)->toBe('1000.0000');
});
