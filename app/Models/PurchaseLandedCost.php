<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseLandedCost extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'purchase_id',
        'cost_type',
        'amount',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'expense_account_id',
        'payable_account_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $landedCost): void {
            $landedCost->assertPurchaseIsDraft();
        });

        static::updating(function (self $landedCost): void {
            $landedCost->assertPurchaseIsDraft();
        });

        static::deleting(function (self $landedCost): void {
            $landedCost->assertPurchaseIsDraft();
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'expense_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'payable_account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseLandedCostAllocation::class);
    }

    private function assertPurchaseIsDraft(): void
    {
        $status = Purchase::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->purchase_id)
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'purchase_landed_cost' => 'Landed costs for a posted purchase are immutable.',
            ]);
        }
    }
}
