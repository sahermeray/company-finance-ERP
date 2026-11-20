<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\CompanyContextService;
use App\Services\CompanySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a company with a user attached and set the company context.
 *
 * @return array{company: Company, user: User}
 */
function settingsSetUp(string $baseCurrency = 'SYP'): array
{
    $company = Company::query()->create([
        'name'          => 'Settings Company '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    return compact('company', 'user');
}

/**
 * @return array<string, mixed>
 */
function settingsSession(Company $company): array
{
    return [
        'active_company_id' => $company->getKey(),
        'locale'            => 'en',
    ];
}

/**
 * Create a FinancialAccount for the given company.
 *
 * @param  array<string, mixed>  $overrides
 */
function settingsMakeAccount(Company $company, array $overrides = []): FinancialAccount
{
    return FinancialAccount::query()->create(array_merge([
        'company_id'   => $company->getKey(),
        'code'         => 'ACC-'.uniqid(),
        'name'         => 'Test account '.uniqid(),
        'account_type' => 'revenue',
        'currency'     => $company->base_currency,
        'is_active'    => true,
    ], $overrides));
}

/**
 * Create a posted AccountingJournal for the given company.
 * Uses AccountingService because the model blocks direct creation with status=posted.
 */
function settingsMakePostedJournal(Company $company): AccountingJournal
{
    $accounting = app(\App\Services\AccountingService::class);

    // Two balanced accounts are required for a valid journal.
    $debit = FinancialAccount::query()->create([
        'company_id'   => $company->getKey(),
        'code'         => 'DR-'.uniqid(),
        'name'         => 'Debit account',
        'account_type' => 'asset',
        'currency'     => $company->base_currency,
        'is_active'    => true,
    ]);
    $credit = FinancialAccount::query()->create([
        'company_id'   => $company->getKey(),
        'code'         => 'CR-'.uniqid(),
        'name'         => 'Credit account',
        'account_type' => 'liability',
        'currency'     => $company->base_currency,
        'is_active'    => true,
    ]);

    $journal = $accounting->createDraftJournal([
        'company_id'  => $company->getKey(),
        'entry_date'  => now()->toDateString(),
        'description' => 'Settings test posted journal',
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $debit->getKey(),
        'debit'                => '100',
        'credit'               => '0',
        'currency'             => $company->base_currency,
        'base_currency'        => $company->base_currency,
        'exchange_rate'        => '1',
    ]);
    $accounting->addJournalLine($journal, [
        'financial_account_id' => $credit->getKey(),
        'debit'                => '0',
        'credit'               => '100',
        'currency'             => $company->base_currency,
        'base_currency'        => $company->base_currency,
        'exchange_rate'        => '1',
    ]);

    return $accounting->postJournal($journal);
}

// ---------------------------------------------------------------------------
// AC 1 — Navigation reaches settings without 404
// ---------------------------------------------------------------------------

it('navigates to company settings without a 404 (AC 1)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.index'))
        ->assertRedirect(route('settings.general'));

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.general'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// AC 2 — Unauthenticated users are redirected to /login
// ---------------------------------------------------------------------------

it('redirects unauthenticated requests to login (AC 2)', function (): void {
    $this->get(route('settings.general'))->assertRedirect(route('login'));
    $this->get(route('settings.financial'))->assertRedirect(route('login'));
    $this->get(route('settings.exchange-rates'))->assertRedirect(route('login'));
    $this->get(route('settings.forex-accounts'))->assertRedirect(route('login'));
    $this->get(route('settings.fiscal-year'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// AC 3 — No active company context → handled before reaching controller
// ---------------------------------------------------------------------------

it('handles missing company context before reaching settings controller (AC 3)', function (): void {
    $user = User::factory()->create();

    // The EnsureActiveCompany middleware aborts with 403 when the user has no companies
    // and with 409 when they have multiple companies and need to select one.
    // A fresh user has no companies, so we expect 403.
    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->get(route('settings.general'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// AC 4 — Saving valid general information updates the company row
// ---------------------------------------------------------------------------

it('saves valid general information and shows a success message (AC 4)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.general.update'), [
            'name'           => 'Updated Company Name',
            'legal_name'     => 'Updated Legal LLC',
            'tax_identifier' => '1234567890',
            'email'          => 'info@example.com',
            'phone'          => '+963 11 123 4567',
            'address'        => '123 Main St',
            'country_code'   => 'SY',
            'timezone'       => 'Asia/Damascus',
        ]);

    $response->assertRedirect(route('settings.general'));
    $response->assertSessionHas('status');

    $company->refresh();
    expect($company->name)->toBe('Updated Company Name')
        ->and($company->email)->toBe('info@example.com')
        ->and($company->timezone)->toBe('Asia/Damascus');
});

// ---------------------------------------------------------------------------
// AC 5 — Blank optional email does not null-out an existing email value
// ---------------------------------------------------------------------------

it('preserves existing email when optional field is left blank on update (AC 5)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    // Seed an existing email.
    $company->update(['email' => 'existing@example.com']);

    // Submit form without an email value.
    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.general.update'), [
            'name'  => $company->name,
            'email' => '',      // explicitly blank
        ]);

    $company->refresh();
    expect($company->email)->toBe('existing@example.com');
});

// ---------------------------------------------------------------------------
// AC 6 — Invalid email returns a validation error
// ---------------------------------------------------------------------------

it('returns a validation error for an invalid email (AC 6)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.general.update'), [
            'name'  => $company->name,
            'email' => 'not-an-email',
        ])
        ->assertSessionHasErrors('email');
});

// ---------------------------------------------------------------------------
// AC 7 — Financial page shows current base_currency
// ---------------------------------------------------------------------------

it('displays the current base currency on the financial settings page (AC 7)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.financial'))
        ->assertOk()
        ->assertSee('SYP');
});

// ---------------------------------------------------------------------------
// AC 8 — Base currency selector is disabled when posted journals exist
// ---------------------------------------------------------------------------

it('locks the base currency selector when posted journals exist (AC 8)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    app(CompanyContextService::class)->runForCompany($company, fn () => settingsMakePostedJournal($company));

    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.financial'))
        ->assertOk();

    // The locked explanation notice must be visible.
    $response->assertSee(__('ui.settings.financial.currency_locked'));

    // When locked, no currency-change form should exist in the response.
    // We confirm by checking that neither the confirmation checkbox nor submit
    // button for the currency change are rendered.
    $response->assertDontSee('confirm_change', false)
        ->assertDontSee('id="currency-submit"', false);
});

// ---------------------------------------------------------------------------
// AC 9 — Base currency can be changed when no posted journals exist
// ---------------------------------------------------------------------------

it('allows changing base currency when no posted journals exist (AC 9)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.financial.update'), [
            'base_currency'  => 'USD',
            'confirm_change' => '1',
        ])
        ->assertRedirect(route('settings.financial'));

    $company->refresh();
    expect($company->base_currency)->toBe('USD');
});

// ---------------------------------------------------------------------------
// AC 9 extra — Changing base currency does not modify any other record
// ---------------------------------------------------------------------------

it('updating base currency only touches the companies row (AC 9 extra)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    // Create some exchange rate records to verify they are untouched.
    app(CompanyContextService::class)->runForCompany($company, function () use ($company): void {
        ExchangeRate::create([
            'company_id'    => $company->getKey(),
            'currency'      => 'USD',
            'base_currency' => 'SYP',
            'rate'          => '14000.0000000000',
            'effective_at'  => now(),
            'source'        => 'manual',
            'created_by'    => null,
        ]);
    });

    $countBefore = ExchangeRate::withoutGlobalScopes()->count();

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.financial.update'), [
            'base_currency'  => 'USD',
            'confirm_change' => '1',
        ]);

    expect(ExchangeRate::withoutGlobalScopes()->count())->toBe($countBefore);
});

// ---------------------------------------------------------------------------
// AC 10 — Exchange rate list shows all rates ordered by effective_at DESC
// ---------------------------------------------------------------------------

it('lists exchange rates ordered by effective_at descending (AC 10)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    app(CompanyContextService::class)->runForCompany($company, function () use ($company): void {
        ExchangeRate::create([
            'company_id'    => $company->getKey(),
            'currency'      => 'USD',
            'base_currency' => 'SYP',
            'rate'          => '13000.0000000000',
            'effective_at'  => now()->subDays(2),
            'source'        => 'manual',
            'created_by'    => null,
        ]);
        ExchangeRate::create([
            'company_id'    => $company->getKey(),
            'currency'      => 'USD',
            'base_currency' => 'SYP',
            'rate'          => '14000.0000000000',
            'effective_at'  => now()->subDay(),
            'source'        => 'manual',
            'created_by'    => null,
        ]);
    });

    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.exchange-rates'))
        ->assertOk();

    $content = $response->getContent();
    // The newer rate (14000) should appear before the older rate (13000).
    expect(strpos($content, '14000'))->toBeLessThan(strpos($content, '13000'));
});

// ---------------------------------------------------------------------------
// AC 11 — Zero exchange rate returns a validation error
// ---------------------------------------------------------------------------

it('rejects a zero exchange rate (AC 11)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->post(route('settings.exchange-rates.store'), [
            'currency'     => 'USD',
            'rate'         => '0',
            'effective_at' => now()->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('rate');
});

// ---------------------------------------------------------------------------
// AC 12 — Currency equal to base currency returns a validation error
// ---------------------------------------------------------------------------

it('rejects an exchange rate whose currency equals the base currency (AC 12)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->post(route('settings.exchange-rates.store'), [
            'currency'     => 'SYP',  // same as base
            'rate'         => '1',
            'effective_at' => now()->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('currency');
});

// ---------------------------------------------------------------------------
// AC 13 — Valid new exchange rate creates a row; existing rows are unchanged
// ---------------------------------------------------------------------------

it('creates a new exchange rate without altering existing rows (AC 13)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $existingId = app(CompanyContextService::class)->runForCompany($company, function () use ($company): int {
        return ExchangeRate::create([
            'company_id'    => $company->getKey(),
            'currency'      => 'USD',
            'base_currency' => 'SYP',
            'rate'          => '13000.0000000000',
            'effective_at'  => now()->subDay(),
            'source'        => 'manual',
            'created_by'    => null,
        ])->getKey();
    });

    $countBefore = ExchangeRate::withoutGlobalScopes()->where('company_id', $company->getKey())->count();

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->post(route('settings.exchange-rates.store'), [
            'currency'     => 'USD',
            'rate'         => '14000',
            'effective_at' => now()->format('Y-m-d\TH:i'),
        ])
        ->assertRedirect(route('settings.exchange-rates'));

    $countAfter = ExchangeRate::withoutGlobalScopes()->where('company_id', $company->getKey())->count();
    expect($countAfter)->toBe($countBefore + 1);

    // The old rate record is unchanged.
    $existing = ExchangeRate::withoutGlobalScopes()->find($existingId);
    expect((string) $existing->rate)->toStartWith('13000');
});

// ---------------------------------------------------------------------------
// AC 14 — base_currency, source, created_by are set server-side
// ---------------------------------------------------------------------------

it('ignores injected base_currency, source, and created_by in exchange rate request (AC 14)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->post(route('settings.exchange-rates.store'), [
            'currency'      => 'USD',
            'rate'          => '15000',
            'effective_at'  => now()->format('Y-m-d\TH:i'),
            'base_currency' => 'USD',    // injection attempt
            'source'        => 'api',    // injection attempt
            'created_by'    => 99999,    // injection attempt
        ])
        ->assertRedirect(route('settings.exchange-rates'));

    $rate = ExchangeRate::withoutGlobalScopes()
        ->where('company_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($rate->base_currency)->toBe('SYP')    // server-set from company
        ->and($rate->source)->toBe('manual')      // always 'manual'
        ->and($rate->created_by)->toBe($user->getKey());
});

// ---------------------------------------------------------------------------
// AC 15 — Gain account selector shows only eligible accounts
// ---------------------------------------------------------------------------

it('shows only eligible gain accounts (revenue/income, active, base currency) (AC 15)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $service = app(CompanySettingsService::class);

    // Eligible account.
    $gainAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => true]));

    // Ineligible: wrong type.
    app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'expense', 'currency' => 'SYP', 'is_active' => true]));

    // Ineligible: inactive.
    app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => false]));

    // Ineligible: wrong currency.
    app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'USD', 'is_active' => true]));

    $eligible = app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->eligibleGainAccounts($company));

    expect($eligible->pluck('id')->toArray())->toContain($gainAcc->getKey())
        ->and($eligible->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// AC 16 — Loss account selector shows only eligible accounts
// ---------------------------------------------------------------------------

it('shows only eligible loss accounts (expense, active, base currency) (AC 16)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $service = app(CompanySettingsService::class);

    // Eligible account.
    $lossAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'expense', 'currency' => 'SYP', 'is_active' => true]));

    // Ineligible: wrong type.
    app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => true]));

    // Ineligible: inactive expense.
    app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'expense', 'currency' => 'SYP', 'is_active' => false]));

    $eligible = app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->eligibleLossAccounts($company));

    expect($eligible->pluck('id')->toArray())->toContain($lossAcc->getKey())
        ->and($eligible->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// AC 17 — Saving forex accounts updates only the mapping columns
// ---------------------------------------------------------------------------

it('saving a forex gain account updates only exchange_gain_account_id (AC 17)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $gainAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => true]));

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.forex-accounts.gain'), [
            'financial_account_id' => $gainAcc->getKey(),
        ])
        ->assertRedirect(route('settings.forex-accounts'));

    $company->refresh();
    expect($company->exchange_gain_account_id)->toBe($gainAcc->getKey());
});

it('saving a forex loss account updates only exchange_loss_account_id (AC 17b)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $lossAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'expense', 'currency' => 'SYP', 'is_active' => true]));

    $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.forex-accounts.loss'), [
            'financial_account_id' => $lossAcc->getKey(),
        ])
        ->assertRedirect(route('settings.forex-accounts'));

    $company->refresh();
    expect($company->exchange_loss_account_id)->toBe($lossAcc->getKey());
});

// ---------------------------------------------------------------------------
// AC 18 — Cross-company account injection is rejected
// ---------------------------------------------------------------------------

it('rejects a forex account from another company (AC 18)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    // Create a second company and an account in it.
    $otherCompany = Company::query()->create(['name' => 'Other company '.uniqid(), 'base_currency' => 'SYP']);
    $otherUser    = User::factory()->create();
    $otherCompany->users()->attach($otherUser);

    $foreignAccount = app(CompanyContextService::class)->runForCompany($otherCompany,
        fn () => settingsMakeAccount($otherCompany, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => true]));

    // Send that foreign account ID while acting as the first company.
    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->put(route('settings.forex-accounts.gain'), [
            'financial_account_id' => $foreignAccount->getKey(),
        ]);

    // TenantScoped global scope constrains the lookup to the active company,
    // so the account is not found — 404 or validation error (422).
    expect($response->getStatusCode())->toBeIn([404, 422]);

    $company->refresh();
    expect($company->exchange_gain_account_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// AC 19 — Financial year section renders an informational notice only
// ---------------------------------------------------------------------------

it('renders only an informational notice on the financial year page (AC 19)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.fiscal-year'))
        ->assertOk();

    $response->assertSee(__('ui.settings.fiscal_year.notice'));

    // No form action pointing to a fiscal-year store/update route must exist.
    $response->assertDontSee('fiscal-year/store', false)
        ->assertDontSee('fiscal-year/update', false);
});

// ---------------------------------------------------------------------------
// AC 20 — Every UI string has entries in both lang files
// ---------------------------------------------------------------------------

it('all settings translation keys exist in both en and ar lang files (AC 20)', function (): void {
    $enSettings = __('ui.settings', [], 'en');
    $arSettings = __('ui.settings', [], 'ar');

    expect($enSettings)->toBeArray()
        ->and($arSettings)->toBeArray();

    // Flatten and compare keys — both locales must have the same set.
    $enKeys = array_keys(\Illuminate\Support\Arr::dot($enSettings));
    $arKeys = array_keys(\Illuminate\Support\Arr::dot($arSettings));

    sort($enKeys);
    sort($arKeys);

    expect($enKeys)->toBe($arKeys);
});

// ---------------------------------------------------------------------------
// AC 21 — Arabic locale sets dir="rtl"
// ---------------------------------------------------------------------------

it('renders dir=rtl for Arabic locale (AC 21)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'ar'])
        ->get(route('settings.general'))
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

// ---------------------------------------------------------------------------
// AC 22 — Each section has its own distinct save button
// ---------------------------------------------------------------------------

it('each settings section has its own save button (AC 22)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $session = settingsSession($company);

    // General: has its own save.
    $this->actingAs($user)->withSession($session)
        ->get(route('settings.general'))
        ->assertOk()
        ->assertSee(route('settings.general.update'), false);

    // Financial: has its own save when unlocked.
    $this->actingAs($user)->withSession($session)
        ->get(route('settings.financial'))
        ->assertOk()
        ->assertSee(route('settings.financial.update'), false);

    // Exchange rates: has its own save.
    $this->actingAs($user)->withSession($session)
        ->get(route('settings.exchange-rates'))
        ->assertOk()
        ->assertSee(route('settings.exchange-rates.store'), false);
});

// ---------------------------------------------------------------------------
// AC 23 — Locked base currency shows an explanation notice
// ---------------------------------------------------------------------------

it('locked base currency renders a visible explanation notice (AC 23)', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp();

    app(CompanyContextService::class)->runForCompany($company, fn () => settingsMakePostedJournal($company));

    $response = $this->actingAs($user)
        ->withSession(settingsSession($company))
        ->get(route('settings.financial'))
        ->assertOk();

    $response->assertSee(__('ui.settings.financial.currency_locked'))
        ->assertSee(__('ui.settings.financial.currency_locked_reason'));
});

// ---------------------------------------------------------------------------
// Extra service-layer unit tests
// ---------------------------------------------------------------------------

it('hasPostedJournals returns false for draft journals only', function (): void {
    ['company' => $company] = settingsSetUp();

    app(CompanyContextService::class)->runForCompany($company, function () use ($company): void {
        AccountingJournal::query()->create([
            'company_id'     => $company->getKey(),
            'journal_number' => 'DRAFT-'.uniqid(),
            'entry_date'     => now()->toDateString(),
            'description'    => 'Draft journal',
            'status'         => 'draft',
        ]);
    });

    $service = app(CompanySettingsService::class);
    expect($service->hasPostedJournals($company))->toBeFalse();
});

it('hasPostedJournals returns true when at least one posted journal exists', function (): void {
    ['company' => $company] = settingsSetUp();

    app(CompanyContextService::class)->runForCompany($company, fn () => settingsMakePostedJournal($company));

    $service = app(CompanySettingsService::class);
    expect($service->hasPostedJournals($company))->toBeTrue();
});

it('updateBaseCurrency throws when posted journals exist', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    app(CompanyContextService::class)->runForCompany($company, fn () => settingsMakePostedJournal($company));

    $service = app(CompanySettingsService::class);

    expect(fn () => $service->updateBaseCurrency($company, 'USD'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('updateBaseCurrency rejects unsupported currencies', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $service = app(CompanySettingsService::class);

    expect(fn () => $service->updateBaseCurrency($company, 'EUR'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('storeExchangeRate sets base_currency, source, and created_by from server context', function (): void {
    ['company' => $company, 'user' => $user] = settingsSetUp('SYP');

    $service = app(CompanySettingsService::class);

    $rate = app(CompanyContextService::class)->runForCompany($company, function () use ($service, $company): ExchangeRate {
        return $service->storeExchangeRate($company, [
            'currency'     => 'USD',
            'rate'         => '14000',
            'effective_at' => now()->toDateTimeString(),
        ]);
    });

    expect($rate->base_currency)->toBe('SYP')
        ->and($rate->source)->toBe('manual')
        ->and($rate->created_by)->toBe($user->getKey());
});

it('storeExchangeRate throws for a zero rate', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $service = app(CompanySettingsService::class);

    expect(fn () => app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->storeExchangeRate($company, [
            'currency'     => 'USD',
            'rate'         => '0',
            'effective_at' => now()->toDateTimeString(),
        ])
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('updateForexAccount rejects an inactive account', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $inactive = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => false]));

    $service = app(CompanySettingsService::class);

    expect(fn () => app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->updateForexAccount($company, 'exchange_gain_account_id', $inactive->getKey())
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('updateForexAccount rejects an account with the wrong currency', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $usdAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'USD', 'is_active' => true]));

    $service = app(CompanySettingsService::class);

    expect(fn () => app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->updateForexAccount($company, 'exchange_gain_account_id', $usdAcc->getKey())
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('updateForexAccount rejects a gain account of the wrong type', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $expAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'expense', 'currency' => 'SYP', 'is_active' => true]));

    $service = app(CompanySettingsService::class);

    expect(fn () => app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->updateForexAccount($company, 'exchange_gain_account_id', $expAcc->getKey())
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('updateForexAccount rejects a loss account of the wrong type', function (): void {
    ['company' => $company] = settingsSetUp('SYP');

    $revAcc = app(CompanyContextService::class)->runForCompany($company,
        fn () => settingsMakeAccount($company, ['account_type' => 'revenue', 'currency' => 'SYP', 'is_active' => true]));

    $service = app(CompanySettingsService::class);

    expect(fn () => app(CompanyContextService::class)->runForCompany($company,
        fn () => $service->updateForexAccount($company, 'exchange_loss_account_id', $revAcc->getKey())
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});
