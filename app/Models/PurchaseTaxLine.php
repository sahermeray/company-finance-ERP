<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseTaxLine extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'purchase_id',
        'purchase_item_id',
        'treatment',
        'amount',
        'base_amount',
        'tax_asset_account_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->assertPurchaseIsDraft();
        });

        static::updating(function (self $line): void {
            $line->assertPurchaseIsDraft();
        });

        static::deleting(function (self $line): void {
            $line->assertPurchaseIsDraft();
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function taxAssetAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'tax_asset_account_id');
    }

    private function assertPurchaseIsDraft(): void
    {
        $status = Purchase::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->purchase_id)
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'purchase_tax_line' => 'Tax classification for a posted purchase is immutable.',
            ]);
        }
    }
}
