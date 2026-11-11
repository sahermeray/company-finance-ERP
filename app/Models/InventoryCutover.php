<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class InventoryCutover extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'created_by',
        'inventory_asset_account_id',
        'opening_equity_account_id',
        'accounting_journal_id',
        'effective_at',
        'movement_id_boundary',
        'status',
        'posted_at',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'posted_at' => 'datetime',
            'movement_id_boundary' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cutover): void {
            if ($cutover->getOriginal('status') === 'posted') {
                throw ValidationException::withMessages([
                    'inventory_cutover' => 'A posted inventory cutover is immutable.',
                ]);
            }
        });

        static::deleting(function (self $cutover): void {
            if ($cutover->status === 'posted' || $cutover->posted_at !== null) {
                throw ValidationException::withMessages([
                    'inventory_cutover' => 'A posted inventory cutover cannot be deleted.',
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

    public function inventoryAssetAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'inventory_asset_account_id');
    }

    public function openingEquityAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'opening_equity_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCutoverLine::class);
    }
}
