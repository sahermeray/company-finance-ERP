<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InventoryAdjustment extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'created_by',
        'approved_by',
        'posted_by',
        'inventory_asset_account_id',
        'offset_account_id',
        'stock_count_line_id',
        'movement_id',
        'accounting_journal_id',
        'adjustment_type',
        'quantity',
        'base_unit_cost',
        'base_cost_amount',
        'occurred_at',
        'reason',
        'status',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_unit_cost' => 'decimal:10',
            'base_cost_amount' => 'decimal:4',
            'occurred_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            if ($adjustment->getOriginal('status') === 'posted' || $adjustment->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages([
                    'inventory_adjustment' => 'Posted inventory adjustments are immutable; use a compensating adjustment.',
                ]);
            }
        });

        static::deleting(function (self $adjustment): void {
            if ($adjustment->status === 'posted' || $adjustment->posted_at !== null) {
                throw ValidationException::withMessages([
                    'inventory_adjustment' => 'Posted inventory adjustments cannot be deleted; use a compensating adjustment.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function inventoryAssetAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'inventory_asset_account_id');
    }

    public function offsetAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'offset_account_id');
    }

    public function stockCountLine(): BelongsTo
    {
        return $this->belongsTo(StockCountLine::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }
}
