<?php

use App\Models\AccountingJournal;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('associates company members, inventory, parties, projects, attachments, and audit records', function () {
    $company = Company::create(['name' => 'Northwind Works']);
    $user = User::factory()->create();
    $company->users()->attach($user);

    $category = ProductCategory::create([
        'company_id' => $company->id,
        'name' => 'Materials',
    ]);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $product = Product::create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'MAT-001',
        'name' => 'Fastener',
    ]);
    $warehouse = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'MAIN',
        'name' => 'Main Warehouse',
    ]);
    $party = Party::create([
        'company_id' => $company->id,
        'name' => 'Alex Morgan',
    ]);
    $partyType = PartyType::create([
        'company_id' => $company->id,
        'code' => 'EMPLOYEE',
        'name' => 'Employee',
    ]);
    $party->partyTypes()->attach($partyType);
    $project = Project::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'code' => 'JOB-001',
        'name' => 'Workshop fit-out',
    ]);
    $movement = StockMovement::create([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'project_id' => $project->id,
        'party_id' => $party->id,
        'created_by' => $user->id,
        'movement_type' => 'opening_balance',
        'direction' => 'in',
        'quantity' => 12.5,
        'occurred_at' => '2026-10-06 09:00:00',
    ]);
    $attachment = Attachment::create([
        'company_id' => $company->id,
        'uploaded_by' => $user->id,
        'path' => 'records/quote.pdf',
        'original_filename' => 'quote.pdf',
        'size_bytes' => 512,
    ]);
    $product->attachments()->attach($attachment);
    $project->attachments()->attach($attachment);
    $auditLog = AuditLog::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'auditable_type' => Product::class,
        'auditable_id' => $product->id,
        'action' => 'created',
        'new_values' => ['sku' => 'MAT-001'],
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $attachment,
        $auditLog,
        $company,
        $movement,
        $party,
        $partyType,
        $product,
        $project,
        $user,
        $warehouse,
    ): void {
        $this->assertModelExists($company);
        $this->assertModelExists($product);
        $this->assertModelExists($warehouse);
        $this->assertModelExists($party);
        $this->assertModelExists($project);
        $this->assertModelExists($movement);
        $this->assertModelExists($auditLog);
        expect($user->companies()->whereKey($company->id)->exists())->toBeTrue();
        expect($party->partyTypes()->whereKey($partyType->id)->exists())->toBeTrue();
        expect($movement->product->is($product))->toBeTrue();
        expect($movement->warehouse->is($warehouse))->toBeTrue();
        expect($movement->creator->is($user))->toBeTrue();
        expect($attachment->products()->whereKey($product->id)->exists())->toBeTrue();
        expect($attachment->projects()->whereKey($project->id)->exists())->toBeTrue();
        expect($auditLog->auditable->is($product))->toBeTrue();
        expect($auditLog->new_values)->toBe(['sku' => 'MAT-001']);
    });
});

it('links purchases, revenues, expenses, payments, and their projects', function () {
    $company = Company::create(['name' => 'Contoso Construction']);
    $party = Party::create([
        'company_id' => $company->id,
        'name' => 'Building Supplier',
    ]);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $product = Product::create([
        'company_id' => $company->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'BRICK-001',
        'name' => 'Brick',
    ]);
    $project = Project::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'name' => 'Site A',
    ]);
    $expenseAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '6100',
        'name' => 'Materials Expense',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);
    $purchase = Purchase::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'project_id' => $project->id,
        'purchase_number' => 'PUR-001',
        'purchase_date' => '2026-10-06',
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_subtotal' => 25,
        'base_tax_total' => 0,
        'base_total' => 25,
    ]);
    $purchaseItem = PurchaseItem::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'line_number' => 1,
        'quantity' => 10,
        'unit_price' => 2.5,
        'line_total' => 25,
    ]);
    $revenue = Revenue::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'project_id' => $project->id,
        'revenue_number' => 'REV-001',
        'revenue_date' => '2026-10-06',
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_subtotal' => 20,
        'base_tax_total' => 0,
        'base_total' => 20,
    ]);
    $revenueItem = RevenueItem::create([
        'revenue_id' => $revenue->id,
        'product_id' => $product->id,
        'unit_of_measure_id' => $unit->id,
        'line_number' => 1,
        'quantity' => 5,
        'unit_price' => 4,
        'line_total' => 20,
    ]);
    $expense = Expense::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'project_id' => $project->id,
        'financial_account_id' => $expenseAccount->id,
        'expense_number' => 'EXP-001',
        'expense_date' => '2026-10-06',
        'amount' => 25,
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_amount' => 25,
        'description' => 'Site materials',
    ]);
    $payment = Payment::create([
        'company_id' => $company->id,
        'party_id' => $party->id,
        'project_id' => $project->id,
        'financial_account_id' => $expenseAccount->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchase->id,
        'direction' => 'outbound',
        'paid_at' => '2026-10-06 10:00:00',
        'amount' => 25,
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_amount' => 25,
        'applied_amount' => 25,
        'applied_currency' => 'SYP',
        'applied_exchange_rate' => 1,
        'applied_base_amount' => 25,
    ]);
    $attachment = Attachment::create([
        'company_id' => $company->id,
        'path' => 'records/invoice.pdf',
        'original_filename' => 'invoice.pdf',
        'size_bytes' => 256,
    ]);
    $purchase->attachments()->attach($attachment);

    app(CompanyContextService::class)->runForCompany($company, function () use (
        $attachment,
        $expense,
        $party,
        $payment,
        $project,
        $purchase,
        $purchaseItem,
        $revenue,
        $revenueItem,
    ): void {
        $this->assertModelExists($purchase);
        $this->assertModelExists($purchaseItem);
        $this->assertModelExists($revenue);
        $this->assertModelExists($revenueItem);
        $this->assertModelExists($expense);
        $this->assertModelExists($payment);
        expect($purchaseItem->purchase->is($purchase))->toBeTrue();
        expect($purchase->party->is($party))->toBeTrue();
        expect($revenueItem->revenue->is($revenue))->toBeTrue();
        expect($expense->project->is($project))->toBeTrue();
        expect($payment->payable->is($purchase))->toBeTrue();
        expect($project->payments()->whereKey($payment->id)->exists())->toBeTrue();
        expect($attachment->purchases()->whereKey($purchase->id)->exists())->toBeTrue();
    });
});

it('associates double-entry journal lines with accounts, parties, and projects', function () {
    $company = Company::create(['name' => 'Fabrikam Services']);
    $party = Party::create([
        'company_id' => $company->id,
        'name' => 'Employee One',
    ]);
    $project = Project::create([
        'company_id' => $company->id,
        'name' => 'Service contract',
    ]);
    $expenseAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '6000',
        'name' => 'Operating Expense',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);
    $payableAccount = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2100',
        'name' => 'Payable to Employees',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);
    $journal = AccountingJournal::create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'journal_number' => 'JRN-001',
        'entry_date' => '2026-10-06',
        'description' => 'Employee-paid project materials',
    ]);
    $debitLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $expenseAccount->id,
        'project_id' => $project->id,
        'line_number' => 1,
        'debit' => 1000,
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_debit' => 1000,
        'base_credit' => 0,
    ]);
    $creditLine = JournalLine::create([
        'accounting_journal_id' => $journal->id,
        'financial_account_id' => $payableAccount->id,
        'party_id' => $party->id,
        'project_id' => $project->id,
        'line_number' => 2,
        'credit' => 1000,
        'currency' => 'SYP',
        'exchange_rate' => 1,
        'base_currency' => 'SYP',
        'base_debit' => 0,
        'base_credit' => 1000,
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use (

        $creditLine,
        $debitLine,
        $expenseAccount,
        $journal,
        $party,
        $payableAccount,
    ): void {
        $this->assertModelExists($journal);
        $this->assertModelExists($debitLine);
        $this->assertModelExists($creditLine);
        expect($journal->lines)->toHaveCount(2);
        expect($debitLine->financialAccount->is($expenseAccount))->toBeTrue();
        expect($creditLine->financialAccount->is($payableAccount))->toBeTrue();
        expect($creditLine->party->is($party))->toBeTrue();
        expect($creditLine->credit)->toBe('1000.0000');
    });
});

it('enforces product SKU uniqueness within a company', function () {
    $company = Company::create(['name' => 'Adventure Works']);
    $unit = UnitOfMeasure::create([
        'company_id' => $company->id,
        'code' => 'EA',
        'name' => 'Each',
    ]);
    $productAttributes = [
        'company_id' => $company->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-001',
        'name' => 'Test product',
    ];
    Product::create($productAttributes);

    $this->expectException(QueryException::class);

    Product::create($productAttributes);
});

it('rejects stock movements that reference a nonexistent product', function () {
    $company = Company::create(['name' => 'Tailspin Toys']);
    $warehouse = Warehouse::create([
        'company_id' => $company->id,
        'name' => 'Central',
    ]);

    $this->expectException(ValidationException::class);

    StockMovement::create([
        'company_id' => $company->id,
        'product_id' => 999999,
        'warehouse_id' => $warehouse->id,
        'movement_type' => 'opening_balance',
        'direction' => 'in',
        'quantity' => 1,
        'occurred_at' => '2026-10-06 10:00:00',
    ]);
});
