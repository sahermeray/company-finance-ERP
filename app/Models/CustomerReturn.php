<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CustomerReturn extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'original_revenue_id',
        'party_id',
        'created_by',
        'posted_by',
        'refund_account_id',
        'revenue_account_id',
        'inventory_asset_account_id',
        'cogs_account_id',
        'accounting_journal_id',
        'return_number',
        'return_date',
        'status',
        'currency',
        'exchange_rate',
        'base_currency',
        'subtotal',
        'tax_total',
        'total',
        'base_subtotal',
        'base_tax_total',
        'base_total',
        'reason',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'exchange_rate' => 'decimal:10',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'base_subtotal' => 'decimal:4',
            'base_tax_total' => 'decimal:4',
            'base_total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $return): void {
            if ($return->getOriginal('status') === 'posted' || $return->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages([
                    'customer_return' => 'Posted customer returns are immutable; use a compensating transaction.',
                ]);
            }
        });

        static::deleting(function (self $return): void {
            if ($return->status === 'posted' || $return->posted_at !== null) {
                throw ValidationException::withMessages([
                    'customer_return' => 'Posted customer returns cannot be deleted; use a compensating transaction.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function originalRevenue(): BelongsTo
    {
        return $this->belongsTo(Revenue::class, 'original_revenue_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerReturnLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }
}
