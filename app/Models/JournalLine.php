<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'journal';
    }

    public static function booted(): void
    {
        static::updating(function (self $line): void {
            // Load the parent journal without the global scope to avoid false negatives
            // when no company context is active (e.g. internal service calls).
            $journal = AccountingJournal::withoutGlobalScope(\App\Eloquent\CompanyScope::class)
                ->find($line->accounting_journal_id);
            if ($journal !== null && ($journal->status === 'posted' || $journal->posted_at !== null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'journal_line' => 'Journal lines belonging to a posted journal are immutable.',
                ]);
            }
        });

        static::deleting(function (self $line): void {
            $journal = AccountingJournal::withoutGlobalScope(\App\Eloquent\CompanyScope::class)
                ->find($line->accounting_journal_id);
            if ($journal !== null && ($journal->status === 'posted' || $journal->posted_at !== null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'journal_line' => 'Journal lines belonging to a posted journal cannot be deleted.',
                ]);
            }
        });
    }

    protected $fillable = [
        'accounting_journal_id',
        'financial_account_id',
        'party_id',
        'project_id',
        'line_number',
        'description',
        'currency',
        'exchange_rate',
        'base_currency',
        'debit',
        'credit',
        'base_debit',
        'base_credit',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_debit' => 'decimal:4',
            'base_credit' => 'decimal:4',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
