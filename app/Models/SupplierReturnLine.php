<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SupplierReturnLine extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'supplierReturn';
    }

    protected $fillable = [
        'supplier_return_id',
        'purchase_item_id',
        'product_id',
        'warehouse_id',
        'expense_account_id',
        'stock_movement_id',
        'quantity',
        'native_amount',
        'base_amount',
        'inventory_cost_amount',
        'variance_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'native_amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'inventory_cost_amount' => 'decimal:4',
            'variance_amount' => 'decimal:4',
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

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SupplierReturnLineTax::class);
    }

    private function assertReturnIsDraft(): void
    {
        $status = SupplierReturn::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->supplier_return_id)
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'supplier_return_line' => 'Lines on a posted supplier return are immutable.',
            ]);
        }
    }
}
