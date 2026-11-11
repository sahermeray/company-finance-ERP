<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory, TenantScoped;

    protected $fillable = [
        'company_id',
        'currency',
        'base_currency',
        'rate',
        'effective_at',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'effective_at' => 'datetime',
        ];
    }

    public function includesGlobalTenantRecords(): bool
    {
        return true;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
