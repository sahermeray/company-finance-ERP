<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'tracking' => ['nullable', Rule::in(['tracked', 'untracked'])],
        ]);

        $products = Product::query()
            ->with(['category', 'unitOfMeasure'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['category'] ?? null, fn (Builder $query, int $categoryId) => $query->where('product_category_id', $categoryId))
            ->when($filters['tracking'] ?? null, fn (Builder $query, string $tracking) => $query->where('tracks_inventory', $tracking === 'tracked'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('inventory.products.index', [
            'products' => $products,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        $this->authorize('viewAny', Product::class);

        return view('inventory.products.form', $this->formData(new Product));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Product::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $this->validatedProduct($request, $companyId);

        Product::query()->create([...$data, 'company_id' => $companyId]);

        return redirect()->route('inventory.products.index')->with('status', __('ui.inventory.saved'));
    }

    public function edit(Product $product): View
    {
        $this->authorize('view', $product);

        return view('inventory.products.form', $this->formData($product));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('view', $product);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $this->validatedProduct($request, $companyId, $product);

        if ($product->tracks_inventory && ! $data['tracks_inventory'] && $product->stockMovements()->exists()) {
            return back()->withErrors([
                'tracks_inventory' => __('ui.inventory.cannot_disable_tracking'),
            ])->withInput();
        }

        $product->update($data);

        return redirect()->route('inventory.products.show', $product)->with('status', __('ui.inventory.saved'));
    }

    public function show(Product $product, InventoryCostingService $costing): View
    {
        $this->authorize('view', $product);
        $company = app(CompanyContextService::class)->requireCurrent();
        $warehouses = Warehouse::query()->orderBy('name')->get();
        $valuationStatus = $product->tracks_inventory ? $costing->valuationHistoryStatus($product) : 'not_tracked';
        $valuation = in_array($valuationStatus, ['empty', 'valued'], true)
            ? $costing->currentValuation($product)
            : null;
        $warehouseQuantities = $warehouses->mapWithKeys(fn (Warehouse $warehouse) => [
            $warehouse->getKey() => $product->tracks_inventory ? $costing->quantityInWarehouse($product, $warehouse) : null,
        ]);
        $warehouseTotal = $warehouseQuantities->filter(fn (?string $quantity): bool => $quantity !== null)->reduce(
            static fn (string $total, string $quantity): string => bcadd($total, $quantity, 4),
            '0.0000',
        );
        $movementQuery = StockMovement::query()
            ->where('product_id', $product->getKey())
            ->with(['warehouse', 'party', 'project', 'sourceable'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        return view('inventory.products.show', [
            'product' => $product->load(['category', 'unitOfMeasure']),
            'company' => $company,
            'warehouses' => $warehouses,
            'warehouseQuantities' => $warehouseQuantities,
            'warehouseTotal' => $warehouseTotal,
            'valuationStatus' => $valuationStatus,
            'valuation' => $valuation,
            'movements' => $movementQuery->paginate(15),
        ]);
    }

    /**
     * @return array{categories: mixed, units: mixed, product: Product}
     */
    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'units' => UnitOfMeasure::query()->orderBy('name')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProduct(Request $request, int $companyId, ?Product $product = null): array
    {
        $skuRule = Rule::unique('products', 'sku')->where('company_id', $companyId);
        if ($product !== null) {
            $skuRule->ignore($product->getKey());
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255', $skuRule],
            'product_category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'unit_of_measure_id' => ['required', 'integer', Rule::exists('unit_of_measures', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:10000'],
            'tracks_inventory' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
