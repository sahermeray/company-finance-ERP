<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\FinancialAccount;
use App\Models\Party;
use App\Services\CompanyContextService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request, ExpenseService $expenses): View
    {
        $this->authorize('viewAny', Expense::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $currencies = $this->currencies((string) $company->base_currency);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'category' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)],
            'currency' => ['nullable', Rule::in($currencies)],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'posted', 'reversed'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $expensesQuery = Expense::query()
            ->with('party')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('expense_number', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('party', fn (Builder $partyQuery) => $partyQuery->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['party_id'] ?? null, fn (Builder $query, int $partyId) => $query->where('party_id', $partyId))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query
                ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->where('category', $category)))
            ->when($filters['account_id'] ?? null, fn (Builder $query, int $accountId) => $query
                ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->where('expense_account_id', $accountId)))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expense_date', '<=', $date))
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        $paged = $expensesQuery->paginate(15)->withQueryString();

        // Outstanding balances are meaningful only for active posted unpaid expenses.
        $outstanding = [];
        foreach ($paged->getCollection() as $expense) {
            if ($expense->status === 'posted' && $expense->payment_financial_account_id === null) {
                $outstanding[(int) $expense->getKey()] = bcadd($expenses->outstandingBalance($expense), '0', 4);
            }
        }

        return view('commercial.expenses.index', [
            'expenses' => $paged,
            'parties' => Party::query()->orderBy('name')->get(),
            'categories' => ExpenseLine::query()->where('company_id', $companyId)
                ->distinct()->orderBy('category')->pluck('category')->filter()->values(),
            'accounts' => FinancialAccount::query()->where('company_id', $companyId)
                ->where('account_type', 'expense')->where('is_active', true)->orderBy('code')->get(),
            'filters' => $filters,
            'currencies' => $currencies,
            'outstanding' => $outstanding,
        ]);
    }

    public function create(): View
    {
        $this->authorize('viewAny', Expense::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        return view('commercial.expenses.create', [
            'parties' => Party::query()->orderBy('name')->get(),
            'accounts' => $this->accountsByType((int) $company->getKey()),
            'currencies' => $this->currencies((string) $company->base_currency),
            'baseCurrency' => $company->base_currency,
            'categories' => ExpenseLine::query()->where('company_id', (int) $company->getKey())
                ->distinct()->orderBy('category')->pluck('category')->filter()->values(),
        ]);
    }

    public function store(Request $request, ExpenseService $expenses): RedirectResponse
    {
        $this->authorize('viewAny', Expense::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $baseCurrency = strtoupper((string) $company->base_currency);
        $currencies = $this->currencies((string) $company->base_currency);
        $currency = strtoupper((string) $request->input('currency', $baseCurrency));

        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'currency' => ['required', Rule::in($currencies)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'payment_type' => ['required', Rule::in(['paid', 'unpaid'])],
            'payment_financial_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'asset')->where('is_active', true)->where('currency', $currency)],
            'payable_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'liability')->where('is_active', true)->where('currency', $currency)],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.expense_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'expense')->where('is_active', true)->where('currency', $currency)],
            'lines.*.category' => ['required', 'string', 'max:120'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'lines.*.tax_treatment' => ['nullable', Rule::in(['recoverable', 'non_recoverable'])],
            'lines.*.tax_asset_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'asset')->where('is_active', true)->where('currency', $currency)],
        ]);

        if ($data['payment_type'] === 'paid') {
            if (empty($data['payment_financial_account_id'])) {
                throw ValidationException::withMessages([
                    'payment_financial_account_id' => __('ui.expenses.paid_account_required'),
                ]);
            }
            if (! empty($data['payable_account_id'])) {
                throw ValidationException::withMessages([
                    'payable_account_id' => __('ui.expenses.paid_or_unpaid_only'),
                ]);
            }
        } else {
            if (empty($data['payable_account_id'])) {
                throw ValidationException::withMessages([
                    'payable_account_id' => __('ui.expenses.unpaid_account_required'),
                ]);
            }
            if (! empty($data['payment_financial_account_id'])) {
                throw ValidationException::withMessages([
                    'payment_financial_account_id' => __('ui.expenses.paid_or_unpaid_only'),
                ]);
            }
        }

        if ($currency === $baseCurrency && ! empty($data['exchange_rate'])) {
            throw ValidationException::withMessages([
                'exchange_rate' => __('ui.expenses.exchange_rate_base_currency'),
            ]);
        }

        foreach ($data['lines'] as $index => $line) {
            $taxAmount = (string) ($line['tax_amount'] ?? '0');
            if (bccomp($taxAmount, '0', 4) > 0) {
                if (empty($line['tax_treatment'])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.tax_treatment" => __('ui.commercial.tax_treatment_required'),
                    ]);
                }
                if ($line['tax_treatment'] === 'recoverable' && empty($line['tax_asset_account_id'])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.tax_asset_account_id" => __('ui.commercial.tax_account_required'),
                    ]);
                }
                if ($line['tax_treatment'] === 'non_recoverable' && ! empty($line['tax_asset_account_id'])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.tax_asset_account_id" => __('ui.expenses.non_recoverable_no_asset_account'),
                    ]);
                }
            }
        }

        $expenseId = DB::transaction(function () use ($data, $expenses, $company, $currency, $baseCurrency): int {
            $header = [
                'expense_date' => $data['expense_date'],
                'party_id' => $data['party_id'] ?? null,
                'currency' => $data['currency'],
                'base_currency' => $company->base_currency,
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
            ];

            if ($currency === $baseCurrency) {
                $header['exchange_rate'] = '1';
            } elseif (! empty($data['exchange_rate'])) {
                $header['exchange_rate'] = $data['exchange_rate'];
            }

            if ($data['payment_type'] === 'paid') {
                $header['payment_financial_account_id'] = $data['payment_financial_account_id'];
            } else {
                $header['payable_account_id'] = $data['payable_account_id'];
            }

            $expense = $expenses->createDraftExpense($header, $company);

            foreach ($data['lines'] as $line) {
                $expenseLine = $expenses->addExpenseLine($expense, [
                    'expense_account_id' => $line['expense_account_id'],
                    'category' => $line['category'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);

                if (bccomp((string) ($line['tax_amount'] ?? '0'), '0', 4) > 0) {
                    $expenses->addExpenseTaxLine($expenseLine, [
                        'treatment' => $line['tax_treatment'],
                        'amount' => $line['tax_amount'],
                        'tax_asset_account_id' => $line['tax_asset_account_id'] ?? null,
                    ]);
                }
            }

            return (int) $expense->getKey();
        });

        $expense = Expense::query()->findOrFail($expenseId);

        return redirect()->route('expenses.show', $expense)->with('status', __('ui.expenses.draft_saved'));
    }

    public function show(Expense $expense, ExpenseService $expenses): View
    {
        $this->authorize('view', $expense);
        $company = app(CompanyContextService::class)->requireCurrent();

        $expense->load([
            'party',
            'project',
            'creator',
            'approver',
            'poster',
            'reverser',
            'lines.expenseAccount',
            'lines.taxLines.taxAssetAccount',
            'postedJournal.lines.financialAccount',
            'reversalJournal.lines.financialAccount',
            'payments.financialAccount',
            'payments.reversalOf.financialAccount',
            'payments.reversedBy.financialAccount',
        ]);

        $outstanding = null;
        $isSettleable = $expense->status === 'posted'
            && $expense->payable_account_id !== null
            && $expense->payment_financial_account_id === null;
        if ($isSettleable) {
            $rawOutstanding = $expenses->outstandingBalance($expense);
            $isSettleable = bccomp((string) $rawOutstanding, '0', 4) > 0;
            if ($isSettleable) {
                $outstanding = bcadd((string) $rawOutstanding, '0', 4);
            }
        }

        $paymentHistory = $expenses->expensePaymentsAndReversals($expense);

        return view('commercial.expenses.show', [
            'expense' => $expense,
            'outstanding' => $outstanding,
            'isSettleable' => $isSettleable,
            'paymentHistory' => $paymentHistory,
            'assetAccounts' => FinancialAccount::query()
                ->where('company_id', $company->getKey())
                ->where('is_active', true)
                ->where('account_type', 'asset')
                ->orderBy('currency')
                ->orderBy('code')
                ->get(),
            'settleCurrencies' => array_values(array_unique([
                strtoupper((string) $expense->currency),
                strtoupper((string) $company->base_currency),
            ])),
            'settlement' => session('settlement'),
        ]);
    }

    public function approve(Expense $expense, ExpenseService $expenses): RedirectResponse
    {
        $this->authorize('view', $expense);
        $expenses->approveExpense($expense);

        return redirect()->route('expenses.show', $expense)->with('status', __('ui.expenses.approved'));
    }

    public function post(Expense $expense, ExpenseService $expenses): RedirectResponse
    {
        $this->authorize('view', $expense);
        $expenses->postExpense($expense);

        return redirect()->route('expenses.show', $expense)->with('status', __('ui.expenses.posted'));
    }

    public function settle(Request $request, Expense $expense, ExpenseService $expenses, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $expense);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $settleCurrencies = array_values(array_unique([
            strtoupper((string) $expense->currency),
            strtoupper((string) $company->base_currency),
        ]));

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'currency' => ['required', Rule::in($settleCurrencies)],
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'asset')->where('is_active', true)],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:120'],
        ]);

        if ($expense->status !== 'posted' || $expense->payable_account_id === null || $expense->payment_financial_account_id !== null) {
            throw ValidationException::withMessages(['expense' => __('ui.expenses.only_unpaid_settled')]);
        }

        $account = FinancialAccount::query()->findOrFail($data['financial_account_id']);
        if (strtoupper((string) $account->currency) !== strtoupper($data['currency'])) {
            throw ValidationException::withMessages(['financial_account_id' => __('ui.payments.account_currency_mismatch')]);
        }

        $before = $expenses->outstandingBalance($expense);
        if (bccomp((string) $before, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('ui.expenses.no_outstanding')]);
        }

        $expenseCurrency = strtoupper((string) $expense->currency);
        $paymentCurrency = strtoupper($data['currency']);
        $applied = $paymentCurrency === $expenseCurrency
            ? (string) $data['amount']
            : bcdiv((string) $data['amount'], (string) $expense->exchange_rate, 10);
        if (bccomp($applied, $before, 4) > 0) {
            throw ValidationException::withMessages(['amount' => __('ui.expenses.overpayment_rejected')]);
        }

        $payment = $payments->recordExpensePayment($expense, [
            'financial_account_id' => $data['financial_account_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'paid_at' => $data['paid_at'],
            'reference' => $data['reference'] ?? null,
            'method' => $data['method'] ?? null,
        ]);

        $after = $expenses->outstandingBalance($expense->fresh());

        return redirect()->route('expenses.show', $expense)
            ->with('status', __('ui.expenses.settlement_saved'))
            ->with('settlement', [
                'before' => bcadd((string) $before, '0', 4),
                'after' => bcadd((string) $after, '0', 4),
                'currency' => $expense->currency,
                'payment_number' => $payment->payment_number,
            ]);
    }

    public function reverse(Expense $expense, ExpenseService $expenses, PaymentService $payments): RedirectResponse
    {
        $this->authorize('view', $expense);
        $company = app(CompanyContextService::class)->requireCurrent();

        if ($expense->status !== 'posted' || $expense->accounting_journal_id === null || $expense->reversal_journal_id !== null) {
            throw ValidationException::withMessages(['expense' => __('ui.expenses.only_posted_reversed')]);
        }

        DB::transaction(function () use ($expense, $expenses, $payments, $company): void {
            $activePayments = $expense->payments()
                ->where('status', 'posted')
                ->whereNotNull('posted_at')
                ->whereNull('reversal_of_payment_id')
                ->whereNull('reversed_by_payment_id')
                ->get();

            foreach ($activePayments as $payment) {
                $payments->reversePayment($payment, [], $company);
            }

            $expenses->reverseExpense($expense);
        });

        return redirect()->route('expenses.show', $expense)->with('status', __('ui.expenses.reversed'));
    }

    public function destroy(Expense $expense, ExpenseService $expenses): RedirectResponse
    {
        $this->authorize('view', $expense);
        $expenses->deleteDraftExpense($expense);

        return redirect()->route('expenses.index')->with('status', __('ui.expenses.deleted'));
    }

    public function print(Expense $expense): View
    {
        $this->authorize('view', $expense);
        $company = app(CompanyContextService::class)->requireCurrent();
        $expense->load([
            'party',
            'project',
            'creator',
            'approver',
            'poster',
            'lines.expenseAccount',
            'lines.taxLines.taxAssetAccount',
        ]);

        return view('commercial.expenses.print', [
            'company' => $company,
            'expense' => $expense,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function currencies(string $baseCurrency): array
    {
        return array_values(array_unique([strtoupper($baseCurrency), 'SYP', 'USD']));
    }

    /**
     * @return array{expense: mixed, asset: mixed, liability: mixed}
     */
    private function accountsByType(int $companyId): array
    {
        $accounts = FinancialAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('currency')
            ->orderBy('code')
            ->get();

        return [
            'expense' => $accounts->where('account_type', 'expense')->values(),
            'asset' => $accounts->where('account_type', 'asset')->values(),
            'liability' => $accounts->where('account_type', 'liability')->values(),
        ];
    }
}
