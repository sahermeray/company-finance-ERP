<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\CompanyAuthorizationService;
use App\Services\CompanyContextService;
use App\Services\PermissionCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAuthorizationService $authorization,
    ) {}

    public function index(): View
    {
        $company = $this->companyContext->requireCurrent();

        $roles = Role::where('company_id', $company->getKey())
            ->withCount('permissions')
            ->orderBy('is_protected', 'desc')
            ->orderBy('is_system', 'desc')
            ->orderBy('name_en')
            ->paginate(25);

        return view('roles.index', compact('company', 'roles'));
    }

    public function create(): View
    {
        $company    = $this->companyContext->requireCurrent();
        $catalog    = PermissionCatalogService::all();
        $grouped    = collect($catalog)->groupBy('module');
        $allKeys    = collect($catalog)->pluck('key')->all();

        return view('roles.create', compact('company', 'grouped', 'allKeys'));
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();

        $allKeys = collect(PermissionCatalogService::all())->pluck('key')->all();

        $data = $request->validate([
            'name_en'       => ['required', 'string', 'max:100'],
            'name_ar'       => ['required', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:500'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', $allKeys)],
        ]);

        $slug = Str::slug($data['name_en']);
        // Ensure slug uniqueness within the company.
        $existing = Role::where('company_id', $company->getKey())->where('slug', $slug)->exists();
        if ($existing) {
            $slug = $slug.'-'.uniqid();
        }

        $role = Role::create([
            'company_id'   => $company->getKey(),
            'name_en'      => $data['name_en'],
            'name_ar'      => $data['name_ar'],
            'slug'         => $slug,
            'description'  => $data['description'] ?? null,
            'is_system'    => false,
            'is_protected' => false,
        ]);

        if (! empty($data['permissions'])) {
            $permIds = Permission::whereIn('key', $data['permissions'])->pluck('id');
            $role->permissions()->sync($permIds);
        }

        return redirect()->route('roles.show', $role)
            ->with('status', __('ui.roles.created'));
    }

    public function show(Role $role): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorizeRoleScope($role, $company->getKey());

        $role->load('permissions');
        $catalog = PermissionCatalogService::all();
        $grouped = collect($catalog)->groupBy('module');

        return view('roles.show', compact('company', 'role', 'grouped'));
    }

    public function edit(Role $role): View
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorizeRoleScope($role, $company->getKey());

        $role->load('permissions');
        $catalog = PermissionCatalogService::all();
        $grouped = collect($catalog)->groupBy('module');
        $allKeys = collect($catalog)->pluck('key')->all();

        return view('roles.edit', compact('company', 'role', 'grouped', 'allKeys'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorizeRoleScope($role, $company->getKey());

        // Protected (owner) role permissions cannot be changed.
        if ($role->is_protected) {
            return redirect()->route('roles.show', $role)
                ->withErrors(['role' => __('ui.roles.cannot_edit_protected')]);
        }

        $allKeys = collect(PermissionCatalogService::all())->pluck('key')->all();

        $data = $request->validate([
            'name_en'       => ['required', 'string', 'max:100'],
            'name_ar'       => ['required', 'string', 'max:100'],
            'description'   => ['nullable', 'string', 'max:500'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', $allKeys)],
        ]);

        $role->name_en     = $data['name_en'];
        $role->name_ar     = $data['name_ar'];
        $role->description = $data['description'] ?? null;
        $role->save();

        $permIds = ! empty($data['permissions'])
            ? Permission::whereIn('key', $data['permissions'])->pluck('id')
            : collect();

        $role->permissions()->sync($permIds);

        return redirect()->route('roles.show', $role)
            ->with('status', __('ui.roles.updated'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();
        $this->authorizeRoleScope($role, $company->getKey());

        if ($role->is_protected) {
            return redirect()->route('roles.index')
                ->withErrors(['role' => __('ui.roles.cannot_delete_protected')]);
        }

        // Check no users are assigned to this role in this company.
        $assigned = DB::table('company_user')
            ->where('role_id', $role->getKey())
            ->exists();

        if ($assigned) {
            return redirect()->route('roles.show', $role)
                ->withErrors(['role' => __('ui.roles.cannot_delete_assigned')]);
        }

        $role->delete();

        return redirect()->route('roles.index')
            ->with('status', __('ui.roles.deleted'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function authorizeRoleScope(Role $role, int|string $companyId): void
    {
        if ((int) $role->company_id !== (int) $companyId) {
            abort(403);
        }
    }
}
