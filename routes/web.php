<?php

use App\Http\Controllers\AccountingJournalController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CashBankController;
use App\Http\Controllers\CompanyContextController;
use App\Http\Controllers\CompanySelectionController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\Inventory\CatalogController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\LocalePreferenceController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::post('/language', LocalePreferenceController::class)->name('language.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard.index');
    Route::get('/companies/select', CompanySelectionController::class)->name('companies.select');
    Route::post('/company-context', [CompanyContextController::class, 'update'])
        ->name('company-context.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('inventory')->name('inventory.')->middleware('company.context')->group(function (): void {
        Route::get('/', [InventoryController::class, 'overview'])
            ->middleware('company.permission:inventory.view')
            ->name('overview');

        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('company.permission:products.view')
            ->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])
            ->middleware('company.permission:products.create')
            ->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('company.permission:products.create')
            ->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])
            ->middleware('company.permission:products.view')
            ->name('products.show');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])
            ->middleware('company.permission:products.update')
            ->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])
            ->middleware('company.permission:products.update')
            ->name('products.update');

        Route::get('/catalog', [CatalogController::class, 'index'])
            ->middleware('company.permission:catalog.view')
            ->name('catalog.index');
        Route::post('/catalog/categories', [CatalogController::class, 'storeCategory'])
            ->middleware('company.permission:catalog.manage')
            ->name('catalog.categories.store');
        Route::put('/catalog/categories/{category}', [CatalogController::class, 'updateCategory'])
            ->middleware('company.permission:catalog.manage')
            ->name('catalog.categories.update');
        Route::post('/catalog/units', [CatalogController::class, 'storeUnit'])
            ->middleware('company.permission:catalog.manage')
            ->name('catalog.units.store');
        Route::put('/catalog/units/{unit}', [CatalogController::class, 'updateUnit'])
            ->middleware('company.permission:catalog.manage')
            ->name('catalog.units.update');

        Route::get('/warehouses', [WarehouseController::class, 'index'])
            ->middleware('company.permission:warehouses.view')
            ->name('warehouses.index');
        Route::get('/warehouses/create', [WarehouseController::class, 'create'])
            ->middleware('company.permission:warehouses.create')
            ->name('warehouses.create');
        Route::post('/warehouses', [WarehouseController::class, 'store'])
            ->middleware('company.permission:warehouses.create')
            ->name('warehouses.store');
        Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])
            ->middleware('company.permission:warehouses.view')
            ->name('warehouses.show');
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
            ->middleware('company.permission:warehouses.update')
            ->name('warehouses.edit');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->middleware('company.permission:warehouses.update')
            ->name('warehouses.update');

        Route::get('/counts', [InventoryController::class, 'counts'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.index');
        Route::get('/counts/create', [InventoryController::class, 'createCount'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.create');
        Route::post('/counts', [InventoryController::class, 'storeCount'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.store');
        Route::get('/counts/{count}', [InventoryController::class, 'showCount'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.show');
        Route::post('/counts/{count}/approve', [InventoryController::class, 'approveCount'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.approve');
        Route::post('/counts/{count}/post', [InventoryController::class, 'postCount'])
            ->middleware('company.permission:inventory.count')
            ->name('counts.post');

        Route::get('/adjustments', [InventoryController::class, 'adjustments'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.index');
        Route::get('/adjustments/create', [InventoryController::class, 'createAdjustment'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.create');
        Route::post('/adjustments', [InventoryController::class, 'storeAdjustment'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [InventoryController::class, 'showAdjustment'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.show');
        Route::post('/adjustments/{adjustment}/approve', [InventoryController::class, 'approveAdjustment'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/post', [InventoryController::class, 'postAdjustment'])
            ->middleware('company.permission:inventory.adjust')
            ->name('adjustments.post');
    });

    Route::middleware('company.context')->group(function (): void {
        Route::get('/customers', [PartyController::class, 'customers'])
            ->middleware('company.permission:customers.view')
            ->name('customers.index');
        Route::get('/customers/create', [PartyController::class, 'createCustomer'])
            ->middleware('company.permission:customers.create')
            ->name('customers.create');
        Route::post('/customers', [PartyController::class, 'storeCustomer'])
            ->middleware('company.permission:customers.create')
            ->name('customers.store');
        Route::get('/customers/{party}/edit', [PartyController::class, 'editCustomer'])
            ->middleware('company.permission:customers.update')
            ->name('customers.edit');
        Route::put('/customers/{party}', [PartyController::class, 'updateCustomer'])
            ->middleware('company.permission:customers.update')
            ->name('customers.update');
        Route::get('/customers/{party}/statement/print', [PartyController::class, 'printCustomerStatement'])
            ->middleware('company.permission:customers.view')
            ->name('customers.statement.print');
        Route::get('/customers/{party}/statement', [PartyController::class, 'customerStatement'])
            ->middleware('company.permission:customers.view')
            ->name('customers.statement');
        Route::get('/customers/{party}', [PartyController::class, 'showCustomer'])
            ->middleware('company.permission:customers.view')
            ->name('customers.show');

        Route::get('/suppliers', [PartyController::class, 'suppliers'])
            ->middleware('company.permission:suppliers.view')
            ->name('suppliers.index');
        Route::get('/suppliers/create', [PartyController::class, 'createSupplier'])
            ->middleware('company.permission:suppliers.create')
            ->name('suppliers.create');
        Route::post('/suppliers', [PartyController::class, 'storeSupplier'])
            ->middleware('company.permission:suppliers.create')
            ->name('suppliers.store');
        Route::get('/suppliers/{party}/edit', [PartyController::class, 'editSupplier'])
            ->middleware('company.permission:suppliers.update')
            ->name('suppliers.edit');
        Route::put('/suppliers/{party}', [PartyController::class, 'updateSupplier'])
            ->middleware('company.permission:suppliers.update')
            ->name('suppliers.update');
        Route::get('/suppliers/{party}/statement/print', [PartyController::class, 'printSupplierStatement'])
            ->middleware('company.permission:suppliers.view')
            ->name('suppliers.statement.print');
        Route::get('/suppliers/{party}/statement', [PartyController::class, 'supplierStatement'])
            ->middleware('company.permission:suppliers.view')
            ->name('suppliers.statement');
        Route::get('/suppliers/{party}', [PartyController::class, 'showSupplier'])
            ->middleware('company.permission:suppliers.view')
            ->name('suppliers.show');

        Route::get('/payments', [PaymentController::class, 'index'])
            ->middleware('company.permission:payments.view')
            ->name('payments.index');
        Route::get('/payments/create', [PaymentController::class, 'create'])
            ->middleware('company.permission:payments.create')
            ->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])
            ->middleware('company.permission:payments.create')
            ->name('payments.store');
        Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])
            ->middleware('company.permission:payments.reverse')
            ->name('payments.reverse');
        Route::get('/payments/{payment}/print', [PaymentController::class, 'print'])
            ->middleware('company.permission:payments.view')
            ->name('payments.print');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->middleware('company.permission:payments.view')
            ->name('payments.show');

        Route::get('/purchases', [PurchaseController::class, 'index'])
            ->middleware('company.permission:purchases.view')
            ->name('purchases.index');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])
            ->middleware('company.permission:purchases.create')
            ->name('purchases.create');
        Route::post('/purchases', [PurchaseController::class, 'store'])
            ->middleware('company.permission:purchases.create')
            ->name('purchases.store');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])
            ->middleware('company.permission:purchases.view')
            ->name('purchases.show');
        Route::post('/purchases/{purchase}/post', [PurchaseController::class, 'post'])
            ->middleware('company.permission:purchases.post')
            ->name('purchases.post');
        Route::get('/purchases/{purchase}/print', [PurchaseController::class, 'print'])
            ->middleware('company.permission:purchases.view')
            ->name('purchases.print');

        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('company.permission:expenses.view')
            ->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])
            ->middleware('company.permission:expenses.create')
            ->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])
            ->middleware('company.permission:expenses.create')
            ->name('expenses.store');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
            ->middleware('company.permission:expenses.view')
            ->name('expenses.show');
        Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])
            ->middleware('company.permission:expenses.approve')
            ->name('expenses.approve');
        Route::post('/expenses/{expense}/post', [ExpenseController::class, 'post'])
            ->middleware('company.permission:expenses.post')
            ->name('expenses.post');
        Route::post('/expenses/{expense}/settle', [ExpenseController::class, 'settle'])
            ->middleware('company.permission:expenses.settle')
            ->name('expenses.settle');
        Route::post('/expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])
            ->middleware('company.permission:expenses.reverse')
            ->name('expenses.reverse');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
            ->middleware('company.permission:expenses.delete')
            ->name('expenses.destroy');
        Route::get('/expenses/{expense}/print', [ExpenseController::class, 'print'])
            ->middleware('company.permission:expenses.view')
            ->name('expenses.print');

        Route::get('/sales/availability', [SalesController::class, 'availability'])
            ->middleware('company.permission:sales.view')
            ->name('sales.availability');
        Route::get('/sales', [SalesController::class, 'index'])
            ->middleware('company.permission:sales.view')
            ->name('sales.index');
        Route::get('/sales/create', [SalesController::class, 'create'])
            ->middleware('company.permission:sales.create')
            ->name('sales.create');
        Route::post('/sales', [SalesController::class, 'store'])
            ->middleware('company.permission:sales.create')
            ->name('sales.store');
        Route::get('/sales/{revenue}', [SalesController::class, 'show'])
            ->middleware('company.permission:sales.view')
            ->name('sales.show');
        Route::post('/sales/{revenue}/post', [SalesController::class, 'post'])
            ->middleware('company.permission:sales.post')
            ->name('sales.post');
        Route::get('/sales/{revenue}/print', [SalesController::class, 'print'])
            ->middleware('company.permission:sales.view')
            ->name('sales.print');

        Route::get('/cash-bank', [CashBankController::class, 'index'])
            ->middleware('company.permission:cash_accounts.view')
            ->name('cash-bank.index');
        Route::get('/cash-bank/create', [CashBankController::class, 'create'])
            ->middleware('company.permission:cash_accounts.create')
            ->name('cash-bank.create');
        Route::post('/cash-bank', [CashBankController::class, 'store'])
            ->middleware('company.permission:cash_accounts.create')
            ->name('cash-bank.store');
        Route::get('/cash-bank/{cashBank}/print', [CashBankController::class, 'print'])
            ->middleware('company.permission:cash_accounts.view')
            ->name('cash-bank.print');
        Route::get('/cash-bank/{cashBank}/edit', [CashBankController::class, 'edit'])
            ->middleware('company.permission:cash_accounts.update')
            ->name('cash-bank.edit');
        Route::put('/cash-bank/{cashBank}', [CashBankController::class, 'update'])
            ->middleware('company.permission:cash_accounts.update')
            ->name('cash-bank.update');
        Route::get('/cash-bank/{cashBank}', [CashBankController::class, 'show'])
            ->middleware('company.permission:cash_accounts.view')
            ->name('cash-bank.show');

        Route::prefix('accounting')->name('accounting.')->group(function (): void {
            Route::get('/journals', [AccountingJournalController::class, 'index'])
                ->middleware('company.permission:accounting.journals.view')
                ->name('journals.index');
            Route::get('/journals/{journal}', [AccountingJournalController::class, 'show'])
                ->middleware('company.permission:accounting.journals.view')
                ->name('journals.show');
            Route::get('/mappings', [AccountingJournalController::class, 'mappings'])
                ->middleware('company.permission:accounting.mappings.view')
                ->name('mappings');
            Route::put('/mappings/{purpose}', [AccountingJournalController::class, 'updateMapping'])
                ->middleware('company.permission:accounting.mappings.update')
                ->name('mappings.update');
        });

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/', [CompanySettingsController::class, 'index'])
                ->middleware('company.permission:company_settings.view')
                ->name('index');
            Route::get('/general', [CompanySettingsController::class, 'general'])
                ->middleware('company.permission:company_settings.view')
                ->name('general');
            Route::put('/general', [CompanySettingsController::class, 'updateGeneral'])
                ->middleware('company.permission:company_settings.update')
                ->name('general.update');
            Route::get('/financial', [CompanySettingsController::class, 'financial'])
                ->middleware('company.permission:company_settings.view')
                ->name('financial');
            Route::put('/financial', [CompanySettingsController::class, 'updateFinancial'])
                ->middleware('company.permission:company_settings.update')
                ->name('financial.update');
            Route::get('/exchange-rates', [CompanySettingsController::class, 'exchangeRates'])
                ->middleware('company.permission:company_settings.view')
                ->name('exchange-rates');
            Route::post('/exchange-rates', [CompanySettingsController::class, 'storeExchangeRate'])
                ->middleware('company.permission:company_settings.update')
                ->name('exchange-rates.store');
            Route::get('/forex-accounts', [CompanySettingsController::class, 'forexAccounts'])
                ->middleware('company.permission:company_settings.view')
                ->name('forex-accounts');
            Route::put('/forex-accounts/gain', [CompanySettingsController::class, 'updateGainAccount'])
                ->middleware('company.permission:company_settings.update')
                ->name('forex-accounts.gain');
            Route::put('/forex-accounts/loss', [CompanySettingsController::class, 'updateLossAccount'])
                ->middleware('company.permission:company_settings.update')
                ->name('forex-accounts.loss');
            Route::get('/fiscal-year', [CompanySettingsController::class, 'fiscalYear'])
                ->middleware('company.permission:company_settings.view')
                ->name('fiscal-year');
        });

        Route::prefix('reports')->name('reports.')->group(function (): void {
            Route::get('/', [FinancialReportController::class, 'index'])
                ->middleware('company.permission:reports.view')
                ->name('index');
            Route::get('/trial-balance/print', [FinancialReportController::class, 'printTrialBalance'])
                ->middleware('company.permission:reports.view')
                ->name('trial-balance.print');
            Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance'])
                ->middleware('company.permission:reports.view')
                ->name('trial-balance');
            Route::get('/general-ledger/print', [FinancialReportController::class, 'printGeneralLedger'])
                ->middleware('company.permission:reports.view')
                ->name('general-ledger.print');
            Route::get('/general-ledger', [FinancialReportController::class, 'generalLedger'])
                ->middleware('company.permission:reports.view')
                ->name('general-ledger');
            Route::get('/income-statement/print', [FinancialReportController::class, 'printIncomeStatement'])
                ->middleware('company.permission:reports.view')
                ->name('income-statement.print');
            Route::get('/income-statement', [FinancialReportController::class, 'incomeStatement'])
                ->middleware('company.permission:reports.view')
                ->name('income-statement');
            Route::get('/balance-sheet/print', [FinancialReportController::class, 'printBalanceSheet'])
                ->middleware('company.permission:reports.view')
                ->name('balance-sheet.print');
            Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet'])
                ->middleware('company.permission:reports.view')
                ->name('balance-sheet');
            Route::get('/performance-trends', [FinancialReportController::class, 'performanceTrends'])
                ->middleware('company.permission:reports.view')
                ->name('performance-trends');
        });

        Route::prefix('users')->name('users.')->group(function (): void {
            Route::get('/', [UserController::class, 'index'])
                ->middleware('company.permission:users.view')
                ->name('index');
            Route::get('/create', [UserController::class, 'create'])
                ->middleware('company.permission:users.create')
                ->name('create');
            Route::post('/', [UserController::class, 'store'])
                ->middleware('company.permission:users.create')
                ->name('store');
            Route::get('/{user}', [UserController::class, 'show'])
                ->middleware('company.permission:users.view')
                ->name('show');
            Route::get('/{user}/edit', [UserController::class, 'edit'])
                ->middleware('company.permission:users.update')
                ->name('edit');
            Route::put('/{user}', [UserController::class, 'update'])
                ->middleware('company.permission:users.update')
                ->name('update');
            Route::patch('/{user}/activate', [UserController::class, 'activate'])
                ->middleware('company.permission:users.deactivate')
                ->name('activate');
            Route::patch('/{user}/deactivate', [UserController::class, 'deactivate'])
                ->middleware('company.permission:users.deactivate')
                ->name('deactivate');
            Route::post('/{user}/memberships', [UserController::class, 'addMembership'])
                ->middleware('company.permission:users.manage_memberships')
                ->name('memberships.add');
            Route::delete('/{user}/memberships/{company}', [UserController::class, 'removeMembership'])
                ->middleware('company.permission:users.manage_memberships')
                ->name('memberships.remove');
            Route::patch('/{user}/role', [UserController::class, 'assignRole'])
                ->middleware('company.permission:roles.assign')
                ->name('role.assign');
        });

        Route::prefix('roles')->name('roles.')->group(function (): void {
            Route::get('/', [RoleController::class, 'index'])
                ->middleware('company.permission:roles.view')
                ->name('index');
            Route::get('/create', [RoleController::class, 'create'])
                ->middleware('company.permission:roles.create')
                ->name('create');
            Route::post('/', [RoleController::class, 'store'])
                ->middleware('company.permission:roles.create')
                ->name('store');
            Route::get('/{role}', [RoleController::class, 'show'])
                ->middleware('company.permission:roles.view')
                ->name('show');
            Route::get('/{role}/edit', [RoleController::class, 'edit'])
                ->middleware('company.permission:roles.update')
                ->name('edit');
            Route::put('/{role}', [RoleController::class, 'update'])
                ->middleware('company.permission:roles.update')
                ->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])
                ->middleware('company.permission:roles.delete')
                ->name('destroy');
        });
    });
});
