<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class Purchase extends Model
{
    use TenantScoped;

    public static function booted(): void
    {
        static::updating(function (self $purchase): void {
            // This guard fires only when the purchase was ALREADY posted before this save.
            // We intentionally check getOriginal('status') (the persisted value) rather
            // than the in-memory value so that the initial draft→posted transition —
            // which sets status in the same save() call inside PurchaseService::postPurchase()
            // — is NOT gated here. At the time of that save, getOriginal('status') is
            // still 'draft', so the transition proceeds freely.
            //
            // Posted purchases and their status are immutable because linked
            // accounting and stock ledgers depend on the original document.
            if ($purchase->getOriginal('status') === 'posted') {
                if ($purchase->isDirty()) {
                    throw ValidationException::withMessages([
                        'purchase' => 'Posted purchases are immutable. Create a reversal or a new document instead of editing historical financial data.',
                    ]);
                }
            }
        });

        static::deleting(function (self $purchase): void {
            if ($purchase->status === 'posted') {
                throw ValidationException::withMessages([
                    'purchase' => 'Posted purchases cannot be deleted. Create a reversal instead.',
                ]);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'party_id',
        'project_id',
        'created_by',
        'purchase_number',
        'purchase_date',
        'due_date',
        'status',
        'currency',
        'exchange_rate',
        'base_currency',
        'subtotal',
        'tax_total',
        'total',
        'base_subtotal',
        'base_tax_total',
        'base_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_subtotal' => 'decimal:4',
            'base_tax_total' => 'decimal:4',
            'base_total' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(PurchaseTaxLine::class);
    }

    public function landedCosts(): HasMany
    {
        return $this->hasMany(PurchaseLandedCost::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'sourceable');
    }

    public function accountingJournals(): MorphMany
    {
        return $this->morphMany(AccountingJournal::class, 'sourceable');
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}
