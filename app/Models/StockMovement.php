<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class StockMovement extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            throw ValidationException::withMessages([
                'stock_movement' => 'Historical stock movements cannot be edited.',
            ]);
        });

        static::deleting(function (self $movement): void {
            throw ValidationException::withMessages([
                'stock_movement' => 'Historical stock movements cannot be deleted.',
            ]);
        });
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'project_id',
        'party_id',
        'created_by',
        'sourceable_type',
        'sourceable_id',
        'revenue_item_id',
        'purchase_item_id',
        'transfer_group',
        'movement_type',
        'direction',
        'quantity',
        'base_cost_amount',
        'base_unit_cost',
        'cost_source_stock_movement_id',
        'inventory_cutover_line_id',
        'occurred_at',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_cost_amount' => 'decimal:4',
            'base_unit_cost' => 'decimal:10',
            'occurred_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function costSourceStockMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cost_source_stock_movement_id');
    }

    public function costDerivedStockMovements(): HasMany
    {
        return $this->hasMany(self::class, 'cost_source_stock_movement_id');
    }

    public function inventoryCutoverLine(): BelongsTo
    {
        return $this->belongsTo(InventoryCutoverLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function revenueItem(): BelongsTo
    {
        return $this->belongsTo(RevenueItem::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}
