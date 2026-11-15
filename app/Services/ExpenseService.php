<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\ExpenseTaxLine;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly AccountingService $accounting,
        private readonly PaymentService $payments,
    ) {}

    public function createDraftExpense(array $attributes = [], ?Company $company = null): Expense
    {
        $company = $this->resolveCompany($company);
        $this->assertAllowedAttributes($attributes, [
            'company_id',
            'party_id',
            'project_id',
            'financial_account_id',
            'payment_financial_account_id',
            'payable_account_id',
            'expense_number',
            'expense_date',
            'status',
            'amount',
            'currency',
            'exchange_rate',
            'base_currency',
            'description',
            'notes',
        ]);

        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $company->getKey()) {
            $this->fail('company_id', 'The expense must belong to the active company.');
        }
        if (isset($attributes['status']) && $attributes['status'] !== 'draft') {
            $this->fail('status', 'New expenses must be created as drafts.');
        }

        $currency = strtoupper((string) ($attributes['currency'] ?? $company->base_currency));
        $baseCurrency = strtoupper((string) ($attributes['base_currency'] ?? $company->base_currency));
        $this->assertSupportedCurrencies($currency, $baseCurrency);

        $expense = DB::transaction(function () use ($attributes, $company, $currency, $baseCurrency): Expense {
            $exchangeRate = array_key_exists('exchange_rate', $attributes)
                ? $attributes['exchange_rate']
                : ($currency === $baseCurrency ? '1' : null);

            return Expense::create([
                'company_id' => $company->getKey(),
                'party_id' => $attributes['party_id'] ?? null,
                'project_id' => $attributes['project_id'] ?? null,
                'financial_account_id' => $attributes['financial_account_id'] ?? null,
                'payment_financial_account_id' => $attributes['payment_financial_account_id'] ?? null,
                'payable_account_id' => $attributes['payable_account_id'] ?? null,
                'created_by' => Auth::id(),
                'expense_number' => $attributes['expense_number'] ?? $this->generateExpenseNumber($company),
                'expense_date' => $attributes['expense_date'] ?? now()->toDateString(),
                'status' => 'draft',
                'amount' => $this->normalizeMoney((string) ($attributes['amount'] ?? '0'), 'amount'),
                'tax_total' => '0.0000',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'base_currency' => $baseCurrency,
                'base_amount' => '0.0000',
                'description' => $attributes['description'] ?? '',
                'notes' => $attributes['notes'] ?? null,
            ]);
        });

        return $expense->fresh();
    }

    public function updateDraftExpense(Expense $expense, array $attributes): Expense
    {
        $this->assertAllowedAttributes($attributes, [
            'party_id',
            'project_id',
            'financial_account_id',
            'payment_financial_account_id',
            'payable_account_id',
            'expense_number',
            'expense_date',
            'currency',
            'exchange_rate',
            'description',
            'notes',
        ]);

        return DB::transaction(function () use ($expense, $attributes): Expense {
            $company = $this->companyForExpense($expense);
            $lockedExpense = $this->lockExpense($expense, $company);
            $this->assertDraft($lockedExpense);

            if (array_key_exists('party_id', $attributes)) {
                $this->assertOptionalReference(Party::class, $attributes['party_id'], $company, 'party_id');
            }
            if (array_key_exists('project_id', $attributes)) {
                $this->assertOptionalReference(Project::class, $attributes['project_id'], $company, 'project_id');
            }

            $previousCurrency = strtoupper((string) $lockedExpense->currency);
            $currency = strtoupper((string) ($attributes['currency'] ?? $previousCurrency));
            $baseCurrency = strtoupper((string) $company->base_currency);
            $this->assertSupportedCurrencies($currency, $baseCurrency);
            if ($baseCurrency !== strtoupper((string) $lockedExpense->base_currency)) {
                $this->fail('base_currency', 'The expense base currency must remain the company base currency.');
            }

            foreach ([
                'party_id',
                'project_id',
                'financial_account_id',
                'payment_financial_account_id',
                'payable_account_id',
                'expense_number',
                'expense_date',
                'description',
                'notes',
            ] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $lockedExpense->{$field} = $attributes[$field];
                }
            }
            $lockedExpense->currency = $currency;

            if ($currency === $baseCurrency) {
                $lockedExpense->exchange_rate = '1';
            } elseif (array_key_exists('exchange_rate', $attributes)) {
                $lockedExpense->exchange_rate = $attributes['exchange_rate'];
            } elseif (
                $currency !== $previousCurrency
                || array_key_exists('expense_date', $attributes)
            ) {
                $lockedExpense->exchange_rate = null;
            }

            $lockedExpense->save();
            $this->recalculateDraftTotals($lockedExpense);

            return $lockedExpense->fresh();
        });
    }

    public function addExpenseLine(Expense $expense, array $attributes): ExpenseLine
    {
        $this->assertAllowedAttributes($attributes, [
            'expense_account_id',
            'category',
            'description',
            'quantity',
            'unit_price',
        ]);

        return DB::transaction(function () use ($expense, $attributes): ExpenseLine {
            $company = $this->companyForExpense($expense);
            $lockedExpense = $this->lockExpense($expense, $company);
            $this->assertDraft($lockedExpense);
            $this->assertRequired($attributes, ['expense_account_id', 'quantity', 'unit_price']);

            $quantity = $this->normalizeMoney((string) $attributes['quantity'], 'quantity');
            $unitPrice = $this->normalizeMoney((string) $attributes['unit_price'], 'unit_price');
            if (bccomp($quantity, '0', 4) <= 0) {
                $this->fail('quantity', 'The expense line quantity must be greater than zero.');
            }

            $line = $lockedExpense->lines()->create([
                'company_id' => $company->getKey(),
                'expense_account_id' => $attributes['expense_account_id'],
                'line_number' => (int) $lockedExpense->lines()->max('line_number') + 1,
                'category' => trim((string) ($attributes['category'] ?? '')),
                'description' => trim((string) ($attributes['description'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => bcmul($quantity, $unitPrice, 4),
            ]);

            $this->recalculateDraftTotals($lockedExpense);

            return $line->fresh();
        });
    }

    public function updateExpenseLine(ExpenseLine $line, array $attributes): ExpenseLine
    {
        $this->assertAllowedAttributes($attributes, [
            'expense_account_id',
            'category',
            'description',
            'quantity',
            'unit_price',
        ]);

        return DB::transaction(function () use ($line, $attributes): ExpenseLine {
            $company = $this->resolveCompany();
            $lockedLine = ExpenseLine::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->find($line->getKey());
            if ($lockedLine === null) {
                $this->fail('expense_line', 'The expense line was not found in the active company.');
            }

            $expense = $this->lockExpenseById((int) $lockedLine->expense_id, $company);
            $this->assertDraft($expense);

            foreach (['expense_account_id', 'category', 'description'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $lockedLine->{$field} = $field === 'category' || $field === 'description'
                        ? trim((string) $attributes[$field])
                        : $attributes[$field];
                }
            }
            if (array_key_exists('quantity', $attributes)) {
                $lockedLine->quantity = $this->normalizeMoney((string) $attributes['quantity'], 'quantity');
            }
            if (array_key_exists('unit_price', $attributes)) {
                $lockedLine->unit_price = $this->normalizeMoney((string) $attributes['unit_price'], 'unit_price');
            }
            if (bccomp((string) $lockedLine->quantity, '0', 4) <= 0) {
                $this->fail('quantity', 'The expense line quantity must be greater than zero.');
            }

            $lockedLine->line_total = bcmul((string) $lockedLine->quantity, (string) $lockedLine->unit_price, 4);
            $lockedLine->save();
            $this->recalculateDraftTotals($expense);

            return $lockedLine->fresh();
        });
    }

    public function deleteExpenseLine(ExpenseLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $company = $this->resolveCompany();
            $lockedLine = ExpenseLine::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->find($line->getKey());
            if ($lockedLine === null) {
                $this->fail('expense_line', 'The expense line was not found in the active company.');
            }

            $expense = $this->lockExpenseById((int) $lockedLine->expense_id, $company);
            $this->assertDraft($expense);
            foreach ($lockedLine->taxLines()->get() as $taxLine) {
                $taxLine->delete();
            }
            $lockedLine->delete();
            $this->recalculateDraftTotals($expense);
        });
    }

    public function addExpenseTaxLine(ExpenseLine $line, array $attributes): ExpenseTaxLine
    {
        $this->assertAllowedAttributes($attributes, [
            'treatment',
            'amount',
            'tax_asset_account_id',
        ]);
        $this->assertRequired($attributes, ['amount']);

        return DB::transaction(function () use ($line, $attributes): ExpenseTaxLine {
            $company = $this->resolveCompany();
            $lockedLine = ExpenseLine::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->find($line->getKey());
            if ($lockedLine === null) {
                $this->fail('expense_line', 'The expense line was not found in the active company.');
            }
            $expense = $this->lockExpenseById((int) $lockedLine->expense_id, $company);
            $this->assertDraft($expense);
            $treatment = $attributes['treatment'] ?? null;
            if ($treatment !== null && ! in_array($treatment, ['recoverable', 'non_recoverable'], true)) {
                $this->fail('treatment', 'Tax treatment must be recoverable or non-recoverable.');
            }

            $taxLine = $lockedLine->taxLines()->create([
                'company_id' => $company->getKey(),
                'treatment' => $treatment,
                'amount' => $this->normalizeMoney((string) $attributes['amount'], 'amount'),
                'tax_asset_account_id' => $attributes['tax_asset_account_id'] ?? null,
            ]);
            $this->recalculateDraftTotals($expense);

            return $taxLine->fresh();
        });
    }

    public function updateExpenseTaxLine(ExpenseTaxLine $taxLine, array $attributes): ExpenseTaxLine
    {
        $this->assertAllowedAttributes($attributes, [
            'treatment',
            'amount',
            'tax_asset_account_id',
        ]);

        return DB::transaction(function () use ($taxLine, $attributes): ExpenseTaxLine {
            $company = $this->resolveCompany();
            $lockedTaxLine = ExpenseTaxLine::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->find($taxLine->getKey());
            if ($lockedTaxLine === null) {
                $this->fail('expense_tax_line', 'The tax line was not found in the active company.');
            }
            $lockedLine = ExpenseLine::withoutGlobalScope(CompanyScope::class)
                ->find($lockedTaxLine->expense_line_id);
            if ($lockedLine === null) {
                $this->fail('expense_line', 'The tax line parent was not found.');
            }
            $expense = $this->lockExpenseById((int) $lockedLine->expense_id, $company);
            $this->assertDraft($expense);

            if (array_key_exists('treatment', $attributes)) {
                $treatment = $attributes['treatment'];
                if ($treatment !== null && ! in_array($treatment, ['recoverable', 'non_recoverable'], true)) {
                    $this->fail('treatment', 'Tax treatment must be recoverable or non-recoverable.');
                }
                $lockedTaxLine->treatment = $treatment;
            }
            if (array_key_exists('amount', $attributes)) {
                $lockedTaxLine->amount = $this->normalizeMoney((string) $attributes['amount'], 'amount');
            }
            if (array_key_exists('tax_asset_account_id', $attributes)) {
                $lockedTaxLine->tax_asset_account_id = $attributes['tax_asset_account_id'];
            }

            $lockedTaxLine->save();
            $this->recalculateDraftTotals($expense);

            return $lockedTaxLine->fresh();
        });
    }

    public function deleteExpenseTaxLine(ExpenseTaxLine $taxLine): void
    {
        DB::transaction(function () use ($taxLine): void {
            $company = $this->resolveCompany();
            $lockedTaxLine = ExpenseTaxLine::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->find($taxLine->getKey());
            if ($lockedTaxLine === null) {
                $this->fail('expense_tax_line', 'The tax line was not found in the active company.');
            }
            $line = ExpenseLine::withoutGlobalScope(CompanyScope::class)->find($lockedTaxLine->expense_line_id);
            if ($line === null) {
                $this->fail('expense_line', 'The tax line parent was not found.');
            }
            $expense = $this->lockExpenseById((int) $line->expense_id, $company);
            $this->assertDraft($expense);

            $lockedTaxLine->delete();
            $this->recalculateDraftTotals($expense);
        });
    }

    public function approveExpense(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense): Expense {
            $company = $this->companyForExpense($expense);
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedExpense = $this->lockExpense($expense, $company);
            $this->assertDraft($lockedExpense);

            $lines = $lockedExpense->lines()->with('taxLines')->orderBy('line_number')->get();
            $this->validateExpenseForApproval($lockedExpense, $company, $lines);

            $rate = $this->resolveExpenseRate($lockedExpense, $company);
            $amount = $this->normalizeMoney((string) $lockedExpense->amount, 'amount');
            $baseAmount = $this->normalizeMoney(bcmul($amount, $rate, 10), 'base_amount');
            $lockedExpense->currency = strtoupper((string) $lockedExpense->currency);
            $lockedExpense->base_currency = strtoupper((string) $company->base_currency);
            $lockedExpense->exchange_rate = $rate;
            $lockedExpense->base_amount = $baseAmount;

            $weights = [];
            foreach ($lines as $line) {
                $weights['line:'.$line->getKey()] = (string) $line->line_total;
                foreach ($line->taxLines as $taxLine) {
                    $weights['tax:'.$taxLine->getKey()] = (string) $taxLine->amount;
                }
            }
            $baseAllocations = $this->allocateBaseAmount($baseAmount, $weights);
            foreach ($lines as $line) {
                $line->base_amount = $baseAllocations['line:'.$line->getKey()];
                $line->save();
                foreach ($line->taxLines as $taxLine) {
                    $taxLine->base_amount = $baseAllocations['tax:'.$taxLine->getKey()];
                    $taxLine->save();
                }
            }

            $lockedExpense->save();
            $lockedExpense->markAsApprovedBy(Auth::user() instanceof User ? Auth::user() : null);
            $lockedExpense->save();

            return $lockedExpense->fresh(['lines.taxLines', 'approver']);
        });
    }

    public function postExpense(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense): Expense {
            $company = $this->companyForExpense($expense);
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedExpense = $this->lockExpense($expense, $company);
            if ($lockedExpense->status !== 'approved') {
                $this->fail('status', 'Only approved expenses can be posted.');
            }

            $this->assertNoOriginalJournal($lockedExpense);
            $lines = $lockedExpense->lines()->with('taxLines')->orderBy('line_number')->get();
            $this->validateApprovedExpense($lockedExpense, $company, $lines);

            $creditAccountId = $lockedExpense->payment_financial_account_id
                ?? $lockedExpense->payable_account_id;
            if ($creditAccountId === null) {
                $this->fail('payment_financial_account_id', 'A cash/bank or payable account is required to post an expense.');
            }

            $journal = $this->accounting->createDraftJournal([
                'company_id' => $company->getKey(),
                'project_id' => $lockedExpense->project_id,
                'created_by' => Auth::id(),
                'sourceable_type' => Expense::class,
                'sourceable_id' => $lockedExpense->getKey(),
                'entry_date' => $lockedExpense->expense_date->toDateString(),
                'description' => 'Expense '.$lockedExpense->expense_number,
            ], $company);

            foreach ($lines as $line) {
                $expenseDebit = (string) $line->line_total;
                $baseExpenseDebit = (string) $line->base_amount;
                foreach ($line->taxLines as $taxLine) {
                    if ($taxLine->treatment === 'non_recoverable') {
                        $expenseDebit = bcadd($expenseDebit, (string) $taxLine->amount, 4);
                        $baseExpenseDebit = bcadd($baseExpenseDebit, (string) $taxLine->base_amount, 4);
                    }
                }

                if (bccomp($expenseDebit, '0', 4) > 0) {
                    $this->accounting->addJournalLine($journal, [
                        'financial_account_id' => $line->expense_account_id,
                        'party_id' => $lockedExpense->party_id,
                        'project_id' => $lockedExpense->project_id,
                        'debit' => $expenseDebit,
                        'base_debit' => $baseExpenseDebit,
                        'currency' => $lockedExpense->currency,
                        'exchange_rate' => $lockedExpense->exchange_rate,
                        'base_currency' => $lockedExpense->base_currency,
                        'description' => $line->description,
                    ]);
                }

                foreach ($line->taxLines as $taxLine) {
                    if ($taxLine->treatment !== 'recoverable') {
                        continue;
                    }

                    $this->accounting->addJournalLine($journal, [
                        'financial_account_id' => $taxLine->tax_asset_account_id,
                        'party_id' => $lockedExpense->party_id,
                        'project_id' => $lockedExpense->project_id,
                        'debit' => $taxLine->amount,
                        'base_debit' => $taxLine->base_amount,
                        'currency' => $lockedExpense->currency,
                        'exchange_rate' => $lockedExpense->exchange_rate,
                        'base_currency' => $lockedExpense->base_currency,
                        'description' => 'Recoverable tax for '.$lockedExpense->expense_number,
                    ]);
                }
            }

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $creditAccountId,
                'party_id' => $lockedExpense->party_id,
                'project_id' => $lockedExpense->project_id,
                'credit' => $lockedExpense->amount,
                'base_credit' => $lockedExpense->base_amount,
                'currency' => $lockedExpense->currency,
                'exchange_rate' => $lockedExpense->exchange_rate,
                'base_currency' => $lockedExpense->base_currency,
                'description' => 'Expense settlement '.$lockedExpense->expense_number,
            ]);

            $postedJournal = $this->accounting->postJournal($journal);
            $lockedExpense->markAsPostedBy(Auth::user() instanceof User ? Auth::user() : null, $postedJournal);
            $lockedExpense->save();

            return $lockedExpense->fresh(['lines.taxLines', 'postedJournal', 'poster']);
        });
    }

    public function reverseExpense(Expense $expense, array $attributes = []): Expense
    {
        $this->assertAllowedAttributes($attributes, ['description', 'entry_date']);

        return DB::transaction(function () use ($expense, $attributes): Expense {
            $company = $this->companyForExpense($expense);
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedExpense = $this->lockExpense($expense, $company);

            if ($lockedExpense->status !== 'posted' || $lockedExpense->accounting_journal_id === null) {
                $this->fail('expense', 'Only posted expenses can be reversed.');
            }

            $activePayments = Payment::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('payable_type', Expense::class)
                ->where('payable_id', $lockedExpense->getKey())
                ->where('status', 'posted')
                ->whereNotNull('posted_at')
                ->whereNull('reversal_of_payment_id')
                ->whereNull('reversed_by_payment_id')
                ->exists();
            if ($activePayments) {
                $this->fail('expense', 'Reverse active expense payments through PaymentService before reversing the expense.');
            }

            $journal = $lockedExpense->postedJournal()->first();
            if (
                $journal === null
                || $journal->status !== 'posted'
                || $journal->reversed_by_journal_id !== null
                || (int) $journal->company_id !== (int) $company->getKey()
            ) {
                $this->fail('accounting_journal_id', 'The expense requires one active posted journal before reversal.');
            }

            $reversal = $this->accounting->reverseJournal($journal, [
                'description' => $attributes['description'] ?? 'Reversal of expense '.$lockedExpense->expense_number,
                'entry_date' => $attributes['entry_date'] ?? now()->toDateString(),
                'sourceable_type' => Expense::class,
                'sourceable_id' => $lockedExpense->getKey(),
            ]);
            $lockedExpense->markAsReversedBy(
                Auth::user() instanceof User ? Auth::user() : null,
                $reversal,
            );
            $lockedExpense->save();

            return $lockedExpense->fresh(['postedJournal', 'reversalJournal', 'reverser']);
        });
    }

    public function deleteDraftExpense(Expense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $company = $this->companyForExpense($expense);
            $lockedExpense = $this->lockExpense($expense, $company);
            $this->assertDraft($lockedExpense);

            foreach ($lockedExpense->lines()->with('taxLines')->get() as $line) {
                foreach ($line->taxLines as $taxLine) {
                    $taxLine->delete();
                }
                $line->delete();
            }
            $lockedExpense->attachments()->detach();
            $lockedExpense->delete();
        });
    }

    public function outstandingBalance(Expense $expense): string
    {
        $this->companyForExpense($expense);
        if ($expense->status !== 'posted' || $expense->posted_at === null || $expense->status === 'reversed') {
            $this->fail('expense', 'Outstanding balances are available only for active posted expenses.');
        }

        if ($expense->payment_financial_account_id !== null) {
            return '0.0000';
        }

        return $this->payments->calculateOutstandingForExpense($expense);
    }

    /**
     * @return Collection<int, Expense>
     */
    public function paidExpenses(Company $company): Collection
    {
        return $this->expensesBySettlement($company, true);
    }

    /**
     * @return Collection<int, Expense>
     */
    public function unpaidExpenses(Company $company): Collection
    {
        return $this->expensesBySettlement($company, false);
    }

    /**
     * @return array<int, string>
     */
    public function outstandingBalances(Company $company): array
    {
        $balances = [];
        foreach ($this->postedExpenseQuery($company)->get() as $expense) {
            $balance = $this->outstandingBalance($expense);
            if (bccomp($balance, '0', 4) > 0) {
                $balances[(int) $expense->getKey()] = $balance;
            }
        }

        return $balances;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function expensePaymentsAndReversals(Expense $expense): Collection
    {
        $this->companyForExpense($expense);

        return $expense->payments()
            ->whereIn('status', ['posted', 'reversed'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    public function totalExpensesByPeriod(Company $company, string $startDate, string $endDate): string
    {
        $total = '0.0000';
        foreach ($this->postedExpenseQuery($company)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with('lines.taxLines')
            ->get() as $expense) {
            $total = bcadd($total, $this->expenseCostAmount($expense, true), 4);
        }

        return $total;
    }

    /**
     * @return array<string, string>
     */
    public function totalsByCategory(Company $company, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->lineTotals($company, 'category', $startDate, $endDate);
    }

    /**
     * @return array<int, string>
     */
    public function totalsByAccount(Company $company, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->lineTotals($company, 'expense_account_id', $startDate, $endDate);
    }

    /**
     * @return array<string, string>
     */
    public function totalsByParty(Company $company, ?string $startDate = null, ?string $endDate = null): array
    {
        $totals = [];
        foreach ($this->periodExpenseQuery($company, $startDate, $endDate)->with('lines.taxLines')->get() as $expense) {
            $key = (string) ($expense->party_id ?? 'unassigned');
            $totals[$key] = bcadd($totals[$key] ?? '0.0000', $this->expenseCostAmount($expense, true), 4);
        }

        return $totals;
    }

    private function expenseCostAmount(Expense $expense, bool $baseCurrency): string
    {
        $total = '0.0000';
        foreach ($expense->lines as $line) {
            $total = bcadd(
                $total,
                (string) ($baseCurrency ? $line->base_amount : $line->line_total),
                4,
            );
            foreach ($line->taxLines as $taxLine) {
                if ($taxLine->treatment === 'non_recoverable') {
                    $total = bcadd(
                        $total,
                        (string) ($baseCurrency ? $taxLine->base_amount : $taxLine->amount),
                        4,
                    );
                }
            }
        }

        return $total;
    }

    /**
     * @return array<string, string>
     */
    public function totalsByCurrency(Company $company, ?string $startDate = null, ?string $endDate = null): array
    {
        $totals = [];
        foreach ($this->periodExpenseQuery($company, $startDate, $endDate)->with('lines.taxLines')->get() as $expense) {
            $currency = strtoupper((string) $expense->currency);
            $totals[$currency] = bcadd($totals[$currency] ?? '0.0000', $this->expenseCostAmount($expense, false), 4);
        }

        return $totals;
    }

    private function validateExpenseForApproval(Expense $expense, Company $company, Collection $lines): void
    {
        if (
            trim((string) $expense->expense_number) === ''
            || trim((string) $expense->description) === ''
            || $expense->expense_date === null
        ) {
            $this->fail('expense', 'Expense number, date, and description are required before approval.');
        }
        $this->assertOptionalReference(Party::class, $expense->party_id, $company, 'party_id');
        $this->assertOptionalReference(Project::class, $expense->project_id, $company, 'project_id');
        $this->assertSupportedCurrencies(
            strtoupper((string) $expense->currency),
            strtoupper((string) $company->base_currency),
        );

        if (strtoupper((string) $expense->base_currency) !== strtoupper((string) $company->base_currency)) {
            $this->fail('base_currency', 'The expense base currency must match the company base currency.');
        }

        $hasCashAccount = $expense->payment_financial_account_id !== null;
        $hasPayableAccount = $expense->payable_account_id !== null;
        if ($hasCashAccount === $hasPayableAccount) {
            $this->fail('payment_financial_account_id', 'Select exactly one cash/bank account for a paid expense or payable account for an unpaid expense.');
        }
        if ($hasCashAccount) {
            $this->resolveAccount(
                $company,
                $expense->payment_financial_account_id,
                ['asset'],
                (string) $expense->currency,
                'payment_financial_account_id',
            );
        } else {
            $this->resolveAccount(
                $company,
                $expense->payable_account_id,
                ['liability'],
                (string) $expense->currency,
                'payable_account_id',
            );
        }

        if ($lines->isEmpty()) {
            $this->fail('lines', 'An expense must contain at least one line before approval.');
        }

        $subtotal = '0.0000';
        $taxTotal = '0.0000';
        foreach ($lines as $line) {
            if (trim((string) $line->category) === '' || trim((string) $line->description) === '') {
                $this->fail('lines', 'Every expense line requires a category and description.');
            }
            $quantity = $this->normalizeMoney((string) $line->quantity, 'quantity');
            $unitPrice = $this->normalizeMoney((string) $line->unit_price, 'unit_price');
            $lineTotal = $this->normalizeMoney((string) $line->line_total, 'line_total');
            if (bccomp($quantity, '0', 4) <= 0 || bccomp($lineTotal, '0', 4) <= 0) {
                $this->fail('lines', 'Expense line quantities and totals must be greater than zero.');
            }
            if (bccomp(bcmul($quantity, $unitPrice, 4), $lineTotal, 4) !== 0) {
                $this->fail('lines', 'Expense line totals must equal quantity multiplied by unit price.');
            }

            $this->resolveAccount(
                $company,
                $line->expense_account_id,
                ['expense'],
                (string) $expense->currency,
                'expense_account_id',
            );
            $subtotal = bcadd($subtotal, $lineTotal, 4);

            foreach ($line->taxLines as $taxLine) {
                if (! in_array($taxLine->treatment, ['recoverable', 'non_recoverable'], true)) {
                    $this->fail('tax_total', 'Every tax line must have an explicit recoverable or non-recoverable classification.');
                }
                $taxAmount = $this->normalizeMoney((string) $taxLine->amount, 'tax_amount');
                if (bccomp($taxAmount, '0', 4) <= 0) {
                    $this->fail('tax_total', 'Expense tax line amounts must be greater than zero.');
                }
                if ($taxLine->treatment === 'recoverable') {
                    $this->resolveAccount(
                        $company,
                        $taxLine->tax_asset_account_id,
                        ['asset'],
                        (string) $expense->currency,
                        'tax_asset_account_id',
                    );
                } elseif ($taxLine->tax_asset_account_id !== null) {
                    $this->fail('tax_asset_account_id', 'Only recoverable tax may use a tax asset account.');
                }

                $taxTotal = bcadd($taxTotal, $taxAmount, 4);
            }
        }

        if (bccomp($taxTotal, $this->normalizeMoney((string) $expense->tax_total, 'tax_total'), 4) !== 0) {
            $this->fail('tax_total', 'Classified tax lines must reconcile exactly with the expense tax total.');
        }
        $total = bcadd($subtotal, $taxTotal, 4);
        if (bccomp($total, $this->normalizeMoney((string) $expense->amount, 'amount'), 4) !== 0) {
            $this->fail('amount', 'The expense total must equal line subtotal plus classified tax.');
        }
        if (bccomp($total, '0', 4) <= 0) {
            $this->fail('amount', 'The expense total must be greater than zero.');
        }
    }

    private function validateApprovedExpense(Expense $expense, Company $company, Collection $lines): void
    {
        if (
            $expense->accounting_journal_id !== null
            || $expense->approved_at === null
            || bccomp((string) $expense->base_amount, '0', 4) <= 0
        ) {
            $this->fail('expense', 'The approved expense snapshot is incomplete or already posted.');
        }

        $this->validateExpenseForApproval($expense, $company, $lines);
        $rate = $this->normalizeRate((string) $expense->exchange_rate, 'exchange_rate');
        $expectedBase = $this->normalizeMoney(
            bcmul((string) $expense->amount, $rate, 10),
            'base_amount',
        );
        if (bccomp($expectedBase, (string) $expense->base_amount, 4) !== 0) {
            $this->fail('base_amount', 'The approved expense base amount does not match its stored exchange rate.');
        }

        foreach ($lines as $line) {
            if ($line->base_amount === null) {
                $this->fail('base_amount', 'Every approved expense line must preserve its base-currency amount.');
            }
            foreach ($line->taxLines as $taxLine) {
                if ($taxLine->base_amount === null) {
                    $this->fail('base_amount', 'Every approved tax line must preserve its base-currency amount.');
                }
            }
        }

        $lineBaseTotal = '0.0000';
        foreach ($lines as $line) {
            $lineBaseTotal = bcadd($lineBaseTotal, (string) $line->base_amount, 4);
            foreach ($line->taxLines as $taxLine) {
                $lineBaseTotal = bcadd($lineBaseTotal, (string) $taxLine->base_amount, 4);
            }
        }
        if (bccomp($lineBaseTotal, (string) $expense->base_amount, 4) !== 0) {
            $this->fail('base_amount', 'Expense line and tax base amounts must reconcile exactly with the approved total.');
        }
    }

    private function resolveExpenseRate(Expense $expense, Company $company): string
    {
        $currency = strtoupper((string) $expense->currency);
        $baseCurrency = strtoupper((string) $company->base_currency);
        if ($currency === $baseCurrency) {
            if ($expense->exchange_rate !== null && bccomp((string) $expense->exchange_rate, '1', 10) !== 0) {
                $this->fail('exchange_rate', 'Same-currency expenses must use an exchange rate of 1.');
            }

            return '1';
        }

        if ($expense->exchange_rate !== null && $expense->exchange_rate !== '') {
            return $this->normalizeRate((string) $expense->exchange_rate, 'exchange_rate');
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $company->getKey())
            ->where('currency', $currency)
            ->where('base_currency', $baseCurrency)
            ->where('effective_at', '<=', Carbon::parse($expense->expense_date)->endOfDay())
            ->orderByDesc('effective_at')
            ->value('rate');
        if ($rate === null || bccomp((string) $rate, '0', 10) <= 0) {
            $this->fail('exchange_rate', 'A valid exchange rate effective on the expense date is required.');
        }

        return $this->normalizeRate((string) $rate, 'exchange_rate');
    }

    private function resolveAccount(
        Company $company,
        mixed $accountId,
        array $allowedTypes,
        string $currency,
        string $attribute,
    ): FinancialAccount {
        if ((! is_int($accountId) && ! (is_string($accountId) && ctype_digit($accountId))) || (int) $accountId <= 0) {
            $this->fail($attribute, 'A valid account selection is required.');
        }

        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);
        if ($account === null || (int) $account->company_id !== (int) $company->getKey()) {
            $this->fail($attribute, 'The selected account must belong to the expense company.');
        }
        if (! $account->is_active || ! in_array(strtolower((string) $account->account_type), $allowedTypes, true)) {
            $this->fail($attribute, 'The selected account must be active and have type '.implode(' or ', $allowedTypes).'.');
        }
        if (strtoupper((string) $account->currency) !== strtoupper($currency)) {
            $this->fail($attribute, 'The selected account currency must match the expense currency.');
        }

        return $account;
    }

    private function assertOptionalReference(string $model, mixed $id, Company $company, string $attribute): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $record = $model::withoutGlobalScope(CompanyScope::class)->find($id);
        if ($record === null || (int) $record->company_id !== (int) $company->getKey()) {
            $this->fail($attribute, 'The selected record must belong to the expense company.');
        }
    }

    private function assertNoOriginalJournal(Expense $expense): void
    {
        $journalCount = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $expense->company_id)
            ->where('sourceable_type', Expense::class)
            ->where('sourceable_id', $expense->getKey())
            ->whereNull('reversal_of_journal_id')
            ->count();

        if ($journalCount !== 0 || $expense->accounting_journal_id !== null) {
            $this->fail('accounting_journal_id', 'An accounting journal already exists for this expense.');
        }
    }

    private function recalculateDraftTotals(Expense $expense): void
    {
        $subtotal = '0.0000';
        foreach (ExpenseLine::withoutGlobalScope(CompanyScope::class)
            ->where('expense_id', $expense->getKey())
            ->orderBy('line_number')
            ->get(['id', 'expense_account_id', 'line_total']) as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_total, 4);
        }

        $taxTotal = '0.0000';
        foreach (ExpenseTaxLine::withoutGlobalScope(CompanyScope::class)
            ->whereHas('expenseLine', fn (Builder $query) => $query->where('expense_id', $expense->getKey()))
            ->get(['amount']) as $taxLine) {
            $taxTotal = bcadd($taxTotal, (string) $taxLine->amount, 4);
        }

        $expense->tax_total = $taxTotal;
        $expense->amount = bcadd($subtotal, $taxTotal, 4);
        $expense->financial_account_id = ExpenseLine::withoutGlobalScope(CompanyScope::class)
            ->where('expense_id', $expense->getKey())
            ->orderBy('line_number')
            ->value('expense_account_id');
        $expense->save();
    }

    /**
     * @param  array<string, string>  $weights
     * @return array<string, string>
     */
    private function allocateBaseAmount(string $total, array $weights): array
    {
        $allocations = array_fill_keys(array_keys($weights), '0.0000');
        $totalWeight = '0.0000';
        foreach ($weights as $weight) {
            $totalWeight = bcadd($totalWeight, $this->normalizeMoney($weight, 'allocation_weight'), 4);
        }
        if ($weights === [] || bccomp($totalWeight, '0', 4) <= 0) {
            $this->fail('base_amount', 'A positive expense total requires positive line and tax values.');
        }

        $lastKey = array_key_last($weights);
        $allocated = '0.0000';
        foreach ($weights as $key => $weight) {
            $share = $key === $lastKey
                ? bcsub($total, $allocated, 4)
                : $this->normalizeMoney(
                    bcdiv(bcmul($total, $this->normalizeMoney($weight, 'allocation_weight'), 12), $totalWeight, 12),
                    'base_amount',
                );
            $allocations[$key] = $share;
            $allocated = bcadd($allocated, $share, 4);
        }

        return $allocations;
    }

    private function lineTotals(
        Company $company,
        string $groupBy,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $totals = [];
        foreach ($this->periodExpenseQuery($company, $startDate, $endDate)->with('lines.taxLines')->get() as $expense) {
            foreach ($expense->lines as $line) {
                $key = (string) $line->{$groupBy};
                $lineAmount = (string) $line->base_amount;
                foreach ($line->taxLines as $taxLine) {
                    if ($taxLine->treatment === 'non_recoverable') {
                        $lineAmount = bcadd($lineAmount, (string) $taxLine->base_amount, 4);
                    }
                }
                $totals[$key] = bcadd($totals[$key] ?? '0.0000', $lineAmount, 4);
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @return Collection<int, Expense>
     */
    private function expensesBySettlement(Company $company, bool $isPaid): Collection
    {
        $expenses = $this->postedExpenseQuery($company)->get();

        return $expenses->filter(function (Expense $expense) use ($isPaid): bool {
            $isSettled = bccomp($this->outstandingBalance($expense), '0', 4) === 0;

            return $isPaid === $isSettled;
        })->values();
    }

    private function periodExpenseQuery(
        Company $company,
        ?string $startDate,
        ?string $endDate,
    ): Builder {
        $query = $this->postedExpenseQuery($company);
        if ($startDate !== null) {
            $query->whereDate('expense_date', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('expense_date', '<=', $endDate);
        }

        return $query;
    }

    private function postedExpenseQuery(Company $company): Builder
    {
        $this->assertCompanyIsActive($company);

        return Expense::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereNull('reversed_at')
            ->whereHas('postedJournal', fn (Builder $query) => $query
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->whereNull('reversed_by_journal_id')
                ->where('sourceable_type', Expense::class)
                ->whereColumn('sourceable_id', 'expenses.id'));
    }

    private function lockExpense(Expense $expense, Company $company): Expense
    {
        return $this->lockExpenseById((int) $expense->getKey(), $company);
    }

    private function lockExpenseById(int $expenseId, Company $company): Expense
    {
        $expense = Expense::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->lockForUpdate()
            ->find($expenseId);
        if ($expense === null) {
            $this->fail('expense', 'The expense was not found in the active company.');
        }

        return $expense;
    }

    private function companyForExpense(Expense $expense): Company
    {
        $company = Company::query()->find($expense->company_id);
        if ($company === null) {
            $this->fail('company_id', 'The expense company could not be found.');
        }
        $this->assertCompanyIsActive($company);

        return $company;
    }

    private function resolveCompany(?Company $company = null): Company
    {
        $activeCompanyId = $this->companyContext->id();
        if ($company === null) {
            $company = $this->companyContext->requireCurrent();
        }
        if (
            $company->exists === false
            || ($activeCompanyId !== null && (int) $activeCompanyId !== (int) $company->getKey())
            || (Auth::check() && $activeCompanyId === null)
        ) {
            $this->fail('company_id', 'An active company membership is required for expense operations.');
        }

        return $company;
    }

    private function assertCompanyIsActive(Company $company): void
    {
        $this->resolveCompany($company);
    }

    private function assertDraft(Expense $expense): void
    {
        if ($expense->status !== 'draft') {
            $this->fail('expense', 'Only draft expenses can be edited.');
        }
    }

    private function assertSupportedCurrencies(string $currency, string $baseCurrency): void
    {
        if (
            ! in_array($currency, ['SYP', 'USD'], true)
            || ! in_array($baseCurrency, ['SYP', 'USD'], true)
        ) {
            $this->fail('currency', 'Expenses support SYP and USD transaction and base currencies.');
        }
    }

    private function assertAllowedAttributes(array $attributes, array $allowed): void
    {
        $unexpected = array_diff(array_keys($attributes), $allowed);
        if ($unexpected !== []) {
            $this->fail((string) reset($unexpected), 'This field is not editable through the expense workflow.');
        }
    }

    private function assertRequired(array $attributes, array $required): void
    {
        foreach ($required as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === '') {
                $this->fail($field, 'This field is required.');
            }
        }
    }

    private function normalizeMoney(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            $this->fail($field, 'The financial amount must be numeric.');
        }
        if (bccomp($value, '0', 10) < 0) {
            $this->fail($field, 'Financial amounts must be zero or positive.');
        }

        return bcadd($value, '0', 4);
    }

    private function normalizeRate(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value) || bccomp($value, '0', 10) <= 0) {
            $this->fail($field, 'The exchange rate must be a positive decimal value.');
        }

        return bcadd($value, '0', 10);
    }

    private function generateExpenseNumber(Company $company): string
    {
        return strtoupper(substr((string) $company->name, 0, 3))
            .'-EXP-'.now()->format('YmdHis').'-'.substr(uniqid(), -6);
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
