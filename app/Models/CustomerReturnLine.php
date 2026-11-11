<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CustomerReturnLine extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'customerReturn';
    }

    protected $fillable = [
        'customer_return_id',
        'revenue_item_id',
        'product_id',
        'warehouse_id',
        'stock_movement_id',
        'quantity',
        'native_amount',
        'base_amount',
        'inventory_cost_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'native_amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'inventory_cost_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->assertReturnIsDraft();
        });

        static::updating(function (self $line): void {
            $line->assertReturnIsDraft();
        });

        static::deleting(function (self $line): void {
            $line->assertReturnIsDraft();
        });
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function revenueItem(): BelongsTo
    {
        return $this->belongsTo(RevenueItem::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    private function assertReturnIsDraft(): void
    {
        $status = CustomerReturn::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->customer_return_id)
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'customer_return_line' => 'Lines on a posted customer return are immutable.',
            ]);
        }
    }
}
