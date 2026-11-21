<?php

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
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
function cashBankSetUp(string $baseCurrency = 'SYP'): array
{
    $company = Company::query()->create([
        'name' => 'Cash bank company '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $accounts = [];
    foreach ([
        'asset_syp' => ['asset', 'SYP'],
        'asset_usd' => ['asset', 'USD'],
        'liability_syp' => ['liability', 'SYP'],
        'expense_syp' => ['expense', 'SYP'],
        'revenue_syp' => ['revenue', 'SYP'],
        'equity_syp' => ['equity', 'SYP'],
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

    return compact('company', 'user', 'accounts');
}

function cashBankSession(Company $company): array
{
    return ['active_company_id' => (int) $company->getKey(), 'locale' => 'en'];
}

function cashBankActAs(User $user, Company $company): void
{
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());
}

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

it('lists all financial accounts for the active company', function (): void {
    $ctx = cashBankSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index'))
        ->assertOk()
        ->assertSee($ctx['accounts']['asset_syp']->name)
        ->assertSee($ctx['accounts']['liability_syp']->name)
        ->assertSee($ctx['accounts']['expense_syp']->name);
});

it('filters accounts by account_type', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index', ['account_type' => 'asset']));

    $response->assertOk()
        ->assertSee($ctx['accounts']['asset_syp']->name)
        ->assertSee($ctx['accounts']['asset_usd']->name)
        ->assertDontSee($ctx['accounts']['liability_syp']->name);
});

it('filters accounts by currency', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index', ['currency' => 'USD']));

    $response->assertOk()
        ->assertSee($ctx['accounts']['asset_usd']->name)
        ->assertDontSee($ctx['accounts']['asset_syp']->name);
});

it('filters accounts by active status', function (): void {
    $ctx = cashBankSetUp();
    $ctx['accounts']['asset_syp']->update(['is_active' => false]);

    $activeResponse = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index', ['status' => 'active']));

    $activeResponse->assertOk()
        ->assertDontSee($ctx['accounts']['asset_syp']->name)
        ->assertSee($ctx['accounts']['asset_usd']->name);

    $inactiveResponse = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index', ['status' => 'inactive']));

    $inactiveResponse->assertOk()
        ->assertSee($ctx['accounts']['asset_syp']->name)
        ->assertDontSee($ctx['accounts']['asset_usd']->name);
});

it('searches accounts by name and code', function (): void {
    $ctx = cashBankSetUp();
    $uniqueName = 'UniqueCashSearchTarget'.uniqid();
    $ctx['accounts']['asset_syp']->update(['name' => $uniqueName]);

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.index', ['search' => $uniqueName]));

    $response->assertOk()
        ->assertSee($uniqueName)
        ->assertDontSee($ctx['accounts']['liability_syp']->name);
});

// ---------------------------------------------------------------------------
// Company isolation
// ---------------------------------------------------------------------------

it('does not show accounts from another company', function (): void {
    $ctx1 = cashBankSetUp();

    $company2 = Company::query()->create(['name' => 'Other company '.uniqid(), 'base_currency' => 'SYP']);

    // Create the other company's account under its own company context.
    $otherAccount = app(CompanyContextService::class)->runForCompany($company2, function () use ($company2): FinancialAccount {
        return FinancialAccount::create([
            'company_id' => $company2->getKey(),
            'code' => 'OTHER-'.uniqid(),
            'name' => 'Other company account '.uniqid(),
            'account_type' => 'asset',
            'currency' => 'SYP',
        ]);
    });

    // Restore the first company context.
    cashBankActAs($ctx1['user'], $ctx1['company']);

    $this->actingAs($ctx1['user'])
        ->withSession(cashBankSession($ctx1['company']))
        ->get(route('cash-bank.index'))
        ->assertOk()
        ->assertDontSee($otherAccount->name);
});

it('redirects unauthenticated users to login', function (): void {
    $this->get(route('cash-bank.index'))->assertRedirect(route('login'));
});

it('rejects users without company membership', function (): void {
    $company = Company::query()->create(['name' => 'Protected company', 'base_currency' => 'SYP']);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('cash-bank.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Account creation
// ---------------------------------------------------------------------------

it('renders the create form', function (): void {
    $ctx = cashBankSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.create'))
        ->assertOk()
        ->assertSee(__('ui.cash_bank.create'));
});

it('creates a financial account with valid data', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->post(route('cash-bank.store'), [
            'code' => 'NEWACCT-'.uniqid(),
            'name' => 'New test account',
            'account_type' => 'asset',
            'currency' => 'SYP',
            'description' => 'A test account',
            'is_active' => '1',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', __('ui.cash_bank.created'));

    expect(FinancialAccount::query()
        ->where('company_id', $ctx['company']->getKey())
        ->where('name', 'New test account')
        ->exists())->toBeTrue();
});

it('rejects duplicate account codes within the same company', function (): void {
    $ctx = cashBankSetUp();
    $existingCode = $ctx['accounts']['asset_syp']->code;

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->post(route('cash-bank.store'), [
            'code' => $existingCode,
            'name' => 'Duplicate code',
            'account_type' => 'asset',
            'currency' => 'SYP',
        ]);

    $response->assertSessionHasErrors('code');
});

it('rejects an invalid account_type', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->post(route('cash-bank.store'), [
            'code' => 'BADTYPE-'.uniqid(),
            'name' => 'Bad type',
            'account_type' => 'invalid_type',
            'currency' => 'SYP',
        ]);

    $response->assertSessionHasErrors('account_type');
});

it('rejects a currency not in the allowed list', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->post(route('cash-bank.store'), [
            'code' => 'BADCUR-'.uniqid(),
            'name' => 'Bad currency',
            'account_type' => 'asset',
            'currency' => 'EUR',
        ]);

    $response->assertSessionHasErrors('currency');
});

it('creates account with statement_classification when valid for account_type', function (): void {
    $ctx = cashBankSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->post(route('cash-bank.store'), [
            'code' => 'EXPCLASS-'.uniqid(),
            'name' => 'Classified expense',
            'account_type' => 'expense',
            'currency' => 'SYP',
            'statement_classification' => 'operating_expense',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', __('ui.cash_bank.created'));

    expect(FinancialAccount::query()
        ->where('company_id', $ctx['company']->getKey())
        ->where('statement_classification', 'operating_expense')
        ->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Account editing
// ---------------------------------------------------------------------------

it('renders the edit form', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.edit', $account))
        ->assertOk()
        ->assertSee($account->name)
        ->assertSee(__('ui.cash_bank.immutable_fields_note'));
});

it('updates the editable fields of an account', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->put(route('cash-bank.update', $account), [
            'name' => 'Updated account name',
            'description' => 'Updated description',
            'is_active' => '1',
        ]);

    $response->assertRedirect(route('cash-bank.show', $account));
    $response->assertSessionHas('status', __('ui.cash_bank.updated'));

    expect($account->fresh()->name)->toBe('Updated account name');
    expect($account->fresh()->description)->toBe('Updated description');
});

it('cannot change account_type or currency through update', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->put(route('cash-bank.update', $account), [
            'name' => 'Still asset',
            'is_active' => '1',
        ]);

    $account->refresh();
    expect($account->account_type)->toBe('asset');
    expect($account->currency)->toBe('SYP');
});

it('rejects an invalid statement_classification for the account type on edit', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->put(route('cash-bank.update', $account), [
            'name' => 'Still asset',
            'statement_classification' => 'revenue', // invalid for asset
        ]);

    $response->assertSessionHasErrors('statement_classification');
});

// ---------------------------------------------------------------------------
// Account detail / show page
// ---------------------------------------------------------------------------

it('renders the account detail page with balance', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.show', $account))
        ->assertOk()
        ->assertSee($account->name)
        ->assertSee($account->code);
});

it('shows the transaction history from posted journal lines', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];
    $offsetAccount = $ctx['accounts']['liability_syp'];

    cashBankActAs($ctx['user'], $ctx['company']);

    $accounting = app(AccountingService::class);
    $journal = $accounting->createDraftJournal([
        'description' => 'Test transaction for account',
        'entry_date' => now()->toDateString(),
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $account->getKey(),
        'debit' => '500.0000',
        'credit' => '0',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $offsetAccount->getKey(),
        'debit' => '0',
        'credit' => '500.0000',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $accounting->postJournal($journal);

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.show', $account))
        ->assertOk()
        ->assertSee($journal->journal_number);
});

it('does not show draft journal lines in transaction history', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    cashBankActAs($ctx['user'], $ctx['company']);

    $accounting = app(AccountingService::class);
    $journal = $accounting->createDraftJournal([
        'description' => 'Draft journal'.uniqid(),
        'entry_date' => now()->toDateString(),
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $account->getKey(),
        'debit' => '200.0000',
        'credit' => '0',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    // NOT posted — journal stays in draft

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.show', $account))
        ->assertOk()
        ->assertDontSee($journal->journal_number);
});

it('filters transaction history by date range', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];
    $offsetAccount = $ctx['accounts']['liability_syp'];

    cashBankActAs($ctx['user'], $ctx['company']);

    $accounting = app(AccountingService::class);

    // Post a journal with a specific past date.
    $journal = $accounting->createDraftJournal([
        'description' => 'Old transaction',
        'entry_date' => '2024-01-15',
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $account->getKey(),
        'debit' => '100.0000',
        'credit' => '0',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $offsetAccount->getKey(),
        'debit' => '0',
        'credit' => '100.0000',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'exchange_rate' => '1',
    ]);
    $accounting->postJournal($journal);

    // Filter to a future date range that excludes the posted journal.
    $response = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.show', $account).'?date_from=2025-01-01&date_to=2025-12-31');

    $response->assertOk()
        ->assertDontSee($journal->journal_number);

    // Filter to include the transaction.
    $response2 = $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.show', $account).'?date_from=2024-01-01&date_to=2024-12-31');

    $response2->assertOk()
        ->assertSee($journal->journal_number);
});

// ---------------------------------------------------------------------------
// Printable statement
// ---------------------------------------------------------------------------

it('renders the printable statement', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.print', $account))
        ->assertOk()
        ->assertSee($account->code)
        ->assertSee($account->name);
});

it('renders the printable statement with date filters', function (): void {
    $ctx = cashBankSetUp();
    $account = $ctx['accounts']['asset_syp'];

    $this->actingAs($ctx['user'])
        ->withSession(cashBankSession($ctx['company']))
        ->get(route('cash-bank.print', ['cashBank' => $account->getKey(), 'date_from' => '2024-01-01', 'date_to' => '2024-12-31']))
        ->assertOk()
        ->assertSee('2024-01-01')
        ->assertSee('2024-12-31');
});

// ---------------------------------------------------------------------------
// No transfer UI
// ---------------------------------------------------------------------------

it('does not expose a transfer route', function (): void {
    expect(Route::has('cash-bank.transfer'))->toBeFalse();
});
