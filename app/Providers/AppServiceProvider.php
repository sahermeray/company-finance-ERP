<?php

namespace App\Providers;

use App\Models\AccountingJournal;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
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
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\CompanyOwnedPolicy;
use App\Policies\CompanyPolicy;
use App\Services\AccountingService;
use App\Services\CompanyAuthorizationService;
use App\Services\CompanyContextService;
use App\Services\CompanySettingsService;
use App\Services\FinancialReportingService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
        $this->app->singleton(FinancialReportingService::class);
        $this->app->singleton(CompanySettingsService::class);
        $this->app->singleton(CompanyAuthorizationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        view()->composer(['inventory.*', 'commercial.*', 'cash-bank.*', 'accounting.*', 'reports.*', 'settings.*', 'users.*', 'roles.*'], function (View $view): void {
            $user = Auth::user();
            if (! $user instanceof User) {
                return;
            }

            $companyContext = app(CompanyContextService::class);
            $activeCompany  = $companyContext->requireCurrent();

            // Build the set of permission keys for the current user in this company.
            // Owners receive every key; role-less users receive an empty array.
            $currentUserPermissions = $this->resolveUserPermissions($user, $activeCompany);

            $view->with('activeCompany', $activeCompany)
                ->with('companies', $user->companies()
                    ->orderBy('name')
                    ->orderBy('companies.id')
                    ->get(['companies.id', 'companies.name', 'companies.base_currency']))
                ->with('currentUserPermissions', $currentUserPermissions)
                ->with('currentUserIsOwner', app(CompanyAuthorizationService::class)->userIsOwner($user, $activeCompany));
        });

        RateLimiter::for('login', function (Request $request): array {
            $email = Str::transliterate(Str::lower($request->string('email')->toString()));

            return [
                Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip|'.$request->ip()),
            ];
        });

        Gate::policy(Company::class, CompanyPolicy::class);

        foreach ([
            AccountingJournal::class,
            Attachment::class,
            AuditLog::class,
            ExchangeRate::class,
            Expense::class,
            FinancialAccount::class,
            InventoryAdjustment::class,
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
            StockCount::class,
            StockCountLine::class,
            UnitOfMeasure::class,
            Warehouse::class,
        ] as $model) {
            Gate::policy($model, CompanyOwnedPolicy::class);
        }
    }

    /**
     * Resolve the permission key set for a user in a company.
     * Returns all keys for owners, the role's keys for role holders,
     * or an empty array for users with no role.
     *
     * @return string[]
     */
    private function resolveUserPermissions(User $user, Company $company): array
    {
        $authService = app(CompanyAuthorizationService::class);

        if ($authService->userIsOwner($user, $company)) {
            return Role::with('permissions')
                ->where('company_id', $company->getKey())
                ->where('slug', 'owner')
                ->first()
                ?->permissions
                ->pluck('key')
                ->all()
                ?? [];
        }

        $role = $user->roleInCompany($company);

        return $role !== null ? $role->permissions->pluck('key')->all() : [];
    }
}
