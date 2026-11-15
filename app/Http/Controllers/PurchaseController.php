<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\PurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Purchase::class);
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

        $purchases = Purchase::query()
            ->with('party')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('purchase_number', 'like', '%'.$search.'%')
                    ->orWhereHas('party', fn (Builder $partyQuery) => $partyQuery->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['party_id'] ?? null, fn (Builder $query, int $partyId) => $query->where('party_id', $partyId))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '<=', $date))
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('commercial.purchases.index', [
            'purchases' => $purchases,
            'parties' => Party::query()->orderBy('name')->get(),
            'filters' => $filters,
            'currencies' => $currencies,
        ]);
    }

    public function create(): View
    {
        $this->authorize('viewAny', Purchase::class);
        $company = app(CompanyContextService::class)->requireCurrent();

        return view('commercial.purchases.create', [
            'parties' => Party::query()->orderBy('name')->get(),
            'products' => Product::query()->with('unitOfMeasure')->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'currencies' => $this->currencies((string) $company->base_currency),
            'baseCurrency' => $company->base_currency,
            'accountsByCurrency' => $this->accountsByCurrency($company->getKey()),
        ]);
    }

    public function store(Request $request, PurchaseService $purchases): RedirectResponse
    {
        $this->authorize('viewAny', Purchase::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $currencies = $this->currencies((string) $company->base_currency);

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'purchase_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'currency' => ['required', Rule::in($currencies)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'lines.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999.9999'],
            'lines.*.expense_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', strtoupper((string) $request->input('currency')))],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.9999'],
            'lines.*.tax_treatment' => ['nullable', Rule::in(['recoverable', 'non_recoverable'])],
            'lines.*.tax_asset_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', strtoupper((string) $request->input('currency')))],
            'landed_costs' => ['nullable', 'array', 'max:50'],
            'landed_costs.*.cost_type' => ['nullable', Rule::in(['direct_freight', 'direct_handling', 'administrative'])],
            'landed_costs.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.9999'],
            'landed_costs.*.currency' => ['nullable', Rule::in($currencies)],
            'landed_costs.*.exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
            'landed_costs.*.expense_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)],
            'landed_costs.*.payable_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)],
            'landed_costs.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            if ($product->tracks_inventory && empty($line['warehouse_id'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.warehouse_id" => __('ui.commercial.warehouse_required'),
                ]);
            }

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
            }
        }

        $purchaseId = DB::transaction(function () use ($data, $purchases, $company): int {
            $purchase = $purchases->createDraftPurchase([
                'party_id' => $data['party_id'],
                'purchase_date' => $data['purchase_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $data['currency'],
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'base_currency' => $company->base_currency,
                'notes' => $data['notes'] ?? null,
            ], $company);

            foreach ($data['lines'] as $line) {
                $item = $purchases->addPurchaseItem($purchase, [
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'expense_account_id' => $line['expense_account_id'] ?? null,
                ]);

                if (bccomp((string) ($line['tax_amount'] ?? '0'), '0', 4) > 0) {
                    $purchases->addPurchaseTaxLine($purchase, $item, [
                        'amount' => $line['tax_amount'],
                        'treatment' => $line['tax_treatment'],
                        'tax_asset_account_id' => $line['tax_asset_account_id'] ?? null,
                    ]);
                }
            }

            foreach ($data['landed_costs'] ?? [] as $landedCost) {
                if (bccomp((string) ($landedCost['amount'] ?? '0'), '0', 4) <= 0) {
                    continue;
                }

                if (empty($landedCost['cost_type'])) {
                    throw ValidationException::withMessages([
                        'landed_costs' => __('ui.commercial.landed_cost_type_required'),
                    ]);
                }

                $purchases->addLandedCost($purchase, [
                    ...$landedCost,
                    'currency' => $landedCost['currency'] ?? $data['currency'],
                ]);
            }

            return (int) $purchase->getKey();
        });

        $purchase = Purchase::query()->findOrFail($purchaseId);

        return redirect()->route('purchases.show', $purchase)->with('status', __('ui.commercial.draft_saved'));
    }

    public function show(Purchase $purchase): View
    {
        $this->authorize('view', $purchase);
        $company = app(CompanyContextService::class)->requireCurrent();
        $purchase->load([
            'party',
            'items.product.unitOfMeasure',
            'items.warehouse',
            'items.expenseAccount',
            'items.taxLines.taxAssetAccount',
            'taxLines',
            'landedCosts.expenseAccount',
            'landedCosts.payableAccount',
            'landedCosts.allocations.purchaseItem',
            'stockMovements.product',
            'stockMovements.warehouse',
            'accountingJournals.lines.financialAccount',
        ]);

        return view('commercial.purchases.show', [
            'purchase' => $purchase,
            'accountsByCurrency' => $this->accountsByCurrency((int) $company->getKey()),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function post(Request $request, Purchase $purchase, PurchaseService $purchases): RedirectResponse
    {
        $this->authorize('view', $purchase);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'inventory_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', strtoupper((string) $purchase->base_currency))],
            'payable_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', strtoupper((string) $purchase->currency))],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'expense_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('currency', strtoupper((string) $purchase->currency))],
        ]);

        $purchases->postPurchase($purchase, $data);

        return redirect()->route('purchases.show', $purchase)->with('status', __('ui.commercial.posted'));
    }

    public function print(Purchase $purchase): View
    {
        $this->authorize('view', $purchase);
        $company = app(CompanyContextService::class)->requireCurrent();
        $purchase->load(['party', 'items.product.unitOfMeasure', 'items.warehouse', 'taxLines', 'landedCosts']);

        return view('commercial.invoice', [
            'company' => $company,
            'document' => $purchase,
            'items' => $purchase->items,
            'number' => $purchase->purchase_number,
            'date' => $purchase->purchase_date,
            'type' => 'purchase',
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
     * @return array{liability: mixed, asset: mixed, expense: mixed}
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
            'liability' => $accounts->where('account_type', 'liability')->values(),
            'asset' => $accounts->where('account_type', 'asset')->values(),
            'expense' => $accounts->where('account_type', 'expense')->values(),
        ];
    }
}
