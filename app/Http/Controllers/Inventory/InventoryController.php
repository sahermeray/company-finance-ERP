<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\StockCountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function overview(Request $request, InventoryCostingService $costing): View
    {
        $this->authorize('viewAny', Product::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $products = Product::query()
            ->with(['category', 'unitOfMeasure'])
            ->where('tracks_inventory', true)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $warehouses = Warehouse::query()->orderBy('name')->get();
        $rows = $products->getCollection()->map(function (Product $product) use ($costing, $warehouses): array {
            $valuationStatus = $costing->valuationHistoryStatus($product);
            $valuation = in_array($valuationStatus, ['empty', 'valued'], true)
                ? $costing->currentValuation($product)
                : null;
            $warehouseQuantities = $warehouses->mapWithKeys(fn (Warehouse $warehouse) => [
                $warehouse->getKey() => $costing->quantityInWarehouse($product, $warehouse),
            ]);
            $quantity = $warehouseQuantities->reduce(
                static fn (string $total, string $warehouseQuantity): string => bcadd($total, $warehouseQuantity, 4),
                '0.0000',
            );

            return [
                'product' => $product,
                'quantity' => $quantity,
                'valuation' => $valuation,
                'valuation_status' => $valuationStatus,
                'warehouse_quantities' => $warehouseQuantities,
            ];
        });
        $products->setCollection($rows);

        return view('inventory.overview', compact('products', 'warehouses', 'filters'));
    }

    public function counts(Request $request): View
    {
        $this->authorize('viewAny', StockCount::class);
        $counts = StockCount::query()
            ->withCount('lines')
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('inventory.counts.index', compact('counts'));
    }

    public function createCount(): View
    {
        $this->authorize('viewAny', StockCount::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $this->authorize('viewAny', FinancialAccount::class);

        return view('inventory.counts.form', [
            'products' => Product::query()->where('tracks_inventory', true)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'assetAccounts' => $this->accounts(['asset'], $company->base_currency),
            'positiveAccounts' => $this->accounts(['revenue', 'income', 'equity'], $company->base_currency),
            'negativeAccounts' => $this->accounts(['expense'], $company->base_currency),
            'baseCurrency' => $company->base_currency,
        ]);
    }

    public function storeCount(Request $request, StockCountService $counts): RedirectResponse
    {
        $this->authorize('viewAny', StockCount::class);
        $data = $request->validate([
            'inventory_asset_account_id' => ['required', 'integer'],
            'positive_variance_account_id' => ['required', 'integer'],
            'negative_variance_account_id' => ['required', 'integer'],
            'counted_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1950'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.warehouse_id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999.9999'],
            'lines.*.approved_unit_cost' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
        ]);
        $count = $counts->createDraft($data);

        return redirect()->route('inventory.counts.show', $count)->with('status', __('ui.inventory.saved'));
    }

    public function showCount(StockCount $count): View
    {
        $this->authorize('view', $count);

        return view('inventory.counts.show', [
            'count' => $count->load(['lines.product.unitOfMeasure', 'lines.warehouse', 'inventoryAssetAccount', 'positiveVarianceAccount', 'negativeVarianceAccount']),
        ]);
    }

    public function approveCount(StockCount $count, StockCountService $counts): RedirectResponse
    {
        $this->authorize('view', $count);
        $counts->approve($count);

        return back()->with('status', __('ui.inventory.approved'));
    }

    public function postCount(StockCount $count, StockCountService $counts): RedirectResponse
    {
        $this->authorize('view', $count);
        $counts->post($count);

        return back()->with('status', __('ui.inventory.posted'));
    }

    public function adjustments(): View
    {
        $this->authorize('viewAny', InventoryAdjustment::class);
        $adjustments = InventoryAdjustment::query()
            ->with(['product.unitOfMeasure', 'warehouse'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('inventory.adjustments.index', compact('adjustments'));
    }

    public function createAdjustment(): View
    {
        $this->authorize('viewAny', InventoryAdjustment::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $this->authorize('viewAny', FinancialAccount::class);

        return view('inventory.adjustments.form', [
            'products' => Product::query()->where('tracks_inventory', true)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'assetAccounts' => $this->accounts(['asset'], $company->base_currency),
            'positiveAccounts' => $this->accounts(['revenue', 'income', 'equity'], $company->base_currency),
            'negativeAccounts' => $this->accounts(['expense'], $company->base_currency),
            'baseCurrency' => $company->base_currency,
        ]);
    }

    public function storeAdjustment(Request $request, InventoryAdjustmentService $adjustments): RedirectResponse
    {
        $this->authorize('viewAny', InventoryAdjustment::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('tracks_inventory', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'adjustment_type' => ['required', Rule::in(['positive', 'negative', 'damaged', 'lost', 'unusable'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'base_unit_cost' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999.9999999999'],
            'inventory_asset_account_id' => ['required', 'integer'],
            'offset_account_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $adjustment = $adjustments->createDraft($data);

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('status', __('ui.inventory.saved'));
    }

    public function showAdjustment(InventoryAdjustment $adjustment): View
    {
        $this->authorize('view', $adjustment);

        return view('inventory.adjustments.show', [
            'adjustment' => $adjustment->load(['product.unitOfMeasure', 'warehouse', 'inventoryAssetAccount', 'offsetAccount', 'movement', 'journal']),
        ]);
    }

    public function approveAdjustment(InventoryAdjustment $adjustment, InventoryAdjustmentService $adjustments): RedirectResponse
    {
        $this->authorize('view', $adjustment);
        $adjustments->approve($adjustment);

        return back()->with('status', __('ui.inventory.approved'));
    }

    public function postAdjustment(InventoryAdjustment $adjustment, InventoryAdjustmentService $adjustments): RedirectResponse
    {
        $this->authorize('view', $adjustment);
        $adjustments->post($adjustment);

        return back()->with('status', __('ui.inventory.posted'));
    }

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, FinancialAccount>
     */
    private function accounts(array $types, string $currency): Collection
    {
        return FinancialAccount::query()
            ->whereIn('account_type', $types)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }
}
