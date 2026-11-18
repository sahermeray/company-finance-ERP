<?php

namespace App\Http\Controllers;

use App\Models\AccountingJournal;
use App\Models\CompanyAccountMapping;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCutover;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockCount;
use App\Models\SupplierReturn;
use App\Services\CompanyContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountingJournalController extends Controller
{
    /**
     * Sourceable types that have dedicated show routes.
     * Maps FQCN → [route name, route parameter name, number field on the model].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SOURCEABLE_ROUTES = [
        Purchase::class => ['purchases.show', 'purchase', 'purchase_number'],
        Revenue::class => ['sales.show', 'revenue', 'revenue_number'],
        Expense::class => ['expenses.show', 'expense', 'expense_number'],
        Payment::class => ['payments.show', 'payment', 'payment_number'],
        CustomerReturn::class => [null, null, 'return_number'],
        SupplierReturn::class => [null, null, 'return_number'],
        InventoryAdjustment::class => [null, null, null],
        InventoryCutover::class => [null, null, null],
        StockCount::class => [null, null, null],
    ];

    /**
     * Human-readable labels for sourceable types.
     *
     * @var array<string, string>
     */
    private const SOURCEABLE_LABELS = [
        Purchase::class => 'purchase',
        Revenue::class => 'sale',
        Expense::class => 'expense',
        Payment::class => 'payment',
        CustomerReturn::class => 'customer_return',
        SupplierReturn::class => 'supplier_return',
        InventoryAdjustment::class => 'inventory_adjustment',
        InventoryCutover::class => 'inventory_cutover',
        StockCount::class => 'stock_count',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AccountingJournal::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'posted'])],
            'source_type' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $journals = AccountingJournal::query()
            ->with(['creator', 'sourceable'])
            ->withCount('lines')
            ->withSum('lines as base_debit_total', 'base_debit')
            ->withSum('lines as base_credit_total', 'base_credit')
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q
                ->where(fn (Builder $q) => $q
                    ->where('journal_number', 'like', '%'.$s.'%')
                    ->orWhere('description', 'like', '%'.$s.'%')))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $q, string $type) => $q->where('sourceable_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('entry_date', '<=', $d))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.journals.index', [
            'journals' => $journals,
            'filters' => $filters,
            'baseCurrency' => $company->base_currency,
            'sourceTypes' => $this->availableSourceTypes(),
        ]);
    }

    public function show(AccountingJournal $journal): View
    {
        $this->authorize('view', $journal);
        $company = app(CompanyContextService::class)->requireCurrent();

        $journal->load([
            'creator',
            'sourceable',
            'lines' => fn ($q) => $q->orderBy('line_number'),
            'lines.financialAccount',
            'lines.party',
            'lines.project',
            'reversalOf.lines',
            'reversedBy.lines',
        ]);

        $sourceInfo = $this->resolveSourceInfo($journal);

        // Compute base-currency totals from stored BCMath values — never use float.
        $totalBaseDebit = '0.0000';
        $totalBaseCredit = '0.0000';
        foreach ($journal->lines as $line) {
            $totalBaseDebit = bcadd($totalBaseDebit, (string) ($line->base_debit ?? '0'), 4);
            $totalBaseCredit = bcadd($totalBaseCredit, (string) ($line->base_credit ?? '0'), 4);
        }
        $isBalanced = bccomp($totalBaseDebit, $totalBaseCredit, 4) === 0;

        return view('accounting.journals.show', [
            'journal' => $journal,
            'baseCurrency' => $company->base_currency,
            'sourceInfo' => $sourceInfo,
            'totalBaseDebit' => $totalBaseDebit,
            'totalBaseCredit' => $totalBaseCredit,
            'isBalanced' => $isBalanced,
        ]);
    }

    // -------------------------------------------------------------------------
    // Control Account Mappings
    // -------------------------------------------------------------------------

    public function mappings(Request $request): View
    {
        $this->authorize('viewAny', AccountingJournal::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        $existingMappings = CompanyAccountMapping::query()
            ->with('financialAccount')
            ->get()
            ->keyBy('purpose');

        $eligibleAccounts = FinancialAccount::query()
            ->where('is_active', true)
            ->whereIn('account_type', ['asset', 'liability'])
            ->where('currency', $company->base_currency)
            ->orderBy('account_type')
            ->orderBy('code')
            ->get();

        return view('accounting.mappings', [
            'purposes' => CompanyAccountMapping::PURPOSES,
            'mappings' => $existingMappings,
            'eligibleAccounts' => $eligibleAccounts,
            'baseCurrency' => $company->base_currency,
        ]);
    }

    public function updateMapping(Request $request, string $purpose): RedirectResponse
    {
        $this->authorize('viewAny', AccountingJournal::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        if (! in_array($purpose, CompanyAccountMapping::PURPOSES, true)) {
            abort(404);
        }

        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')
                ->where('company_id', (int) $company->getKey())
                ->where('is_active', true)
                ->where('currency', $company->base_currency)],
        ]);

        $expectedType = $purpose === CompanyAccountMapping::SUPPLIER_PAYABLES ? 'liability' : 'asset';
        $account = FinancialAccount::query()->findOrFail($data['financial_account_id']);
        if (strtolower((string) $account->account_type) !== $expectedType) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('ui.accounting.mapping_wrong_type', ['type' => $expectedType]),
            ]);
        }

        $mapping = CompanyAccountMapping::query()
            ->where('company_id', $company->getKey())
            ->where('purpose', $purpose)
            ->first();

        if ($mapping === null) {
            CompanyAccountMapping::create([
                'company_id' => $company->getKey(),
                'purpose' => $purpose,
                'financial_account_id' => $data['financial_account_id'],
            ]);
        } else {
            // The model guard will throw if posted operational history exists.
            $mapping->update(['financial_account_id' => $data['financial_account_id']]);
        }

        return redirect()->route('accounting.mappings')
            ->with('status', __('ui.accounting.mapping_saved'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Public static helper for use in Blade views to resolve a human-readable
     * label key from a sourceable FQCN without instantiating the controller.
     */
    public static function resolveSourceLabel(?string $sourceableType): ?string
    {
        if ($sourceableType === null) {
            return null;
        }

        return self::SOURCEABLE_LABELS[$sourceableType] ?? null;
    }

    /**
     * @return array<string, string> FQCN → human label key
     */
    private function availableSourceTypes(): array
    {
        return array_map(
            fn (string $labelKey): string => __('ui.accounting.source_types.'.$labelKey),
            self::SOURCEABLE_LABELS,
        );
    }

    /**
     * @return array{label: string, reference: string|null, route: string|null, params: array<string, mixed>}
     */
    private function resolveSourceInfo(AccountingJournal $journal): array
    {
        $type = $journal->sourceable_type;
        if ($type === null || ! isset(self::SOURCEABLE_LABELS[$type])) {
            return ['label' => '', 'reference' => null, 'route' => null, 'params' => []];
        }

        $labelKey = self::SOURCEABLE_LABELS[$type];
        $label = __('ui.accounting.source_types.'.$labelKey);
        $routeInfo = self::SOURCEABLE_ROUTES[$type] ?? [null, null, null];
        [$routeName, $routeParam, $numberField] = $routeInfo;

        $source = $journal->sourceable;
        $reference = $source && $numberField ? ($source->{$numberField} ?? null) : null;

        if ($routeName && $routeParam && $source) {
            return [
                'label' => $label,
                'reference' => $reference,
                'route' => route($routeName, [$routeParam => $source]),
                'params' => [],
            ];
        }

        return ['label' => $label, 'reference' => $reference, 'route' => null, 'params' => []];
    }
}
