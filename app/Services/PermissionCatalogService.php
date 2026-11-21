<?php

namespace App\Services;

class PermissionCatalogService
{
    /**
     * Complete permission catalog derived from the application's actual routes.
     *
     * Each entry has: module, action, key, name_en, name_ar.
     * Keys follow the `module.action` convention. Only permissions for routes
     * that actually exist in routes/web.php are listed here.
     *
     * @return array<int, array{module: string, action: string, key: string, name_en: string, name_ar: string}>
     */
    public static function all(): array
    {
        return [
            // Dashboard
            ['module' => 'dashboard',          'action' => 'view',              'key' => 'dashboard.view',              'name_en' => 'View Dashboard',                     'name_ar' => 'عرض لوحة التحكم'],

            // Products
            ['module' => 'products',           'action' => 'view',              'key' => 'products.view',               'name_en' => 'View Products',                      'name_ar' => 'عرض المنتجات'],
            ['module' => 'products',           'action' => 'create',            'key' => 'products.create',             'name_en' => 'Create Products',                    'name_ar' => 'إنشاء المنتجات'],
            ['module' => 'products',           'action' => 'update',            'key' => 'products.update',             'name_en' => 'Edit Products',                      'name_ar' => 'تعديل المنتجات'],

            // Inventory
            ['module' => 'inventory',          'action' => 'view',              'key' => 'inventory.view',              'name_en' => 'View Inventory',                     'name_ar' => 'عرض المخزون'],
            ['module' => 'inventory',          'action' => 'adjust',            'key' => 'inventory.adjust',            'name_en' => 'Manage Inventory Adjustments',       'name_ar' => 'إدارة تعديلات المخزون'],
            ['module' => 'inventory',          'action' => 'count',             'key' => 'inventory.count',             'name_en' => 'Manage Stock Counts',                'name_ar' => 'إدارة جرد المخزون'],

            // Catalog (categories and units)
            ['module' => 'catalog',            'action' => 'view',              'key' => 'catalog.view',                'name_en' => 'View Categories & Units',            'name_ar' => 'عرض الفئات والوحدات'],
            ['module' => 'catalog',            'action' => 'manage',            'key' => 'catalog.manage',              'name_en' => 'Manage Categories & Units',          'name_ar' => 'إدارة الفئات والوحدات'],

            // Warehouses
            ['module' => 'warehouses',         'action' => 'view',              'key' => 'warehouses.view',             'name_en' => 'View Warehouses',                    'name_ar' => 'عرض المستودعات'],
            ['module' => 'warehouses',         'action' => 'create',            'key' => 'warehouses.create',           'name_en' => 'Create Warehouses',                  'name_ar' => 'إنشاء المستودعات'],
            ['module' => 'warehouses',         'action' => 'update',            'key' => 'warehouses.update',           'name_en' => 'Edit Warehouses',                    'name_ar' => 'تعديل المستودعات'],

            // Customers
            ['module' => 'customers',          'action' => 'view',              'key' => 'customers.view',              'name_en' => 'View Customers',                     'name_ar' => 'عرض العملاء'],
            ['module' => 'customers',          'action' => 'create',            'key' => 'customers.create',            'name_en' => 'Create Customers',                   'name_ar' => 'إضافة العملاء'],
            ['module' => 'customers',          'action' => 'update',            'key' => 'customers.update',            'name_en' => 'Edit Customers',                     'name_ar' => 'تعديل العملاء'],

            // Suppliers
            ['module' => 'suppliers',          'action' => 'view',              'key' => 'suppliers.view',              'name_en' => 'View Suppliers',                     'name_ar' => 'عرض الموردين'],
            ['module' => 'suppliers',          'action' => 'create',            'key' => 'suppliers.create',            'name_en' => 'Create Suppliers',                   'name_ar' => 'إضافة الموردين'],
            ['module' => 'suppliers',          'action' => 'update',            'key' => 'suppliers.update',            'name_en' => 'تعديل الموردين',                    'name_ar' => 'تعديل الموردين'],

            // Sales
            ['module' => 'sales',              'action' => 'view',              'key' => 'sales.view',                  'name_en' => 'View Sales Invoices',                'name_ar' => 'عرض فواتير المبيعات'],
            ['module' => 'sales',              'action' => 'create',            'key' => 'sales.create',                'name_en' => 'Create Sales Invoices',              'name_ar' => 'إنشاء فواتير المبيعات'],
            ['module' => 'sales',              'action' => 'post',              'key' => 'sales.post',                  'name_en' => 'Post Sales Invoices',                'name_ar' => 'ترحيل فواتير المبيعات'],

            // Purchases
            ['module' => 'purchases',          'action' => 'view',              'key' => 'purchases.view',              'name_en' => 'View Purchase Invoices',             'name_ar' => 'عرض فواتير المشتريات'],
            ['module' => 'purchases',          'action' => 'create',            'key' => 'purchases.create',            'name_en' => 'Create Purchase Invoices',           'name_ar' => 'إنشاء فواتير المشتريات'],
            ['module' => 'purchases',          'action' => 'post',              'key' => 'purchases.post',              'name_en' => 'Post Purchase Invoices',             'name_ar' => 'ترحيل فواتير المشتريات'],

            // Payments
            ['module' => 'payments',           'action' => 'view',              'key' => 'payments.view',               'name_en' => 'View Payments',                      'name_ar' => 'عرض المدفوعات'],
            ['module' => 'payments',           'action' => 'create',            'key' => 'payments.create',             'name_en' => 'Record Payments',                    'name_ar' => 'تسجيل المدفوعات'],
            ['module' => 'payments',           'action' => 'reverse',           'key' => 'payments.reverse',            'name_en' => 'Reverse Payments',                   'name_ar' => 'عكس المدفوعات'],

            // Expenses
            ['module' => 'expenses',           'action' => 'view',              'key' => 'expenses.view',               'name_en' => 'View Expenses',                      'name_ar' => 'عرض المصروفات'],
            ['module' => 'expenses',           'action' => 'create',            'key' => 'expenses.create',             'name_en' => 'Create Expenses',                    'name_ar' => 'إنشاء المصروفات'],
            ['module' => 'expenses',           'action' => 'approve',           'key' => 'expenses.approve',            'name_en' => 'Approve Expenses',                   'name_ar' => 'اعتماد المصروفات'],
            ['module' => 'expenses',           'action' => 'post',              'key' => 'expenses.post',               'name_en' => 'Post Expenses',                      'name_ar' => 'ترحيل المصروفات'],
            ['module' => 'expenses',           'action' => 'settle',            'key' => 'expenses.settle',             'name_en' => 'Settle Expenses',                    'name_ar' => 'تسوية المصروفات'],
            ['module' => 'expenses',           'action' => 'reverse',           'key' => 'expenses.reverse',            'name_en' => 'Reverse Expenses',                   'name_ar' => 'عكس المصروفات'],
            ['module' => 'expenses',           'action' => 'delete',            'key' => 'expenses.delete',             'name_en' => 'Delete Draft Expenses',              'name_ar' => 'حذف مسودة المصروفات'],

            // Cash & Bank Accounts (chart of accounts)
            ['module' => 'cash_accounts',      'action' => 'view',              'key' => 'cash_accounts.view',          'name_en' => 'View Financial Accounts',            'name_ar' => 'عرض الحسابات المالية'],
            ['module' => 'cash_accounts',      'action' => 'create',            'key' => 'cash_accounts.create',        'name_en' => 'Create Financial Accounts',          'name_ar' => 'إنشاء الحسابات المالية'],
            ['module' => 'cash_accounts',      'action' => 'update',            'key' => 'cash_accounts.update',        'name_en' => 'Edit Financial Accounts',            'name_ar' => 'تعديل الحسابات المالية'],

            // Accounting journals
            ['module' => 'accounting',         'action' => 'journals.view',     'key' => 'accounting.journals.view',    'name_en' => 'View Accounting Journals',           'name_ar' => 'عرض القيود المحاسبية'],
            ['module' => 'accounting',         'action' => 'mappings.view',     'key' => 'accounting.mappings.view',    'name_en' => 'View Account Mappings',              'name_ar' => 'عرض ربط الحسابات'],
            ['module' => 'accounting',         'action' => 'mappings.update',   'key' => 'accounting.mappings.update',  'name_en' => 'Update Account Mappings',            'name_ar' => 'تعديل ربط الحسابات'],

            // Reports
            ['module' => 'reports',            'action' => 'view',              'key' => 'reports.view',                'name_en' => 'View Financial Reports',             'name_ar' => 'عرض التقارير المالية'],

            // Company Settings
            ['module' => 'company_settings',   'action' => 'view',              'key' => 'company_settings.view',       'name_en' => 'View Company Settings',              'name_ar' => 'عرض إعدادات الشركة'],
            ['module' => 'company_settings',   'action' => 'update',            'key' => 'company_settings.update',     'name_en' => 'Update Company Settings',            'name_ar' => 'تعديل إعدادات الشركة'],

            // User Management
            ['module' => 'users',              'action' => 'view',              'key' => 'users.view',                  'name_en' => 'View Users',                         'name_ar' => 'عرض المستخدمين'],
            ['module' => 'users',              'action' => 'create',            'key' => 'users.create',                'name_en' => 'Create Users',                       'name_ar' => 'إنشاء المستخدمين'],
            ['module' => 'users',              'action' => 'update',            'key' => 'users.update',                'name_en' => 'Edit Users',                         'name_ar' => 'تعديل المستخدمين'],
            ['module' => 'users',              'action' => 'deactivate',        'key' => 'users.deactivate',            'name_en' => 'Activate / Deactivate Users',        'name_ar' => 'تفعيل / تعطيل المستخدمين'],
            ['module' => 'users',              'action' => 'manage_memberships','key' => 'users.manage_memberships',    'name_en' => 'Manage User Memberships',            'name_ar' => 'إدارة عضوية المستخدمين'],

            // Roles & Permissions (future UI — keys defined now for catalog completeness)
            ['module' => 'roles',              'action' => 'view',              'key' => 'roles.view',                  'name_en' => 'View Roles',                         'name_ar' => 'عرض الأدوار'],
            ['module' => 'roles',              'action' => 'create',            'key' => 'roles.create',                'name_en' => 'Create Roles',                       'name_ar' => 'إنشاء الأدوار'],
            ['module' => 'roles',              'action' => 'update',            'key' => 'roles.update',                'name_en' => 'Edit Roles',                         'name_ar' => 'تعديل الأدوار'],
            ['module' => 'roles',              'action' => 'delete',            'key' => 'roles.delete',                'name_en' => 'Delete Roles',                       'name_ar' => 'حذف الأدوار'],
            ['module' => 'roles',              'action' => 'assign',            'key' => 'roles.assign',                'name_en' => 'Assign Roles to Users',              'name_ar' => 'تعيين الأدوار للمستخدمين'],
        ];
    }
}
