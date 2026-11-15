<?php

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use App\Services\CompanyContextService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     company: Company,
 *     user: User,
 *     party: Party,
 *     accounts: array<string, FinancialAccount>
 * }
 */
function expenseWorkflowContext(string $baseCurrency = 'SYP'): array
{
    $company = Company::create([
        'name' => 'Expense company '.uniqid(),
        'base_currency' => $baseCurrency,
    ]);
    $user = User::factory()->create();
    $company->users()->attach($user);
    app(CompanyContextService::class)->switchTo($user, $company->getKey());
    Auth::login($user);

    $party = Party::create([
        'company_id' => $company->getKey(),
        'name' => 'Expense supplier '.uniqid(),
    ]);

    $accounts = [];
    foreach ([
        'expense_syp' => ['expense', 'SYP'],
        'expense_usd' => ['expense', 'USD'],
        'cash_syp' => ['asset', 'SYP'],
        'cash_usd' => ['asset', 'USD'],
        'payable_syp' => ['liability', 'SYP'],
        'payable_usd' => ['liability', 'USD'],
        'tax_syp' => ['asset', 'SYP'],
        'tax_usd' => ['asset', 'USD'],
        'gain' => ['income', $baseCurrency],
        'loss' => ['expense', $baseCurrency],
    ] as $key => [$type, $currency]) {
        $accounts[$key] = FinancialAccount::create([
            'company_id' => $company->getKey(),
            'code' => strtoupper($key).'-'.uniqid(),
            'name' => ucwords(str_replace('_', ' ', $key)),
            'account_type' => $type,
            'currency' => $currency,
        ]);
    }

    return compact('company', 'user', 'party', 'accounts');
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $overrides
 * @return array{expense: Expense, line: ExpenseLine}
 */
function createExpenseDraft(array $context, array $overrides = []): array
{
    $currency = strtoupper((string) ($overrides['currency'] ?? $context['company']->base_currency));
    $isPaid = (bool) ($overrides['is_paid'] ?? true);
    $attributes = [
        'expense_number' => 'EXP-'.uniqid(),
        'expense_date' => '2026-10-14',
        'party_id' => $context['party']->getKey(),
        'description' => 'Travel and operating expense',
        'currency' => $currency,
        'exchange_rate' => $overrides['exchange_rate'] ?? null,
        'payment_financial_account_id' => $isPaid
            ? $context['accounts']['cash_'.strtolower($currency)]->getKey()
            : null,
        'payable_account_id' => $isPaid
            ? null
            : $context['accounts']['payable_'.strtolower($currency)]->getKey(),
    ];
    unset($attributes['exchange_rate']);
    if (array_key_exists('exchange_rate', $overrides)) {
        $attributes['exchange_rate'] = $overrides['exchange_rate'];
    }
    if (isset($overrides['description'])) {
        $attributes['description'] = $overrides['description'];
    }
    if (isset($overrides['expense_number'])) {
        $attributes['expense_number'] = $overrides['expense_number'];
    }
    $expense = app(ExpenseService::class)->createDraftExpense($attributes, $context['company']);
    $line = app(ExpenseService::class)->addExpenseLine($expense, [
        'expense_account_id' => $overrides['expense_account_id']
            ?? $context['accounts']['expense_'.strtolower($currency)]->getKey(),
        'category' => $overrides['category'] ?? 'Travel',
        'description' => $overrides['line_description'] ?? 'Ground transport',
        'quantity' => $overrides['quantity'] ?? '1',
        'unit_price' => $overrides['unit_price'] ?? '100',
    ]);

    return ['expense' => $expense->fresh(), 'line' => $line];
}

function approveAndPostExpense(Expense $expense): Expense
{
    $service = app(ExpenseService::class);
    $service->approveExpense($expense);

    return $service->postExpense($expense->fresh());
}

it('creates and edits drafts, then locks the approved snapshot', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);
    $draft = $service->createDraftExpense([
        'expense_date' => '2026-10-14',
        'description' => 'Initial description',
        'payment_financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ], $context['company']);
    $line = $service->addExpenseLine($draft, [
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        'category' => 'Travel',
        'description' => 'Taxi',
        'quantity' => '2',
        'unit_price' => '5',
    ]);

    $service->updateExpenseLine($line, ['quantity' => '3']);
    $draft = $service->updateDraftExpense($draft, ['description' => 'Updated description']);
    $approved = $service->approveExpense($draft);

    expect($draft->fresh()->amount)->toBe('15.0000')
        ->and($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($context['user']->getKey())
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->amount)->toBe('15.0000')
        ->and(fn () => $service->updateDraftExpense($approved, ['description' => 'Tampered']))
        ->toThrow(ValidationException::class);
});

it('posts multiple expense lines once and keeps posted data immutable', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);
    ['expense' => $expense] = createExpenseDraft($context, ['unit_price' => '30']);
    $service->addExpenseLine($expense, [
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        'category' => 'Meals',
        'description' => 'Team meal',
        'quantity' => '2',
        'unit_price' => '10',
    ]);

    $posted = approveAndPostExpense($expense);
    $journal = $posted->postedJournal;
    $debits = $journal->lines->where('debit', '>', 0)->values();
    $credit = $journal->lines->firstWhere('credit', '>', 0);

    expect($posted->status)->toBe('posted')
        ->and($posted->posted_by)->toBe($context['user']->getKey())
        ->and($posted->posted_at)->not->toBeNull()
        ->and($journal->sourceable_type)->toBe(Expense::class)
        ->and((int) $journal->sourceable_id)->toBe((int) $posted->getKey())
        ->and($debits)->toHaveCount(2)
        ->and(bcadd((string) $debits[0]->debit, (string) $debits[1]->debit, 4))->toBe('50.0000')
        ->and($credit->financial_account_id)->toBe($context['accounts']['cash_syp']->getKey())
        ->and($credit->credit)->toBe('50.0000')
        ->and($posted->accountingJournals()->where('reversal_of_journal_id', null)->count())->toBe(1)
        ->and(fn () => $service->postExpense($posted))
        ->toThrow(ValidationException::class);

    expect(fn () => $posted->update(['description' => 'Changed after posting']))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->deleteDraftExpense($posted))
        ->toThrow(ValidationException::class);
});

it('posts an unpaid expense to its configured payable account', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['is_paid' => false, 'unit_price' => '75']);
    $posted = approveAndPostExpense($expense);
    $journal = $posted->postedJournal;
    $credit = $journal->lines->firstWhere('credit', '>', 0);

    expect($credit->financial_account_id)->toBe($context['accounts']['payable_syp']->getKey())
        ->and($credit->credit)->toBe('75.0000')
        ->and($credit->currency)->toBe('SYP')
        ->and($credit->base_credit)->toBe('75.0000')
        ->and(app(ExpenseService::class)->outstandingBalance($posted))->toBe('75.0000000000');
});

it('rejects invalid expense accounts and accounts owned by another company', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);
    $wrongType = createExpenseDraft($context, [
        'expense_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);
    expect(fn () => $service->approveExpense($wrongType['expense']))
        ->toThrow(ValidationException::class);

    $otherCompany = Company::create(['name' => 'Other expense company']);
    $otherAccount = app(CompanyContextService::class)->runForCompany(
        $otherCompany,
        fn (): FinancialAccount => FinancialAccount::create([
            'company_id' => $otherCompany->getKey(),
            'code' => 'FOREIGN-'.uniqid(),
            'name' => 'Other expense',
            'account_type' => 'expense',
            'currency' => 'SYP',
        ]),
    );
    $otherExpense = $service->createDraftExpense([
        'expense_date' => '2026-10-14',
        'description' => 'Cross-company test',
    ], $context['company']);

    expect(fn () => $service->addExpenseLine($otherExpense, [
        'expense_account_id' => $otherAccount->getKey(),
        'category' => 'Travel',
        'description' => 'Foreign account',
        'quantity' => '1',
        'unit_price' => '1',
    ]))->toThrow(ValidationException::class);
});

it('rejects missing, wrong-type, or wrong-currency account configuration', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);

    $missingCredit = $service->createDraftExpense([
        'expense_date' => '2026-10-14',
        'description' => 'Missing credit account',
    ], $context['company']);
    $service->addExpenseLine($missingCredit, [
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        'category' => 'Travel',
        'description' => 'Taxi',
        'quantity' => '1',
        'unit_price' => '10',
    ]);

    $wrongPayable = $service->createDraftExpense([
        'expense_date' => '2026-10-14',
        'description' => 'Wrong payable account',
        'payable_account_id' => $context['accounts']['cash_syp']->getKey(),
    ], $context['company']);
    $service->addExpenseLine($wrongPayable, [
        'expense_account_id' => $context['accounts']['expense_syp']->getKey(),
        'category' => 'Travel',
        'description' => 'Taxi',
        'quantity' => '1',
        'unit_price' => '10',
    ]);

    ['expense' => $wrongCurrency] = createExpenseDraft($context, [
        'expense_account_id' => $context['accounts']['expense_usd']->getKey(),
    ]);

    expect(fn () => $service->approveExpense($missingCredit))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->approveExpense($wrongPayable))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->approveExpense($wrongCurrency))
        ->toThrow(ValidationException::class);
});

it('posts recoverable tax to an asset and includes non-recoverable tax in expense', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);
    ['expense' => $expense, 'line' => $line] = createExpenseDraft($context, ['unit_price' => '100']);
    $service->addExpenseTaxLine($line, [
        'treatment' => 'recoverable',
        'amount' => '5',
        'tax_asset_account_id' => $context['accounts']['tax_syp']->getKey(),
    ]);
    $service->addExpenseTaxLine($line, [
        'treatment' => 'non_recoverable',
        'amount' => '3',
    ]);

    $posted = approveAndPostExpense($expense);
    $journalLines = $posted->postedJournal->lines;
    $expenseDebit = $journalLines->firstWhere('financial_account_id', $context['accounts']['expense_syp']->getKey());
    $taxDebit = $journalLines->firstWhere('financial_account_id', $context['accounts']['tax_syp']->getKey());
    $credit = $journalLines->firstWhere('credit', '>', 0);

    expect($posted->amount)->toBe('108.0000')
        ->and($posted->tax_total)->toBe('8.0000')
        ->and($expenseDebit->debit)->toBe('103.0000')
        ->and($taxDebit->debit)->toBe('5.0000')
        ->and($credit->credit)->toBe('108.0000')
        ->and(bcadd($expenseDebit->base_debit, $taxDebit->base_debit, 4))->toBe($credit->base_credit);
});

it('rejects unclassified or mismatched tax and recoverable tax without an asset account', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);

    ['expense' => $unclassified, 'line' => $unclassifiedLine] = createExpenseDraft($context);
    $service->addExpenseTaxLine($unclassifiedLine, ['amount' => '5']);
    expect(fn () => $service->approveExpense($unclassified))
        ->toThrow(ValidationException::class);

    ['expense' => $mismatched, 'line' => $mismatchedLine] = createExpenseDraft($context);
    $service->addExpenseTaxLine($mismatchedLine, [
        'treatment' => 'non_recoverable',
        'amount' => '5',
    ]);
    DB::table('expenses')->where('id', $mismatched->getKey())->update(['tax_total' => '7.0000']);
    expect(fn () => $service->approveExpense($mismatched->fresh()))
        ->toThrow(ValidationException::class);

    ['expense' => $missingAsset, 'line' => $missingAssetLine] = createExpenseDraft($context);
    $service->addExpenseTaxLine($missingAssetLine, [
        'treatment' => 'recoverable',
        'amount' => '5',
    ]);
    expect(fn () => $service->approveExpense($missingAsset))
        ->toThrow(ValidationException::class);

    ['expense' => $wrongAssetType, 'line' => $wrongAssetTypeLine] = createExpenseDraft($context);
    $service->addExpenseTaxLine($wrongAssetTypeLine, [
        'treatment' => 'recoverable',
        'amount' => '5',
        'tax_asset_account_id' => $context['accounts']['expense_syp']->getKey(),
    ]);
    expect(fn () => $service->approveExpense($wrongAssetType))
        ->toThrow(ValidationException::class);
});

it('rolls back a failed posting without creating a partial journal', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context);
    $approved = app(ExpenseService::class)->approveExpense($expense);
    JournalLine::creating(function (JournalLine $line): void {
        if ($line->journal?->sourceable_type === Expense::class) {
            throw ValidationException::withMessages(['journal_line' => 'Simulated journal-line failure.']);
        }
    });

    expect(fn () => app(ExpenseService::class)->postExpense($approved))
        ->toThrow(ValidationException::class);

    expect(AccountingJournal::withoutGlobalScope(CompanyScope::class)
        ->where('sourceable_type', Expense::class)
        ->where('sourceable_id', $expense->getKey())
        ->exists())->toBeFalse()
        ->and($expense->fresh()->status)->toBe('approved');
});

it('posts USD expenses in SYP base currency using their stored rate', function () {
    $context = expenseWorkflowContext('SYP');
    ['expense' => $expense] = createExpenseDraft($context, [
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'unit_price' => '20',
    ]);
    $posted = approveAndPostExpense($expense);
    $debit = $posted->postedJournal->lines->firstWhere('debit', '>', 0);
    $credit = $posted->postedJournal->lines->firstWhere('credit', '>', 0);

    expect($posted->currency)->toBe('USD')
        ->and($posted->exchange_rate)->toBe('12000.0000000000')
        ->and($posted->base_amount)->toBe('240000.0000')
        ->and($debit->debit)->toBe('20.0000')
        ->and($debit->currency)->toBe('USD')
        ->and($debit->base_debit)->toBe('240000.0000')
        ->and($credit->base_credit)->toBe('240000.0000');
});

it('preserves the historical exchange rate effective on the expense date', function () {
    $context = expenseWorkflowContext('SYP');
    ExchangeRate::create([
        'company_id' => $context['company']->getKey(),
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '12000',
        'effective_at' => '2026-10-01 00:00:00',
    ]);
    ExchangeRate::create([
        'company_id' => $context['company']->getKey(),
        'currency' => 'USD',
        'base_currency' => 'SYP',
        'rate' => '15000',
        'effective_at' => '2026-11-01 00:00:00',
    ]);
    ['expense' => $expense] = createExpenseDraft($context, [
        'currency' => 'USD',
        'unit_price' => '10',
    ]);

    $posted = approveAndPostExpense($expense);

    expect($posted->exchange_rate)->toBe('12000.0000000000')
        ->and($posted->base_amount)->toBe('120000.0000');
});

it('rejects a foreign-currency expense without an effective exchange rate', function () {
    $context = expenseWorkflowContext('SYP');
    ['expense' => $expense] = createExpenseDraft($context, [
        'currency' => 'USD',
        'unit_price' => '10',
    ]);

    expect(fn () => app(ExpenseService::class)->approveExpense($expense))
        ->toThrow(ValidationException::class);
});

it('settles expenses partially and fully without exceeding the outstanding amount', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['is_paid' => false, 'unit_price' => '100']);
    $posted = approveAndPostExpense($expense);
    $service = app(PaymentService::class);

    $first = $service->recordExpensePayment($posted, [
        'amount' => '40',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);
    $second = $service->recordExpensePayment($posted, [
        'amount' => '30',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);
    expect($first->status)->toBe('posted')
        ->and($first->applied_amount)->toBe('40.0000')
        ->and($first->applied_currency)->toBe('SYP')
        ->and($second->status)->toBe('posted')
        ->and($service->calculateOutstandingForExpense($posted))->toBe('30.0000000000')
        ->and(fn () => $service->recordExpensePayment($posted, [
            'amount' => '31',
            'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        ]))->toThrow(ValidationException::class)
        ->and(Payment::where('payable_type', Expense::class)->where('payable_id', $posted->getKey())->count())->toBe(2);

    $last = $service->recordExpensePayment($posted, [
        'amount' => '30',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);
    expect($last->posted_at)->not->toBeNull()
        ->and($service->calculateOutstandingForExpense($posted))->toBe('0.0000000000');
});

it('rejects expense payments before posting', function () {
    $context = expenseWorkflowContext();
    ['expense' => $draft] = createExpenseDraft($context, ['is_paid' => false]);

    expect(fn () => app(PaymentService::class)->recordExpensePayment($draft, [
        'amount' => '10',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]))->toThrow(ValidationException::class)
        ->and(Payment::where('payable_type', Expense::class)->count())->toBe(0);
});

it('restores an expense balance when a settlement payment is reversed', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['is_paid' => false, 'unit_price' => '100']);
    $posted = approveAndPostExpense($expense);
    $payments = app(PaymentService::class);
    $payment = $payments->recordExpensePayment($posted, [
        'amount' => '25',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);

    $payments->reversePayment($payment, [], $context['company']);
    $reversedPayment = $payment->fresh();
    $history = app(ExpenseService::class)->expensePaymentsAndReversals($posted);

    expect($reversedPayment->status)->toBe('reversed')
        ->and($history)->toHaveCount(2)
        ->and($history->pluck('status')->all())->toBe(['reversed', 'posted'])
        ->and($payments->calculateOutstandingForExpense($posted))->toBe('100.0000000000')
        ->and($posted->payments()->where('status', 'reversed')->whereNull('reversal_of_payment_id')->count())->toBe(1)
        ->and($posted->payments()->whereNotNull('reversal_of_payment_id')->where('status', 'posted')->count())->toBe(1);
});

it('settles a USD expense in SYP while preserving applied currency and historical base amounts', function () {
    $context = expenseWorkflowContext('SYP');
    ['expense' => $expense] = createExpenseDraft($context, [
        'is_paid' => false,
        'currency' => 'USD',
        'exchange_rate' => '12000',
        'unit_price' => '100',
    ]);
    $posted = approveAndPostExpense($expense);
    $payment = app(PaymentService::class)->recordExpensePayment($posted, [
        'amount' => '240000',
        'currency' => 'SYP',
        'exchange_rate' => '1',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        'exchange_gain_account_id' => $context['accounts']['gain']->getKey(),
        'exchange_loss_account_id' => $context['accounts']['loss']->getKey(),
    ]);

    expect($payment->currency)->toBe('SYP')
        ->and($payment->amount)->toBe('240000.0000')
        ->and($payment->applied_currency)->toBe('USD')
        ->and($payment->applied_amount)->toBe('20.0000')
        ->and($payment->applied_exchange_rate)->toBe('12000.0000000000')
        ->and($payment->base_amount)->toBe('240000.0000')
        ->and($payment->applied_base_amount)->toBe('240000.0000')
        ->and(app(PaymentService::class)->calculateOutstandingForExpense($posted))->toBe('80.0000000000');
});

it('reverses an unpaid expense through the accounting reversal workflow', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['unit_price' => '60']);
    $posted = approveAndPostExpense($expense);
    $reversed = app(ExpenseService::class)->reverseExpense($posted);

    expect($reversed->status)->toBe('reversed')
        ->and($reversed->reversed_by)->toBe($context['user']->getKey())
        ->and($reversed->reversalJournal->status)->toBe('posted')
        ->and($reversed->reversalJournal->reversal_of_journal_id)->toBe($posted->accounting_journal_id)
        ->and($reversed->accountingJournals()->where('status', 'posted')->count())->toBe(2)
        ->and(fn () => app(ExpenseService::class)->reverseExpense($reversed))
        ->toThrow(ValidationException::class);
});

it('reports posted expenses by period, category, account, party and currency excluding drafts and reversals', function () {
    $context = expenseWorkflowContext();
    $service = app(ExpenseService::class);
    ['expense' => $paid] = createExpenseDraft($context, [
        'unit_price' => '100',
        'category' => 'Travel',
    ]);
    approveAndPostExpense($paid);

    ['expense' => $fullyPaid] = createExpenseDraft($context, [
        'is_paid' => false,
        'unit_price' => '50',
        'category' => 'Office',
    ]);
    $fullyPaid = approveAndPostExpense($fullyPaid);
    app(PaymentService::class)->recordExpensePayment($fullyPaid, [
        'amount' => '50',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);

    ['expense' => $partiallyPaid] = createExpenseDraft($context, [
        'is_paid' => false,
        'unit_price' => '25',
        'category' => 'Office',
    ]);
    $partiallyPaid = approveAndPostExpense($partiallyPaid);
    app(PaymentService::class)->recordExpensePayment($partiallyPaid, [
        'amount' => '5',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);

    createExpenseDraft($context, ['unit_price' => '999']);
    ['expense' => $reversed] = createExpenseDraft($context, ['unit_price' => '10']);
    $reversed = approveAndPostExpense($reversed);
    $service->reverseExpense($reversed);

    expect($service->totalExpensesByPeriod($context['company'], '2026-10-01', '2026-10-31'))->toBe('175.0000')
        ->and($service->totalsByCategory($context['company']))->toBe([
            'Office' => '75.0000',
            'Travel' => '100.0000',
        ])
        ->and($service->totalsByAccount($context['company']))->toBe([
            $context['accounts']['expense_syp']->getKey() => '175.0000',
        ])
        ->and($service->totalsByParty($context['company']))->toBe([
            (string) $context['party']->getKey() => '175.0000',
        ])
        ->and($service->totalsByCurrency($context['company']))->toBe(['SYP' => '175.0000'])
        ->and($service->paidExpenses($context['company'])->modelKeys())->toContain($paid->getKey(), $fullyPaid->getKey())
        ->and($service->unpaidExpenses($context['company'])->modelKeys())->toBe([$partiallyPaid->getKey()])
        ->and($service->outstandingBalances($context['company']))->toBe([$partiallyPaid->getKey() => '20.0000000000']);
});

it('rejects reports and payments that cross the active company boundary', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['is_paid' => false]);
    $otherCompany = Company::create(['name' => 'Second active company']);
    $otherUser = User::factory()->create();
    $otherCompany->users()->attach($otherUser);
    app(CompanyContextService::class)->switchTo($otherUser, $otherCompany->getKey());

    expect(fn () => app(ExpenseService::class)->approveExpense($expense))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(PaymentService::class)->recordExpensePayment($expense, [
            'amount' => '10',
            'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
        ]))->toThrow(ValidationException::class);
});

it('requires a balanced reversal of an expense to follow reversal of its payments', function () {
    $context = expenseWorkflowContext();
    ['expense' => $expense] = createExpenseDraft($context, ['is_paid' => false]);
    $posted = approveAndPostExpense($expense);
    app(PaymentService::class)->recordExpensePayment($posted, [
        'amount' => '10',
        'financial_account_id' => $context['accounts']['cash_syp']->getKey(),
    ]);

    expect(fn () => app(ExpenseService::class)->reverseExpense($posted))
        ->toThrow(ValidationException::class);
});
