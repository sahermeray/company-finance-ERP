<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SupplierReturn extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'original_purchase_id',
        'party_id',
        'created_by',
        'posted_by',
        'payable_account_id',
        'inventory_asset_account_id',
        'purchase_return_variance_account_id',
        'accounting_journal_id',
        'return_number',
        'return_date',
        'status',
        'currency',
        'exchange_rate',
        'base_currency',
        'total',
        'base_total',
        'inventory_cost_total',
        'variance_total',
        'reason',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'exchange_rate' => 'decimal:10',
            'total' => 'decimal:4',
            'base_total' => 'decimal:4',
            'inventory_cost_total' => 'decimal:4',
            'variance_total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $return): void {
            if ($return->getOriginal('status') === 'posted' || $return->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages([
                    'supplier_return' => 'Posted supplier returns are immutable; use a compensating transaction.',
                ]);
            }
        });

        static::deleting(function (self $return): void {
            if ($return->status === 'posted' || $return->posted_at !== null) {
                throw ValidationException::withMessages([
                    'supplier_return' => 'Posted supplier returns cannot be deleted; use a compensating transaction.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function originalPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'original_purchase_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }
}
