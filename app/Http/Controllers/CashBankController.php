<?php

namespace App\Http\Controllers;

use App\Models\AccountingJournal;
use App\Models\FinancialAccount;
use App\Services\CompanyContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CashBankController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', FinancialAccount::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $currencies = $this->currencies((string) $company->base_currency);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::in(FinancialAccount::VALID_ACCOUNT_TYPES)],
            'currency' => ['nullable', Rule::in($currencies)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        // Fetch all matching accounts to build a hierarchy depth map before paginating.
        // This avoids N+1 queries when walking parent chains and keeps the tree
        // structure visible even across paginated pages.
        $allAccounts = FinancialAccount::query()
            ->with('parentAccount')
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q
                ->where(fn (Builder $q) => $q
                    ->where('name', 'like', '%'.$s.'%')
                    ->orWhere('code', 'like', '%'.$s.'%')))
            ->when($filters['account_type'] ?? null, fn (Builder $q, string $t) => $q->where('account_type', $t))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $c) => $q->where('currency', strtoupper($c)))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('is_active', $filters['status'] === 'active'))
            ->orderBy('account_type')
            ->orderBy('code')
            ->get();

        // Build a depth map: walk each account's parent chain within the result set.
        // If a parent is outside the filtered set, depth falls back to 0 so the
        // indentation never misleads the user about hierarchy.
        $depths = [];
        foreach ($allAccounts as $account) {
            $depth = 0;
            $parentId = $account->parent_account_id;
            while ($parentId !== null) {
                $parent = $allAccounts->firstWhere('id', $parentId);
                if ($parent === null) {
                    $depth = 0;
                    break;
                }
                $depth++;
                $parentId = $parent->parent_account_id;
            }
            $depths[$account->getKey()] = $depth;
        }

        $perPage = 20;
        $page = (int) $request->get('page', 1);
        $accounts = new LengthAwarePaginator(
            $allAccounts->forPage($page, $perPage)->values(),
            $allAccounts->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('cash-bank.index', [
            'accounts' => $accounts,
            'filters' => $filters,
            'currencies' => $currencies,
            'accountTypes' => FinancialAccount::VALID_ACCOUNT_TYPES,
            'depths' => $depths,
        ]);
    }

    public function create(): View
    {
        $this->authorize('viewAny', FinancialAccount::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        return view('cash-bank.create', [
            'currencies' => $this->currencies((string) $company->base_currency),
            'accountTypes' => FinancialAccount::VALID_ACCOUNT_TYPES,
            'statementClassifications' => FinancialAccount::STATEMENT_CLASSIFICATIONS,
            'parentAccounts' => FinancialAccount::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', FinancialAccount::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $currencies = $this->currencies((string) $company->base_currency);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('financial_accounts')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', Rule::in(FinancialAccount::VALID_ACCOUNT_TYPES)],
            'currency' => ['required', Rule::in($currencies)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'statement_classification' => ['nullable', Rule::in(FinancialAccount::STATEMENT_CLASSIFICATIONS)],
            'parent_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)],
        ]);

        $account = FinancialAccount::create([
            'company_id' => $companyId,
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'currency' => strtoupper($data['currency']),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'statement_classification' => $data['statement_classification'] ?? null,
            'parent_account_id' => $data['parent_account_id'] ?? null,
        ]);

        return redirect()->route('cash-bank.show', $account)
            ->with('status', __('ui.cash_bank.created'));
    }

    public function show(FinancialAccount $cashBank, Request $request): View
    {
        $this->authorize('view', $cashBank);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $linesQuery = $cashBank->postedJournalLines()
            ->with(['journal'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $d) => $q
                ->whereHas('journal', fn (Builder $jq) => $jq->whereDate('entry_date', '>=', $d)))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $d) => $q
                ->whereHas('journal', fn (Builder $jq) => $jq->whereDate('entry_date', '<=', $d)))
            ->orderByDesc(
                AccountingJournal::select('entry_date')
                    ->whereColumn('accounting_journals.id', 'journal_lines.accounting_journal_id')
                    ->limit(1)
            )
            ->orderByDesc('id');

        $lines = $linesQuery->paginate(25)->withQueryString();

        $balance = $cashBank->nativeCurrencyBalance((string) $cashBank->currency);

        return view('cash-bank.show', [
            'account' => $cashBank,
            'lines' => $lines,
            'filters' => $filters,
            'balance' => $balance,
        ]);
    }

    public function edit(FinancialAccount $cashBank): View
    {
        $this->authorize('view', $cashBank);

        $company = app(CompanyContextService::class)->requireCurrent();

        return view('cash-bank.edit', [
            'account' => $cashBank,
            'statementClassifications' => FinancialAccount::STATEMENT_CLASSIFICATIONS,
            'parentAccounts' => FinancialAccount::query()
                ->where('is_active', true)
                ->where('id', '!=', $cashBank->getKey())
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function update(Request $request, FinancialAccount $cashBank): RedirectResponse
    {
        $this->authorize('view', $cashBank);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'statement_classification' => ['nullable', Rule::in(FinancialAccount::STATEMENT_CLASSIFICATIONS)],
            'parent_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')
                ->where('company_id', (int) $cashBank->company_id)
                ->whereNot('id', $cashBank->getKey())],
        ]);

        // Validate statement_classification is valid for the account_type.
        // We re-create the same logic as the model but with nicer error messages.
        if (! empty($data['statement_classification'])) {
            $accountType = strtolower((string) $cashBank->account_type);
            $validClassifications = match ($accountType) {
                'revenue' => ['revenue', 'other_income'],
                'income' => ['other_income'],
                'expense' => ['cost_of_goods_sold', 'operating_expense', 'other_expense'],
                default => [],
            };
            if (! in_array($data['statement_classification'], $validClassifications, true)) {
                throw ValidationException::withMessages([
                    'statement_classification' => __('ui.cash_bank.classification_invalid'),
                ]);
            }
        }

        $cashBank->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? $cashBank->is_active,
            'statement_classification' => $data['statement_classification'] ?? null,
            'parent_account_id' => $data['parent_account_id'] ?? null,
        ]);

        return redirect()->route('cash-bank.show', $cashBank)
            ->with('status', __('ui.cash_bank.updated'));
    }

    public function print(FinancialAccount $cashBank, Request $request): View
    {
        $this->authorize('view', $cashBank);
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $lines = $cashBank->postedJournalLines()
            ->with(['journal'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $d) => $q
                ->whereHas('journal', fn (Builder $jq) => $jq->whereDate('entry_date', '>=', $d)))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $d) => $q
                ->whereHas('journal', fn (Builder $jq) => $jq->whereDate('entry_date', '<=', $d)))
            ->orderBy(
                AccountingJournal::select('entry_date')
                    ->whereColumn('accounting_journals.id', 'journal_lines.accounting_journal_id')
                    ->limit(1)
            )
            ->orderBy('id')
            ->get();

        $balance = $cashBank->nativeCurrencyBalance((string) $cashBank->currency);

        return view('cash-bank.print', [
            'account' => $cashBank,
            'lines' => $lines,
            'filters' => $filters,
            'balance' => $balance,
            'company' => $company,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function currencies(string $baseCurrency): array
    {
        return array_values(array_unique([strtoupper($baseCurrency), 'SYP', 'USD']));
    }
}
