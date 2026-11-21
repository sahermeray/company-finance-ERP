<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed permissions from the catalog and create predefined roles for the
     * given company. When no company is supplied, the seeder is skipped (it
     * must always be called in a company-scoped context).
     *
     * This seeder is idempotent: re-running it will not create duplicates.
     * Do NOT execute this seeder automatically — call it explicitly when
     * initialising a company's role system.
     */
    public function run(?Company $company = null): void
    {
        // Seed the global permission catalog.
        $this->seedPermissions();

        if ($company === null) {
            return;
        }

        $this->seedPredefinedRoles($company);
    }

    // -------------------------------------------------------------------------
    // Global permissions
    // -------------------------------------------------------------------------

    private function seedPermissions(): void
    {
        foreach (PermissionCatalogService::all() as $entry) {
            Permission::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'module'   => $entry['module'],
                    'action'   => $entry['action'],
                    'name_en'  => $entry['name_en'],
                    'name_ar'  => $entry['name_ar'],
                ],
            );
        }
    }

    // -------------------------------------------------------------------------
    // Predefined company roles
    // -------------------------------------------------------------------------

    private function seedPredefinedRoles(Company $company): void
    {
        $allPermissions    = Permission::all();
        $viewPermissions   = $allPermissions->filter(fn (Permission $p) => str_ends_with($p->key, '.view'));

        // ----- Owner --------------------------------------------------------
        $owner = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'owner'],
            [
                'name_en'      => 'Owner',
                'name_ar'      => 'مالك',
                'description'  => 'Full access. Protected system role.',
                'is_system'    => true,
                'is_protected' => true,
            ],
        );
        $owner->permissions()->sync($allPermissions->pluck('id'));

        // ----- Administrator ------------------------------------------------
        $adminPermissions = $this->adminPermissionKeys();
        $administrator = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'administrator'],
            [
                'name_en'      => 'Administrator',
                'name_ar'      => 'مدير',
                'description'  => 'Broad company administration.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $administrator->permissions()->sync(
            $allPermissions->whereIn('key', $adminPermissions)->pluck('id'),
        );

        // ----- Accountant ---------------------------------------------------
        $accountantPermissions = $this->accountantPermissionKeys();
        $accountant = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'accountant'],
            [
                'name_en'      => 'Accountant',
                'name_ar'      => 'محاسب',
                'description'  => 'Accounting, payments, expenses, and reports.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $accountant->permissions()->sync(
            $allPermissions->whereIn('key', $accountantPermissions)->pluck('id'),
        );

        // ----- Sales --------------------------------------------------------
        $salesPermissions = $this->salesPermissionKeys();
        $sales = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'sales'],
            [
                'name_en'      => 'Sales',
                'name_ar'      => 'مبيعات',
                'description'  => 'Sales invoices, customers, and payments view.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $sales->permissions()->sync(
            $allPermissions->whereIn('key', $salesPermissions)->pluck('id'),
        );

        // ----- Purchases ----------------------------------------------------
        $purchasesPermissions = $this->purchasesPermissionKeys();
        $purchases = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'purchases'],
            [
                'name_en'      => 'Purchases',
                'name_ar'      => 'مشتريات',
                'description'  => 'Purchase invoices, suppliers, and payments view.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $purchases->permissions()->sync(
            $allPermissions->whereIn('key', $purchasesPermissions)->pluck('id'),
        );

        // ----- Warehouse ----------------------------------------------------
        $warehousePermissions = $this->warehousePermissionKeys();
        $warehouse = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'warehouse'],
            [
                'name_en'      => 'Warehouse',
                'name_ar'      => 'مستودع',
                'description'  => 'Inventory, products, and warehouses.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $warehouse->permissions()->sync(
            $allPermissions->whereIn('key', $warehousePermissions)->pluck('id'),
        );

        // ----- Viewer -------------------------------------------------------
        $viewer = Role::updateOrCreate(
            ['company_id' => $company->getKey(), 'slug' => 'viewer'],
            [
                'name_en'      => 'Viewer',
                'name_ar'      => 'مشاهد',
                'description'  => 'Read-only access to all modules.',
                'is_system'    => true,
                'is_protected' => false,
            ],
        );
        $viewer->permissions()->sync($viewPermissions->pluck('id'));
    }

    // -------------------------------------------------------------------------
    // Permission key sets per predefined role
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function adminPermissionKeys(): array
    {
        return [
            'dashboard.view',
            'products.view', 'products.create', 'products.update',
            'inventory.view', 'inventory.adjust', 'inventory.count',
            'catalog.view', 'catalog.manage',
            'warehouses.view', 'warehouses.create', 'warehouses.update',
            'customers.view', 'customers.create', 'customers.update',
            'suppliers.view', 'suppliers.create', 'suppliers.update',
            'sales.view', 'sales.create', 'sales.post',
            'purchases.view', 'purchases.create', 'purchases.post',
            'payments.view', 'payments.create', 'payments.reverse',
            'expenses.view', 'expenses.create', 'expenses.approve', 'expenses.post', 'expenses.settle', 'expenses.reverse', 'expenses.delete',
            'cash_accounts.view', 'cash_accounts.create', 'cash_accounts.update',
            'accounting.journals.view', 'accounting.mappings.view', 'accounting.mappings.update',
            'reports.view',
            'company_settings.view', 'company_settings.update',
            'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.manage_memberships',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign',
        ];
    }

    /** @return string[] */
    private function accountantPermissionKeys(): array
    {
        return [
            'dashboard.view',
            'customers.view',
            'suppliers.view',
            'sales.view',
            'purchases.view',
            'payments.view', 'payments.create', 'payments.reverse',
            'expenses.view', 'expenses.create', 'expenses.approve', 'expenses.post', 'expenses.settle', 'expenses.reverse', 'expenses.delete',
            'cash_accounts.view', 'cash_accounts.create', 'cash_accounts.update',
            'accounting.journals.view', 'accounting.mappings.view', 'accounting.mappings.update',
            'reports.view',
        ];
    }

    /** @return string[] */
    private function salesPermissionKeys(): array
    {
        return [
            'dashboard.view',
            'customers.view', 'customers.create', 'customers.update',
            'products.view',
            'inventory.view',
            'sales.view', 'sales.create', 'sales.post',
            'payments.view',
        ];
    }

    /** @return string[] */
    private function purchasesPermissionKeys(): array
    {
        return [
            'dashboard.view',
            'suppliers.view', 'suppliers.create', 'suppliers.update',
            'products.view',
            'inventory.view',
            'purchases.view', 'purchases.create', 'purchases.post',
            'payments.view',
        ];
    }

    /** @return string[] */
    private function warehousePermissionKeys(): array
    {
        return [
            'dashboard.view',
            'products.view', 'products.create', 'products.update',
            'inventory.view', 'inventory.adjust', 'inventory.count',
            'catalog.view', 'catalog.manage',
            'warehouses.view', 'warehouses.create', 'warehouses.update',
        ];
    }
}
