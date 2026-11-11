<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitOfMeasure extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'symbol',
        'decimal_places',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function revenueItems(): HasMany
    {
        return $this->hasMany(RevenueItem::class);
    }
}
