<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Services\CompanyContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ProductCategory::class);
        $this->authorize('viewAny', UnitOfMeasure::class);

        return view('inventory.catalog.index', [
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'units' => UnitOfMeasure::query()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', ProductCategory::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_categories', 'code')->where('company_id', $companyId),
            ],
        ]);

        ProductCategory::query()->create([...$data, 'company_id' => $companyId]);

        return back()->with('status', __('ui.inventory.saved'));
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorize('view', $category);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_categories', 'code')->where('company_id', $companyId)->ignore($category->getKey()),
            ],
        ]);

        $category->update($data);

        return back()->with('status', __('ui.inventory.saved'));
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', UnitOfMeasure::class);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unit_of_measures', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'decimal_places' => ['required', 'integer', 'between:0,6'],
        ]);

        UnitOfMeasure::query()->create([...$data, 'company_id' => $companyId]);

        return back()->with('status', __('ui.inventory.saved'));
    }

    public function updateUnit(Request $request, UnitOfMeasure $unit): RedirectResponse
    {
        $this->authorize('view', $unit);
        $companyId = app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unit_of_measures', 'code')->where('company_id', $companyId)->ignore($unit->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'decimal_places' => ['required', 'integer', 'between:0,6'],
        ]);

        $unit->update($data);

        return back()->with('status', __('ui.inventory.saved'));
    }
}
