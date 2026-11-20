<?php

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{company: Company, user: User, accounts: array<string, FinancialAccount>}
 */
function financialReportUiSetUp(): array
{
    $company = Company::query()->create([
        'name' => 'Report UI company '.uniqid(),
        'base_currency' => 'SYP',
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $accounts = [];
    foreach ([
        'asset' => 'asset',
        'liability' => 'liability',
        'equity' => 'equity',
        'revenue' => 'revenue',
        'expense' => 'expense',
    ] as $key => $type) {
        $accounts[$key] = FinancialAccount::query()->create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucfirst($key).' account',
            'account_type' => $type,
            'currency' => 'SYP',
            'is_active' => true,
            'statement_classification' => match ($type) {
                'revenue' => 'revenue',
                'expense' => 'operating_expense',
                default => null,
            },
        ]);
    }

    // Post one journal so reports have data.
    app(CompanyContextService::class)->runForCompany($company, function () use ($company, $accounts): void {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'entry_date' => now()->toDateString(),
            'description' => 'Report UI test journal',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $accounts['asset']->getKey(),
            'debit' => '500.0000',
            'credit' => '0',
            'currency' => 'SYP',
            'base_currency' => 'SYP',
            'exchange_rate' => '1',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $accounts['revenue']->getKey(),
            'debit' => '0',
            'credit' => '500.0000',
            'currency' => 'SYP',
            'base_currency' => 'SYP',
            'exchange_rate' => '1',
        ]);
        $accounting->postJournal($journal);
    });

    return compact('company', 'user', 'accounts');
}

function financialReportUiSession(Company $company): array
{
    return ['active_company_id' => (int) $company->getKey(), 'locale' => 'en'];
}

// ---------------------------------------------------------------------------
// AC-1: Reports index lists five reports
// ---------------------------------------------------------------------------

it('shows the reports index page with five report names', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee(__('ui.reports.trial_balance'))
        ->assertSee(__('ui.reports.general_ledger'))
        ->assertSee(__('ui.reports.income_statement'))
        ->assertSee(__('ui.reports.balance_sheet'))
        ->assertSee(__('ui.reports.performance_trends'));
});

// ---------------------------------------------------------------------------
// AC-2: Trial balance shows table headings
// ---------------------------------------------------------------------------

it('shows the trial balance with table headings', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.trial-balance'))
        ->assertOk()
        ->assertSee(__('ui.reports.col_opening_debit'))
        ->assertSee(__('ui.reports.col_closing_credit'));
});

// ---------------------------------------------------------------------------
// AC-3: Trial balance with missing date_from redirects with errors
// ---------------------------------------------------------------------------

it('redirects trial balance with invalid date_from with validation errors', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.trial-balance', ['date_from' => 'not-a-date']))
        ->assertRedirect()
        ->assertSessionHasErrors('date_from');
});

// ---------------------------------------------------------------------------
// AC-4: General ledger returns 200
// ---------------------------------------------------------------------------

it('shows the general ledger page', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.general-ledger'))
        ->assertOk()
        ->assertSee(__('ui.reports.general_ledger'));
});

// ---------------------------------------------------------------------------
// AC-5: General ledger with foreign account_id returns validation error
// ---------------------------------------------------------------------------

it('returns a validation error when account_id does not belong to the company', function (): void {
    $ctx = financialReportUiSetUp();

    $otherCompany = Company::query()->create(['name' => 'Other company', 'base_currency' => 'SYP']);
    $foreignAccount = app(CompanyContextService::class)->runForCompany($otherCompany, function () use ($otherCompany): FinancialAccount {
        return FinancialAccount::query()->create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'FOREIGN-'.uniqid(),
            'name' => 'Foreign account',
            'account_type' => 'asset',
            'currency' => 'SYP',
            'is_active' => true,
        ]);
    });
    // Restore the original company context.
    app(CompanyContextService::class)->switchTo($ctx['user'], (int) $ctx['company']->getKey());

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.general-ledger', ['account_id' => $foreignAccount->getKey()]))
        ->assertRedirect()
        ->assertSessionHasErrors('account_id');
});

// ---------------------------------------------------------------------------
// AC-6: Income statement shows all eight metric label translations
// ---------------------------------------------------------------------------

it('shows the income statement with all eight metric labels', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.income-statement'))
        ->assertOk()
        ->assertSee(__('ui.reports.metric_revenue'))
        ->assertSee(__('ui.reports.metric_cogs'))
        ->assertSee(__('ui.reports.metric_gross_profit'))
        ->assertSee(__('ui.reports.metric_gross_margin'))
        ->assertSee(__('ui.reports.metric_operating_expenses'))
        ->assertSee(__('ui.reports.metric_other_income'))
        ->assertSee(__('ui.reports.metric_other_expenses'))
        ->assertSee(__('ui.reports.metric_net_profit'));
});

// ---------------------------------------------------------------------------
// AC-7: Income statement with comparison dates shows comparison column heading
// ---------------------------------------------------------------------------

it('shows comparison column heading when both comparison dates are submitted', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.income-statement', [
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
            'comparison_from' => now()->subMonth()->startOfMonth()->toDateString(),
            'comparison_to' => now()->subMonth()->endOfMonth()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee(__('ui.reports.comparison_period'));
});

// ---------------------------------------------------------------------------
// AC-8: Income statement with only comparison_from redirects with error on comparison_to
// ---------------------------------------------------------------------------

it('redirects when only comparison_from is provided without comparison_to', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.income-statement', [
            'comparison_from' => now()->subMonth()->startOfMonth()->toDateString(),
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('comparison_to');
});

// ---------------------------------------------------------------------------
// AC-9: Balance sheet shows assets/liabilities/equity headings
// ---------------------------------------------------------------------------

it('shows the balance sheet with assets, liabilities, and equity headings', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.balance-sheet'))
        ->assertOk()
        ->assertSee(__('ui.reports.section_assets'))
        ->assertSee(__('ui.reports.section_liabilities'))
        ->assertSee(__('ui.reports.section_equity'));
});

// ---------------------------------------------------------------------------
// AC-10: Performance trends renders monthly table
// ---------------------------------------------------------------------------

it('shows the performance trends monthly table', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.performance-trends'))
        ->assertOk()
        ->assertSee(__('ui.reports.performance_trends'));
});

// ---------------------------------------------------------------------------
// AC-11: Trial balance print page contains data-print-document
// ---------------------------------------------------------------------------

it('trial balance print page contains the data-print-document button', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.trial-balance.print'))
        ->assertOk()
        ->assertSee('data-print-document', false);
});

// ---------------------------------------------------------------------------
// AC-13: Unauthenticated access redirects to login
// ---------------------------------------------------------------------------

it('redirects unauthenticated users to login', function (): void {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// AC-14: User with two companies and no session context gets 409
// ---------------------------------------------------------------------------

it('returns 409 when no active company context is set', function (): void {
    $company1 = Company::query()->create(['name' => 'Company 1 '.uniqid(), 'base_currency' => 'SYP']);
    $company2 = Company::query()->create(['name' => 'Company 2 '.uniqid(), 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company1->users()->attach($user);
    $company2->users()->attach($user);

    $this->actingAs($user)
        ->withSession(['locale' => 'en']) // no active_company_id
        ->get(route('reports.index'))
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// AC-15: English page has no raw ui.reports. literals
// ---------------------------------------------------------------------------

it('renders without leaving raw ui.reports. translation literals', function (): void {
    $ctx = financialReportUiSetUp();

    $response = $this->actingAs($ctx['user'])
        ->withSession(financialReportUiSession($ctx['company']))
        ->get(route('reports.index'))
        ->assertOk();

    expect($response->getContent())->not->toContain('ui.reports.');
});

// ---------------------------------------------------------------------------
// AC-16: Arabic locale renders RTL html tag and bdi tags
// ---------------------------------------------------------------------------

it('renders RTL direction and bdi tags on Arabic locale', function (): void {
    $ctx = financialReportUiSetUp();

    $this->actingAs($ctx['user'])
        ->withSession(['active_company_id' => (int) $ctx['company']->getKey(), 'locale' => 'ar'])
        ->get(route('reports.trial-balance'))
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertSee('<bdi dir="ltr">', false);
});
