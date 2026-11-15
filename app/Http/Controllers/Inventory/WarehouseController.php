<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\CompanyContextService;
use App\Services\InventoryCostingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Warehouse::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $warehouses = Warehouse::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%'));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('inventory.warehouses.index', compact('warehouses', 'filters'));
    }

    public function create(): View
    {
        $this->authorize('viewAny', Warehouse::class);

        return view('inventory.warehouses.form', ['warehouse' => new Warehouse]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Warehouse::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $this->validatedWarehouse($request, $companyId);

        Warehouse::query()->create([...$data, 'company_id' => $companyId]);

        return redirect()->route('inventory.warehouses.index')->with('status', __('ui.inventory.saved'));
    }

    public function show(Warehouse $warehouse, InventoryCostingService $costing): View
    {
        $this->authorize('view', $warehouse);
        $products = Product::query()
            ->with(['unitOfMeasure', 'category'])
            ->where('tracks_inventory', true)
            ->orderBy('name')
            ->get();

        $stockRows = $products->map(fn (Product $product): array => [
            'product' => $product,
            'quantity' => $costing->quantityInWarehouse($product, $warehouse),
        ]);

        return view('inventory.warehouses.show', compact('warehouse', 'stockRows'));
    }

    public function edit(Warehouse $warehouse): View
    {
        $this->authorize('view', $warehouse);

        return view('inventory.warehouses.form', compact('warehouse'));
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('view', $warehouse);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $warehouse->update($this->validatedWarehouse($request, $companyId, $warehouse));

        return redirect()->route('inventory.warehouses.show', $warehouse)->with('status', __('ui.inventory.saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWarehouse(Request $request, int $companyId, ?Warehouse $warehouse = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('warehouses', 'name')->where('company_id', $companyId)->ignore($warehouse?->getKey()),
            ],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('warehouses', 'code')->where('company_id', $companyId)->ignore($warehouse?->getKey()),
            ],
            'address' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
