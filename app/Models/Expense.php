<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Expense extends Model
{
    use TenantScoped;

    private bool $workflowTransitionAuthorized = false;

    public static function booted(): void
    {
        static::creating(function (self $expense): void {
            if (($expense->status ?? 'draft') !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Expenses must be created as drafts.',
                ]);
            }
        });

        static::updating(function (self $expense): void {
            $originalStatus = $expense->getOriginal('status');
            $dirty = array_keys($expense->getDirty());
            $serviceFields = [
                'approved_by',
                'approved_at',
                'posted_by',
                'posted_at',
                'reversed_by',
                'reversed_at',
                'accounting_journal_id',
                'reversal_journal_id',
            ];

            if ($originalStatus === 'draft' && $expense->isDirty('status')) {
                if (
                    ! $expense->workflowTransitionAuthorized
                    || $expense->status !== 'approved'
                    || array_diff($dirty, ['status', 'approved_by', 'approved_at']) !== []
                ) {
                    $expense->failImmutableTransition();
                }
            } elseif ($originalStatus === 'approved' && $expense->isDirty('status')) {
                if (
                    ! $expense->workflowTransitionAuthorized
                    || $expense->status !== 'posted'
                    || array_diff($dirty, ['status', 'posted_by', 'posted_at', 'accounting_journal_id']) !== []
                ) {
                    $expense->failImmutableTransition();
                }
            } elseif ($originalStatus === 'posted' && $expense->isDirty('status')) {
                if (
                    ! $expense->workflowTransitionAuthorized
                    || $expense->status !== 'reversed'
                    || array_diff($dirty, ['status', 'reversed_by', 'reversed_at', 'reversal_journal_id']) !== []
                ) {
                    $expense->failImmutableTransition();
                }
            } elseif ($originalStatus !== 'draft' && $dirty !== []) {
                $expense->failImmutableTransition();
            }

            if (
                array_intersect($dirty, $serviceFields) !== []
                && ! $expense->workflowTransitionAuthorized
            ) {
                $expense->failImmutableTransition();
            }
        });

        static::updated(function (self $expense): void {
            $expense->workflowTransitionAuthorized = false;
        });

        static::deleting(function (self $expense): void {
            if ($expense->status !== 'draft') {
                throw ValidationException::withMessages([
                    'expense' => 'Approved and posted expenses cannot be deleted.',
                ]);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'party_id',
        'project_id',
        'financial_account_id',
        'payment_financial_account_id',
        'payable_account_id',
        'created_by',
        'expense_number',
        'expense_date',
        'status',
        'amount',
        'tax_total',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'description',
        'notes',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'accounting_journal_id',
        'reversal_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'amount' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_amount' => 'decimal:4',
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

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function paymentFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'payment_financial_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'payable_account_id');
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

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('line_number');
    }

    public function postedJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'reversal_journal_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function accountingJournals(): MorphMany
    {
        return $this->morphMany(AccountingJournal::class, 'sourceable');
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function markAsApprovedBy(?User $user): void
    {
        $this->assertWorkflowTransaction();

        if ($this->status !== 'draft') {
            $this->failImmutableTransition();
        }

        $this->workflowTransitionAuthorized = true;
        $this->status = 'approved';
        $this->approved_by = $user?->getKey();
        $this->approved_at = now();
    }

    public function markAsPostedBy(?User $user, AccountingJournal $journal): void
    {
        $this->assertWorkflowTransaction();

        if (
            $this->status !== 'approved'
            || $journal->status !== 'posted'
            || $journal->posted_at === null
            || (int) $journal->company_id !== (int) $this->company_id
            || $journal->sourceable_type !== self::class
            || (int) $journal->sourceable_id !== (int) $this->getKey()
            || $journal->reversal_of_journal_id !== null
        ) {
            $this->failImmutableTransition();
        }

        $this->workflowTransitionAuthorized = true;
        $this->status = 'posted';
        $this->posted_by = $user?->getKey();
        $this->posted_at = $journal->posted_at;
        $this->accounting_journal_id = $journal->getKey();
    }

    public function markAsReversedBy(?User $user, AccountingJournal $journal): void
    {
        $this->assertWorkflowTransaction();
        $originalJournal = $this->postedJournal()->first();

        if (
            $this->status !== 'posted'
            || $originalJournal === null
            || $journal->status !== 'posted'
            || (int) $journal->company_id !== (int) $this->company_id
            || (int) $journal->reversal_of_journal_id !== (int) $originalJournal->getKey()
            || $journal->sourceable_type !== self::class
            || (int) $journal->sourceable_id !== (int) $this->getKey()
        ) {
            $this->failImmutableTransition();
        }

        $this->workflowTransitionAuthorized = true;
        $this->status = 'reversed';
        $this->reversed_by = $user?->getKey();
        $this->reversed_at = now();
        $this->reversal_journal_id = $journal->getKey();
    }

    private function assertWorkflowTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Expense lifecycle changes must run inside a database transaction.');
        }
    }

    private function failImmutableTransition(): never
    {
        throw ValidationException::withMessages([
            'expense' => 'Only the expense workflow may approve, post, or reverse an expense. Approved and posted expense data is immutable.',
        ]);
    }
}
