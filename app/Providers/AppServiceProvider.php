<?php

namespace App\Providers;

use App\Models\AccountingJournal;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Policies\CompanyOwnedPolicy;
use App\Policies\CompanyPolicy;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CompanyContextService::class);
        $this->app->singleton(AccountingService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(PurchaseService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);

        foreach ([
            AccountingJournal::class,
            Attachment::class,
            AuditLog::class,
            ExchangeRate::class,
            Expense::class,
            FinancialAccount::class,
            JournalLine::class,
            Party::class,
            PartyType::class,
            Payment::class,
            Product::class,
            ProductCategory::class,
            Project::class,
            Purchase::class,
            PurchaseItem::class,
            Revenue::class,
            RevenueItem::class,
            StockMovement::class,
            UnitOfMeasure::class,
            Warehouse::class,
        ] as $model) {
            Gate::policy($model, CompanyOwnedPolicy::class);
        }
    }
}
