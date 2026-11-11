<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SupplierReturnLineTax extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'supplier_return_line_id',
        'purchase_tax_line_id',
        'native_amount',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'native_amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $tax): void {
            $tax->assertReturnIsDraft();
        });

        static::updating(function (self $tax): void {
            $tax->assertReturnIsDraft();
        });

        static::deleting(function (self $tax): void {
            $tax->assertReturnIsDraft();
        });
    }

    public function supplierReturnLine(): BelongsTo
    {
        return $this->belongsTo(SupplierReturnLine::class);
    }

    public function purchaseTaxLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseTaxLine::class);
    }

    private function assertReturnIsDraft(): void
    {
        $status = SupplierReturn::withoutGlobalScope(CompanyScope::class)
            ->whereKey(
                SupplierReturnLine::withoutGlobalScope(CompanyScope::class)
                    ->whereKey($this->supplier_return_line_id)
                    ->value('supplier_return_id')
            )
            ->value('status');

        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'supplier_return_tax' => 'Taxes on a posted supplier return are immutable.',
            ]);
        }
    }
}
