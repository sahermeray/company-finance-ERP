<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use TenantScoped;

    private bool $reversalTransitionAuthorized = false;

    protected $attributes = [
        'status' => 'posted',
    ];

    protected $fillable = [
        'company_id',
        'party_id',
        'project_id',
        'financial_account_id',
        'created_by',
        'payable_type',
        'payable_id',
        'payment_number',
        'direction',
        'method',
        'paid_at',
        'amount',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'applied_amount',
        'applied_currency',
        'applied_exchange_rate',
        'applied_base_amount',
        'reference',
        'notes',
        'status',
        'posted_at',
        'reversal_of_payment_id',
        'reversed_by_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'posted_at' => 'datetime',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'applied_exchange_rate' => 'decimal:10',
            'applied_base_amount' => 'decimal:4',
        ];
    }

    public static function booted(): void
    {
        static::updating(function (self $payment): void {
            $originalStatus = $payment->getOriginal('status');

            if (
                $originalStatus !== 'posted'
                && $originalStatus !== 'reversed'
                && $payment->isDirty('status')
                && in_array($payment->status, ['posted', 'reversed'], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Payment status changes to posted or reversed must use the payment workflow.',
                ]);
            }

            if (in_array($originalStatus, ['posted', 'reversed'], true)) {
                $dirty = array_keys($payment->getDirty());

                if (
                    $originalStatus !== 'posted'
                    || ! $payment->reversalTransitionAuthorized
                    || $payment->status !== 'reversed'
                    || array_diff($dirty, ['status', 'reversed_by_payment_id']) !== []
                    || $payment->reversed_by_payment_id === null
                ) {
                    throw ValidationException::withMessages([
                        'payment' => 'Posted payments are immutable. Create a reversal record instead of editing historical financial data.',
                    ]);
                }

                $reversal = self::withoutGlobalScope(CompanyScope::class)
                    ->find($payment->reversed_by_payment_id);

                if (
                    $reversal === null
                    || $reversal->status !== 'posted'
                    || (int) $reversal->company_id !== (int) $payment->company_id
                    || (int) $reversal->reversal_of_payment_id !== (int) $payment->getKey()
                ) {
                    throw ValidationException::withMessages([
                        'payment' => 'The reversal payment must be posted, linked to this payment, and belong to the same company.',
                    ]);
                }
            }
        });

        static::deleting(function (self $payment): void {
            // Both posted and reversed payments are audit-trail records and must
            // not be deleted. 'reversed' payments are the originals that were
            // reversed — deleting them would orphan their reversal payment (which
            // holds a reversal_of_payment_id FK back to this row) and break the
            // reversal chain. 'posted' payments are immutable financial records.
            if (in_array($payment->status, ['posted', 'reversed'], true)) {
                throw ValidationException::withMessages([
                    'payment' => 'Posted and reversed payments cannot be deleted. Create a reversal record instead.',
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

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function accountingJournals(): MorphMany
    {
        return $this->morphMany(AccountingJournal::class, 'sourceable');
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_payment_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_payment_id');
    }

    public function markAsReversedBy(self $reversal): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payments can only be reversed inside a database transaction.');
        }

        if (
            $this->status !== 'posted'
            || $reversal->status !== 'posted'
            || (int) $reversal->company_id !== (int) $this->company_id
            || (int) $reversal->reversal_of_payment_id !== (int) $this->getKey()
        ) {
            throw ValidationException::withMessages([
                'payment' => 'The reversal payment must be posted, linked to this payment, and belong to the same company.',
            ]);
        }

        $this->reversalTransitionAuthorized = true;
        $this->status = 'reversed';
        $this->reversed_by_payment_id = $reversal->getKey();
    }
}
