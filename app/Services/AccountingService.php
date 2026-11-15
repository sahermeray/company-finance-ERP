<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCutover;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SupplierReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function createDraftJournal(array $attributes = [], ?Company $company = null): AccountingJournal
    {
        if ($company === null && (($attributes['company_id'] ?? null) !== null)) {
            $company = Company::query()->find($attributes['company_id']);
        }

        $company ??= $this->companyContext->requireCurrent();

        if (
            $this->companyContext->id() !== null
            && (int) $this->companyContext->id() !== (int) $company->getKey()
        ) {
            throw ValidationException::withMessages([
                'company_id' => 'The journal must belong to the active company.',
            ]);
        }

        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'company_id' => 'The journal must belong to the selected company.',
            ]);
        }

        if (isset($attributes['status']) && $attributes['status'] !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'New journals must be created as drafts and posted through the accounting service.',
            ]);
        }

        $journalAttributes = [
            'company_id' => $company->getKey(),
            'entry_date' => now()->toDateString(),
            ...$attributes,
            'status' => 'draft',
        ];

        if (empty($journalAttributes['journal_number'])) {
            $journalAttributes['journal_number'] = $this->generateJournalNumber($company);
        }

        $journal = AccountingJournal::create($journalAttributes);

        return AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($journal->getKey()) ?? $journal;
    }

    public function addJournalLine(AccountingJournal $journal, array $attributes): JournalLine
    {
        return DB::transaction(function () use ($journal, $attributes): JournalLine {
            $lockedJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with('company')
                ->lockForUpdate()
                ->find($journal->getKey());

            if ($lockedJournal === null || $lockedJournal->status !== 'draft' || $lockedJournal->posted_at !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'Only draft journals can accept new lines.',
                ]);
            }

            $this->assertJournalBelongsToActiveCompany($lockedJournal);

            $accountId = $attributes['financial_account_id'] ?? null;
            $account = $accountId === null ? null : FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);

            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'The selected account does not exist or is inactive.',
                ]);
            }

            $this->assertSameCompany($lockedJournal->company, $account->company, 'financial_account_id');

            $lineAttributes = [
                ...$attributes,
                'accounting_journal_id' => $lockedJournal->getKey(),
                'financial_account_id' => $account->getKey(),
                'line_number' => $this->nextLineNumber($lockedJournal),
            ];

            $lineAttributes['currency'] ??= $account->currency;
            $lineAttributes['base_currency'] ??= $lockedJournal->company?->base_currency ?? $account->currency;
            $lineAttributes['exchange_rate'] ??= '1';

            $debit = $this->normalizeAmount($lineAttributes['debit'] ?? '0', 'debit');
            $credit = $this->normalizeAmount($lineAttributes['credit'] ?? '0', 'credit');

            if (bccomp($debit, '0', 10) === 0 && bccomp($credit, '0', 10) === 0) {
                throw ValidationException::withMessages([
                    'debit' => 'Journal lines must contain a positive debit or credit value.',
                ]);
            }

            if (bccomp($debit, '0', 10) > 0 && bccomp($credit, '0', 10) > 0) {
                throw ValidationException::withMessages([
                    'credit' => 'A journal line cannot contain both debit and credit values.',
                ]);
            }

            if (bccomp($debit, '0', 10) < 0 || bccomp($credit, '0', 10) < 0) {
                throw ValidationException::withMessages([
                    'debit' => 'Financial amounts must be zero or positive.',
                ]);
            }

            $lineAttributes['debit'] = $debit;
            $lineAttributes['credit'] = $credit;

            if (empty($lineAttributes['description']) && ! empty($lockedJournal->description)) {
                $lineAttributes['description'] = $lockedJournal->description;
            }

            $lineAttributes = $this->applyBaseAmounts($lineAttributes, $lockedJournal);

            return JournalLine::create($lineAttributes);
        });
    }

    public function postJournal(AccountingJournal $journal): AccountingJournal
    {
        return DB::transaction(function () use ($journal): AccountingJournal {
            // Reload the journal bypassing TenantScoped so this method works both
            // with and without an active company context (e.g. internal service calls).
            $companyId = (int) $journal->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($journal->getKey());

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal could not be found in its company.',
                ]);
            }

            $this->assertJournalBelongsToActiveCompany($journal);

            if ($journal->status !== 'draft' || $journal->posted_at !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'Only draft journals can be posted.',
                ]);
            }

            $this->assertReferenceBelongsToCompany(Project::class, $journal->project_id, $journal->company, 'project_id');

            // Load lines and their linked accounts without the global scope so
            // the method works when no company context is active.
            $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
                ->where('accounting_journal_id', $journal->getKey())
                ->orderBy('line_number')
                ->get();

            // Preload financial accounts for each line without the global scope.
            $accountIds = $lines->pluck('financial_account_id')->filter()->unique()->values();
            $accounts = FinancialAccount::withoutGlobalScope(CompanyScope::class)
                ->whereIn('id', $accountIds)
                ->get()
                ->keyBy('id');

            // Set the preloaded accounts on each line to avoid lazy-load through the scope.
            foreach ($lines as $line) {
                $line->setRelation('financialAccount', $accounts->get($line->financial_account_id));
            }

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'journal' => 'A posted journal must contain at least one line.',
                ]);
            }

            $totalDebits = '0';
            $totalCredits = '0';

            foreach ($lines as $line) {
                $this->assertLineBelongsToJournal($line, $journal);

                if ($line->financialAccount === null) {
                    throw ValidationException::withMessages([
                        'financial_account_id' => 'Each journal line must reference a valid account.',
                    ]);
                }

                $this->assertSameCompany($journal->company, $line->financialAccount->company, 'financial_account_id');
                $this->assertReferenceBelongsToCompany(Party::class, $line->party_id, $journal->company, 'party_id');
                $this->assertReferenceBelongsToCompany(Project::class, $line->project_id, $journal->company, 'project_id');

                if (! $line->financialAccount->is_active) {
                    throw ValidationException::withMessages([
                        'financial_account_id' => 'Journal lines must reference an active account.',
                    ]);
                }

                $debit = $this->normalizeAmount($line->debit ?? '0', 'debit');
                $credit = $this->normalizeAmount($line->credit ?? '0', 'credit');

                if (bccomp($debit, '0', 10) === 0 && bccomp($credit, '0', 10) === 0) {
                    throw ValidationException::withMessages([
                        'debit' => 'Journal lines cannot be zero-value entries.',
                    ]);
                }

                if (bccomp($debit, '0', 10) < 0 || bccomp($credit, '0', 10) < 0) {
                    throw ValidationException::withMessages([
                        'debit' => 'Negative financial amounts are not allowed.',
                    ]);
                }

                if (bccomp($debit, '0', 10) > 0 && bccomp($credit, '0', 10) > 0) {
                    throw ValidationException::withMessages([
                        'credit' => 'A line cannot have both debit and credit values.',
                    ]);
                }

                $line->currency ??= $line->financialAccount->currency;
                $line->base_currency ??= $journal->company?->base_currency ?? $line->financialAccount->currency;
                $line->exchange_rate = $this->normalizeExchangeRate((string) ($line->exchange_rate ?? '1'));

                if (
                    preg_match('/^[A-Z]{3}$/', (string) $line->currency) !== 1
                    || preg_match('/^[A-Z]{3}$/', (string) $line->base_currency) !== 1
                    || strtoupper((string) $line->base_currency) !== strtoupper((string) $journal->company?->base_currency)
                ) {
                    throw ValidationException::withMessages([
                        'currency' => 'Journal line currencies must be valid, match the account, and use the company base currency.',
                    ]);
                }

                $line->base_debit = empty($line->base_debit) ? ($debit !== '0' ? $this->convertAmount($debit, $line->exchange_rate, $line->base_currency, $line->currency) : '0') : $this->normalizeAmount($line->base_debit, 'base_debit');
                $line->base_credit = empty($line->base_credit) ? ($credit !== '0' ? $this->convertAmount($credit, $line->exchange_rate, $line->base_currency, $line->currency) : '0') : $this->normalizeAmount($line->base_credit, 'base_credit');

                if (
                    (bccomp($debit, '0', 10) === 0 && bccomp($line->base_debit, '0', 10) !== 0)
                    || (bccomp($credit, '0', 10) === 0 && bccomp($line->base_credit, '0', 10) !== 0)
                    || bccomp($line->base_debit, '0', 10) < 0
                    || bccomp($line->base_credit, '0', 10) < 0
                    || (bccomp($line->base_debit, '0', 10) > 0 && bccomp($line->base_credit, '0', 10) > 0)
                ) {
                    throw ValidationException::withMessages([
                        'base_debit' => 'Base-currency amounts must correspond to the journal line debit or credit.',
                    ]);
                }

                $line->save();

                $totalDebits = bcadd($totalDebits, $line->base_debit ?? $debit, 10);
                $totalCredits = bcadd($totalCredits, $line->base_credit ?? $credit, 10);
            }

            if (bccomp($totalDebits, $totalCredits, 10) !== 0) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal is unbalanced. Total debits must equal total credits.',
                ]);
            }

            $journal->markAsPosted();
            $journal->save();

            return AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with(['lines' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])
                ->find($journal->getKey()) ?? $journal;
        });
    }

    public function reverseJournal(AccountingJournal $journal, array $attributes = []): AccountingJournal
    {
        return $this->reverseJournalInternal($journal, $attributes, false);
    }

    public function reversePaymentJournal(AccountingJournal $journal, array $attributes = []): AccountingJournal
    {
        return $this->reverseJournalInternal($journal, $attributes, true);
    }

    private function reverseJournalInternal(
        AccountingJournal $journal,
        array $attributes,
        bool $allowPaymentJournal,
    ): AccountingJournal {
        return DB::transaction(function () use ($journal, $attributes, $allowPaymentJournal): AccountingJournal {
            $companyId = (int) $journal->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            // Re-read the journal with a row-level write lock so concurrent
            // reversal requests are serialised. lockForUpdate() is a no-op on
            // SQLite but provides serialisation on MySQL/PostgreSQL.
            $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with(['lines', 'company'])
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($journal->getKey());

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal could not be found.',
                ]);
            }

            $this->assertJournalBelongsToActiveCompany($journal);

            if ($journal->status !== 'posted' || $journal->posted_at === null) {
                throw ValidationException::withMessages([
                    'journal' => 'Only posted journals can be reversed.',
                ]);
            }

            if ($journal->reversal_of_journal_id !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'A reversal journal cannot itself be reversed.',
                ]);
            }

            if ($journal->sourceable_type === Payment::class && ! $allowPaymentJournal) {
                throw ValidationException::withMessages([
                    'journal' => 'Payment journals must be reversed through PaymentService so the payment and journal remain atomic.',
                ]);
            }

            if ($journal->sourceable_type === InventoryCutover::class) {
                throw ValidationException::withMessages([
                    'journal' => 'Inventory cutover journals require a coordinated inventory correction and cannot be reversed independently.',
                ]);
            }

            if ($journal->sourceable_type === InventoryAdjustment::class) {
                throw ValidationException::withMessages([
                    'journal' => 'Inventory adjustment journals require a compensating stock adjustment and cannot be reversed independently.',
                ]);
            }

            if (in_array($journal->sourceable_type, [CustomerReturn::class, SupplierReturn::class], true)) {
                throw ValidationException::withMessages([
                    'journal' => 'Return journals require a coordinated inventory correction and cannot be reversed independently.',
                ]);
            }

            // Double-reversal guard: reject if this journal has already been reversed.
            if ($journal->reversed_by_journal_id !== null) {
                throw ValidationException::withMessages([
                    'journal' => 'This journal has already been reversed.',
                ]);
            }

            if (AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->where('reversal_of_journal_id', $journal->getKey())
                ->exists()) {
                throw ValidationException::withMessages([
                    'journal' => 'This journal already has a reversal journal.',
                ]);
            }

            $reversal = $this->createDraftJournal([
                'company_id' => $journal->company_id,
                'description' => $attributes['description'] ?? 'Reversal of '.$journal->journal_number,
                'entry_date' => $attributes['entry_date'] ?? now()->toDateString(),
                'project_id' => $attributes['project_id'] ?? $journal->project_id,
                'sourceable_type' => $attributes['sourceable_type'] ?? $journal->sourceable_type,
                'sourceable_id' => $attributes['sourceable_id'] ?? $journal->sourceable_id,
                'reversal_of_journal_id' => $journal->getKey(),
            ], $journal->company);

            $originalLines = JournalLine::withoutGlobalScope(CompanyScope::class)
                ->where('accounting_journal_id', $journal->getKey())
                ->orderBy('line_number')
                ->get();

            $journal->setRelation('lines', $originalLines);

            foreach ($originalLines as $line) {
                $reversed = [
                    'financial_account_id' => $line->financial_account_id,
                    'party_id' => $line->party_id,
                    'project_id' => $line->project_id,
                    'description' => $line->description,
                    'currency' => $line->currency,
                    'exchange_rate' => $line->exchange_rate,
                    'base_currency' => $line->base_currency,
                ];

                if (bccomp((string) $line->debit, '0', 10) > 0) {
                    $reversed['credit'] = $line->debit;
                    $reversed['base_credit'] = $line->base_debit ?? $line->debit;
                } else {
                    $reversed['debit'] = $line->credit;
                    $reversed['base_debit'] = $line->base_credit ?? $line->credit;
                }

                $this->addJournalLine($reversal, $reversed);
            }

            $reversal = $this->postJournal($reversal);

            $this->validateJournalReversal($journal, $reversal);

            $journal->markAsReversedBy($reversal);
            $journal->save();

            $reversal->setRelation(
                'lines',
                JournalLine::withoutGlobalScope(CompanyScope::class)
                    ->where('accounting_journal_id', $reversal->getKey())
                    ->orderBy('line_number')
                    ->get(),
            );

            return $reversal;
        });
    }

    private function nextLineNumber(AccountingJournal $journal): int
    {
        $lastLineNumber = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $journal->getKey())
            ->max('line_number');

        return ((int) ($lastLineNumber ?? 0)) + 1;
    }

    private function generateJournalNumber(Company $company): string
    {
        $prefix = strtoupper(substr($company->name ?? 'COMP', 0, 3));
        $dateSegment = now()->format('Ymd');
        $sequence = AccountingJournal::query()
            ->where('company_id', $company->getKey())
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return sprintf('%s-%s-%04d', $prefix, $dateSegment, $sequence + 1);
    }

    private function applyBaseAmounts(array $lineAttributes, AccountingJournal $journal): array
    {
        $debit = $this->normalizeAmount($lineAttributes['debit'] ?? '0', 'debit');
        $credit = $this->normalizeAmount($lineAttributes['credit'] ?? '0', 'credit');
        $currency = $lineAttributes['currency'] ?? $journal->company?->base_currency;
        $baseCurrency = $lineAttributes['base_currency'] ?? $journal->company?->base_currency ?? 'SYP';
        $exchangeRate = $this->normalizeExchangeRate((string) ($lineAttributes['exchange_rate'] ?? '1'));

        if (bccomp($exchangeRate, '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Exchange rates must be greater than zero.',
            ]);
        }

        if (empty($lineAttributes['base_debit']) && bccomp($debit, '0', 10) > 0) {
            $lineAttributes['base_debit'] = $this->convertAmount($debit, $exchangeRate, $baseCurrency, $currency);
        }

        if (empty($lineAttributes['base_credit']) && bccomp($credit, '0', 10) > 0) {
            $lineAttributes['base_credit'] = $this->convertAmount($credit, $exchangeRate, $baseCurrency, $currency);
        }

        if ((! empty($lineAttributes['base_debit']) && bccomp($this->normalizeAmount($lineAttributes['base_debit'], 'base_debit'), '0', 10) < 0)
            || (! empty($lineAttributes['base_credit']) && bccomp($this->normalizeAmount($lineAttributes['base_credit'], 'base_credit'), '0', 10) < 0)) {
            throw ValidationException::withMessages([
                'debit' => 'Base-currency amounts must be zero or positive.',
            ]);
        }

        return $lineAttributes;
    }

    private function normalizeExchangeRate(string $exchangeRate): string
    {
        $exchangeRate = trim($exchangeRate);

        if (preg_match('/^\d+(?:\.\d+)?$/', $exchangeRate) !== 1
            || bccomp($exchangeRate, '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Exchange rates must be positive decimal values.',
            ]);
        }

        $truncatedEleven = bcadd($exchangeRate, '0', 11);
        $truncatedTen = bcadd($exchangeRate, '0', 10);
        $dotPosition = strpos($truncatedEleven, '.');
        $eleventhDigit = $dotPosition !== false && isset($truncatedEleven[$dotPosition + 11])
            ? $truncatedEleven[$dotPosition + 11]
            : '0';

        if ($eleventhDigit >= '5') {
            $truncatedTen = bcadd($truncatedTen, '0.0000000001', 10);
        }

        return bcadd($truncatedTen, '0', 10);
    }

    private function convertAmount(string $amount, string $exchangeRate, string $baseCurrency, string $currency): string
    {
        if ($baseCurrency === $currency) {
            return $amount;
        }

        return bcmul($amount, $exchangeRate, 10);
    }

    private function assertJournalBelongsToActiveCompany(AccountingJournal $journal): void
    {
        $activeCompanyId = $this->companyContext->id();

        if ($activeCompanyId !== null && (int) $journal->company_id !== (int) $activeCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The journal must belong to the active company.',
            ]);
        }
    }

    private function assertLineBelongsToJournal(JournalLine $line, AccountingJournal $journal): void
    {
        if ((int) $line->accounting_journal_id !== (int) $journal->getKey()) {
            throw ValidationException::withMessages([
                'accounting_journal_id' => 'The journal line must belong to the journal being posted.',
            ]);
        }
    }

    private function assertSameCompany(?Company $company, ?object $record, string $field, bool $allowNull = false): void
    {
        if ($allowNull && ($company === null || $record === null)) {
            return;
        }

        $recordCompanyId = $record instanceof Company
            ? $record->getKey()
            : ($record->company_id ?? null);

        if (
            $company === null
            || $record === null
            || $recordCompanyId === null
            || (int) $company->getKey() !== (int) $recordCompanyId
        ) {
            throw ValidationException::withMessages([
                $field => 'The selected record must belong to the same company.',
            ]);
        }
    }

    private function assertReferenceBelongsToCompany(string $modelClass, ?int $recordId, ?Company $company, string $field): void
    {
        if ($recordId === null) {
            return;
        }

        $record = $modelClass::withoutGlobalScope(CompanyScope::class)->find($recordId);

        $this->assertSameCompany($company, $record, $field);
    }

    /**
     * Validate the persisted journal contents before allowing any model-level
     * transition to posted, including callers that do not use postJournal().
     */
    public function validateJournalForPosting(AccountingJournal $journal): void
    {
        $freshJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->with('company')
            ->find($journal->getKey());

        if ($freshJournal === null) {
            throw ValidationException::withMessages([
                'journal' => 'The journal could not be found.',
            ]);
        }

        $this->assertReferenceBelongsToCompany(Project::class, $freshJournal->project_id, $freshJournal->company, 'project_id');
        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $freshJournal->getKey())
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'journal' => 'A posted journal must contain at least one line.',
            ]);
        }

        $debits = '0';
        $credits = '0';

        foreach ($lines as $line) {
            $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($line->financial_account_id);
            $debit = $this->normalizeAmount($line->debit, 'debit');
            $credit = $this->normalizeAmount($line->credit, 'credit');
            $baseDebit = $this->normalizeAmount($line->base_debit, 'base_debit');
            $baseCredit = $this->normalizeAmount($line->base_credit, 'base_credit');

            if (
                $account === null
                || ! $account->is_active
                || (int) $account->company_id !== (int) $freshJournal->company_id
                || (int) $line->accounting_journal_id !== (int) $freshJournal->getKey()
            ) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'Each journal line must reference an active account owned by the journal company.',
                ]);
            }

            $this->assertReferenceBelongsToCompany(Party::class, $line->party_id, $freshJournal->company, 'party_id');
            $this->assertReferenceBelongsToCompany(Project::class, $line->project_id, $freshJournal->company, 'project_id');

            if (
                bccomp($debit, '0', 10) < 0
                || bccomp($credit, '0', 10) < 0
                || (bccomp($debit, '0', 10) === 0 && bccomp($credit, '0', 10) === 0)
                || (bccomp($debit, '0', 10) > 0 && bccomp($credit, '0', 10) > 0)
                || preg_match('/^[A-Z]{3}$/', (string) $line->currency) !== 1
                || preg_match('/^[A-Z]{3}$/', (string) $line->base_currency) !== 1
                || strtoupper((string) $line->base_currency) !== strtoupper((string) $freshJournal->company->base_currency)
                || bccomp($baseDebit, '0', 10) < 0
                || bccomp($baseCredit, '0', 10) < 0
                || (bccomp($debit, '0', 10) === 0 && bccomp($baseDebit, '0', 10) !== 0)
                || (bccomp($credit, '0', 10) === 0 && bccomp($baseCredit, '0', 10) !== 0)
                || (bccomp($baseDebit, '0', 10) > 0 && bccomp($baseCredit, '0', 10) > 0)
            ) {
                throw ValidationException::withMessages([
                    'journal' => 'Journal lines contain invalid amounts or currencies.',
                ]);
            }

            $this->normalizeExchangeRate((string) $line->exchange_rate);
            $debits = bcadd($debits, $baseDebit, 10);
            $credits = bcadd($credits, $baseCredit, 10);
        }

        if (bccomp($debits, $credits, 10) !== 0) {
            throw ValidationException::withMessages([
                'journal' => 'The journal is unbalanced. Total debits must equal total credits.',
            ]);
        }
    }

    public function validateJournalReversal(AccountingJournal $original, AccountingJournal $reversal): void
    {
        $originalLines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $original->getKey())
            ->orderBy('line_number')
            ->get();
        $reversalLines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('accounting_journal_id', $reversal->getKey())
            ->orderBy('line_number')
            ->get();

        if ($originalLines->count() !== $reversalLines->count()) {
            throw ValidationException::withMessages([
                'journal' => 'The reversal must contain exactly one line for every original journal line.',
            ]);
        }

        foreach ($originalLines as $index => $line) {
            $reversedLine = $reversalLines->get($index);

            if (
                (int) $line->financial_account_id !== (int) $reversedLine->financial_account_id
                || (int) $line->party_id !== (int) $reversedLine->party_id
                || (int) $line->project_id !== (int) $reversedLine->project_id
                || $line->currency !== $reversedLine->currency
                || $line->base_currency !== $reversedLine->base_currency
                || bccomp((string) $line->exchange_rate, (string) $reversedLine->exchange_rate, 10) !== 0
                || bccomp((string) $line->debit, (string) $reversedLine->credit, 10) !== 0
                || bccomp((string) $line->credit, (string) $reversedLine->debit, 10) !== 0
                || bccomp((string) $line->base_debit, (string) $reversedLine->base_credit, 10) !== 0
                || bccomp((string) $line->base_credit, (string) $reversedLine->base_debit, 10) !== 0
            ) {
                throw ValidationException::withMessages([
                    'journal' => 'Reversal lines must exactly exchange the original debit and credit amounts.',
                ]);
            }
        }
    }

    private function normalizeAmount(mixed $amount, string $field): string
    {
        if ($amount === null || $amount === '') {
            return '0';
        }

        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                $field => 'The amount must be numeric.',
            ]);
        }

        $amount = (string) $amount;

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1) {
            throw ValidationException::withMessages([
                $field => 'The amount must be a plain decimal value.',
            ]);
        }

        return $amount;
    }
}
