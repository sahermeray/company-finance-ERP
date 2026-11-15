<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\CustomerReturn;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\SupplierReturn;
use Illuminate\Database\Eloquent\Collection;

class OperationalSubledgerReconciliationService
{
    public function reconcileReceivables(Company $company): array
    {
        $mapping = $this->mapping($company, CompanyAccountMapping::CUSTOMER_RECEIVABLES);
        if ($mapping === null) {
            return $this->unsupported($company, 'customer_receivables', 'No customer receivables control account is configured.');
        }

        $issues = [];
        $limitations = [];
        $sourceRecords = [];
        $operational = $this->emptyTotals();
        $recognizedRevenueIds = [];

        $revenues = Revenue::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->orderBy('id')
            ->get();

        foreach ($revenues as $revenue) {
            $journals = $this->sourceJournals(Revenue::class, (int) $revenue->getKey());
            if ($this->isReversed($journals, $company)) {
                continue;
            }
            $journal = $this->activeSourceJournal($journals, $company);
            if ($journal === null) {
                if ($revenue->receivable_account_id === null) {
                    $issues[] = $this->sourceIssue(Revenue::class, $revenue->getKey(), 'posted_revenue_missing_or_inconsistent_journal');
                    $limitations[] = 'Posted revenue without one active posted journal and without a persisted receivable account cannot be classified as a credit sale or cash sale.';

                    continue;
                }

                $issues[] = $this->sourceIssue(Revenue::class, $revenue->getKey(), 'posted_revenue_missing_or_inconsistent_journal');
                $recognizedRevenueIds[] = (int) $revenue->getKey();
                $this->appendDocumentAmount($operational, $revenue, (string) $revenue->base_total, (string) $revenue->total);
                $sourceRecords[] = $this->sourceRecord(Revenue::class, $revenue->getKey(), $revenue->revenue_number);
                $this->appendActivePayments(
                    $operational,
                    $company,
                    $revenue,
                    Revenue::class,
                    'inbound',
                    $mapping,
                    $issues,
                    $sourceRecords,
                );

                continue;
            }

            $controlLines = $journal->lines
                ->where('financial_account_id', $mapping->financial_account_id)
                ->filter(fn (JournalLine $line): bool => bccomp((string) ($line->base_debit ?? '0'), '0', 4) > 0);

            $isReceivable = $revenue->receivable_account_id !== null || $controlLines->isNotEmpty();
            if (! $isReceivable && $revenue->cash_account_id === null) {
                $assetDebitLines = $journal->lines
                    ->filter(fn (JournalLine $line): bool => bccomp((string) ($line->base_debit ?? '0'), '0', 4) > 0)
                    ->filter(fn (JournalLine $line): bool => strtolower((string) $line->financialAccount?->account_type) === 'asset');

                if ($assetDebitLines->isNotEmpty()) {
                    $issues[] = $this->sourceIssue(Revenue::class, $revenue->getKey(), 'revenue_debit_account_not_mapped');
                    $limitations[] = 'Revenue records do not persist whether the debit account represents receivables or cash; unmapped asset debits remain unresolved.';
                }

                continue;
            }

            if (! $isReceivable) {
                continue;
            }

            if ((int) $revenue->receivable_account_id !== (int) $mapping->financial_account_id && $controlLines->isEmpty()) {
                $issues[] = $this->sourceIssue(Revenue::class, $revenue->getKey(), 'revenue_receivable_account_not_mapped', [
                    'account_id' => (int) $revenue->receivable_account_id,
                ]);
            }

            $recognizedRevenueIds[] = (int) $revenue->getKey();
            $this->appendDocumentAmount($operational, $revenue, (string) $revenue->base_total, (string) $revenue->total);
            $sourceRecords[] = $this->sourceRecord(Revenue::class, $revenue->getKey(), $revenue->revenue_number);

            $this->checkPartyDimension($controlLines, $revenue->party_id, $company, $issues, Revenue::class, $revenue->getKey());
            $this->appendActivePayments(
                $operational,
                $company,
                $revenue,
                Revenue::class,
                'inbound',
                $mapping,
                $issues,
                $sourceRecords,
            );
        }

        $returns = CustomerReturn::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereIn('original_revenue_id', $recognizedRevenueIds)
            ->orderBy('id')
            ->get();

        foreach ($returns as $return) {
            $journals = $this->sourceJournals(CustomerReturn::class, (int) $return->getKey());
            $journal = $this->activeSourceJournal($journals, $company);
            if ($journal === null) {
                $issues[] = $this->sourceIssue(CustomerReturn::class, $return->getKey(), 'posted_customer_return_missing_or_inconsistent_journal');
                if (
                    (int) $return->refund_account_id === (int) $mapping->financial_account_id
                    || (int) $return->refund_account_id === (int) $return->originalRevenue?->receivable_account_id
                ) {
                    $this->appendDocumentAmount($operational, $return, bcsub('0', (string) $return->base_total, 4), bcsub('0', (string) $return->total, 4));
                    $sourceRecords[] = $this->sourceRecord(CustomerReturn::class, $return->getKey(), $return->return_number);
                }

                continue;
            }

            $controlLines = $journal->lines
                ->where('financial_account_id', $mapping->financial_account_id)
                ->filter(fn (JournalLine $line): bool => bccomp((string) ($line->base_credit ?? '0'), '0', 4) > 0);

            $originalRevenue = Revenue::withoutGlobalScope(CompanyScope::class)->find($return->original_revenue_id);
            if (
                (int) $return->refund_account_id === (int) $mapping->financial_account_id
                || (int) $return->refund_account_id === (int) $originalRevenue?->receivable_account_id
                || $controlLines->isNotEmpty()
            ) {
                if ($controlLines->isEmpty()) {
                    $issues[] = $this->sourceIssue(CustomerReturn::class, $return->getKey(), 'customer_return_receivable_account_not_mapped', [
                        'account_id' => (int) $return->refund_account_id,
                    ]);
                }
                $this->appendDocumentAmount($operational, $return, bcsub('0', (string) $return->base_total, 4), bcsub('0', (string) $return->total, 4));
                $this->checkPartyDimension($controlLines, $return->party_id, $company, $issues, CustomerReturn::class, $return->getKey());
                $sourceRecords[] = $this->sourceRecord(CustomerReturn::class, $return->getKey(), $return->return_number);
            }
        }

        return $this->completeReport(
            $company,
            'customer_receivables',
            $mapping,
            'asset',
            $operational,
            $issues,
            $limitations,
            $sourceRecords,
        );
    }

    public function reconcilePayables(Company $company): array
    {
        $mapping = $this->mapping($company, CompanyAccountMapping::SUPPLIER_PAYABLES);
        if ($mapping === null) {
            return $this->unsupported($company, 'supplier_payables', 'No supplier payables control account is configured.');
        }

        $issues = [];
        $limitations = [];
        $sourceRecords = [];
        $operational = $this->emptyTotals();

        $purchases = Purchase::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->orderBy('id')
            ->get();

        foreach ($purchases as $purchase) {
            $journals = $this->sourceJournals(Purchase::class, (int) $purchase->getKey());
            if ($this->isReversed($journals, $company)) {
                continue;
            }
            $journal = $this->activeSourceJournal($journals, $company);
            if ($journal === null) {
                $issues[] = $this->sourceIssue(Purchase::class, $purchase->getKey(), 'posted_purchase_missing_or_inconsistent_journal');

                if ($purchase->payable_account_id === null) {
                    $issues[] = $this->sourceIssue(Purchase::class, $purchase->getKey(), 'purchase_payable_account_unresolved');
                    $limitations[] = 'Posted purchases without a persisted payable account cannot be assigned to a control account.';
                    $sourceRecords[] = $this->sourceRecord(Purchase::class, $purchase->getKey(), $purchase->purchase_number);

                    continue;
                }

                if ((int) $purchase->payable_account_id !== (int) $mapping->financial_account_id) {
                    $issues[] = $this->sourceIssue(Purchase::class, $purchase->getKey(), 'purchase_payable_account_not_mapped', [
                        'account_id' => (int) $purchase->payable_account_id,
                    ]);
                    $limitations[] = 'Posted purchases assigned to another payable account are unresolved for this control-account reconciliation.';
                    $sourceRecords[] = $this->sourceRecord(Purchase::class, $purchase->getKey(), $purchase->purchase_number);

                    continue;
                }

                $this->appendDocumentAmount($operational, $purchase, (string) $purchase->base_total, (string) $purchase->total);
                $sourceRecords[] = $this->sourceRecord(Purchase::class, $purchase->getKey(), $purchase->purchase_number);
                $this->appendActivePayments(
                    $operational,
                    $company,
                    $purchase,
                    Purchase::class,
                    'outbound',
                    $mapping,
                    $issues,
                    $sourceRecords,
                );

                continue;
            }

            $payableLines = $journal->lines
                ->filter(fn (JournalLine $line): bool => strtolower((string) $line->financialAccount?->account_type) === 'liability')
                ->filter(fn (JournalLine $line): bool => bccomp((string) ($line->base_credit ?? '0'), '0', 4) > 0);
            $controlLines = $payableLines->where('financial_account_id', $mapping->financial_account_id);

            if ($controlLines->isEmpty()) {
                $issues[] = $this->sourceIssue(Purchase::class, $purchase->getKey(), 'purchase_payable_account_not_mapped', [
                    'account_ids' => $payableLines->pluck('financial_account_id')->unique()->values()->all(),
                ]);
            }

            $this->appendDocumentAmount($operational, $purchase, (string) $purchase->base_total, (string) $purchase->total);
            $this->checkPartyDimension($controlLines, $purchase->party_id, $company, $issues, Purchase::class, $purchase->getKey());
            $sourceRecords[] = $this->sourceRecord(Purchase::class, $purchase->getKey(), $purchase->purchase_number);
            $this->appendActivePayments(
                $operational,
                $company,
                $purchase,
                Purchase::class,
                'outbound',
                $mapping,
                $issues,
                $sourceRecords,
            );
        }

        $returns = SupplierReturn::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->orderBy('id')
            ->get();

        foreach ($returns as $return) {
            $journals = $this->sourceJournals(SupplierReturn::class, (int) $return->getKey());
            $journal = $this->activeSourceJournal($journals, $company);
            if ($journal === null) {
                $issues[] = $this->sourceIssue(SupplierReturn::class, $return->getKey(), 'posted_supplier_return_missing_or_inconsistent_journal');
                $this->appendDocumentAmount($operational, $return, bcsub('0', (string) $return->base_total, 4), bcsub('0', (string) $return->total, 4));
                $sourceRecords[] = $this->sourceRecord(SupplierReturn::class, $return->getKey(), $return->return_number);

                continue;
            }

            $controlLines = $journal->lines
                ->where('financial_account_id', $mapping->financial_account_id)
                ->filter(fn (JournalLine $line): bool => bccomp((string) ($line->base_debit ?? '0'), '0', 4) > 0);
            $this->appendDocumentAmount($operational, $return, bcsub('0', (string) $return->base_total, 4), bcsub('0', (string) $return->total, 4));
            if (
                (int) $return->payable_account_id !== (int) $mapping->financial_account_id
                || $controlLines->isEmpty()
            ) {
                $issues[] = $this->sourceIssue(SupplierReturn::class, $return->getKey(), 'supplier_return_payable_account_not_mapped', [
                    'account_id' => (int) $return->payable_account_id,
                ]);
            }
            $this->checkPartyDimension($controlLines, $return->party_id, $company, $issues, SupplierReturn::class, $return->getKey());
            $sourceRecords[] = $this->sourceRecord(SupplierReturn::class, $return->getKey(), $return->return_number);
        }

        $expenses = Expense::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereNull('payment_financial_account_id')
            ->whereNotNull('payable_account_id')
            ->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            $journals = $this->sourceJournals(Expense::class, (int) $expense->getKey());
            $journal = $this->activeSourceJournal($journals, $company);

            if (
                $journal === null
                || (int) $expense->accounting_journal_id !== (int) $journal->getKey()
            ) {
                $issues[] = $this->sourceIssue(Expense::class, $expense->getKey(), 'posted_payable_expense_missing_or_inconsistent_journal');
            } else {
                $controlLines = $journal->lines()
                    ->where('financial_account_id', $mapping->financial_account_id)
                    ->whereRaw('base_credit > 0')
                    ->get();
                if ((int) $expense->payable_account_id !== (int) $mapping->financial_account_id || $controlLines->isEmpty()) {
                    $issues[] = $this->sourceIssue(Expense::class, $expense->getKey(), 'expense_payable_account_not_mapped', [
                        'account_id' => (int) $expense->payable_account_id,
                    ]);
                }
                $this->checkPartyDimension($controlLines, $expense->party_id, $company, $issues, Expense::class, $expense->getKey());
            }

            $this->appendDocumentAmount($operational, $expense, (string) $expense->base_amount, (string) $expense->amount);
            $sourceRecords[] = $this->sourceRecord(Expense::class, $expense->getKey(), $expense->expense_number);
            $this->appendActivePayments(
                $operational,
                $company,
                $expense,
                Expense::class,
                'outbound',
                $mapping,
                $issues,
                $sourceRecords,
            );
        }

        return $this->completeReport(
            $company,
            'supplier_payables',
            $mapping,
            'liability',
            $operational,
            $issues,
            $limitations,
            $sourceRecords,
        );
    }

    private function mapping(Company $company, string $purpose): ?CompanyAccountMapping
    {
        return CompanyAccountMapping::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('purpose', $purpose)
            ->with('financialAccount')
            ->first();
    }

    private function unsupported(Company $company, string $category, string $reason): array
    {
        return [
            'status' => 'unsupported',
            'supported' => false,
            'company_id' => (int) $company->getKey(),
            'category' => $category,
            'control_account' => null,
            'operational_balance' => null,
            'general_ledger_balance' => null,
            'expected_amount' => null,
            'actual_amount' => null,
            'difference' => null,
            'currency' => strtoupper((string) $company->base_currency),
            'native_balances' => [],
            'source_records' => [],
            'party_balances' => [],
            'missing_mappings' => [$category],
            'discrepancies' => [],
            'difference_classification' => 'unsupported_comparison',
            'timing_differences_supported' => false,
            'timing_limitation' => 'The reconciliation has no as-of date or period parameter, so timing differences cannot be isolated.',
            'limitations' => [$reason],
        ];
    }

    /**
     * @param  array<string, mixed>  $operational
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<int, string>  $limitations
     * @param  array<int, array<string, mixed>>  $sourceRecords
     * @return array<string, mixed>
     */
    private function completeReport(
        Company $company,
        string $category,
        CompanyAccountMapping $mapping,
        string $accountType,
        array $operational,
        array $issues,
        array $limitations,
        array $sourceRecords,
    ): array {
        $account = $mapping->financialAccount;
        if (
            $account === null
            || (int) $account->company_id !== (int) $company->getKey()
            || strtolower((string) $account->account_type) !== $accountType
            || strtoupper((string) $account->currency) !== strtoupper((string) $company->base_currency)
        ) {
            return $this->unsupported($company, $category, 'The configured control account no longer satisfies company, account-type, or base-currency requirements.');
        }

        $lines = JournalLine::query()
            ->where('financial_account_id', $account->getKey())
            ->whereHas('journal', fn ($query) => $query
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('status', 'posted'))
            ->with(['journal', 'party'])
            ->orderBy('accounting_journal_id')
            ->orderBy('line_number')
            ->get();

        $sign = $accountType === 'asset' ? 1 : -1;
        $generalLedger = '0.0000';
        $generalLedgerByCurrency = [];
        $generalLedgerByParty = [];
        $hasUnassignedPartyLines = false;

        foreach ($lines as $line) {
            if (
                $line->base_debit === null
                || $line->base_credit === null
                || strtoupper((string) $line->base_currency) !== strtoupper((string) $company->base_currency)
            ) {
                $issues[] = $this->sourceIssue(
                    $line->journal?->sourceable_type ?? AccountingJournal::class,
                    $line->journal?->sourceable_id ?? $line->accounting_journal_id,
                    'control_account_line_missing_company_base_amount',
                    ['journal_id' => (int) $line->accounting_journal_id, 'line_id' => (int) $line->getKey()],
                );

                continue;
            }

            $lineBalance = $sign === 1
                ? bcsub((string) $line->base_debit, (string) $line->base_credit, 4)
                : bcsub((string) $line->base_credit, (string) $line->base_debit, 4);
            $generalLedger = bcadd($generalLedger, $lineBalance, 4);

            $currency = strtoupper((string) $line->currency);
            $nativeBalance = $sign === 1
                ? bcsub((string) $line->debit, (string) $line->credit, 4)
                : bcsub((string) $line->credit, (string) $line->debit, 4);
            $generalLedgerByCurrency[$currency] = bcadd($generalLedgerByCurrency[$currency] ?? '0.0000', $nativeBalance, 4);

            $party = $line->party;
            if ($line->party_id === null || $party === null || (int) $party->company_id !== (int) $company->getKey()) {
                $hasUnassignedPartyLines = true;

                continue;
            }

            $partyId = (int) $line->party_id;
            $generalLedgerByParty[$partyId] = bcadd($generalLedgerByParty[$partyId] ?? '0.0000', $lineBalance, 4);
        }

        $difference = bcsub($operational['base'], $generalLedger, 4);
        $nativeBalances = [];
        foreach (array_unique(array_merge(array_keys($operational['native']), array_keys($generalLedgerByCurrency))) as $currency) {
            $nativeOperational = $operational['native'][$currency] ?? '0.0000';
            $nativeGeneralLedger = $generalLedgerByCurrency[$currency] ?? '0.0000';
            $nativeBalances[$currency] = [
                'currency' => $currency,
                'operational_balance' => $nativeOperational,
                'general_ledger_balance' => $nativeGeneralLedger,
                'difference' => bcsub($nativeOperational, $nativeGeneralLedger, 4),
            ];
        }

        $partyBalances = [];
        foreach (array_unique(array_merge(array_keys($operational['parties']), array_keys($generalLedgerByParty))) as $partyId) {
            $party = Party::withoutGlobalScope(CompanyScope::class)->find($partyId);
            $partyOperational = $operational['parties'][$partyId]['base'] ?? '0.0000';
            $partyGeneralLedger = $generalLedgerByParty[$partyId] ?? '0.0000';
            $partyBalances[] = [
                'party_id' => (int) $partyId,
                'party_name' => $party?->name,
                'operational_balance' => $partyOperational,
                'general_ledger_balance' => $hasUnassignedPartyLines ? null : $partyGeneralLedger,
                'difference' => $hasUnassignedPartyLines ? null : bcsub($partyOperational, $partyGeneralLedger, 4),
                'native_balances' => $operational['parties'][$partyId]['native'] ?? [],
                'source_document_ids' => array_values(array_unique($operational['parties'][$partyId]['source_ids'] ?? [])),
            ];
        }

        if ($hasUnassignedPartyLines) {
            $limitations[] = 'One or more control-account journal lines do not preserve a valid party dimension; party-level General Ledger balances are not reported.';
            $issues[] = [
                'issue' => 'control_account_party_dimension_missing',
                'account_id' => (int) $account->getKey(),
            ];
        }

        $status = $issues !== [] || $limitations !== []
            ? 'incomplete'
            : (bccomp($difference, '0', 4) === 0 ? 'consistent' : 'discrepant');

        return [
            'status' => $status,
            'supported' => true,
            'company_id' => (int) $company->getKey(),
            'category' => $category,
            'control_account' => [
                'account_id' => (int) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
            ],
            'operational_balance' => $operational['base'],
            'general_ledger_balance' => $generalLedger,
            'expected_amount' => $operational['base'],
            'actual_amount' => $generalLedger,
            'difference' => $difference,
            'currency' => strtoupper((string) $company->base_currency),
            'native_balances' => $nativeBalances,
            'party_balances' => $partyBalances,
            'source_records' => $sourceRecords,
            'missing_mappings' => [],
            'discrepancies' => $issues,
            'difference_classification' => $issues !== []
                ? 'incomplete_data'
                : (bccomp($difference, '0', 4) === 0 ? 'no_difference' : 'accounting_discrepancy'),
            'timing_differences_supported' => false,
            'timing_limitation' => 'The reconciliation has no as-of date or period parameter, so timing differences cannot be isolated.',
            'limitations' => $limitations,
        ];
    }

    private function emptyTotals(): array
    {
        return ['base' => '0.0000', 'native' => [], 'parties' => []];
    }

    private function appendDocumentAmount(array &$totals, object $document, string $base, string $native): void
    {
        $currency = strtoupper((string) ($document->currency ?? $document->base_currency));
        $base = $this->normalizeAmount($base);
        $native = $this->normalizeAmount($native);
        $totals['base'] = bcadd($totals['base'], $base, 4);
        $totals['native'][$currency] = bcadd($totals['native'][$currency] ?? '0.0000', $native, 4);

        if ($document->party_id === null) {
            return;
        }

        $partyId = (int) $document->party_id;
        $totals['parties'][$partyId] ??= ['base' => '0.0000', 'native' => [], 'source_ids' => []];
        $totals['parties'][$partyId]['base'] = bcadd($totals['parties'][$partyId]['base'], $base, 4);
        $totals['parties'][$partyId]['native'][$currency] = bcadd(
            $totals['parties'][$partyId]['native'][$currency] ?? '0.0000',
            $native,
            4,
        );
        $totals['parties'][$partyId]['source_ids'][] = (int) $document->getKey();
    }

    private function appendActivePayments(
        array &$totals,
        Company $company,
        object $document,
        string $sourceType,
        string $direction,
        CompanyAccountMapping $mapping,
        array &$issues,
        array &$sourceRecords,
    ): void {
        $payments = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('payable_type', $sourceType)
            ->where('payable_id', $document->getKey())
            ->whereIn('status', ['posted', 'reversed'])
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            if ($payment->status === 'reversed' || $payment->reversal_of_payment_id !== null) {
                continue;
            }

            $journals = $this->sourceJournals(Payment::class, (int) $payment->getKey());
            $journal = $this->activeSourceJournal($journals, $company);
            if (
                $journal === null
                || $payment->posted_at === null
                || $payment->direction !== $direction
                || $payment->reversed_by_payment_id !== null
                || (int) $payment->company_id !== (int) $company->getKey()
            ) {
                $issues[] = $this->sourceIssue(Payment::class, $payment->getKey(), 'posted_payment_missing_or_inconsistent_journal');

                continue;
            }

            $accountLines = $journal->lines->where('financial_account_id', $mapping->financial_account_id);
            $hasExpectedDirection = $accountLines->contains(fn (JournalLine $line): bool => $direction === 'inbound'
                ? bccomp((string) ($line->base_credit ?? '0'), '0', 4) > 0
                : bccomp((string) ($line->base_debit ?? '0'), '0', 4) > 0);
            if (! $hasExpectedDirection) {
                $issues[] = $this->sourceIssue(Payment::class, $payment->getKey(), 'payment_control_account_not_mapped');
            }

            $baseAmount = (string) ($payment->applied_base_amount ?? $payment->base_amount ?? '0');
            $appliedCurrency = strtoupper((string) ($payment->applied_currency ?? $document->currency));
            $sourceCurrency = strtoupper((string) $document->currency);
            $documentRate = (string) ($document->exchange_rate ?? '1');
            $nativeAmount = $sourceCurrency === $appliedCurrency
                ? (string) ($payment->applied_amount ?? $payment->amount)
                : (bccomp($documentRate, '0', 10) > 0 ? bcdiv($baseAmount, $documentRate, 4) : '0.0000');

            $signedBase = bcsub('0', $this->normalizeAmount($baseAmount), 4);
            $signedNative = bcsub('0', $this->normalizeAmount($nativeAmount), 4);
            $this->appendDocumentAmount($totals, $document, $signedBase, $signedNative);
            $sourceRecords[] = $this->sourceRecord(Payment::class, $payment->getKey(), $payment->payment_number);

            $this->checkPartyDimension($accountLines, $document->party_id, $company, $issues, Payment::class, $payment->getKey());
        }
    }

    private function sourceJournals(string $sourceType, int $sourceId): Collection
    {
        return AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', $sourceType)
            ->where('sourceable_id', $sourceId)
            ->with(['lines.financialAccount', 'lines.party'])
            ->orderBy('id')
            ->get();
    }

    private function activeSourceJournal(Collection $journals, Company $company): ?AccountingJournal
    {
        if (
            $journals->count() !== 1
            || $journals->first()->status !== 'posted'
            || $journals->first()->posted_at === null
            || (int) $journals->first()->company_id !== (int) $company->getKey()
            || $journals->first()->reversed_by_journal_id !== null
        ) {
            return null;
        }

        return $journals->first();
    }

    private function isReversed(Collection $journals, Company $company): bool
    {
        return $journals->count() === 1
            && $journals->first()->status === 'posted'
            && (int) $journals->first()->company_id === (int) $company->getKey()
            && $journals->first()->reversed_by_journal_id !== null;
    }

    private function checkPartyDimension(
        Collection $lines,
        ?int $expectedPartyId,
        Company $company,
        array &$issues,
        string $sourceType,
        int $sourceId,
    ): void {
        foreach ($lines as $line) {
            $party = $line->party;
            if (
                $expectedPartyId === null
                || (int) $line->party_id !== (int) $expectedPartyId
                || $party === null
                || (int) $party->company_id !== (int) $company->getKey()
            ) {
                $issues[] = $this->sourceIssue($sourceType, $sourceId, 'source_journal_party_dimension_missing_or_inconsistent', [
                    'journal_id' => (int) $line->accounting_journal_id,
                    'line_id' => (int) $line->getKey(),
                    'party_id' => $line->party_id === null ? null : (int) $line->party_id,
                ]);
            }
        }
    }

    private function sourceIssue(string $sourceType, int $sourceId, string $issue, array $context = []): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'issue' => $issue,
            ...$context,
        ];
    }

    private function sourceRecord(string $sourceType, int $sourceId, ?string $number): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => (int) $sourceId,
            'document_number' => $number,
        ];
    }

    private function normalizeAmount(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', 4);
    }
}
