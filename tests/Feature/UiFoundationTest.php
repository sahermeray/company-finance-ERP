<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\BusinessInsightsService;
use App\Services\CompanyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{account: FinancialAccount, debit?: string, credit?: string}>  $lines
 */
function postUiJournal(Company $company, string $date, array $lines): AccountingJournal
{
    return app(CompanyContextService::class)->runForCompany($company, function () use (
        $company,
        $date,
        $lines,
    ): AccountingJournal {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'journal_number' => 'UI-'.uniqid(),
            'entry_date' => $date,
        ], $company);

        foreach ($lines as $line) {
            $accounting->addJournalLine($journal, [
                'financial_account_id' => $line['account']->getKey(),
                'debit' => $line['debit'] ?? '0',
                'credit' => $line['credit'] ?? '0',
                'currency' => $company->base_currency,
                'exchange_rate' => '1',
                'base_currency' => $company->base_currency,
            ]);
        }

        return $accounting->postJournal($journal);
    });
}

function uiAccount(Company $company, string $code, string $type, ?string $classification = null): FinancialAccount
{
    return FinancialAccount::create([
        'company_id' => $company->getKey(),
        'code' => $code,
        'name' => $code,
        'account_type' => $type,
        'statement_classification' => $classification,
        'currency' => $company->base_currency,
        'is_active' => true,
    ]);
}

it('requires authentication and preserves session login and logout behavior', function () {
    $this->get(route('dashboard.index'))->assertRedirect(route('login'));
    $this->get(route('login'))->assertSee('lang="ar" dir="rtl"', false);

    $company = Company::create(['name' => 'Login company']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard.index'));
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('shows company-scoped insight metrics and service-provided comparisons', function () {
    $company = Company::create(['name' => 'North Shop', 'base_currency' => 'SYP']);
    $otherCompany = Company::create(['name' => 'South Shop', 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $user->companies()->attach([$company->getKey(), $otherCompany->getKey()]);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $companyRevenue = uiAccount($company, '4000', 'revenue', 'revenue');
    $companyEquity = uiAccount($company, '3000', 'equity');
    $otherRevenue = uiAccount($otherCompany, '4000', 'revenue', 'revenue');
    $otherEquity = uiAccount($otherCompany, '3000', 'equity');
    postUiJournal($company, '2026-10-09', [
        ['account' => $companyRevenue, 'credit' => '80'],
        ['account' => $companyEquity, 'debit' => '80'],
    ]);
    postUiJournal($company, '2026-10-10', [
        ['account' => $companyRevenue, 'credit' => '120'],
        ['account' => $companyEquity, 'debit' => '120'],
    ]);
    postUiJournal($otherCompany, '2026-10-10', [
        ['account' => $otherRevenue, 'credit' => '999'],
        ['account' => $otherEquity, 'debit' => '999'],
    ]);

    $this->actingAs($user)
        ->post(route('company-context.update'), [
            'company_id' => $company->getKey(),
            'return_to' => '/dashboard',
        ])
        ->assertRedirect('/dashboard');

    $this->get(route('dashboard.index', [
        'period' => 'custom',
        'start_date' => '2026-10-10',
        'end_date' => '2026-10-10',
    ]))
        ->assertViewHas('company', fn (Company $selectedCompany): bool => $selectedCompany->is($company))
        ->assertViewHas('metrics.sales_revenue.value', '120.0000')
        ->assertViewHas('metrics.sales_revenue.comparison.value', '80.0000')
        ->assertSee('North Shop')
        ->assertSee('SYP')
        ->assertSee('South Shop')
        ->assertDontSee('999 SYP');
});

it('ignores a browser session company id that is not a user membership', function () {
    $company = Company::create(['name' => 'Authorized shop']);
    $otherCompany = Company::create(['name' => 'Private shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $otherCompany->getKey()])
        ->get(route('dashboard.index'))
        ->assertViewHas('company', fn (Company $selectedCompany): bool => $selectedCompany->is($company))
        ->assertSessionHas('active_company_id', $company->getKey())
        ->assertSee('Authorized shop')
        ->assertDontSee('Private shop');
});

it('requires selection for multiple companies and validates every company switch', function () {
    $firstCompany = Company::create(['name' => 'First shop']);
    $secondCompany = Company::create(['name' => 'Second shop']);
    $outsiderCompany = Company::create(['name' => 'Unlinked shop']);
    $user = User::factory()->create();
    $user->companies()->attach([$firstCompany->getKey(), $secondCompany->getKey()]);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->get(route('dashboard.index'))
        ->assertRedirect(route('companies.select'));

    $this->get(route('companies.select'))
        ->assertSee('First shop')
        ->assertSee('Second shop');

    $this->post(route('company-context.update'), [
        'company_id' => $secondCompany->getKey(),
        'return_to' => '/dashboard?period=month',
    ])
        ->assertRedirect('/dashboard?period=month')
        ->assertSessionHas('active_company_id', $secondCompany->getKey());

    $this->get(route('dashboard.index'))->assertSee('Second shop');

    $this->post(route('company-context.update'), [
        'company_id' => $outsiderCompany->getKey(),
        'return_to' => '/dashboard',
    ])
        ->assertNotFound()
        ->assertSessionHas('active_company_id', $secondCompany->getKey());
});

it('renders unsupported and incomplete metrics without replacing them with zero', function () {
    $this->travelTo('2026-10-10 08:00:00');
    $company = Company::create(['name' => 'Data quality shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $cash = uiAccount($company, '1000', 'asset');
    $unclassifiedExpense = uiAccount($company, '5000', 'expense');
    postUiJournal($company, '2026-10-10', [
        ['account' => $cash, 'debit' => '45'],
        ['account' => $unclassifiedExpense, 'credit' => '45'],
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('dashboard.index', [
            'period' => 'custom',
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-10',
        ]))
        ->assertViewHas('metrics.receivables.availability', 'unsupported')
        ->assertViewHas('metrics.operating_expenses.value', null)
        ->assertViewHas('metrics.operating_expenses.availability', 'unavailable')
        ->assertSee('غير مدعوم')
        ->assertSee('غير متاح')
        ->assertSee('—');
});

it('marks current balance metrics and warnings unsupported for a historical reporting date', function () {
    $this->travelTo('2026-10-14 08:00:00');
    $company = Company::create(['name' => 'Historical report shop', 'base_currency' => 'SYP']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $metric = ['value' => '40.0000', 'availability' => 'available', 'currency' => 'SYP'];
    $subledger = [
        'operational_balance' => '40.0000',
        'supported' => true,
        'status' => 'discrepant',
        'currency' => 'SYP',
    ];

    $this->mock(BusinessInsightsService::class)
        ->shouldReceive('insights')
        ->once()
        ->andReturn([
            'financial_performance' => [
                'metrics' => [
                    'revenue' => $metric,
                    'gross_profit' => $metric,
                    'operating_expenses' => $metric,
                    'net_profit' => $metric,
                ],
                'trend' => ['status' => 'complete', 'months' => []],
            ],
            'sales_and_purchases' => ['purchases_total' => $metric],
            'inventory' => [
                'total_valued_inventory' => $metric,
                'unvalued_legacy_inventory' => [['product_id' => 1]],
            ],
            'receivables_and_payables' => [
                'customer_receivables' => $subledger,
                'supplier_payables' => $subledger,
            ],
            'warnings' => [
                ['category' => 'inventory', 'status' => 'discrepant', 'discrepancies' => []],
                ['category' => 'customer_receivables', 'status' => 'discrepant', 'discrepancies' => []],
            ],
        ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('dashboard.index', [
            'period' => 'custom',
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-10',
        ]))
        ->assertViewHas('metrics.inventory.value', null)
        ->assertViewHas('metrics.inventory.availability', 'unsupported')
        ->assertViewHas('metrics.receivables.value', null)
        ->assertViewHas('metrics.receivables.availability', 'unsupported')
        ->assertViewHas('metrics.payables.availability', 'unsupported')
        ->assertViewHas('warnings', [])
        ->assertSee('لا يمكن عرضه كتقرير عن تاريخ سابق أو مستقبلي.');
});

it('labels a reported reconciliation difference as a discrepancy even without issue details', function () {
    $company = Company::create(['name' => 'Reconciliation shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $metric = ['value' => '0.0000', 'availability' => 'available', 'currency' => 'SYP'];
    $subledger = [
        'operational_balance' => '0.0000',
        'supported' => true,
        'status' => 'consistent',
        'currency' => 'SYP',
    ];

    $this->mock(BusinessInsightsService::class)
        ->shouldReceive('insights')
        ->once()
        ->andReturn([
            'financial_performance' => [
                'metrics' => [
                    'revenue' => $metric,
                    'gross_profit' => $metric,
                    'operating_expenses' => $metric,
                    'net_profit' => $metric,
                ],
                'trend' => ['status' => 'complete', 'months' => []],
            ],
            'sales_and_purchases' => ['purchases_total' => $metric],
            'inventory' => [
                'total_valued_inventory' => $metric,
                'unvalued_legacy_inventory' => [],
            ],
            'receivables_and_payables' => [
                'customer_receivables' => $subledger,
                'supplier_payables' => $subledger,
            ],
            'warnings' => [[
                'category' => 'inventory',
                'status' => 'discrepant',
                'discrepancies' => [],
            ]],
        ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('dashboard.index'))
        ->assertViewHas('warnings', fn (array $warnings): bool => $warnings[0]['type'] === 'discrepancy');
});

it('rejects reversed custom reporting dates', function () {
    $company = Company::create(['name' => 'Date validation shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->from(route('dashboard.index'))
        ->get(route('dashboard.index', [
            'period' => 'custom',
            'start_date' => '2026-10-11',
            'end_date' => '2026-10-10',
        ]))
        ->assertSessionHasErrors('end_date');
});

it('limits custom reporting ranges to 366 days', function () {
    $company = Company::create(['name' => 'Period limit shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->from(route('dashboard.index'))
        ->get(route('dashboard.index', [
            'period' => 'custom',
            'start_date' => '2020-01-01',
            'end_date' => '2026-01-02',
        ]))
        ->assertSessionHasErrors('end_date');
});

it('uses the company timezone for preset reporting periods', function () {
    $this->travelTo('2026-10-14 18:00:00');
    $company = Company::create(['name' => 'Timezone shop', 'timezone' => 'UTC']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('dashboard.index', ['period' => 'week']))
        ->assertViewHas('fromDate', '2026-10-12')
        ->assertViewHas('toDate', '2026-10-14');
});

it('defaults to Arabic and preserves the current dashboard URL when switching languages', function () {
    $company = Company::create(['name' => 'Language shop']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->get(route('dashboard.index'))
        ->assertSee('lang="ar" dir="rtl"', false)
        ->assertSee('data-sidebar-toggle', false)
        ->assertSee('aria-controls="app-sidebar"', false)
        ->assertSee('data-sidebar-overlay', false)
        ->assertSee('لوحة المعلومات');

    $this->post(route('language.update'), [
        'locale' => 'en',
        'return_to' => '/dashboard?period=month',
    ])->assertRedirect('/dashboard?period=month')
        ->assertSessionHas('locale', 'en');

    $this->get(route('dashboard.index', ['period' => 'month']))
        ->assertSee('lang="en" dir="ltr"', false)
        ->assertSee('Dashboard');
});

it('rejects external language return URLs', function () {
    $response = $this->post(route('language.update'), [
        'locale' => 'en',
        'return_to' => 'https://example.test/',
    ]);

    $response->assertSessionHasErrors('return_to');
});
