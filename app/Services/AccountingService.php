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

        $journalAttributes = [
            'company_id' => $company->getKey(),
            'status' => 'draft',
            'entry_date' => now()->toDateString(),
            ...$attributes,
        ];

        if (empty($journalAttributes['journal_number'])) {
            $journalAttributes['journal_number'] = $this->generateJournalNumber($company);
        }

        $journal = AccountingJournal::create($journalAttributes);

        return AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($journal->getKey()) ?? $journal;
    }

    public function addJournalLine(AccountingJournal $journal, array $attributes): JournalLine
    {
        $this->assertJournalBelongsToActiveCompany($journal);

        if ($journal->status !== 'draft') {
            throw ValidationException::withMessages([
                'journal' => 'Only draft journals can accept new lines.',
            ]);
        }

        $accountId = $attributes['financial_account_id'] ?? null;
        $account = $accountId === null ? null : FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The selected account does not exist.',
            ]);
        }

        $this->assertSameCompany($journal->company, $account->company, 'financial_account_id');

        $lineAttributes = [
            'accounting_journal_id' => $journal->getKey(),
            'financial_account_id' => $account->getKey(),
            'line_number' => $this->nextLineNumber($journal),
            ...$attributes,
        ];

        $lineAttributes['currency'] ??= $account->currency;
        $lineAttributes['base_currency'] ??= $journal->company?->base_currency ?? $account->currency;
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

        if (empty($lineAttributes['description']) && ! empty($journal->description)) {
            $lineAttributes['description'] = $journal->description;
        }

        $lineAttributes = $this->applyBaseAmounts($lineAttributes, $journal);

        return JournalLine::create($lineAttributes);
    }

    public function postJournal(AccountingJournal $journal): AccountingJournal
    {
        return DB::transaction(function () use ($journal): AccountingJournal {
            // Reload the journal bypassing TenantScoped so this method works both
            // with and without an active company context (e.g. internal service calls).
            $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with('company')
                ->find($journal->getKey()) ?? $journal;

            $this->assertJournalBelongsToActiveCompany($journal);

            if ($journal->status === 'posted') {
                throw ValidationException::withMessages([
                    'journal' => 'A posted journal is immutable and cannot be edited.',
                ]);
            }

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

                if ($line->currency !== $line->financialAccount->currency) {
                    throw ValidationException::withMessages([
                        'currency' => 'The journal line currency must match the account currency.',
                    ]);
                }

                $line->base_debit = empty($line->base_debit) ? ($debit !== '0' ? $this->convertAmount($debit, $line->exchange_rate, $line->base_currency, $line->currency) : '0') : $this->normalizeAmount($line->base_debit, 'base_debit');
                $line->base_credit = empty($line->base_credit) ? ($credit !== '0' ? $this->convertAmount($credit, $line->exchange_rate, $line->base_currency, $line->currency) : '0') : $this->normalizeAmount($line->base_credit, 'base_credit');
                $line->save();

                $totalDebits = bcadd($totalDebits, $line->base_debit ?? $debit, 10);
                $totalCredits = bcadd($totalCredits, $line->base_credit ?? $credit, 10);
            }

            if (bccomp($totalDebits, $totalCredits, 10) !== 0) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal is unbalanced. Total debits must equal total credits.',
                ]);
            }

            $journal->status = 'posted';
            $journal->posted_at = now();
            $journal->save();

            return AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with(['lines' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])
                ->find($journal->getKey()) ?? $journal;
        });
    }

    public function reverseJournal(AccountingJournal $journal, array $attributes = []): AccountingJournal
    {
        return DB::transaction(function () use ($journal, $attributes): AccountingJournal {
            // Re-read the journal with a row-level write lock so concurrent
            // reversal requests are serialised. lockForUpdate() is a no-op on
            // SQLite but provides serialisation on MySQL/PostgreSQL.
            $journal = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                ->with(['lines', 'company'])
                ->lockForUpdate()
                ->find($journal->getKey());

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'journal' => 'The journal could not be found.',
                ]);
            }

            if ($journal->status !== 'posted') {
                throw ValidationException::withMessages([
                    'journal' => 'Only posted journals can be reversed.',
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

            $reversal = $this->createDraftJournal([
                'company_id' => $journal->company_id,
                'description' => $attributes['description'] ?? 'Reversal of '.$journal->journal_number,
                'entry_date' => $attributes['entry_date'] ?? now()->toDateString(),
                'project_id' => $attributes['project_id'] ?? $journal->project_id,
                'sourceable_type' => $attributes['sourceable_type'] ?? $journal->sourceable_type,
                'sourceable_id' => $attributes['sourceable_id'] ?? $journal->sourceable_id,
                'reversal_of_journal_id' => $journal->getKey(),
            ], $journal->company);

            foreach ($journal->lines()->orderBy('line_number')->get() as $line) {
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

            $this->postJournal($reversal);

            $journal->reversed_by_journal_id = $reversal->getKey();
            $journal->save();

            return $reversal->fresh(['lines']);
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

    private function assertSameCompany($left, $right, string $field): void
    {
        if ($left === null || $right === null || (int) $left->getKey() !== (int) $right->getKey()) {
            throw ValidationException::withMessages([
                $field => 'The selected record must belong to the same company.',
            ]);
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

        return preg_match('/^-?\d+(\.\d+)?$/', $amount) === 1 ? $amount : '0';
    }
}
