<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CompanyAccountMapping extends Model
{
    use TenantScoped;

    public const CUSTOMER_RECEIVABLES = 'customer_receivables';

    public const SUPPLIER_PAYABLES = 'supplier_payables';

    public const INVENTORY_ASSET = 'inventory_asset';

    public const PURPOSES = [
        self::CUSTOMER_RECEIVABLES,
        self::SUPPLIER_PAYABLES,
        self::INVENTORY_ASSET,
    ];

    protected $fillable = [
        'company_id',
        'purpose',
        'financial_account_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $mapping->validateAccount();

            if (
                $mapping->exists
                && ($mapping->isDirty('company_id') || $mapping->isDirty('purpose'))
            ) {
                throw ValidationException::withMessages([
                    'mapping' => 'A company account mapping cannot change company or purpose.',
                ]);
            }

            if (
                $mapping->exists
                && $mapping->isDirty('financial_account_id')
                && $mapping->hasPostedOperationalHistory()
            ) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A control account mapping cannot be replaced after related transactions have been posted.',
                ]);
            }
        });

        static::deleting(function (self $mapping): void {
            if ($mapping->hasPostedOperationalHistory()) {
                throw ValidationException::withMessages([
                    'mapping' => 'A control account mapping cannot be removed after related transactions have been posted.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    private function validateAccount(): void
    {
        if (! in_array($this->purpose, self::PURPOSES, true)) {
            throw ValidationException::withMessages([
                'purpose' => 'The control account mapping purpose is invalid.',
            ]);
        }

        $company = Company::withoutGlobalScope(CompanyScope::class)->find($this->company_id);
        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($this->financial_account_id);

        if ($company === null) {
            throw ValidationException::withMessages([
                'company_id' => 'The control account mapping company must exist.',
            ]);
        }

        if ($account === null || (int) $account->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The control account must belong to the mapping company.',
            ]);
        }

        $expectedType = $this->purpose === self::SUPPLIER_PAYABLES ? 'liability' : 'asset';
        if (strtolower((string) $account->account_type) !== $expectedType) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The control account must have type '.$expectedType.'.',
            ]);
        }

        if (strtoupper((string) $account->currency) !== strtoupper((string) $company->base_currency)) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The control account must use the company base currency.',
            ]);
        }
    }

    private function hasPostedOperationalHistory(): bool
    {
        $sourceTypes = match ($this->purpose) {
            self::CUSTOMER_RECEIVABLES => [
                Revenue::class,
                CustomerReturn::class,
                Payment::class,
            ],
            self::SUPPLIER_PAYABLES => [
                Purchase::class,
                SupplierReturn::class,
                Expense::class,
                Payment::class,
            ],
            self::INVENTORY_ASSET => [
                Purchase::class,
                Revenue::class,
                CustomerReturn::class,
                SupplierReturn::class,
                InventoryAdjustment::class,
                InventoryCutover::class,
            ],
        };

        if (AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->company_id)
            ->where('status', 'posted')
            ->whereIn('sourceable_type', $sourceTypes)
            ->exists()) {
            return true;
        }

        return match ($this->purpose) {
            self::CUSTOMER_RECEIVABLES => Revenue::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || CustomerReturn::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || Payment::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->whereIn('status', ['posted', 'reversed'])->exists(),
            self::SUPPLIER_PAYABLES => Purchase::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || SupplierReturn::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || Expense::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->whereIn('status', ['posted', 'reversed'])->exists()
                || Payment::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->whereIn('status', ['posted', 'reversed'])->exists(),
            self::INVENTORY_ASSET => Purchase::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || Revenue::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || CustomerReturn::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || SupplierReturn::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || InventoryAdjustment::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists()
                || InventoryCutover::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)->where('status', 'posted')->exists(),
        };
    }
}
