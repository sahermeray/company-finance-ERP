<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class InventoryCutoverLine extends Model
{
    use TenantScoped;

    protected $fillable = [
        'inventory_cutover_id',
        'company_id',
        'product_id',
        'warehouse_id',
        'opening_quantity',
        'opening_value',
        'base_unit_cost',
        'valuation_reference',
    ];

    protected function casts(): array
    {
        return [
            'opening_quantity' => 'decimal:4',
            'opening_value' => 'decimal:4',
            'base_unit_cost' => 'decimal:10',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            $cutoverIsPosted = InventoryCutover::withoutGlobalScope(CompanyScope::class)
                ->whereIn('id', array_unique([
                    $line->getOriginal('inventory_cutover_id'),
                    $line->inventory_cutover_id,
                ]))
                ->where('status', 'posted')
                ->exists();

            if ($cutoverIsPosted) {
                throw ValidationException::withMessages([
                    'inventory_cutover_line' => 'Opening balances for a posted cutover are immutable.',
                ]);
            }
        });

        static::deleting(function (self $line): void {
            $cutoverIsPosted = InventoryCutover::withoutGlobalScope(CompanyScope::class)
                ->whereKey($line->inventory_cutover_id)
                ->where('status', 'posted')
                ->exists();

            if ($cutoverIsPosted) {
                throw ValidationException::withMessages([
                    'inventory_cutover_line' => 'Opening balances for a posted cutover cannot be deleted.',
                ]);
            }
        });
    }

    public function cutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class, 'inventory_cutover_id');
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

    public function openingMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'inventory_cutover_line_id');
    }
}
