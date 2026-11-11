<?php

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\User;
use App\Services\CompanyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware([StartSession::class, 'auth', 'company.context'])->get('/tenant-test/parties', function () {
        return response()->json([
            'company_id' => app(CompanyContextService::class)->id(),
            'parties' => Party::query()->orderBy('name')->pluck('name'),
        ]);
    });

    Route::middleware([
        StartSession::class,
        'auth',
        'company.context',
        SubstituteBindings::class,
    ])->get('/tenant-test/parties/{party}', function (Party $party) {
        Gate::authorize('view', $party);

        return response()->json(['party' => $party->name]);
    });
});

it('fails closed when a company-scoped query has no active company context', function () {
    $company = Company::create(['name' => 'Company A']);
    Party::create(['company_id' => $company->id, 'name' => 'Party A']);

    expect(Party::query()->count())->toBe(0)
        ->and(Party::withoutGlobalScope(CompanyScope::class)->count())->toBe(1);
});

it('limits a single-company user to that company and hides another company record', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $user = User::factory()->create();
    $companyA->users()->attach($user);
    $partyA = Party::create(['company_id' => $companyA->id, 'name' => 'Party A']);
    $partyB = Party::create(['company_id' => $companyB->id, 'name' => 'Party B']);

    $this->actingAs($user)
        ->get('/tenant-test/parties')
        ->assertOk()
        ->assertJsonPath('company_id', $companyA->id)
        ->assertJsonPath('parties', ['Party A'])
        ->assertSessionHas('active_company_id', $companyA->id);
    $this->get("/tenant-test/parties/{$partyB->id}")->assertNotFound();
    $this->get("/tenant-test/parties/{$partyA->id}")
        ->assertOk()
        ->assertJsonPath('party', 'Party A');
});

it('requires a multi-company user to select an authorized company and permits switching', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $user = User::factory()->create();
    $companyA->users()->attach($user);
    $companyB->users()->attach($user);
    Party::create(['company_id' => $companyA->id, 'name' => 'Party A']);
    Party::create(['company_id' => $companyB->id, 'name' => 'Party B']);

    $this->actingAs($user)->get('/tenant-test/parties')->assertStatus(409);

    $this->from('/tenant-test/parties')
        ->post('/company-context', ['company_id' => $companyA->id])
        ->assertRedirect('/tenant-test/parties');
    $this->get('/tenant-test/parties')->assertJsonPath('parties', ['Party A']);

    $this->from('/tenant-test/parties')
        ->post('/company-context', ['company_id' => $companyB->id])
        ->assertRedirect('/tenant-test/parties');
    $this->get('/tenant-test/parties')
        ->assertJsonPath('company_id', $companyB->id)
        ->assertJsonPath('parties', ['Party B']);
});

it('rejects a company switch when the user is not a member', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $user = User::factory()->create();
    $companyA->users()->attach($user);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $companyA->id])
        ->from('/')
        ->post('/company-context', ['company_id' => $companyB->id])
        ->assertNotFound()
        ->assertSessionHas('active_company_id', $companyA->id);
});

it('rejects a purchase that references a party from another company', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $partyB = Party::create(['company_id' => $companyB->id, 'name' => 'Party B']);

    expect(fn () => Purchase::create([
        'company_id' => $companyA->id,
        'party_id' => $partyB->id,
        'purchase_number' => 'PUR-001',
        'purchase_date' => '2026-10-08',
    ]))->toThrow(ValidationException::class);
});

it('rejects client-selected company ownership that differs from the active company', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $partyB = Party::create(['company_id' => $companyB->id, 'name' => 'Party B']);

    app(CompanyContextService::class)->runForCompany($companyA, function () use ($companyA, $companyB, $partyB): void {
        $createdParty = Party::create(['name' => 'Assigned to active company']);

        expect($createdParty->company_id)->toBe($companyA->id);

        expect(fn () => Purchase::create([
            'company_id' => $companyB->id,
            'party_id' => $partyB->id,
            'purchase_number' => 'PUR-SPOOFED',
            'purchase_date' => '2026-10-08',
        ]))->toThrow(ValidationException::class);
    });
});

it('rejects payments and journal lines referencing another company', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $purchaseB = Purchase::create([
        'company_id' => $companyB->id,
        'party_id' => Party::create([
            'company_id' => $companyB->id,
            'name' => 'Party B',
        ])->id,
        'purchase_number' => 'PUR-B-001',
        'purchase_date' => '2026-10-08',
    ]);
    $journalA = AccountingJournal::create([
        'company_id' => $companyA->id,
        'journal_number' => 'JRN-A-001',
        'entry_date' => '2026-10-08',
    ]);
    $accountB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '1000',
        'name' => 'Cash B',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    expect(fn () => Payment::create([
        'company_id' => $companyA->id,
        'payable_type' => Purchase::class,
        'payable_id' => $purchaseB->id,
        'direction' => 'outbound',
        'paid_at' => '2026-10-08 10:00:00',
        'amount' => '100',
        'currency' => 'SYP',
    ]))->toThrow(ValidationException::class);

    expect(fn () => JournalLine::create([
        'accounting_journal_id' => $journalA->id,
        'financial_account_id' => $accountB->id,
        'line_number' => 1,
        'debit' => '100',
    ]))->toThrow(ValidationException::class);
});

it('rejects attaching a file owned by another company', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $partyA = Party::create(['company_id' => $companyA->id, 'name' => 'Party A']);
    $attachmentB = Attachment::create([
        'company_id' => $companyB->id,
        'path' => 'private/company-b.pdf',
        'original_filename' => 'company-b.pdf',
        'size_bytes' => 100,
    ]);

    expect(fn () => $partyA->attachments()->attach($attachmentB))
        ->toThrow(ValidationException::class);
});

it('isolates company-specific multi-currency accounts and exchange rates', function () {
    $companyA = Company::create(['name' => 'Company A', 'base_currency' => 'SYP']);
    $companyB = Company::create(['name' => 'Company B', 'base_currency' => 'SYP']);
    $accountA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '1000-USD',
        'name' => 'USD Cash A',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '1000-SYP',
        'name' => 'SYP Cash A',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '1000-USD',
        'name' => 'USD Cash B',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    ExchangeRate::create([
        'company_id' => $companyA->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-08 09:00:00',
    ]);
    ExchangeRate::create([
        'company_id' => $companyB->id,
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '11000',
        'effective_at' => '2026-10-08 09:00:00',
    ]);
    ExchangeRate::create([
        'company_id' => null,
        'currency' => 'EUR',
        'base_currency' => 'SYP',
        'rate' => '13000',
        'effective_at' => '2026-10-08 09:00:00',
    ]);

    app(CompanyContextService::class)->runForCompany($companyA, function () use ($accountA): void {
        expect(FinancialAccount::query()->pluck('currency')->all())->toEqualCanonicalizing(['USD', 'SYP'])
            ->and(FinancialAccount::query()->whereKey($accountA->id)->exists())->toBeTrue()
            ->and(ExchangeRate::query()->orderBy('rate')->pluck('rate')->all())
            ->toBe(['12000.0000000000', '13000.0000000000']);
    });

    app(CompanyContextService::class)->runForCompany($companyB, function (): void {
        expect(FinancialAccount::query()->pluck('name')->all())->toBe(['USD Cash B'])
            ->and(ExchangeRate::query()->orderBy('rate')->pluck('rate')->all())
            ->toBe(['11000.0000000000', '13000.0000000000']);
    });
});

it('keeps standard user authentication and membership relationships available', function () {
    $company = Company::create(['name' => 'Company A']);
    $user = User::factory()->create();
    $company->users()->attach($user);

    expect($user->companies()->whereKey($company->id)->exists())->toBeTrue()
        ->and($user->email)->not->toBeEmpty();
});

it('allows a member to view company data but denies updates until a role grants them', function () {
    $company = Company::create(['name' => 'Company A']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    $party = Party::create(['company_id' => $company->id, 'name' => 'Party A']);
    $this->actingAs($user);

    app(CompanyContextService::class)->runForCompany($company, function () use ($party, $user): void {
        expect(Gate::forUser($user)->allows('view', $party))->toBeTrue()
            ->and(Gate::forUser($user)->allows('update', $party))->toBeFalse();
    });
});
