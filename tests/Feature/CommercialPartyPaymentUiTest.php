<?php

use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * @return array{company: Company, user: User, accounts: array<string, FinancialAccount>}
 */
function phase8PartyPaymentContext(): array
{
    $company = Company::create(['name' => 'Phase 8 Shop '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $accounts = [];
    foreach ([
        'cash_syp' => ['asset', 'SYP'],
        'cash_usd' => ['asset', 'USD'],
        'receivable_syp' => ['asset', 'SYP'],
        'receivable_usd' => ['asset', 'USD'],
        'payable_syp' => ['liability', 'SYP'],
        'payable_usd' => ['liability', 'USD'],
        'revenue_syp' => ['revenue', 'SYP'],
        'inventory_syp' => ['asset', 'SYP'],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
            'is_active' => true,
        ]);
    }

    return compact('company', 'user', 'accounts');
}

function phase8PartyInvoice(array $context, Party $party, string $kind, string $total, string $currency = 'SYP'): Revenue|Purchase
{
    $baseTotal = $currency === 'USD' ? bcmul($total, '12000', 4) : $total;
    $attributes = [
        'company_id' => $context['company']->getKey(),
        'party_id' => $party->getKey(),
        'status' => 'posted',
        'currency' => $currency,
        'exchange_rate' => $currency === 'USD' ? '12000' : '1',
        'base_currency' => 'SYP',
        'subtotal' => $total,
        'tax_total' => '0',
        'total' => $total,
        'base_subtotal' => $baseTotal,
        'base_tax_total' => '0',
        'base_total' => $baseTotal,
    ];

    if ($kind === 'customer') {
        return Revenue::create([
            ...$attributes,
            'receivable_account_id' => $context['accounts'][$currency === 'USD' ? 'receivable_usd' : 'receivable_syp']->getKey(),
            'revenue_number' => 'REV-'.uniqid(),
            'revenue_date' => '2026-11-01',
        ]);
    }

    return Purchase::create([
        ...$attributes,
        'payable_account_id' => $context['accounts'][$currency === 'USD' ? 'payable_usd' : 'payable_syp']->getKey(),
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => '2026-11-01',
    ]);
}

function phase8PostPartyPayment(array $context, Revenue|Purchase $invoice, string $kind, string $amount, ?string $date = null): Payment
{
    $currency = strtoupper((string) $invoice->currency);
    $attributes = [
        'party_id' => $invoice->party_id,
        'amount' => $amount,
        'currency' => $currency,
        'exchange_rate' => $invoice->exchange_rate,
        'financial_account_id' => $context['accounts'][$currency === 'USD' ? 'cash_usd' : 'cash_syp']->getKey(),
        'paid_at' => $date ?? '2026-11-02',
        'reference' => 'REF-'.uniqid(),
    ];

    return $kind === 'customer'
        ? app(PaymentService::class)->recordCustomerReceipt($invoice, $attributes)
        : app(PaymentService::class)->recordSupplierPayment($invoice, $attributes);
}

it('creates and edits customers and suppliers while isolating company records', function (): void {
    $context = phase8PartyPaymentContext();
    $this->actingAs($context['user'])->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en']);

    $this->post(route('customers.store'), [
        'name' => 'Retail customer',
        'code' => 'CUS-001',
        'phone' => '+963 111 222',
        'tax_identifier' => 'TAX-1',
    ])->assertRedirect();

    $customer = Party::query()->where('code', 'CUS-001')->firstOrFail();
    expect($customer->partyTypes()->where('code', 'customer')->exists())->toBeTrue();

    $this->put(route('customers.update', $customer), [
        'name' => 'Updated customer',
        'phone' => '+963 333 444',
    ])->assertRedirect(route('customers.show', $customer));
    expect($customer->fresh()->name)->toBe('Updated customer')
        ->and($customer->fresh()->phone)->toBe('+963 333 444');

    $this->post(route('suppliers.store'), ['name' => 'Wholesale supplier', 'code' => 'SUP-001'])
        ->assertRedirect();
    $supplier = Party::query()->where('code', 'SUP-001')->firstOrFail();
    expect($supplier->partyTypes()->where('code', 'supplier')->exists())->toBeTrue();
    $this->put(route('suppliers.update', $supplier), ['name' => 'Updated supplier', 'code' => 'SUP-001'])
        ->assertRedirect(route('suppliers.show', $supplier));
    expect($supplier->fresh()->name)->toBe('Updated supplier');

    $otherCompany = Company::create(['name' => 'Other tenant']);
    $otherParty = app(CompanyContextService::class)->runForCompany(
        $otherCompany,
        fn (): Party => Party::create(['company_id' => $otherCompany->getKey(), 'name' => 'Private customer']),
    );

    $this->get(route('customers.show', $otherParty))->assertNotFound();
    $this->post(route('customers.store'), ['name' => ''])->assertSessionHasErrors('name');
});

it('paginates customer lists and searches phone and identifier fields', function (): void {
    $context = phase8PartyPaymentContext();
    $type = PartyType::create([
        'company_id' => $context['company']->getKey(),
        'code' => 'customer',
        'name' => 'Customer',
        'is_active' => true,
    ]);
    for ($index = 1; $index <= 16; $index++) {
        $party = Party::create([
            'company_id' => $context['company']->getKey(),
            'name' => 'Customer '.$index,
            'phone' => $index === 16 ? '+963 555 0016' : null,
            'tax_identifier' => $index === 16 ? 'TAX-LOOKUP' : null,
        ]);
        $party->partyTypes()->attach($type);
    }

    $this->actingAs($context['user'])->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('customers.index', ['page' => 2]))
        ->assertOk()
        ->assertViewHas('parties', fn ($parties): bool => $parties->total() === 16 && $parties->count() === 1);

    $this->get(route('customers.index', ['search' => 'TAX-LOOKUP']))
        ->assertOk()
        ->assertSee('+963 555 0016')
        ->assertDontSee('Customer 1</a>');
});

it('records partial customer receipts and supplier payments through the payment service', function (): void {
    $context = phase8PartyPaymentContext();
    $customer = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'Customer']);
    $supplier = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'Supplier']);
    $sale = phase8PartyInvoice($context, $customer, 'customer', '100');
    $purchase = phase8PartyInvoice($context, $supplier, 'supplier', '80');

    $this->actingAs($context['user'])->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('payments.create'))
        ->assertOk()
        ->assertSee($sale->revenue_number)
        ->assertSee($purchase->purchase_number);

    $this->post(route('payments.store'), [
        'direction' => 'inbound',
        'payable_id' => $sale->getKey(),
        'party_id' => $customer->getKey(),
        'amount' => '40',
        'currency' => 'SYP',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        'paid_at' => '2026-11-03',
        'reference' => 'RCPT-1',
    ])->assertRedirect();

    $receipt = Payment::query()->where('party_id', $customer->getKey())->firstOrFail();
    expect(strtolower($receipt->direction))->toBe('inbound')
        ->and($receipt->status)->toBe('posted')
        ->and(bccomp(app(PaymentService::class)->calculateOutstandingForRevenue($sale->fresh()), '60', 4))->toBe(0);

    $this->get(route('payments.print', $receipt))->assertOk()->assertSee($customer->name)->assertSee('RCPT-1');

    $this->post(route('payments.store'), [
        'direction' => 'outbound',
        'payable_id' => $purchase->getKey(),
        'party_id' => $supplier->getKey(),
        'amount' => '25',
        'currency' => 'SYP',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        'paid_at' => '2026-11-04',
    ])->assertRedirect();

    $supplierPayment = Payment::query()->where('party_id', $supplier->getKey())->firstOrFail();
    expect(strtolower($supplierPayment->direction))->toBe('outbound')
        ->and(bccomp(app(PaymentService::class)->calculateOutstandingForPurchase($purchase->fresh()), '55', 4))->toBe(0);
});

it('reverses payments only through the service and filters payment history', function (): void {
    $context = phase8PartyPaymentContext();
    $customer = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'History customer']);
    $supplier = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'History supplier']);
    $sale = phase8PartyInvoice($context, $customer, 'customer', '100', 'USD');
    $purchase = phase8PartyInvoice($context, $supplier, 'supplier', '100');
    $receipt = phase8PostPartyPayment($context, $sale, 'customer', '10', '2026-11-05');
    phase8PostPartyPayment($context, $purchase, 'supplier', '20', '2026-11-06');

    $this->actingAs($context['user'])->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->post(route('payments.reverse', $receipt))
        ->assertRedirect(route('payments.show', $receipt));

    $receipt->refresh();
    expect($receipt->status)->toBe('reversed')
        ->and($receipt->reversedBy)->not->toBeNull()
        ->and($receipt->reversedBy->reversal_of_payment_id)->toBe($receipt->getKey());

    $this->get(route('payments.index', [
        'direction' => 'inbound',
        'party_id' => $customer->getKey(),
        'currency' => 'USD',
        'status' => 'reversed',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-30',
    ]))->assertOk()->assertSee($receipt->payment_number)->assertDontSee($purchase->purchase_number);

    $this->get(route('payments.print', $receipt))->assertOk()->assertSee($customer->name)->assertSee('Reversal of:');
});

it('shows customer and supplier statements with currency-separated balances and posted returns', function (): void {
    $context = phase8PartyPaymentContext();
    $customer = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'Statement customer']);
    $supplier = Party::create(['company_id' => $context['company']->getKey(), 'name' => 'Statement supplier']);
    $saleSyp = phase8PartyInvoice($context, $customer, 'customer', '100');
    $saleUsd = phase8PartyInvoice($context, $customer, 'customer', '20', 'USD');
    $purchaseSyp = phase8PartyInvoice($context, $supplier, 'supplier', '50');
    $purchaseUsd = phase8PartyInvoice($context, $supplier, 'supplier', '8', 'USD');
    phase8PostPartyPayment($context, $saleUsd, 'customer', '5');
    phase8PostPartyPayment($context, $purchaseUsd, 'supplier', '3');

    $customerReturn = CustomerReturn::create([
        'company_id' => $context['company']->getKey(),
        'original_revenue_id' => $saleSyp->getKey(),
        'party_id' => $customer->getKey(),
        'refund_account_id' => $context['accounts']['cash_syp']->getKey(),
        'revenue_account_id' => $context['accounts']['revenue_syp']->getKey(),
        'return_number' => 'CR-'.uniqid(),
        'return_date' => '2026-11-03',
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '10',
        'tax_total' => '0',
        'total' => '10',
        'base_subtotal' => '10',
        'base_tax_total' => '0',
        'base_total' => '10',
        'reason' => 'Returned goods',
    ]);
    $journal = app(AccountingService::class)->createDraftJournal([
        'sourceable_type' => CustomerReturn::class,
        'sourceable_id' => $customerReturn->getKey(),
        'description' => 'Test return',
    ], $context['company']);
    app(AccountingService::class)->addJournalLine($journal, [
        'financial_account_id' => $context['accounts']['revenue_syp']->getKey(),
        'debit' => '10',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    app(AccountingService::class)->addJournalLine($journal, [
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        'credit' => '10',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $journal = app(AccountingService::class)->postJournal($journal);
    $customerReturn->update(['accounting_journal_id' => $journal->getKey(), 'status' => 'posted', 'posted_at' => now()]);

    $supplierReturn = SupplierReturn::create([
        'company_id' => $context['company']->getKey(),
        'original_purchase_id' => $purchaseSyp->getKey(),
        'party_id' => $supplier->getKey(),
        'payable_account_id' => $context['accounts']['payable_syp']->getKey(),
        'inventory_asset_account_id' => $context['accounts']['inventory_syp']->getKey(),
        'return_number' => 'SR-'.uniqid(),
        'return_date' => '2026-11-03',
        'status' => 'draft',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'total' => '7',
        'base_total' => '7',
        'inventory_cost_total' => '7',
        'variance_total' => '0',
        'reason' => 'Returned goods',
    ]);
    $supplierJournal = app(AccountingService::class)->createDraftJournal([
        'sourceable_type' => SupplierReturn::class,
        'sourceable_id' => $supplierReturn->getKey(),
        'description' => 'Test supplier return',
    ], $context['company']);
    app(AccountingService::class)->addJournalLine($supplierJournal, [
        'financial_account_id' => $context['accounts']['payable_syp']->getKey(),
        'debit' => '7',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    app(AccountingService::class)->addJournalLine($supplierJournal, [
        'financial_account_id' => $context['accounts']['inventory_syp']->getKey(),
        'credit' => '7',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $supplierJournal = app(AccountingService::class)->postJournal($supplierJournal);
    $supplierReturn->update(['accounting_journal_id' => $supplierJournal->getKey(), 'status' => 'posted', 'posted_at' => now()]);

    $this->actingAs($context['user'])->withSession(['active_company_id' => $context['company']->getKey(), 'locale' => 'en'])
        ->get(route('customers.statement', $customer))
        ->assertOk()
        ->assertSee($customerReturn->return_number)
        ->assertViewHas('statement.balances.SYP', fn (string $balance): bool => bccomp($balance, '90', 4) === 0)
        ->assertViewHas('statement.balances.USD', fn (string $balance): bool => bccomp($balance, '15', 4) === 0);

    $this->get(route('suppliers.statement', $supplier))
        ->assertOk()
        ->assertSee($supplierReturn->return_number)
        ->assertViewHas('statement.balances.SYP', fn (string $balance): bool => bccomp($balance, '43', 4) === 0)
        ->assertViewHas('statement.balances.USD', fn (string $balance): bool => bccomp($balance, '5', 4) === 0);

    $this->get(route('customers.statement.print', $customer))->assertOk()->assertSee($customer->name)->assertSee('90.0000 SYP');
    $this->get(route('suppliers.statement.print', $supplier))->assertOk()->assertSee($supplier->name)->assertSee($purchaseSyp->purchase_number);
    $this->get(route('payments.print', Payment::query()->where('party_id', $supplier->getKey())->firstOrFail()))->assertOk();
    expect($purchaseUsd->currency)->toBe('USD');
});
