<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingReconciliationService;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function subledgerAccount(Company $company, string $code, string $type = 'asset', string $currency = 'SYP'): FinancialAccount
{
    return FinancialAccount::create([
        'company_id' => $company->getKey(),
        'code' => $code.'-'.uniqid(),
        'name' => $code,
        'account_type' => $type,
        'currency' => $currency,
    ]);
}

function subledgerParty(Company $company, string $name): Party
{
    return Party::create([
        'company_id' => $company->getKey(),
        'code' => strtoupper(substr($name, 0, 3)).'-'.uniqid(),
        'name' => $name,
    ]);
}

function subledgerRevenue(Company $company, Party $party, FinancialAccount $receivable, string $amount, string $status = 'posted'): Revenue
{
    return Revenue::create([
        'company_id' => $company->getKey(),
        'party_id' => $party->getKey(),
        'receivable_account_id' => $receivable->getKey(),
        'revenue_number' => 'REV-'.uniqid(),
        'revenue_date' => now()->toDateString(),
        'status' => $status,
        'currency' => $company->base_currency,
        'exchange_rate' => '1',
        'base_currency' => $company->base_currency,
        'subtotal' => $amount,
        'tax_total' => '0',
        'total' => $amount,
        'base_subtotal' => $amount,
        'base_tax_total' => '0',
        'base_total' => $amount,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function postSubledgerJournal(Company $company, ?string $sourceType, ?int $sourceId, array $lines): AccountingJournal
{
    return app(CompanyContextService::class)->runForCompany($company, function () use ($company, $sourceType, $sourceId, $lines): AccountingJournal {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'sourceable_type' => $sourceType,
            'sourceable_id' => $sourceId,
            'journal_number' => 'SUB-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'Subledger reconciliation test',
        ], $company);

        foreach ($lines as $line) {
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $line['account']->getKey(),
                'party_id' => $line['party_id'] ?? null,
                'debit' => $line['debit'] ?? '0',
                'credit' => $line['credit'] ?? '0',
                'base_debit' => $line['base_debit'] ?? ($line['debit'] ?? '0'),
                'base_credit' => $line['base_credit'] ?? ($line['credit'] ?? '0'),
                'currency' => $line['currency'] ?? $company->base_currency,
                'exchange_rate' => '1',
                'base_currency' => $company->base_currency,
            ]);
        }

        return $accounting->postJournal($journal);
    });
}

function subledgerPayment(
    Company $company,
    Party $party,
    Revenue|Purchase $source,
    FinancialAccount $cash,
    string $direction,
    string $amount,
): Payment {
    return Payment::create([
        'company_id' => $company->getKey(),
        'party_id' => $party->getKey(),
        'financial_account_id' => $cash->getKey(),
        'payable_type' => $source::class,
        'payable_id' => $source->getKey(),
        'payment_number' => 'PAY-'.uniqid(),
        'direction' => $direction,
        'paid_at' => now(),
        'amount' => $amount,
        'currency' => $source->currency,
        'exchange_rate' => '1',
        'base_currency' => $company->base_currency,
        'base_amount' => $amount,
        'applied_amount' => $amount,
        'applied_currency' => $source->currency,
        'applied_exchange_rate' => '1',
        'applied_base_amount' => $amount,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
}

it('validates company-level mappings and prevents unsafe replacement', function () {
    $company = Company::create(['name' => 'Mapping company']);
    $otherCompany = Company::create(['name' => 'Other mapping company']);
    $receivables = subledgerAccount($company, 'AR');
    $replacement = subledgerAccount($company, 'AR2');
    $foreign = subledgerAccount($otherCompany, 'FOREIGN');
    $wrongType = subledgerAccount($company, 'LIAB', 'liability');
    $wrongCurrency = subledgerAccount($company, 'USD', 'asset', 'USD');
    $mapping = CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
        'financial_account_id' => $receivables->getKey(),
    ]);

    $mapping->financial_account_id = $replacement->getKey();
    $mapping->save();
    $party = subledgerParty($company, 'Customer');
    subledgerRevenue($company, $party, $replacement, '10');

    expect(fn () => CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
        'financial_account_id' => $receivables->getKey(),
    ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => CompanyAccountMapping::create([
            'company_id' => $company->getKey(),
            'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
            'financial_account_id' => $foreign->getKey(),
        ]))->toThrow(ValidationException::class)
        ->and(fn () => CompanyAccountMapping::create([
            'company_id' => $company->getKey(),
            'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
            'financial_account_id' => $wrongType->getKey(),
        ]))->toThrow(ValidationException::class)
        ->and(fn () => CompanyAccountMapping::create([
            'company_id' => $company->getKey(),
            'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
            'financial_account_id' => $wrongCurrency->getKey(),
        ]))->toThrow(ValidationException::class);

    $mapping->financial_account_id = $receivables->getKey();

    expect(fn () => $mapping->save())->toThrow(ValidationException::class);
});

it('reconciles posted customer receivables, excludes drafts and nets valid payment reversals', function () {
    $company = Company::create(['name' => 'Receivable company']);
    $customer = subledgerParty($company, 'Customer One');
    $receivables = subledgerAccount($company, 'AR');
    $revenueAccount = subledgerAccount($company, 'Sales', 'revenue');
    $cash = subledgerAccount($company, 'Cash');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
        'financial_account_id' => $receivables->getKey(),
    ]);
    $revenue = subledgerRevenue($company, $customer, $receivables, '100');
    $draft = subledgerRevenue($company, $customer, $receivables, '20', 'draft');
    postSubledgerJournal($company, Revenue::class, $revenue->getKey(), [
        ['account' => $receivables, 'debit' => '100', 'party_id' => $customer->getKey()],
        ['account' => $revenueAccount, 'credit' => '100', 'party_id' => $customer->getKey()],
    ]);

    $receipt = subledgerPayment($company, $customer, $revenue, $cash, 'inbound', '30');
    postSubledgerJournal($company, Payment::class, $receipt->getKey(), [
        ['account' => $cash, 'debit' => '30', 'party_id' => $customer->getKey()],
        ['account' => $receivables, 'credit' => '30', 'party_id' => $customer->getKey()],
    ]);
    $reversedReceipt = subledgerPayment($company, $customer, $revenue, $cash, 'inbound', '15');
    postSubledgerJournal($company, Payment::class, $reversedReceipt->getKey(), [
        ['account' => $cash, 'debit' => '15', 'party_id' => $customer->getKey()],
        ['account' => $receivables, 'credit' => '15', 'party_id' => $customer->getKey()],
    ]);
    app(CompanyContextService::class)->runForCompany(
        $company,
        fn () => app(PaymentService::class)->reversePayment($reversedReceipt, [], $company),
    );

    $missingJournalRevenue = subledgerRevenue($company, $customer, $receivables, '5');
    $report = app(AccountingReconciliationService::class)->reconcile($company)['customer_receivables'];
    $customerBalance = collect($report['party_balances'])->firstWhere('party_id', $customer->getKey());

    expect($report['operational_balance'])->toBe('75.0000')
        ->and($report['general_ledger_balance'])->toBe('70.0000')
        ->and($report['difference'])->toBe('5.0000')
        ->and($report['difference_classification'])->toBe('incomplete_data')
        ->and($report['discrepancies'])->toContain([
            'source_type' => Revenue::class,
            'source_id' => (int) $missingJournalRevenue->getKey(),
            'issue' => 'posted_revenue_missing_or_inconsistent_journal',
        ])
        ->and($customerBalance['operational_balance'])->toBe('75.0000')
        ->and($customerBalance['general_ledger_balance'])->toBe('70.0000')
        ->and($draft->fresh()->status)->toBe('draft')
        ->and($missingJournalRevenue->fresh()->status)->toBe('posted');
});

it('does not fabricate party-level GL balances when mapped journal lines omit the party dimension', function () {
    $company = Company::create(['name' => 'Dimension company']);
    $customer = subledgerParty($company, 'Undimensioned customer');
    $receivables = subledgerAccount($company, 'AR');
    $revenueAccount = subledgerAccount($company, 'Sales', 'revenue');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::CUSTOMER_RECEIVABLES,
        'financial_account_id' => $receivables->getKey(),
    ]);
    $revenue = subledgerRevenue($company, $customer, $receivables, '45');
    postSubledgerJournal($company, Revenue::class, $revenue->getKey(), [
        ['account' => $receivables, 'debit' => '45'],
        ['account' => $revenueAccount, 'credit' => '45', 'party_id' => $customer->getKey()],
    ]);

    $report = app(AccountingReconciliationService::class)->reconcile($company)['customer_receivables'];
    $customerBalance = collect($report['party_balances'])->firstWhere('party_id', $customer->getKey());

    expect($customerBalance['general_ledger_balance'])->toBeNull()
        ->and($customerBalance['difference'])->toBeNull()
        ->and(collect($report['limitations'])->contains(fn (string $limitation): bool => str_contains($limitation, 'party dimension')))->toBeTrue();
});

it('reconciles supplier purchases, payments, returns, and payable expenses without double counting', function () {
    $company = Company::create(['name' => 'Payable company']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->getKey());
    Auth::login($user);

    $supplier = subledgerParty($company, 'Supplier');
    $payables = subledgerAccount($company, 'AP', 'liability');
    $cash = subledgerAccount($company, 'Cash');
    $expenseAccount = subledgerAccount($company, 'Operating expense', 'expense');
    $offset = subledgerAccount($company, 'Purchase return offset', 'expense');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::SUPPLIER_PAYABLES,
        'financial_account_id' => $payables->getKey(),
    ]);

    $purchase = Purchase::create([
        'company_id' => $company->getKey(),
        'party_id' => $supplier->getKey(),
        'payable_account_id' => $payables->getKey(),
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => now()->toDateString(),
        'status' => 'posted',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '100',
        'tax_total' => '0',
        'total' => '100',
        'base_subtotal' => '100',
        'base_tax_total' => '0',
        'base_total' => '100',
    ]);
    postSubledgerJournal($company, Purchase::class, $purchase->getKey(), [
        ['account' => $expenseAccount, 'debit' => '100', 'party_id' => $supplier->getKey()],
        ['account' => $payables, 'credit' => '100', 'party_id' => $supplier->getKey()],
    ]);

    $payment = subledgerPayment($company, $supplier, $purchase, $cash, 'outbound', '25');
    postSubledgerJournal($company, Payment::class, $payment->getKey(), [
        ['account' => $payables, 'debit' => '25', 'party_id' => $supplier->getKey()],
        ['account' => $cash, 'credit' => '25', 'party_id' => $supplier->getKey()],
    ]);

    $supplierReturn = SupplierReturn::create([
        'company_id' => $company->getKey(),
        'original_purchase_id' => $purchase->getKey(),
        'party_id' => $supplier->getKey(),
        'payable_account_id' => $payables->getKey(),
        'return_number' => 'RET-'.uniqid(),
        'return_date' => now()->toDateString(),
        'status' => 'posted',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'total' => '10',
        'base_total' => '10',
        'inventory_cost_total' => '0',
        'variance_total' => '0',
        'reason' => 'Supplier credit',
        'posted_at' => now(),
    ]);
    $returnJournal = postSubledgerJournal($company, SupplierReturn::class, $supplierReturn->getKey(), [
        ['account' => $payables, 'debit' => '10', 'party_id' => $supplier->getKey()],
        ['account' => $offset, 'credit' => '10', 'party_id' => $supplier->getKey()],
    ]);
    DB::table('supplier_returns')->where('id', $supplierReturn->getKey())->update(['accounting_journal_id' => $returnJournal->getKey()]);

    $expense = app(ExpenseService::class)->createDraftExpense([
        'party_id' => $supplier->getKey(),
        'payable_account_id' => $payables->getKey(),
        'currency' => 'SYP',
        'description' => 'Supplier service',
    ], $company);
    app(ExpenseService::class)->addExpenseLine($expense, [
        'expense_account_id' => $expenseAccount->getKey(),
        'category' => 'Supplier service',
        'description' => 'Service',
        'quantity' => '1',
        'unit_price' => '15',
    ]);
    $expenseService = app(ExpenseService::class);
    $expenseService->approveExpense($expense);
    $expenseService->postExpense($expense->fresh());

    $report = app(AccountingReconciliationService::class)->reconcile($company)['supplier_payables'];

    expect($report['operational_balance'])->toBe('80.0000')
        ->and($report['general_ledger_balance'])->toBe('80.0000')
        ->and($report['difference'])->toBe('0.0000')
        ->and($report['status'])->toBe('consistent')
        ->and(collect($report['source_records'])->where('source_type', Expense::class))->toHaveCount(1);
});

it('leaves posted purchases with no persisted payable account unresolved', function () {
    $company = Company::create(['name' => 'Unresolved payable company']);
    $supplier = subledgerParty($company, 'Unresolved supplier');
    $payables = subledgerAccount($company, 'AP', 'liability');
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::SUPPLIER_PAYABLES,
        'financial_account_id' => $payables->getKey(),
    ]);

    $purchase = Purchase::create([
        'company_id' => $company->getKey(),
        'party_id' => $supplier->getKey(),
        'purchase_number' => 'PUR-'.uniqid(),
        'purchase_date' => now()->toDateString(),
        'status' => 'posted',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'base_currency' => 'SYP',
        'subtotal' => '35',
        'tax_total' => '0',
        'total' => '35',
        'base_subtotal' => '35',
        'base_tax_total' => '0',
        'base_total' => '35',
    ]);

    $report = app(AccountingReconciliationService::class)->reconcile($company)['supplier_payables'];

    expect($report['operational_balance'])->toBe('0.0000')
        ->and($report['difference_classification'])->toBe('incomplete_data')
        ->and($report['discrepancies'])->toContain([
            'source_type' => Purchase::class,
            'source_id' => (int) $purchase->getKey(),
            'issue' => 'purchase_payable_account_unresolved',
        ])
        ->and($report['limitations'])->toContain('Posted purchases without a persisted payable account cannot be assigned to a control account.');
});

it('compares valued WAC inventory to its mapped control account and leaves cash reconciliation unsupported', function () {
    $company = Company::create(['name' => 'Inventory reconciliation company']);
    $inventoryAccount = subledgerAccount($company, 'Inventory');
    $equity = subledgerAccount($company, 'Opening equity', 'equity');
    $unit = UnitOfMeasure::create([
        'company_id' => $company->getKey(),
        'code' => 'EA-'.uniqid(),
        'name' => 'Each',
    ]);
    $warehouse = Warehouse::create([
        'company_id' => $company->getKey(),
        'code' => 'WH-'.uniqid(),
        'name' => 'Main warehouse',
    ]);
    $product = Product::create([
        'company_id' => $company->getKey(),
        'unit_of_measure_id' => $unit->getKey(),
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Tracked product',
        'tracks_inventory' => true,
    ]);
    CompanyAccountMapping::create([
        'company_id' => $company->getKey(),
        'purpose' => CompanyAccountMapping::INVENTORY_ASSET,
        'financial_account_id' => $inventoryAccount->getKey(),
    ]);
    StockMovement::create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => 'opening',
        'direction' => 'in',
        'quantity' => '5',
        'base_cost_amount' => '100',
        'base_unit_cost' => '20',
        'occurred_at' => now(),
    ]);
    postSubledgerJournal($company, null, null, [
        ['account' => $inventoryAccount, 'debit' => '100'],
        ['account' => $equity, 'credit' => '100'],
    ]);

    $report = app(AccountingReconciliationService::class)->reconcile($company);

    expect($report['inventory']['supported'])->toBeTrue()
        ->and($report['inventory']['operational_balance'])->toBe('100.0000')
        ->and($report['inventory']['general_ledger_balance'])->toBe('100.0000')
        ->and($report['inventory']['difference'])->toBe('0.0000')
        ->and($report['inventory']['product_balances'][0]['quantity'])->toBe('5.0000')
        ->and($report['inventory']['warehouse_balances'][0]['inventory_value'])->toBeNull()
        ->and($report['cash_and_bank']['status'])->toBe('unsupported')
        ->and($report['cash_and_bank']['supported'])->toBeFalse();
});
