<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class AccountingJournal extends Model
{
    use TenantScoped;

    public static function booted(): void
    {
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
            if ($journal->getOriginal('status') === 'posted') {
                $dirty = array_keys($journal->getDirty());
                $allowed = ['status', 'reversed_by_journal_id'];

                if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'journal' => 'Posted journals are immutable. Create a reversal instead of editing historical entries.',
                    ]);
                }
            }
        });

        static::deleting(function (self $journal): void {
            if ($journal->status === 'posted' || $journal->posted_at !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
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

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}
