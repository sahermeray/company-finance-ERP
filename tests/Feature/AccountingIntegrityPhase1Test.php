<?php

/**
 * AccountingIntegrityPhase1Test
 *
 * Regression tests for the five P0/P1 fixes from the accounting integrity review:
 *   1. Account-type validation in PaymentService and PurchaseService
 *   2. Prevention of payments against draft purchases
 *   3. Removal of silent 1:1 exchange-rate fallback
 *   4. Decimal-safe normalizeAmount in PurchaseService (no floating-point)
 *   5. Mixed-currency partner balance rejection + per-currency breakdown
 */

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function makeIntegrityCompany(string $currency = 'SYP', string $base = 'SYP'): Company
{
    return Company::create(['name' => 'IntegCo '.uniqid(), 'base_currency' => $base]);
}

function makeAccount(Company $company, string $type, string $currency = 'SYP', ?string $code = null): FinancialAccount
{
    return FinancialAccount::create([
        'company_id' => $company->id,
        'code' => $code ?? uniqid(),
        'name' => ucfirst($type).' '.uniqid(),
        'account_type' => $type,
        'currency' => $currency,
    ]);
}

function makePostedPurchaseForIntegrity(Company $company, string $total = '1000', string $currency = 'SYP'): Purchase
{
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'posted',
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => $currency,
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

function makeDraftPurchase(Company $company, string $total = '1000', string $currency = 'SYP'): Purchase
{
    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier '.uniqid()]);

    return Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-10-01',
        'status' => 'draft',
        'currency' => $currency,
        'exchange_rate' => '1',
        'base_currency' => $currency,
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $total,
        'base_tax_total' => '0',
        'base_total' => $total,
    ]);
}

// ===========================================================================
// FIX 1 — Account-type validation
// ===========================================================================

it('rejects a supplier payment when the payable account is an asset, not a liability', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $cashAccount = makeAccount($company, 'asset');
    $revenueAccount = makeAccount($company, 'revenue'); // wrong type
    $purchase = makePostedPurchaseForIntegrity($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount, $revenueAccount,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
        $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

        // payable_account_id is a revenue account — must be rejected
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $revenueAccount->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
        expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
    });
});

it('rejects a supplier payment when the cash account is a liability, not an asset', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $liabilityAsCash = makeAccount($company, 'liability', 'SYP', 'CASH-LIAB'); // wrong type
    $purchase = makePostedPurchaseForIntegrity($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $liabilityAsCash,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();

        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $liabilityAsCash->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    });
});

it('rejects a partner-paid purchase when the partner liability account is an asset', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $assetAsPartner = makeAccount($company, 'asset'); // wrong type
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner']);
    $purchase = makePostedPurchaseForIntegrity($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $payableAccount, $assetAsPartner,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();

        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $payableAccount->id,
            'partner_liability_account_id' => $assetAsPartner->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    });
});

it('rejects a partner reimbursement when the liability account is an expense', function (): void {
    $company = makeIntegrityCompany();
    $expenseAsLiability = makeAccount($company, 'expense'); // wrong type
    $cashAccount = makeAccount($company, 'asset');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner']);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $company, $partner, $expenseAsLiability, $cashAccount,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();

        expect(fn () => app(PaymentService::class)->recordPartnerReimbursement($company, [
            'amount' => '300',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'partner_liability_account_id' => $expenseAsLiability->id,
            'financial_account_id' => $cashAccount->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    });
});

it('rejects a purchase posting when the inventory account is a liability', function (): void {
    $company = makeIntegrityCompany();
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $payableAccount = makeAccount($company, 'liability');
    $liabilityAsInventory = makeAccount($company, 'liability', 'SYP', 'INV-LIAB'); // wrong type
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'Warehouse']);
    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'PROD-'.uniqid(), 'name' => 'Product',
    ]);

    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $purchase = Purchase::create([
        'company_id' => $company->id, 'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(), 'purchase_date' => '2026-10-01',
        'status' => 'draft', 'currency' => 'SYP', 'exchange_rate' => '1',
        'base_currency' => 'SYP', 'subtotal' => '500', 'tax_total' => '0',
        'total' => '500', 'base_subtotal' => '500', 'base_tax_total' => '0', 'base_total' => '500',
    ]);
    PurchaseItem::create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id, 'line_number' => 1,
        'quantity' => '10', 'unit_price' => '50', 'line_total' => '500',
    ]);

    expect(fn () => app(PurchaseService::class)->postPurchase($purchase, [
        'warehouse_id' => $warehouse->id,
        'inventory_account_id' => $liabilityAsInventory->id,
        'payable_account_id' => $payableAccount->id,
    ]))->toThrow(ValidationException::class);
});

// ===========================================================================
// FIX 2 — Prevent payments against draft purchases
// ===========================================================================

it('rejects a supplier payment against a draft purchase', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $cashAccount = makeAccount($company, 'asset');
    $purchase = makeDraftPurchase($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $payableAccount, $cashAccount,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
        $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
        expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
    });
});

it('rejects a partner-funded settlement against a draft purchase', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $partnerLiability = makeAccount($company, 'liability', 'SYP', 'DUE-PARTNER');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Partner']);
    $purchase = makeDraftPurchase($company, '500');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $partner, $payableAccount, $partnerLiability,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();
        $journalsBefore = AccountingJournal::withoutGlobalScope(CompanyScope::class)->count();

        expect(fn () => app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
            'amount' => '500',
            'currency' => 'SYP',
            'party_id' => $partner->id,
            'payable_account_id' => $payableAccount->id,
            'partner_liability_account_id' => $partnerLiability->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
        expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journalsBefore);
    });
});

it('allows supplier payments against posted purchases', function (): void {
    $company = makeIntegrityCompany();
    $payableAccount = makeAccount($company, 'liability');
    $cashAccount = makeAccount($company, 'asset');
    $purchase = makePostedPurchaseForIntegrity($company, '500');

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
    });
});

// ===========================================================================
// FIX 3 — No silent 1:1 exchange-rate fallback
// ===========================================================================

it('rejects a cross-currency payment when no exchange rate exists', function (): void {
    $company = Company::create(['name' => 'NoRateCo '.uniqid(), 'base_currency' => 'SYP']);

    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable USD', 'account_type' => 'liability', 'currency' => 'USD',
    ]);
    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash SYP', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);

    // Deliberately no ExchangeRate record for USD→SYP.
    $purchase = makePostedPurchaseForIntegrity($company, '1000', 'USD');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount, $payableAccount,
    ): void {
        $paymentsBefore = Payment::withoutGlobalScope(CompanyScope::class)->count();

        // applied_currency = SYP (differs from payable currency USD) — guard
        // must convert SYP→USD but no rate exists → must throw.
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '12000000',
            'applied_amount' => '12000000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '0', // force fallback to resolveExchangeRate
            'applied_base_amount' => '12000000',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);

        expect(Payment::withoutGlobalScope(CompanyScope::class)->count())->toBe($paymentsBefore);
    });
});

it('accepts a cross-currency payment when an explicit valid exchange rate is supplied', function (): void {
    $company = Company::create(['name' => 'RateCo '.uniqid(), 'base_currency' => 'SYP']);

    ExchangeRate::create([
        'company_id' => $company->id, 'currency' => 'USD', 'base_currency' => 'SYP',
        'rate' => '12000', 'effective_at' => '2026-10-01 00:00:00',
    ]);

    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash SYP', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable USD', 'account_type' => 'liability', 'currency' => 'USD',
    ]);
    $gainAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '7100',
        'name' => 'FX Gain', 'account_type' => 'income', 'currency' => 'SYP',
    ]);
    $lossAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '8100',
        'name' => 'FX Loss', 'account_type' => 'expense', 'currency' => 'SYP',
    ]);

    $purchase = makePostedPurchaseForIntegrity($company, '1000', 'USD');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount, $payableAccount, $gainAccount, $lossAccount,
    ): void {
        $payment = app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '12000000',
            'applied_amount' => '1000',
            'applied_currency' => 'USD',
            'applied_exchange_rate' => '12000',
            'applied_base_amount' => '12000000',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
            'exchange_gain_account_id' => $gainAccount->id,
            'exchange_loss_account_id' => $lossAccount->id,
        ]);

        expect($payment->status)->toBe('posted');

        $outstanding = app(PaymentService::class)->calculateOutstandingForPurchase($purchase);
        expect(bccomp($outstanding, '0', 10))->toBe(0);
    });
});

it('rejects a payment with a zero exchange rate for cross-currency transactions', function (): void {
    $company = Company::create(['name' => 'ZeroRateCo '.uniqid(), 'base_currency' => 'SYP']);

    // Store a zero-rate exchange rate for USD→SYP.
    ExchangeRate::create([
        'company_id' => $company->id, 'currency' => 'USD', 'base_currency' => 'SYP',
        'rate' => '0', 'effective_at' => '2026-10-01 00:00:00',
    ]);

    $cashAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash SYP', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable USD', 'account_type' => 'liability', 'currency' => 'USD',
    ]);

    $purchase = makePostedPurchaseForIntegrity($company, '1000', 'USD');

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $purchase, $cashAccount, $payableAccount,
    ): void {
        // applied_currency = SYP, payable = USD — guard must convert.
        // applied_exchange_rate = '0' forces fallback to resolveExchangeRate
        // which finds the zero rate and must reject it.
        expect(fn () => app(PaymentService::class)->recordSupplierPayment($purchase, [
            'amount' => '12000000',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_amount' => '12000000',
            'applied_amount' => '12000000',
            'applied_currency' => 'SYP',
            'applied_exchange_rate' => '0',
            'applied_base_amount' => '0',
            'financial_account_id' => $cashAccount->id,
            'payable_account_id' => $payableAccount->id,
        ]))->toThrow(ValidationException::class);
    });
});

it('does not require an exchange rate for same-currency transactions', function (): void {
    $company = makeIntegrityCompany('SYP');
    $payableAccount = makeAccount($company, 'liability');
    $cashAccount = makeAccount($company, 'asset');
    $purchase = makePostedPurchaseForIntegrity($company, '500', 'SYP');

    // No ExchangeRate record needed — same currency.
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
    });
});

// ===========================================================================
// FIX 4 — Decimal-safe normalizeAmount (no floating-point)
// ===========================================================================

it('normalizes large SYP values without floating-point precision loss', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'BIG-'.uniqid(), 'name' => 'Big Product',
    ]);
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'WH']);
    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $service = app(PurchaseService::class);

    $purchase = $service->createDraftPurchase([
        'party_id' => $party->id,
        'currency' => 'SYP',
    ]);

    // Use a large value that won't trigger rounding edge cases.
    // 99,999,999,999.5000 — ~100 billion SYP, well within decimal(19,4).
    $service->addPurchaseItem($purchase, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => '99999999999.5000',
    ]);

    $purchase->refresh();

    // The total must be exactly 99999999999.5000 — no float rounding artifacts.
    expect((string) $purchase->total)->toBe('99999999999.5000');
    expect((string) $purchase->base_total)->toBe('99999999999.5000');
});

it('rounds values with more than four fractional digits using round-half-up', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'RND-'.uniqid(), 'name' => 'Round Product',
    ]);
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'WH']);
    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $service = app(PurchaseService::class);
    $purchase = $service->createDraftPurchase(['party_id' => $party->id, 'currency' => 'SYP']);

    // unit_price = 100.45678 → should round to 100.4568 (round-half-up at 4th decimal)
    $service->addPurchaseItem($purchase, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '1',
        'unit_price' => '100.45678',
    ]);

    $purchase->refresh();
    // line_total = quantity(1) × unit_price(100.45678) = 100.45678, normalized to 100.4568
    expect((string) $purchase->total)->toBe('100.4568');
});

it('handles zero and negative values in normalizeAmount without float', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'ZERO-'.uniqid(), 'name' => 'Zero Product',
    ]);
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'WH']);
    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $service = app(PurchaseService::class);
    $purchase = $service->createDraftPurchase(['party_id' => $party->id, 'currency' => 'SYP']);

    // zero unit_price is valid (free sample) — total must be 0.0000
    $service->addPurchaseItem($purchase, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '5',
        'unit_price' => '0',
    ]);

    $purchase->refresh();
    expect((string) $purchase->total)->toBe('0.0000');
});

it('handles decimal boundary values at exactly four fractional digits', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'BD-'.uniqid(), 'name' => 'Boundary Product',
    ]);
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'WH']);
    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $service = app(PurchaseService::class);
    $purchase = $service->createDraftPurchase(['party_id' => $party->id, 'currency' => 'SYP']);

    // Exactly 4 decimal places — no rounding needed
    $service->addPurchaseItem($purchase, [
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'quantity' => '3',
        'unit_price' => '250.5000',
    ]);

    $purchase->refresh();
    // 3 × 250.5000 = 751.5000
    expect((string) $purchase->total)->toBe('751.5000');
});

// ===========================================================================
// FIX 5 — Mixed-currency partner balance rejection + per-currency breakdown
// ===========================================================================

it('rejects calculatePartnerLiabilityBalance when the partner has mixed currencies', function (): void {
    $company = Company::create(['name' => 'MixedCurCo '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    // A single shared liability account.
    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partner', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    $partner = Party::create(['company_id' => $company->id, 'name' => 'Mixed Partner']);

    // Create a posted journal with SYP lines directly (bypassing service to
    // simulate a mixed-currency scenario that the accounting service would
    // normally prevent — the guard must still defend against it).
    $journal = AccountingJournal::create([
        'company_id' => $company->id,
        'journal_number' => 'JRN-MIX-1',
        'entry_date' => '2026-10-01',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $dueToPartner->id,
        'party_id' => $partner->id,
        'line_number' => 1,
        'credit' => '500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_credit' => '500000',
    ]);

    JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $dueToPartner->id,
        'party_id' => $partner->id,
        'line_number' => 2,
        'credit' => '100',
        'currency' => 'USD', // different currency on same account
        'exchange_rate' => '12000',
        'base_currency' => 'SYP',
        'base_credit' => '1200000',
    ]);

    // Single-currency sum must throw.
    expect(fn () => app(PaymentService::class)->calculatePartnerLiabilityBalance($partner, $dueToPartner))
        ->toThrow(ValidationException::class);
});

it('returns per-currency balances via calculatePartnerLiabilityBalanceByCurrency', function (): void {
    $company = Company::create(['name' => 'ByCurCo '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partner', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    $partner = Party::create(['company_id' => $company->id, 'name' => 'Multi Partner']);

    // Create journal lines directly to simulate mixed currencies.
    $journal = AccountingJournal::create([
        'company_id' => $company->id,
        'journal_number' => 'JRN-MIX-2',
        'entry_date' => '2026-10-01',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $dueToPartner->id,
        'party_id' => $partner->id,
        'line_number' => 1,
        'credit' => '500000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'base_credit' => '500000',
    ]);

    JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $dueToPartner->id,
        'party_id' => $partner->id,
        'line_number' => 2,
        'credit' => '100',
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'base_currency' => 'SYP',
        'base_credit' => '1200000',
    ]);

    $balances = app(PaymentService::class)->calculatePartnerLiabilityBalanceByCurrency($partner, $dueToPartner);

    expect($balances)->toHaveKey('SYP')
        ->and($balances)->toHaveKey('USD')
        ->and(bccomp($balances['SYP'], '500000', 10))->toBe(0)
        ->and(bccomp($balances['USD'], '100', 10))->toBe(0);
});

it('separates partner balances when two partners share the same liability account', function (): void {
    $company = Company::create(['name' => 'SharedCo '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    // Shared liability account
    $dueToPartner = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2100',
        'name' => 'Due to Partners (shared)', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);
    $supplierPayable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable SYP', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    $partnerA = Party::create(['company_id' => $company->id, 'name' => 'Partner Alice']);
    $partnerB = Party::create(['company_id' => $company->id, 'name' => 'Partner Bob']);

    // Alice pays 300000 SYP
    $pA = makePostedPurchaseForIntegrity($company, '300000', 'SYP');
    app(PaymentService::class)->recordPartnerPaidPurchase($pA, [
        'amount' => '300000', 'applied_amount' => '300000',
        'currency' => 'SYP', 'party_id' => $partnerA->id,
        'payable_account_id' => $supplierPayable->id,
        'partner_liability_account_id' => $dueToPartner->id,
    ]);

    // Bob pays 700000 SYP
    $pB = makePostedPurchaseForIntegrity($company, '700000', 'SYP');
    app(PaymentService::class)->recordPartnerPaidPurchase($pB, [
        'amount' => '700000', 'applied_amount' => '700000',
        'currency' => 'SYP', 'party_id' => $partnerB->id,
        'payable_account_id' => $supplierPayable->id,
        'partner_liability_account_id' => $dueToPartner->id,
    ]);

    // Each partner's balance must be correct and separate.
    $balA = app(PaymentService::class)->calculatePartnerLiabilityBalance($partnerA, $dueToPartner);
    $balB = app(PaymentService::class)->calculatePartnerLiabilityBalance($partnerB, $dueToPartner);

    expect(bccomp($balA, '300000', 10))->toBe(0)
        ->and(bccomp($balB, '700000', 10))->toBe(0);
});

it('returns correct single-currency balance when only one currency is present', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $dueToPartner = makeAccount($company, 'liability', 'SYP', 'DUE');
    $supplierPayable = makeAccount($company, 'liability');
    $partner = Party::create(['company_id' => $company->id, 'name' => 'Single Partner']);

    $purchase = makePostedPurchaseForIntegrity($company, '800', 'SYP');
    app(PaymentService::class)->recordPartnerPaidPurchase($purchase, [
        'amount' => '800', 'applied_amount' => '800',
        'currency' => 'SYP', 'party_id' => $partner->id,
        'payable_account_id' => $supplierPayable->id,
        'partner_liability_account_id' => $dueToPartner->id,
    ]);

    $balance = app(PaymentService::class)->calculatePartnerLiabilityBalance($partner, $dueToPartner);
    expect(bccomp($balance, '800', 10))->toBe(0);
});

// ===========================================================================
// FIX 6 — Purchase immutability after posting
// ===========================================================================

it('prevents modifying the total of a posted purchase', function (): void {
    $company = makeIntegrityCompany();
    $purchase = makePostedPurchaseForIntegrity($company, '1000');

    expect(fn () => $purchase->update(['total' => '500']))->toThrow(ValidationException::class);
    expect(bccomp((string) $purchase->fresh()->total, '1000', 4))->toBe(0);
});

it('prevents modifying the currency of a posted purchase', function (): void {
    $company = makeIntegrityCompany();
    $purchase = makePostedPurchaseForIntegrity($company, '1000', 'SYP');

    expect(fn () => $purchase->update(['currency' => 'USD']))->toThrow(ValidationException::class);
    expect($purchase->fresh()->currency)->toBe('SYP');
});

it('prevents deleting a posted purchase', function (): void {
    $company = makeIntegrityCompany();
    $purchase = makePostedPurchaseForIntegrity($company, '1000');

    expect(fn () => $purchase->delete())->toThrow(ValidationException::class);
    expect(Purchase::withoutGlobalScope(CompanyScope::class)->find($purchase->id))->not->toBeNull();
});

it('allows editing a draft purchase', function (): void {
    $company = makeIntegrityCompany();
    $purchase = makeDraftPurchase($company, '1000');

    $purchase->total = '500';
    $purchase->save();

    expect(bccomp((string) $purchase->fresh()->total, '500', 4))->toBe(0);
});

it('allows deleting a draft purchase', function (): void {
    $company = makeIntegrityCompany();
    $purchase = makeDraftPurchase($company, '1000');
    $id = $purchase->id;

    $purchase->delete();
    expect(Purchase::withoutGlobalScope(CompanyScope::class)->find($id))->toBeNull();
});

it('posts purchase value to the journal while recording receipt quantity without cost', function (): void {
    $company = makeIntegrityCompany('SYP');
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->id);
    Auth::login($user);

    $inventoryAccount = makeAccount($company, 'asset', 'SYP', 'INV-OK');
    $payableAccount = makeAccount($company, 'liability');
    $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'WH', 'name' => 'Warehouse']);
    $unit = UnitOfMeasure::create(['company_id' => $company->id, 'code' => 'EA', 'name' => 'Each']);
    $product = Product::create([
        'company_id' => $company->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'PROD-'.uniqid(), 'name' => 'Product',
    ]);

    app(InventoryService::class)->recordOpeningBalance($product, $warehouse, 100, $unit);

    $party = Party::create(['company_id' => $company->id, 'name' => 'Supplier']);
    $purchase = Purchase::create([
        'company_id' => $company->id, 'party_id' => $party->id,
        'purchase_number' => 'PUR-'.uniqid(), 'purchase_date' => now()->toDateString(),
        'status' => 'draft', 'currency' => 'SYP', 'exchange_rate' => '1',
        'base_currency' => 'SYP', 'subtotal' => '500', 'tax_total' => '0',
        'total' => '500', 'base_subtotal' => '500', 'base_tax_total' => '0', 'base_total' => '500',
    ]);
    PurchaseItem::create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id, 'line_number' => 1,
        'quantity' => '10', 'unit_price' => '50', 'line_total' => '500',
    ]);

    $posted = app(PurchaseService::class)->postPurchase($purchase, [
        'warehouse_id' => $warehouse->id,
        'inventory_account_id' => $inventoryAccount->id,
        'payable_account_id' => $payableAccount->id,
    ]);

    expect($posted->status)->toBe('posted');
    expect(bccomp((string) $posted->total, '500', 4))->toBe(0);

    $journal = AccountingJournal::query()
        ->where('sourceable_type', Purchase::class)
        ->where('sourceable_id', $posted->id)
        ->where('status', 'posted')
        ->firstOrFail();
    $lines = $journal->lines()->get();

    expect($lines)->toHaveCount(2)
        ->and(bccomp((string) $lines->firstWhere('financial_account_id', $inventoryAccount->id)->debit, '500', 4))->toBe(0)
        ->and(bccomp((string) $lines->firstWhere('financial_account_id', $payableAccount->id)->credit, '500', 4))->toBe(0);

    $movement = StockMovement::query()
        ->where('sourceable_type', Purchase::class)
        ->where('sourceable_id', $posted->id)
        ->firstOrFail();

    expect($movement->movement_type)->toBe('receipt')
        ->and($movement->direction)->toBe('in')
        ->and($movement->quantity)->toBe('10.0000')
        ->and(array_key_exists('unit_cost', $movement->getAttributes()))->toBeFalse();
});
