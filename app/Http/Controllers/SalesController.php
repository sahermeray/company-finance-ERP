<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\Revenue;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryService;
use App\Services\RevenueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Revenue::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $currencies = $this->currencies((string) $company->base_currency);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $company->getKey())],
            'currency' => ['nullable', Rule::in($currencies)],
            'status' => ['nullable', Rule::in(['draft', 'posted'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $sales = Revenue::query()
            ->with('party')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('revenue_number', 'like', '%'.$search.'%')
                    ->orWhereHas('party', fn (Builder $partyQuery) => $partyQuery->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['party_id'] ?? null, fn (Builder $query, int $partyId) => $query->where('party_id', $partyId))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('revenue_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('revenue_date', '<=', $date))
            ->orderByDesc('revenue_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('commercial.sales.index', [
            'sales' => $sales,
            'parties' => Party::query()->orderBy('name')->get(),
            'filters' => $filters,
            'currencies' => $currencies,
        ]);
    }

    public function create(): View
    {
        $this->authorize('viewAny', Revenue::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        return view('commercial.sales.create', [
            'parties' => Party::query()->orderBy('name')->get(),
            'products' => Product::query()->with('unitOfMeasure')->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'currencies' => $this->currencies((string) $company->base_currency),
            'baseCurrency' => $company->base_currency,
        ]);
    }

    public function store(Request $request, RevenueService $revenues): RedirectResponse
    {
        $this->authorize('viewAny', Revenue::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $currencies = $this->currencies((string) $company->base_currency);
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'revenue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:revenue_date'],
            'currency' => ['required', Rule::in($currencies)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'lines.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999.9999'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            if ($product->tracks_inventory && empty($line['warehouse_id'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.warehouse_id" => __('ui.commercial.warehouse_required'),
                ]);
            }
        }

        $revenueId = DB::transaction(function () use ($data, $revenues, $company): int {
            $revenue = $revenues->createDraftRevenue([
                'party_id' => $data['party_id'],
                'revenue_date' => $data['revenue_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $data['currency'],
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'base_currency' => $company->base_currency,
                'notes' => $data['notes'] ?? null,
            ], $company);

            foreach ($data['lines'] as $line) {
                $revenues->addRevenueItem($revenue, [
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            return (int) $revenue->getKey();
        });

        return redirect()->route('sales.show', Revenue::query()->findOrFail($revenueId))->with('status', __('ui.commercial.draft_saved'));
    }

    public function show(Revenue $revenue): View
    {
        $this->authorize('view', $revenue);
        $revenue->load([
            'party',
            'items.product.unitOfMeasure',
            'items.warehouse',
            'stockMovements.product',
            'stockMovements.warehouse',
            'accountingJournals.lines.financialAccount',
        ]);

        return view('commercial.sales.show', [
            'revenue' => $revenue,
            'accountsByCurrency' => $this->accountsByCurrency((int) $revenue->company_id),
            'stockMovements' => $revenue->stockMovements->sortBy('id')->values(),
        ]);
    }

    public function post(Request $request, Revenue $revenue, RevenueService $revenues): RedirectResponse
    {
        $this->authorize('view', $revenue);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $currency = strtoupper((string) $revenue->currency);
        $baseCurrency = strtoupper((string) $revenue->base_currency);
        $data = $request->validate([
            'settlement_type' => ['required', Rule::in(['receivable', 'cash'])],
            'settlement_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', $currency)],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', $currency)],
            'inventory_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', $baseCurrency)],
            'cogs_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', $baseCurrency)],
        ]);

        $settlementField = $data['settlement_type'].'_account_id';
        $options = [
            $settlementField => $data['settlement_account_id'],
            'revenue_account_id' => $data['revenue_account_id'] ?? null,
            'inventory_account_id' => $data['inventory_account_id'] ?? null,
            'cogs_account_id' => $data['cogs_account_id'] ?? null,
        ];
        $revenues->postRevenue($revenue, $options);

        return redirect()->route('sales.show', $revenue)->with('status', __('ui.commercial.posted'));
    }

    public function print(Revenue $revenue): View
    {
        $this->authorize('view', $revenue);
        $company = app(CompanyContextService::class)->requireCurrent();
        $revenue->load(['party', 'items.product.unitOfMeasure', 'items.warehouse']);

        return view('commercial.invoice', [
            'company' => $company,
            'document' => $revenue,
            'items' => $revenue->items,
            'number' => $revenue->revenue_number,
            'date' => $revenue->revenue_date,
            'type' => 'sales',
        ]);
    }

    public function availability(Request $request, InventoryService $inventory): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('tracks_inventory', true)->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)->where('is_active', true)],
        ]);
        $product = Product::query()->findOrFail($data['product_id']);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        return response()->json([
            'quantity' => number_format($inventory->getProductStockInWarehouse($product, $warehouse), 4, '.', ''),
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
     * @return array{asset: mixed, revenue: mixed, expense: mixed}
     */
    private function accountsByCurrency(int $companyId): array
    {
        $accounts = FinancialAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('currency')
            ->orderBy('code')
            ->get();

        return [
            'asset' => $accounts->where('account_type', 'asset')->values(),
            'revenue' => $accounts->whereIn('account_type', ['revenue', 'income'])->values(),
            'expense' => $accounts->where('account_type', 'expense')->values(),
        ];
    }
}
