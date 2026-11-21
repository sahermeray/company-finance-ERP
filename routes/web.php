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
    Route::get('/dashboard', DashboardController::class)->name('dashboard.index');
    Route::get('/companies/select', CompanySelectionController::class)->name('companies.select');
    Route::post('/company-context', [CompanyContextController::class, 'update'])
        ->name('company-context.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('inventory')->name('inventory.')->middleware('company.context')->group(function (): void {
        Route::get('/', [InventoryController::class, 'overview'])->name('overview');

        Route::resource('products', ProductController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);

        Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalog/categories', [CatalogController::class, 'storeCategory'])->name('catalog.categories.store');
        Route::put('/catalog/categories/{category}', [CatalogController::class, 'updateCategory'])->name('catalog.categories.update');
        Route::post('/catalog/units', [CatalogController::class, 'storeUnit'])->name('catalog.units.store');
        Route::put('/catalog/units/{unit}', [CatalogController::class, 'updateUnit'])->name('catalog.units.update');

        Route::resource('warehouses', WarehouseController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);

        Route::get('/counts', [InventoryController::class, 'counts'])->name('counts.index');
        Route::get('/counts/create', [InventoryController::class, 'createCount'])->name('counts.create');
        Route::post('/counts', [InventoryController::class, 'storeCount'])->name('counts.store');
        Route::get('/counts/{count}', [InventoryController::class, 'showCount'])->name('counts.show');
        Route::post('/counts/{count}/approve', [InventoryController::class, 'approveCount'])->name('counts.approve');
        Route::post('/counts/{count}/post', [InventoryController::class, 'postCount'])->name('counts.post');

        Route::get('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments.index');
        Route::get('/adjustments/create', [InventoryController::class, 'createAdjustment'])->name('adjustments.create');
        Route::post('/adjustments', [InventoryController::class, 'storeAdjustment'])->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [InventoryController::class, 'showAdjustment'])->name('adjustments.show');
        Route::post('/adjustments/{adjustment}/approve', [InventoryController::class, 'approveAdjustment'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/post', [InventoryController::class, 'postAdjustment'])->name('adjustments.post');
    });

    Route::middleware('company.context')->group(function (): void {
        Route::get('/customers', [PartyController::class, 'customers'])->name('customers.index');
        Route::get('/customers/create', [PartyController::class, 'createCustomer'])->name('customers.create');
        Route::post('/customers', [PartyController::class, 'storeCustomer'])->name('customers.store');
        Route::get('/customers/{party}/edit', [PartyController::class, 'editCustomer'])->name('customers.edit');
        Route::put('/customers/{party}', [PartyController::class, 'updateCustomer'])->name('customers.update');
        Route::get('/customers/{party}/statement/print', [PartyController::class, 'printCustomerStatement'])->name('customers.statement.print');
        Route::get('/customers/{party}/statement', [PartyController::class, 'customerStatement'])->name('customers.statement');
        Route::get('/customers/{party}', [PartyController::class, 'showCustomer'])->name('customers.show');

        Route::get('/suppliers', [PartyController::class, 'suppliers'])->name('suppliers.index');
        Route::get('/suppliers/create', [PartyController::class, 'createSupplier'])->name('suppliers.create');
        Route::post('/suppliers', [PartyController::class, 'storeSupplier'])->name('suppliers.store');
        Route::get('/suppliers/{party}/edit', [PartyController::class, 'editSupplier'])->name('suppliers.edit');
        Route::put('/suppliers/{party}', [PartyController::class, 'updateSupplier'])->name('suppliers.update');
        Route::get('/suppliers/{party}/statement/print', [PartyController::class, 'printSupplierStatement'])->name('suppliers.statement.print');
        Route::get('/suppliers/{party}/statement', [PartyController::class, 'supplierStatement'])->name('suppliers.statement');
        Route::get('/suppliers/{party}', [PartyController::class, 'showSupplier'])->name('suppliers.show');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');
        Route::get('/payments/{payment}/print', [PaymentController::class, 'print'])->name('payments.print');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

        Route::resource('purchases', PurchaseController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('/purchases/{purchase}/post', [PurchaseController::class, 'post'])->name('purchases.post');
        Route::get('/purchases/{purchase}/print', [PurchaseController::class, 'print'])->name('purchases.print');

        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        Route::post('/expenses/{expense}/post', [ExpenseController::class, 'post'])->name('expenses.post');
        Route::post('/expenses/{expense}/settle', [ExpenseController::class, 'settle'])->name('expenses.settle');
        Route::post('/expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->name('expenses.reverse');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::get('/expenses/{expense}/print', [ExpenseController::class, 'print'])->name('expenses.print');

        Route::get('/sales/availability', [SalesController::class, 'availability'])->name('sales.availability');
        Route::resource('sales', SalesController::class)
            ->only(['index', 'create', 'store', 'show'])
            ->parameters(['sales' => 'revenue']);
        Route::post('/sales/{revenue}/post', [SalesController::class, 'post'])->name('sales.post');
        Route::get('/sales/{revenue}/print', [SalesController::class, 'print'])->name('sales.print');

        Route::get('/cash-bank', [CashBankController::class, 'index'])->name('cash-bank.index');
        Route::get('/cash-bank/create', [CashBankController::class, 'create'])->name('cash-bank.create');
        Route::post('/cash-bank', [CashBankController::class, 'store'])->name('cash-bank.store');
        Route::get('/cash-bank/{cashBank}/print', [CashBankController::class, 'print'])->name('cash-bank.print');
        Route::get('/cash-bank/{cashBank}/edit', [CashBankController::class, 'edit'])->name('cash-bank.edit');
        Route::put('/cash-bank/{cashBank}', [CashBankController::class, 'update'])->name('cash-bank.update');
        Route::get('/cash-bank/{cashBank}', [CashBankController::class, 'show'])->name('cash-bank.show');

        Route::prefix('accounting')->name('accounting.')->group(function (): void {
            Route::get('/journals', [AccountingJournalController::class, 'index'])->name('journals.index');
            Route::get('/journals/{journal}', [AccountingJournalController::class, 'show'])->name('journals.show');
            Route::get('/mappings', [AccountingJournalController::class, 'mappings'])->name('mappings');
            Route::put('/mappings/{purpose}', [AccountingJournalController::class, 'updateMapping'])->name('mappings.update');
        });

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/', [CompanySettingsController::class, 'index'])->name('index');
            Route::get('/general', [CompanySettingsController::class, 'general'])->name('general');
            Route::put('/general', [CompanySettingsController::class, 'updateGeneral'])->name('general.update');
            Route::get('/financial', [CompanySettingsController::class, 'financial'])->name('financial');
            Route::put('/financial', [CompanySettingsController::class, 'updateFinancial'])->name('financial.update');
            Route::get('/exchange-rates', [CompanySettingsController::class, 'exchangeRates'])->name('exchange-rates');
            Route::post('/exchange-rates', [CompanySettingsController::class, 'storeExchangeRate'])->name('exchange-rates.store');
            Route::get('/forex-accounts', [CompanySettingsController::class, 'forexAccounts'])->name('forex-accounts');
            Route::put('/forex-accounts/gain', [CompanySettingsController::class, 'updateGainAccount'])->name('forex-accounts.gain');
            Route::put('/forex-accounts/loss', [CompanySettingsController::class, 'updateLossAccount'])->name('forex-accounts.loss');
            Route::get('/fiscal-year', [CompanySettingsController::class, 'fiscalYear'])->name('fiscal-year');
        });

        Route::prefix('reports')->name('reports.')->group(function (): void {
            Route::get('/', [FinancialReportController::class, 'index'])->name('index');
            Route::get('/trial-balance/print', [FinancialReportController::class, 'printTrialBalance'])->name('trial-balance.print');
            Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('trial-balance');
            Route::get('/general-ledger/print', [FinancialReportController::class, 'printGeneralLedger'])->name('general-ledger.print');
            Route::get('/general-ledger', [FinancialReportController::class, 'generalLedger'])->name('general-ledger');
            Route::get('/income-statement/print', [FinancialReportController::class, 'printIncomeStatement'])->name('income-statement.print');
            Route::get('/income-statement', [FinancialReportController::class, 'incomeStatement'])->name('income-statement');
            Route::get('/balance-sheet/print', [FinancialReportController::class, 'printBalanceSheet'])->name('balance-sheet.print');
            Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('balance-sheet');
            Route::get('/performance-trends', [FinancialReportController::class, 'performanceTrends'])->name('performance-trends');
        });

        Route::prefix('users')->name('users.')->group(function (): void {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/create', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::get('/{user}', [UserController::class, 'show'])->name('show');
            Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
            Route::put('/{user}', [UserController::class, 'update'])->name('update');
            Route::patch('/{user}/activate', [UserController::class, 'activate'])->name('activate');
            Route::patch('/{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
            Route::post('/{user}/memberships', [UserController::class, 'addMembership'])->name('memberships.add');
            Route::delete('/{user}/memberships/{company}', [UserController::class, 'removeMembership'])->name('memberships.remove');
        });
    });
});
