<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseLandedCostAllocation extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'purchase_landed_cost_id',
        'purchase_item_id',
        'allocated_amount',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            $allocation->assertPurchaseIsDraft();
        });

        static::updating(function (self $allocation): void {
            $allocation->assertPurchaseIsDraft();
        });

        static::deleting(function (self $allocation): void {
            $allocation->assertPurchaseIsDraft();
        });
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(PurchaseLandedCost::class, 'purchase_landed_cost_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    private function assertPurchaseIsDraft(): void
    {
        $purchaseId = PurchaseLandedCost::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->purchase_landed_cost_id)
            ->value('purchase_id');
        $purchaseStatus = $purchaseId === null
            ? null
            : Purchase::withoutGlobalScope(CompanyScope::class)
                ->whereKey($purchaseId)
                ->value('status');

        if ($purchaseStatus === 'posted') {
            throw ValidationException::withMessages([
                'purchase_landed_cost_allocation' => 'Landed-cost allocations for a posted purchase are immutable.',
            ]);
        }
    }
}
