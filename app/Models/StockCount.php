<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class StockCount extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'created_by',
        'approved_by',
        'posted_by',
        'inventory_asset_account_id',
        'positive_variance_account_id',
        'negative_variance_account_id',
        'counted_at',
        'description',
        'status',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $count): void {
            if ($count->getOriginal('status') === 'posted' || $count->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages([
                    'stock_count' => 'Posted stock counts are immutable; create a new count to correct them.',
                ]);
            }
        });

        static::deleting(function (self $count): void {
            if ($count->status === 'posted' || $count->posted_at !== null) {
                throw ValidationException::withMessages([
                    'stock_count' => 'Posted stock counts cannot be deleted.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function positiveVarianceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'positive_variance_account_id');
    }

    public function negativeVarianceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'negative_variance_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
