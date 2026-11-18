<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{company: Company, user: User, accounts: array<string, FinancialAccount>}
 */
function accountingUiSetUp(string $baseCurrency = 'SYP'): array
{
    $company = Company::query()->create([
        'name' => 'Accounting UI company '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $accounts = [];
    foreach ([
        'cash' => ['asset', 'SYP'],
        'cash_usd' => ['asset', 'USD'],
        'receivable' => ['asset', 'SYP'],
        'payable' => ['liability', 'SYP'],
        'expense' => ['expense', 'SYP'],
        'revenue' => ['revenue', 'SYP', 'revenue'],
        'equity' => ['equity', 'SYP'],
    ] as $key => $config) {
        [$type, $currency] = $config;
        $accounts[$key] = FinancialAccount::query()->create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
            'is_active' => true,
            'statement_classification' => $config[2] ?? null,
        ]);
    }

    return compact('company', 'user', 'accounts');
}

function accountingUiSession(Company $company): array
{
    return ['active_company_id' => (int) $company->getKey(), 'locale' => 'en'];
}

function accountingUiActAs(User $user, Company $company): void
{
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());
}

/**
 * Post a balanced two-line journal with the given sourceable.
 */
function accountingUiPostJournal(Company $company, array $accounts, string $debitKey, string $creditKey, string $amount = '100.0000', ?string $sourceType = null, ?int $sourceId = null): AccountingJournal
{
    return app(CompanyContextService::class)->runForCompany($company, function () use ($company, $accounts, $debitKey, $creditKey, $amount, $sourceType, $sourceId): AccountingJournal {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'sourceable_type' => $sourceType,
            'sourceable_id' => $sourceId,
            'entry_date' => now()->toDateString(),
            'description' => 'Test journal entry',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $accounts[$debitKey]->getKey(),
            'debit' => $amount,
            'credit' => '0',
            'currency' => 'SYP',
            'base_currency' => 'SYP',
            'exchange_rate' => '1',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $accounts[$creditKey]->getKey(),
            'debit' => '0',
            'credit' => $amount,
            'currency' => 'SYP',
            'base_currency' => 'SYP',
            'exchange_rate' => '1',
        ]);

        return $accounting->postJournal($journal);
    });
}

// ---------------------------------------------------------------------------
// Journal listing
// ---------------------------------------------------------------------------

it('lists accounting journals for the active company', function (): void {
    $ctx = accountingUiSetUp();
    accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue');

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index'))
        ->assertOk()
        ->assertSee('Accounting journals');
});

it('paginates journals', function (): void {
    $ctx = accountingUiSetUp();
    for ($i = 0; $i < 25; $i++) {
        accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue', '1.0000');
    }

    $response = $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index'));

    $response->assertOk();
    // With 25 journals and 20 per page, the pagination links must include page 2.
    expect($response->getContent())->toContain('page=2');
});

it('filters journals by date range', function (): void {
    $ctx = accountingUiSetUp();
    $old = app(CompanyContextService::class)->runForCompany($ctx['company'], function () use ($ctx): AccountingJournal {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'entry_date' => '2020-01-01',
            'description' => 'Old journal',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $ctx['accounts']['cash']->getKey(),
            'debit' => '10.0000', 'credit' => '0',
            'currency' => 'SYP', 'base_currency' => 'SYP', 'exchange_rate' => '1',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $ctx['accounts']['revenue']->getKey(),
            'debit' => '0', 'credit' => '10.0000',
            'currency' => 'SYP', 'base_currency' => 'SYP', 'exchange_rate' => '1',
        ]);

        return $accounting->postJournal($journal);
    });

    $recent = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue', '20.0000');

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index', ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString()]))
        ->assertOk()
        ->assertSee($recent->journal_number)
        ->assertDontSee($old->journal_number);
});

it('filters journals by status', function (): void {
    $ctx = accountingUiSetUp();
    $posted = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue');

    $draft = app(CompanyContextService::class)->runForCompany($ctx['company'], function (): AccountingJournal {
        return app(AccountingService::class)->createDraftJournal([
            'entry_date' => now()->toDateString(),
            'description' => 'Draft only',
        ]);
    });

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index', ['status' => 'posted']))
        ->assertOk()
        ->assertSee($posted->journal_number)
        ->assertDontSee($draft->journal_number);
});

it('filters journals by source type', function (): void {
    $ctx = accountingUiSetUp();

    // Create a real Expense that generates a journal with Expense::class as sourceable_type.
    $expense = app(ExpenseService::class)->createDraftExpense([
        'expense_date' => now()->toDateString(),
        'description' => 'Test expense',
        'currency' => 'SYP',
        'payment_financial_account_id' => $ctx['accounts']['cash']->getKey(),
    ], $ctx['company']);
    app(ExpenseService::class)->addExpenseLine($expense, [
        'expense_account_id' => $ctx['accounts']['expense']->getKey(),
        'category' => 'Test',
        'description' => 'Test line',
        'quantity' => '1',
        'unit_price' => '50',
    ]);
    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);
    $expenseJournal = $expense->fresh()->postedJournal;

    // Create a generic journal with no sourceable.
    $genericJournal = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue');

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index', ['source_type' => Expense::class]))
        ->assertOk()
        ->assertSee($expenseJournal->journal_number)
        ->assertDontSee($genericJournal->journal_number);
});

it('searches journals by number', function (): void {
    $ctx = accountingUiSetUp();
    $journal = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue');

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.index', ['search' => $journal->journal_number]))
        ->assertOk()
        ->assertSee($journal->journal_number);
});

// ---------------------------------------------------------------------------
// Journal details
// ---------------------------------------------------------------------------

it('shows the journal details page with lines and balance check', function (): void {
    $ctx = accountingUiSetUp();
    $journal = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue', '150.0000');

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.show', $journal))
        ->assertOk()
        ->assertSee($journal->journal_number)
        ->assertSee($ctx['accounts']['cash']->code)
        ->assertSee($ctx['accounts']['revenue']->code)
        ->assertSee('Balanced');
});

it('shows source document link when a routed source exists', function (): void {
    $ctx = accountingUiSetUp();

    // Create a real Expense — its posted journal has Expense::class as sourceable_type,
    // and the Expense show route exists at expenses.show.
    $expense = app(ExpenseService::class)->createDraftExpense([
        'expense_date' => now()->toDateString(),
        'description' => 'Source doc test expense',
        'currency' => 'SYP',
        'payment_financial_account_id' => $ctx['accounts']['cash']->getKey(),
    ], $ctx['company']);
    app(ExpenseService::class)->addExpenseLine($expense, [
        'expense_account_id' => $ctx['accounts']['expense']->getKey(),
        'category' => 'Test',
        'description' => 'Test line',
        'quantity' => '1',
        'unit_price' => '50',
    ]);
    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);
    $journal = $expense->fresh()->postedJournal;

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.show', $journal))
        ->assertOk()
        ->assertSee('Expense');
});

it('shows reversal links when a journal has been reversed', function (): void {
    $ctx = accountingUiSetUp();
    $journal = accountingUiPostJournal($ctx['company'], $ctx['accounts'], 'cash', 'revenue', '100.0000');

    $reversal = app(CompanyContextService::class)->runForCompany($ctx['company'], function () use ($journal): AccountingJournal {
        return app(AccountingService::class)->reverseJournal($journal);
    });

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.journals.show', $journal))
        ->assertOk()
        ->assertSee('Reversed by')
        ->assertSee($reversal->journal_number);

    $this->get(route('accounting.journals.show', $reversal))
        ->assertOk()
        ->assertSee('Reversal of')
        ->assertSee($journal->journal_number);
});

// ---------------------------------------------------------------------------
// Company isolation
// ---------------------------------------------------------------------------

it('does not show journals from another company', function (): void {
    $ctx1 = accountingUiSetUp();

    // Create a journal in company1.
    $journal = accountingUiPostJournal($ctx1['company'], $ctx1['accounts'], 'cash', 'revenue');

    // Create company2 and switch to it.
    $company2 = Company::query()->create(['name' => 'Other accounting company', 'base_currency' => 'SYP']);
    $company2->users()->attach($ctx1['user']);
    accountingUiActAs($ctx1['user'], $company2);

    // Company2's journal listing should not include company1's journal.
    $this->actingAs($ctx1['user'])
        ->withSession(accountingUiSession($company2))
        ->get(route('accounting.journals.index'))
        ->assertOk()
        ->assertDontSee($journal->journal_number);

    // Accessing company1's journal from company2's context returns 404 (tenant scope).
    $this->actingAs($ctx1['user'])
        ->withSession(accountingUiSession($company2))
        ->get(route('accounting.journals.show', $journal))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('redirects unauthenticated users to login', function (): void {
    $this->get(route('accounting.journals.index'))->assertRedirect(route('login'));
});

it('rejects users without company membership', function (): void {
    $company = Company::query()->create(['name' => 'Protected accounting company', 'base_currency' => 'SYP']);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('accounting.journals.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Read-only restrictions
// ---------------------------------------------------------------------------

it('does not expose journal create or post routes', function (): void {
    expect(Route::has('accounting.journals.create'))->toBeFalse()
        ->and(Route::has('accounting.journals.store'))->toBeFalse()
        ->and(Route::has('accounting.journals.post'))->toBeFalse()
        ->and(Route::has('accounting.journals.destroy'))->toBeFalse()
        ->and(Route::has('accounting.journals.reverse'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Hierarchical account display
// ---------------------------------------------------------------------------

it('shows child accounts with indentation in the chart of accounts', function (): void {
    $ctx = accountingUiSetUp();
    $parent = $ctx['accounts']['cash'];
    $child = FinancialAccount::query()->create([
        'company_id' => $ctx['company']->getKey(),
        'parent_account_id' => $parent->getKey(),
        'code' => 'CHILD-'.uniqid(),
        'name' => 'Child cash account',
        'account_type' => 'asset',
        'currency' => 'SYP',
        'is_active' => true,
    ]);

    $response = $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('cash-bank.index'));

    $response->assertOk()
        ->assertSee($parent->code)
        ->assertSee($child->code);
});

// ---------------------------------------------------------------------------
// Control Account Mappings
// ---------------------------------------------------------------------------

it('shows the control account mappings page', function (): void {
    $ctx = accountingUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->get(route('accounting.mappings'))
        ->assertOk()
        ->assertSee('Customer receivables')
        ->assertSee('Supplier payables')
        ->assertSee('Inventory asset');
});

it('creates a new control account mapping', function (): void {
    $ctx = accountingUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->put(route('accounting.mappings.update', 'customer_receivables'), [
            'financial_account_id' => $ctx['accounts']['receivable']->getKey(),
        ])
        ->assertRedirect(route('accounting.mappings'))
        ->assertSessionHas('status', __('ui.accounting.mapping_saved'));

    expect(CompanyAccountMapping::query()
        ->where('company_id', $ctx['company']->getKey())
        ->where('purpose', 'customer_receivables')
        ->where('financial_account_id', $ctx['accounts']['receivable']->getKey())
        ->exists())->toBeTrue();
});

it('updates an existing control account mapping', function (): void {
    $ctx = accountingUiSetUp();

    CompanyAccountMapping::create([
        'company_id' => $ctx['company']->getKey(),
        'purpose' => 'supplier_payables',
        'financial_account_id' => $ctx['accounts']['payable']->getKey(),
    ]);

    $newPayable = FinancialAccount::query()->create([
        'company_id' => $ctx['company']->getKey(),
        'code' => 'NEW-PAY-'.uniqid(),
        'name' => 'New payable account',
        'account_type' => 'liability',
        'currency' => 'SYP',
        'is_active' => true,
    ]);

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->put(route('accounting.mappings.update', 'supplier_payables'), [
            'financial_account_id' => $newPayable->getKey(),
        ])
        ->assertRedirect(route('accounting.mappings'));

    expect(CompanyAccountMapping::query()
        ->where('purpose', 'supplier_payables')
        ->first()->financial_account_id)->toBe($newPayable->getKey());
});

it('rejects a mapping with the wrong account type', function (): void {
    $ctx = accountingUiSetUp();

    // customer_receivables requires 'asset', not 'liability'
    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->put(route('accounting.mappings.update', 'customer_receivables'), [
            'financial_account_id' => $ctx['accounts']['payable']->getKey(), // liability
        ])
        ->assertSessionHasErrors('financial_account_id');
});

it('rejects a mapping with an invalid purpose', function (): void {
    $ctx = accountingUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(accountingUiSession($ctx['company']))
        ->put(route('accounting.mappings.update', 'invalid_purpose'), [
            'financial_account_id' => $ctx['accounts']['cash']->getKey(),
        ])
        ->assertNotFound();
});

it('does not expose mappings for another company', function (): void {
    $ctx1 = accountingUiSetUp();
    $company2 = Company::query()->create(['name' => 'Other mapping company', 'base_currency' => 'SYP']);

    // Create the mapping under company2's own context.
    $mapping = app(CompanyContextService::class)->runForCompany($company2, function () use ($company2): CompanyAccountMapping {
        $account = FinancialAccount::create([
            'company_id' => $company2->getKey(),
            'code' => 'AR-OTHER',
            'name' => 'Other AR',
            'account_type' => 'asset',
            'currency' => 'SYP',
        ]);

        return CompanyAccountMapping::create([
            'company_id' => $company2->getKey(),
            'purpose' => 'customer_receivables',
            'financial_account_id' => $account->getKey(),
        ]);
    });

    // Save the name before switching context (the relation is company-scoped).
    $otherAccountName = $mapping->financialAccount?->name ?? 'Other AR';
    $mapping->unsetRelation('financialAccount');

    accountingUiActAs($ctx1['user'], $ctx1['company']);

    $this->actingAs($ctx1['user'])
        ->withSession(accountingUiSession($ctx1['company']))
        ->get(route('accounting.mappings'))
        ->assertOk()
        ->assertDontSee($otherAccountName);
});

// ---------------------------------------------------------------------------
// Navigation
// ---------------------------------------------------------------------------

it('shows the accounting navigation item as available', function (): void {
    $company = Company::query()->create(['name' => 'Nav company']);
    $user = User::factory()->create();
    $company->users()->attach($user);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('data-navigation-item="accounting"', false)
        ->assertSee('data-navigation-status="available"', false);
});
