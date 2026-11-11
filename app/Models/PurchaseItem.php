<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseItem extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'purchase';
    }

    protected $fillable = [
        'purchase_id',
        'product_id',
        'warehouse_id',
        'expense_account_id',
        'unit_of_measure_id',
        'line_number',
        'description',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'expense_account_id');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(PurchaseTaxLine::class);
    }

    public function landedCostAllocations(): HasMany
    {
        return $this->hasMany(PurchaseLandedCostAllocation::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->assertPurchaseIsDraft();
        });

        static::updating(function (self $item): void {
            $item->assertPurchaseIsDraft();
        });

        static::deleting(function (self $item): void {
            $item->assertPurchaseIsDraft();
        });
    }

    private function assertPurchaseIsDraft(): void
    {
        $status = Purchase::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->purchase_id)
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'purchase_item' => 'Items for a posted purchase are immutable.',
            ]);
        }
    }
}
