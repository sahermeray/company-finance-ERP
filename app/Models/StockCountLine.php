<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class StockCountLine extends Model
{
    use TenantScoped;

    protected $fillable = [
        'stock_count_id',
        'company_id',
        'product_id',
        'warehouse_id',
        'system_quantity',
        'counted_quantity',
        'variance_quantity',
        'approved_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'approved_unit_cost' => 'decimal:10',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->stockCount()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'stock_count_line' => 'Lines belonging to a posted stock count are immutable.',
                ]);
            }
        });

        static::deleting(function (self $line): void {
            if ($line->stockCount()->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages([
                    'stock_count_line' => 'Lines belonging to a posted stock count cannot be deleted.',
                ]);
            }
        });
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
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

    public function adjustment(): HasOne
    {
        return $this->hasOne(InventoryAdjustment::class);
    }
}
