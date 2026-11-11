<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class Revenue extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'party_id',
        'project_id',
        'created_by',
        'revenue_number',
        'revenue_date',
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
            'revenue_date' => 'date',
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

    public static function booted(): void
    {
        static::updating(function (self $revenue): void {
            if ($revenue->getOriginal('status') === 'posted') {
                $dirty = array_keys($revenue->getDirty());
                $allowed = ['status'];          // only status column is ever mutated after posting (e.g. reversal)

                if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
                    throw ValidationException::withMessages([
                        'revenue' => 'Posted revenue documents are immutable. Create a credit note instead.',
                    ]);
                }
            }
        });

        static::deleting(function (self $revenue): void {
            if ($revenue->status === 'posted') {
                throw ValidationException::withMessages([
                    'revenue' => 'Posted revenue documents cannot be deleted. Create a credit note instead.',
                ]);
            }
        });
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
        return $this->hasMany(RevenueItem::class);
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
