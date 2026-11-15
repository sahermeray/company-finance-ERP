<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class JournalLine extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'journal';
    }

    public static function booted(): void
    {
        static::creating(function (self $line): void {
            self::assertBelongsToDraftJournal($line, (int) $line->accounting_journal_id);
            self::assertOwnershipIsConsistent($line);
        });

        static::updating(function (self $line): void {
            $originalJournalId = (int) $line->getOriginal('accounting_journal_id');
            $currentJournalId = (int) $line->accounting_journal_id;

            self::assertBelongsToDraftJournal($line, $originalJournalId);

            if ($currentJournalId !== $originalJournalId) {
                self::assertBelongsToDraftJournal($line, $currentJournalId);
            }

            self::assertOwnershipIsConsistent($line);
        });

        static::deleting(function (self $line): void {
            $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->find($line->accounting_journal_id);

            if ($journal !== null && ($journal->status !== 'draft' || $journal->posted_at !== null)) {
                throw ValidationException::withMessages([
                    'journal_line' => 'Journal lines belonging to a posted journal cannot be deleted.',
                ]);
            }
        });
    }

    private static function assertBelongsToDraftJournal(self $line, int $journalId): void
    {
        $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($journalId);

        if ($journal === null || $journal->status !== 'draft' || $journal->posted_at !== null) {
            throw ValidationException::withMessages([
                'journal_line' => 'Journal lines can only be created or edited on a draft journal.',
            ]);
        }
    }

    private static function assertOwnershipIsConsistent(self $line): void
    {
        $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->find($line->accounting_journal_id);
        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->find($line->financial_account_id);

        if (
            $journal === null
            || $account === null
            || (int) $journal->company_id !== (int) $account->company_id
        ) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The journal line account must belong to the same company as its journal.',
            ]);
        }

        foreach ([
            'party_id' => Party::class,
            'project_id' => Project::class,
        ] as $field => $modelClass) {
            $recordId = $line->{$field};

            if ($recordId === null) {
                continue;
            }

            $record = $modelClass::withoutGlobalScope(CompanyScope::class)->find($recordId);

            if ($record === null || (int) $record->company_id !== (int) $journal->company_id) {
                throw ValidationException::withMessages([
                    $field => 'The journal line record must belong to the same company as its journal.',
                ]);
            }
        }
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
