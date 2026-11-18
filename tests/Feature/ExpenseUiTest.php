<?php

use App\Models\Company;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyContextService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     company: Company,
 *     user: User,
 *     party: Party,
 *     accounts: array<string, FinancialAccount>
 * }
 */
function expenseUiSetUp(string $baseCurrency = 'SYP'): array
{
    $company = Company::query()->create([
        'name' => 'Expense shop '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());

    $party = Party::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Expense supplier '.uniqid(),
    ]);

    $accounts = [];
    foreach ([
        'expense_syp' => ['expense', 'SYP'],
        'cash_syp' => ['asset', 'SYP'],
        'payable_syp' => ['liability', 'SYP'],
        'tax_asset_syp' => ['asset', 'SYP'],
        'expense_usd' => ['expense', 'USD'],
        'cash_usd' => ['asset', 'USD'],
        'payable_usd' => ['liability', 'USD'],
        'tax_asset_usd' => ['asset', 'USD'],
        'gain' => ['income', $baseCurrency],
        'loss' => ['expense', $baseCurrency],
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

    return compact('company', 'user', 'party', 'accounts');
}

function expenseUiAs(User $user, Company $company): void
{
    Auth::login($user);
    app(CompanyContextService::class)->switchTo($user, (int) $company->getKey());
}

function expenseUiSession(Company $company): array
{
    return ['active_company_id' => (int) $company->getKey(), 'locale' => 'en'];
}

function expenseUiStoreDraft($testCase, array $context, array $overrides = []): Expense
{
    $currency = $overrides['currency'] ?? 'SYP';
    $paymentType = $overrides['payment_type'] ?? 'paid';
    $suffix = strtolower($currency);

    $payload = array_merge([
        'expense_date' => $overrides['expense_date'] ?? now()->toDateString(),
        'party_id' => $overrides['party_id'] ?? $context['party']->getKey(),
        'currency' => $currency,
        'description' => $overrides['description'] ?? 'Operating expense',
        'payment_type' => $paymentType,
        'lines' => $overrides['lines'] ?? [[
            'expense_account_id' => $context['accounts']['expense_'.$suffix]->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ], array_filter([
        'exchange_rate' => $overrides['exchange_rate'] ?? null,
        'notes' => $overrides['notes'] ?? null,
        'payment_financial_account_id' => $paymentType === 'paid' ? ($overrides['payment_financial_account_id'] ?? $context['accounts']['cash_'.$suffix]->getKey()) : null,
        'payable_account_id' => $paymentType === 'unpaid' ? ($overrides['payable_account_id'] ?? $context['accounts']['payable_'.$suffix]->getKey()) : null,
    ], fn ($value) => $value !== null));

    $testCase->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->post(route('expenses.store'), $payload)
        ->assertSessionHasNoErrors();

    return Expense::query()->latest('id')->firstOrFail();
}

it('creates an expense draft with multiple lines and recalculated totals', function (): void {
    $context = expenseUiSetUp();

    $expense = expenseUiStoreDraft($this, $context, [
        'lines' => [
            [
                'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
                'category' => 'Travel',
                'description' => 'Ground transport',
                'quantity' => '2',
                'unit_price' => '100',
                'tax_amount' => '10',
                'tax_treatment' => 'recoverable',
                'tax_asset_account_id' => $context['accounts']['tax_asset_syp']->getKey(),
            ],
            [
                'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
                'category' => 'Office',
                'description' => 'Stationery',
                'quantity' => '1',
                'unit_price' => '50',
                'tax_amount' => '5',
                'tax_treatment' => 'non_recoverable',
            ],
        ],
    ]);

    expect($expense->status)->toBe('draft')
        ->and($expense->currency)->toBe('SYP')
        ->and($expense->amount)->toBe('265.0000')
        ->and($expense->tax_total)->toBe('15.0000')
        ->and($expense->lines)->count()->toBe(2)
        ->and($expense->lines->first()->line_total)->toBe('200.0000');

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.show', $expense))
        ->assertOk()
        ->assertSee($expense->expense_number)
        ->assertSee('265.0000 SYP');
});

it('requires a tax asset account for recoverable tax and forbids it for non-recoverable tax', function (): void {
    $context = expenseUiSetUp();

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(),
            'party_id' => $context['party']->getKey(),
            'currency' => 'SYP',
            'description' => 'Tax classification check',
            'payment_type' => 'paid',
            'payment_financial_account_id' => $context['accounts']['cash_syp']->getKey(),
            'lines' => [[
                'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
                'category' => 'Travel',
                'description' => 'Ground transport',
                'quantity' => '1',
                'unit_price' => '100',
                'tax_amount' => '10',
                'tax_treatment' => 'recoverable',
            ]],
        ])
        ->assertSessionHasErrors(['lines.0.tax_asset_account_id']);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(),
            'party_id' => $context['party']->getKey(),
            'currency' => 'SYP',
            'description' => 'Tax classification check',
            'payment_type' => 'paid',
            'payment_financial_account_id' => $context['accounts']['cash_syp']->getKey(),
            'lines' => [[
                'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
                'category' => 'Travel',
                'description' => 'Ground transport',
                'quantity' => '1',
                'unit_price' => '100',
                'tax_amount' => '10',
                'tax_treatment' => 'non_recoverable',
                'tax_asset_account_id' => $context['accounts']['tax_asset_syp']->getKey(),
            ]],
        ])
        ->assertSessionHasErrors(['lines.0.tax_asset_account_id']);
});

it('records an immediately paid expense with a zero outstanding balance', function (): void {
    $context = expenseUiSetUp();

    $expense = expenseUiStoreDraft($this, $context, ['payment_type' => 'paid']);
    expect($expense->payment_financial_account_id)->not->toBeNull()
        ->and($expense->payable_account_id)->toBeNull();

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->post(route('expenses.approve', $expense))
        ->assertSessionHasNoErrors();
    $this->post(route('expenses.post', $expense))->assertSessionHasNoErrors();

    $expense->refresh();
    expect($expense->status)->toBe('posted')
        ->and($expense->postedJournal)->not->toBeNull()
        ->and(app(ExpenseService::class)->outstandingBalance($expense))->toBe('0.0000');

    $this->get(route('expenses.show', $expense))
        ->assertOk()
        ->assertSee('Paid now')
        ->assertDontSee('Settle expense');
});

it('records an unpaid expense and keeps the full amount outstanding', function (): void {
    $context = expenseUiSetUp();

    $expense = expenseUiStoreDraft($this, $context, [
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '2',
            'unit_price' => '100',
        ]],
    ]);
    expect($expense->payable_account_id)->not->toBeNull()
        ->and($expense->payment_financial_account_id)->toBeNull();

    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);

    $expense->refresh();
    expect($expense->status)->toBe('posted')
        ->and(bccomp(app(ExpenseService::class)->outstandingBalance($expense), '200', 4))->toBe(0);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.show', $expense))
        ->assertOk()
        ->assertSee('Unpaid')
        ->assertSee('200.0000 SYP')
        ->assertSee('Settle expense');
});

it('approves and posts an expense through the lifecycle actions', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->post(route('expenses.approve', $expense))
        ->assertRedirect(route('expenses.show', $expense))
        ->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe('approved')
        ->and($expense->fresh()->approved_at)->not->toBeNull()
        ->and($expense->fresh()->base_amount)->toBe('100.0000');

    $this->post(route('expenses.post', $expense))
        ->assertRedirect(route('expenses.show', $expense))
        ->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe('posted')
        ->and($expense->fresh()->posted_at)->not->toBeNull()
        ->and($expense->fresh()->accounting_journal_id)->not->toBeNull();
});

it('supports partial and full settlement of a posted unpaid expense', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context, [
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '2',
            'unit_price' => '100',
        ]],
    ]);
    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);

    $service = app(ExpenseService::class);
    expect(bccomp($service->outstandingBalance($expense->fresh()), '200', 4))->toBe(0);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->from(route('expenses.show', $expense))
        ->post(route('expenses.settle', $expense), [
            'amount' => '80.0000',
            'currency' => 'SYP',
            'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
            'paid_at' => now()->toDateString(),
            'reference' => 'PART-001',
        ])
        ->assertRedirect(route('expenses.show', $expense))
        ->assertSessionHasNoErrors();
    expect(bccomp($service->outstandingBalance($expense->fresh()), '120', 4))->toBe(0);

    $this->from(route('expenses.show', $expense))
        ->post(route('expenses.settle', $expense), [
            'amount' => '120.0000',
            'currency' => 'SYP',
            'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
            'paid_at' => now()->toDateString(),
            'reference' => 'PART-002',
        ])
        ->assertSessionHasNoErrors();
    expect(bccomp($service->outstandingBalance($expense->fresh()), '0', 4))->toBe(0)
        ->and($expense->payments()->count())->toBe(2);
});

it('rejects settlement that exceeds the outstanding balance', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context, [
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ]);
    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->from(route('expenses.show', $expense))
        ->post(route('expenses.settle', $expense), [
            'amount' => '300.0000',
            'currency' => 'SYP',
            'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
            'paid_at' => now()->toDateString(),
        ])
        ->assertSessionHasErrors(['amount']);

    expect(bccomp(app(ExpenseService::class)->outstandingBalance($expense->fresh()), '100', 4))->toBe(0)
        ->and($expense->payments()->count())->toBe(0);
});

it('reverses active payments before reversing the expense in one transaction', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context, [
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ]);
    app(ExpenseService::class)->approveExpense($expense);
    app(ExpenseService::class)->postExpense($expense);
    $expense->refresh();

    app(PaymentService::class)->recordExpensePayment($expense, [
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        'amount' => '100.0000',
        'currency' => 'SYP',
        'paid_at' => now()->toDateString(),
    ]);
    $payment = $expense->payments()->firstOrFail();
    expect($payment->status)->toBe('posted');

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->from(route('expenses.show', $expense))
        ->post(route('expenses.reverse', $expense))
        ->assertRedirect(route('expenses.show', $expense))
        ->assertSessionHasNoErrors();

    expect($expense->fresh()->status)->toBe('reversed')
        ->and($expense->fresh()->reversal_journal_id)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe('reversed');
});

it('keeps native amounts separated by currency in the listing and reporting', function (): void {
    $context = expenseUiSetUp();

    $syp = expenseUiStoreDraft($this, $context, [
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '2',
            'unit_price' => '100',
            'tax_amount' => '10',
            'tax_treatment' => 'non_recoverable',
        ]],
    ]);
    app(ExpenseService::class)->approveExpense($syp);
    app(ExpenseService::class)->postExpense($syp);

    $usd = expenseUiStoreDraft($this, $context, [
        'currency' => 'USD',
        'exchange_rate' => '12500',
        'payment_type' => 'unpaid',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_usd']->getKey(),
            'category' => 'Software',
            'description' => 'Annual license',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ]);
    app(ExpenseService::class)->approveExpense($usd);
    app(ExpenseService::class)->postExpense($usd);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertSee('210.0000 SYP')
        ->assertSee('100.0000 USD')
        ->assertSee('1250000.0000 SYP');

    $byCurrency = app(ExpenseService::class)->totalsByCurrency($context['company']);
    expect($byCurrency)->toHaveKey('SYP')
        ->and($byCurrency)->toHaveKey('USD')
        ->and($byCurrency['SYP'])->toBe('210.0000')
        ->and($byCurrency['USD'])->toBe('100.0000');
});

it('filters the expense listing by party, status, currency and date', function (): void {
    $context = expenseUiSetUp();
    $syp = expenseUiStoreDraft($this, $context, ['description' => 'Visible expense']);
    $usd = expenseUiStoreDraft($this, $context, [
        'currency' => 'USD',
        'exchange_rate' => '12500',
        'description' => 'Hidden expense',
    ]);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.index', [
            'currency' => 'SYP',
            'status' => 'draft',
            'party_id' => $context['party']->getKey(),
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee($syp->expense_number)
        ->assertDontSee($usd->expense_number);
});

it('prints a professional expense voucher', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context, [
        'notes' => 'Authorised by finance',
        'lines' => [[
            'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
            'category' => 'Travel',
            'description' => 'Ground transport',
            'quantity' => '2',
            'unit_price' => '100',
            'tax_amount' => '10',
            'tax_treatment' => 'recoverable',
            'tax_asset_account_id' => $context['accounts']['tax_asset_syp']->getKey(),
        ]],
    ]);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.print', $expense))
        ->assertOk()
        ->assertSee($context['company']->name)
        ->assertSee($expense->expense_number)
        ->assertSee('Expense voucher')
        ->assertSee('210.0000 SYP')
        ->assertSee('Authorised by finance');
});

it('isolates expenses by company and enforces authorization', function (): void {
    $context = expenseUiSetUp();
    $otherCompany = Company::query()->create([
        'name' => 'Private expense company',
        'base_currency' => 'SYP',
    ]);
    $otherCompany->users()->attach($context['user']);
    expenseUiAs($context['user'], $otherCompany);

    $otherExpense = app(ExpenseService::class)->createDraftExpense([
        'expense_date' => now()->toDateString(),
        'description' => 'Private expense',
        'currency' => 'SYP',
        'base_currency' => 'SYP',
        'payment_financial_account_id' => FinancialAccount::query()->create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'CASH-PRIVATE',
            'name' => 'Private cash',
            'account_type' => 'asset',
            'currency' => 'SYP',
            'is_active' => true,
        ])->getKey(),
    ], $otherCompany);
    app(ExpenseService::class)->addExpenseLine($otherExpense, [
        'expense_account_id' => FinancialAccount::query()->create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'EXP-PRIVATE',
            'name' => 'Private expense account',
            'account_type' => 'expense',
            'currency' => 'SYP',
            'is_active' => true,
        ])->getKey(),
        'category' => 'Private',
        'description' => 'Private line',
        'quantity' => '1',
        'unit_price' => '50',
    ]);

    expenseUiAs($context['user'], $context['company']);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->get(route('expenses.show', $otherExpense))
        ->assertNotFound();

    $unlinkedUser = User::factory()->create();
    $this->actingAs($unlinkedUser)
        ->withSession(['locale' => 'en'])
        ->get(route('expenses.index'))
        ->assertForbidden();
});

it('requires authentication before showing expense screens', function (): void {
    $this->withSession(['locale' => 'en'])
        ->get(route('expenses.index'))
        ->assertRedirect(route('login'));
});

it('deletes a draft expense and forbids deleting approved expenses', function (): void {
    $context = expenseUiSetUp();
    $expense = expenseUiStoreDraft($this, $context);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->delete(route('expenses.destroy', $expense))
        ->assertRedirect(route('expenses.index'))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('expenses', ['id' => $expense->getKey()]);

    $approved = expenseUiStoreDraft($this, $context);
    app(ExpenseService::class)->approveExpense($approved);

    $this->actingAs($context['user'])
        ->withSession(expenseUiSession($context['company']))
        ->from(route('expenses.show', $approved))
        ->delete(route('expenses.destroy', $approved))
        ->assertSessionHasErrors();
    expect($approved->fresh()->status)->toBe('approved');
});
