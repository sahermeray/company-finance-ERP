<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use App\Services\AccountingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingJournal extends Model
{
    use TenantScoped;

    protected $attributes = [
        'status' => 'draft',
    ];

    private bool $postingTransitionAuthorized = false;

    public static function booted(): void
    {
        static::creating(function (self $journal): void {
            if ($journal->status !== 'draft' || $journal->posted_at !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'Journals must be created as drafts and posted through the accounting service.',
                ]);
            }

            if ($journal->reversed_by_journal_id !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'The reversed-by link may only be assigned by the reversal workflow.',
                ]);
            }

            if ($journal->reversal_of_journal_id !== null) {
                $original = self::withoutGlobalScope(CompanyScope::class)
                    ->find($journal->reversal_of_journal_id);

                if (
                    $original === null
                    || $original->status !== 'posted'
                    || (int) $original->company_id !== (int) $journal->company_id
                    || $original->reversed_by_journal_id !== null
                ) {
                    throw ValidationException::withMessages([
                        'journal' => 'A reversal draft must reference an active posted journal in the same company.',
                    ]);
                }
            }
        });

        static::updating(function (self $journal): void {
            // This guard fires only when the journal was ALREADY posted before this save.
            // We intentionally check getOriginal('status') (the persisted value) rather
            // than the in-memory value so that the initial draft→posted transition —
            // which sets both status and posted_at in the same save() call — is NOT
            // gated here. At the time of that save, getOriginal('status') is still
            // 'draft', so the transition proceeds freely.
            //
            // Once a journal IS posted (getOriginal === 'posted'), only these fields
            // may change. 'posted_at' is NOT in this list: any attempt to change the
            // timestamp of an already-posted journal would be timestamp tampering and
            // must be rejected. The initial setting of posted_at happens before this
            // guard is reached (see above).
            if ($journal->getOriginal('status') === 'posted' || $journal->getOriginal('posted_at') !== null) {
                $dirty = array_keys($journal->getDirty());
                $allowed = ['reversed_by_journal_id'];

                if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
                    throw ValidationException::withMessages([
                        'journal' => 'Posted journals are immutable. Create a reversal instead of editing historical entries.',
                    ]);
                }

                if ($journal->isDirty('reversed_by_journal_id')) {
                    if (
                        DB::transactionLevel() === 0
                        || $journal->getOriginal('reversed_by_journal_id') !== null
                        || $journal->reversed_by_journal_id === null
                    ) {
                        throw ValidationException::withMessages([
                            'journal' => 'A posted journal can be reversed only once.',
                        ]);
                    }

                    $reversal = self::withoutGlobalScope(CompanyScope::class)
                        ->find($journal->reversed_by_journal_id);

                    if (
                        $reversal === null
                        || $reversal->status !== 'posted'
                        || (int) $reversal->company_id !== (int) $journal->company_id
                        || (int) $reversal->reversal_of_journal_id !== (int) $journal->getKey()
                    ) {
                        throw ValidationException::withMessages([
                            'journal' => 'The reversal journal must be posted, linked to this journal, and belong to the same company.',
                        ]);
                    }

                    app(AccountingService::class)->validateJournalReversal($journal, $reversal);
                }
            } elseif ($journal->isDirty('status') && $journal->status === 'posted') {
                if (
                    ! $journal->postingTransitionAuthorized
                    || $journal->getOriginal('status') !== 'draft'
                    || $journal->posted_at === null
                ) {
                    throw ValidationException::withMessages([
                        'journal' => 'Only a draft journal can be posted.',
                    ]);
                }

                app(AccountingService::class)->validateJournalForPosting($journal);
            } elseif ($journal->isDirty('posted_at') && $journal->posted_at !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'Journal posting timestamps are set only when posting through the accounting service.',
                ]);
            } elseif ($journal->isDirty('status')) {
                throw ValidationException::withMessages([
                    'journal' => 'Journal status changes must use the accounting posting or reversal workflow.',
                ]);
            }
        });

        static::deleting(function (self $journal): void {
            if ($journal->status === 'posted' || $journal->posted_at !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'Posted journals cannot be deleted. Create a reversal instead.',
                ]);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'project_id',
        'created_by',
        'sourceable_type',
        'sourceable_id',
        'journal_number',
        'entry_date',
        'status',
        'description',
        'posted_at',
        'reversal_of_journal_id',
        'reversed_by_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    public function markAsReversedBy(self $reversal): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Journals can only be reversed inside a database transaction.');
        }

        if (
            $reversal->status !== 'posted'
            || (int) $reversal->company_id !== (int) $this->company_id
            || (int) $reversal->reversal_of_journal_id !== (int) $this->getKey()
        ) {
            throw ValidationException::withMessages([
                'journal' => 'The reversal journal must be posted, linked to this journal, and belong to the same company.',
            ]);
        }

        $this->reversed_by_journal_id = $reversal->getKey();
    }

    public function markAsPosted(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Journals can only be posted inside a database transaction.');
        }

        $this->postingTransitionAuthorized = true;
        $this->status = 'posted';
        $this->posted_at = now();
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}
