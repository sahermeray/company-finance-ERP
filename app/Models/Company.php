<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'base_currency' => 'SYP',
    ];

    protected $fillable = [
        'name',
        'legal_name',
        'tax_identifier',
        'email',
        'phone',
        'address',
        'country_code',
        'base_currency',
        'exchange_gain_account_id',
        'exchange_loss_account_id',
        'timezone',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function partyTypes(): HasMany
    {
        return $this->hasMany(PartyType::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function unitsOfMeasure(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    public function exchangeGainAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'exchange_gain_account_id');
    }

    public function exchangeLossAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'exchange_loss_account_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventoryCutovers(): HasMany
    {
        return $this->hasMany(InventoryCutover::class);
    }

    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function supplierReturns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    public function accountingJournals(): HasMany
    {
        return $this->hasMany(AccountingJournal::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
