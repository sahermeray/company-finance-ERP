<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FinancialReportingService
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function trialBalance(Company $company, string $fromDate, string $toDate): array
    {
        [$fromDate, $toDate] = $this->validatedRange($fromDate, $toDate);
        $accounts = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (FinancialAccount $account): int => (int) $account->getKey());
        $activity = [];

        foreach ($accounts as $accountId => $account) {
            $activity[$accountId] = [
                'account' => $account,
                'opening_net' => '0.0000',
                'period_debit' => '0.0000',
                'period_credit' => '0.0000',
                'native' => [],
            ];
        }

        $issues = $this->postedJournalTimestampIssues($company, $toDate);
        $unresolved = [];
        $warnings = $this->postedJournalTimestampIssues($company, $toDate);

        foreach ($this->postedLines($company, $toDate) as $line) {
            $account = $line->financialAccount;
            $journal = $line->journal;

            if ($account === null || (int) $account->company_id !== (int) $company->getKey()) {
                $unresolved[] = [
                    'issue' => 'journal_line_account_missing_or_foreign',
                    'journal_id' => (int) $line->accounting_journal_id,
                    'line_id' => (int) $line->getKey(),
                    'account_id' => (int) $line->financial_account_id,
                ];

                continue;
            }

            if (
                $line->base_debit === null
                || $line->base_credit === null
                || strtoupper((string) $line->base_currency) !== strtoupper((string) $company->base_currency)
            ) {
                $unresolved[] = [
                    'issue' => 'journal_line_missing_company_base_amount',
                    'journal_id' => (int) $line->accounting_journal_id,
                    'line_id' => (int) $line->getKey(),
                ];

                continue;
            }

            $accountId = (int) $account->getKey();
            $date = $journal->entry_date->toDateString();
            $currency = strtoupper((string) $line->currency);
            $isOpening = $date < $fromDate;
            $baseNet = bcsub((string) $line->base_debit, (string) $line->base_credit, 4);
            $hasNativeAmounts = $line->debit !== null
                && $line->credit !== null
                && preg_match('/^[A-Z]{3}$/', $currency) === 1;
            if (! $hasNativeAmounts) {
                $unresolved[] = [
                    'issue' => 'journal_line_native_amount_or_currency_missing',
                    'journal_id' => (int) $journal->getKey(),
                    'line_id' => (int) $line->getKey(),
                ];
            }

            if (! isset($activity[$accountId])) {
                $activity[$accountId] = [
                    'account' => $account,
                    'opening_net' => '0.0000',
                    'period_debit' => '0.0000',
                    'period_credit' => '0.0000',
                    'native' => [],
                ];
            }

            if ($isOpening) {
                $activity[$accountId]['opening_net'] = bcadd($activity[$accountId]['opening_net'], $baseNet, 4);
                if ($hasNativeAmounts) {
                    $activity[$accountId]['native'][$currency] ??= [
                        'opening_net' => '0.0000',
                        'period_debit' => '0.0000',
                        'period_credit' => '0.0000',
                    ];
                    $activity[$accountId]['native'][$currency]['opening_net'] = bcadd(
                        $activity[$accountId]['native'][$currency]['opening_net'],
                        bcsub((string) $line->debit, (string) $line->credit, 4),
                        4,
                    );
                }

                continue;
            }

            $activity[$accountId]['period_debit'] = bcadd(
                $activity[$accountId]['period_debit'],
                (string) $line->base_debit,
                4,
            );
            $activity[$accountId]['period_credit'] = bcadd(
                $activity[$accountId]['period_credit'],
                (string) $line->base_credit,
                4,
            );
            if ($hasNativeAmounts) {
                $activity[$accountId]['native'][$currency] ??= [
                    'opening_net' => '0.0000',
                    'period_debit' => '0.0000',
                    'period_credit' => '0.0000',
                ];
                $activity[$accountId]['native'][$currency]['period_debit'] = bcadd(
                    $activity[$accountId]['native'][$currency]['period_debit'],
                    (string) $line->debit,
                    4,
                );
                $activity[$accountId]['native'][$currency]['period_credit'] = bcadd(
                    $activity[$accountId]['native'][$currency]['period_credit'],
                    (string) $line->credit,
                    4,
                );
            }
        }

        $accountReports = [];
        $baseTotals = $this->emptyBalanceSections();
        $nativeTotals = [];

        foreach ($activity as $accountActivity) {
            $account = $accountActivity['account'];
            $opening = $this->balanceSides($accountActivity['opening_net']);
            $periodNet = bcsub($accountActivity['period_debit'], $accountActivity['period_credit'], 4);
            $closing = $this->balanceSides(bcadd($accountActivity['opening_net'], $periodNet, 4));
            $nativeBalances = [];

            foreach ($accountActivity['native'] as $currency => $nativeActivity) {
                $nativeBalances[$currency] = [
                    'opening' => $this->balanceSides($nativeActivity['opening_net']),
                    'period' => [
                        'debit' => $nativeActivity['period_debit'],
                        'credit' => $nativeActivity['period_credit'],
                    ],
                    'closing' => $this->balanceSides(bcadd(
                        $nativeActivity['opening_net'],
                        bcsub($nativeActivity['period_debit'], $nativeActivity['period_credit'], 4),
                        4,
                    )),
                ];
            }
            ksort($nativeBalances);

            $accountReport = [
                'account_id' => (int) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'statement_classification' => $account->statement_classification,
                'opening' => $opening,
                'period' => [
                    'debit' => $accountActivity['period_debit'],
                    'credit' => $accountActivity['period_credit'],
                ],
                'closing' => $closing,
                'native_balances' => $nativeBalances,
            ];
            $accountReports[] = $accountReport;
            $this->addBalanceSides($baseTotals['opening'], $opening);
            $baseTotals['period']['debit'] = bcadd($baseTotals['period']['debit'], $accountActivity['period_debit'], 4);
            $baseTotals['period']['credit'] = bcadd($baseTotals['period']['credit'], $accountActivity['period_credit'], 4);
            $this->addBalanceSides($baseTotals['closing'], $closing);

            foreach ($nativeBalances as $currency => $balances) {
                $nativeTotals[$currency] ??= $this->emptyBalanceSections();
                $this->addBalanceSides($nativeTotals[$currency]['opening'], $balances['opening']);
                $nativeTotals[$currency]['period']['debit'] = bcadd(
                    $nativeTotals[$currency]['period']['debit'],
                    $balances['period']['debit'],
                    4,
                );
                $nativeTotals[$currency]['period']['credit'] = bcadd(
                    $nativeTotals[$currency]['period']['credit'],
                    $balances['period']['credit'],
                    4,
                );
                $this->addBalanceSides($nativeTotals[$currency]['closing'], $balances['closing']);
            }
        }
        ksort($nativeTotals);

        return [
            'company_id' => (int) $company->getKey(),
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'base_currency' => strtoupper((string) $company->base_currency),
            'accounts' => $accountReports,
            'totals' => $baseTotals,
            'native_currency_totals' => $nativeTotals,
            'status' => $issues === [] && $unresolved === [] ? 'complete' : 'incomplete',
            'warnings' => $issues,
            'unresolved' => $unresolved,
        ];
    }

    public function generalLedger(
        Company $company,
        string $fromDate,
        string $toDate,
        ?int $accountId = null,
    ): array {
        [$fromDate, $toDate] = $this->validatedRange($fromDate, $toDate);
        if ($accountId !== null) {
            $accountExists = FinancialAccount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->whereKey($accountId)
                ->exists();
            if (! $accountExists) {
                throw ValidationException::withMessages([
                    'account_id' => 'The selected account does not belong to the selected company.',
                ]);
            }
        }

        $runningBase = [];
        $runningNative = [];
        $transactions = [];
        $unresolved = [];
        $warnings = $this->postedJournalTimestampIssues($company, $toDate);

        foreach ($this->postedLines($company, $toDate, $accountId) as $line) {
            $account = $line->financialAccount;
            $journal = $line->journal;

            if ($account === null || (int) $account->company_id !== (int) $company->getKey()) {
                $unresolved[] = [
                    'issue' => 'journal_line_account_missing_or_foreign',
                    'journal_id' => (int) $line->accounting_journal_id,
                    'line_id' => (int) $line->getKey(),
                ];

                continue;
            }

            if (
                $line->base_debit === null
                || $line->base_credit === null
                || strtoupper((string) $line->base_currency) !== strtoupper((string) $company->base_currency)
            ) {
                $unresolved[] = [
                    'issue' => 'journal_line_missing_company_base_amount',
                    'journal_id' => (int) $line->accounting_journal_id,
                    'line_id' => (int) $line->getKey(),
                ];

                continue;
            }

            $date = $journal->entry_date->toDateString();
            $currentAccountId = (int) $account->getKey();
            $currency = strtoupper((string) $line->currency);
            $hasNativeAmounts = $line->debit !== null
                && $line->credit !== null
                && preg_match('/^[A-Z]{3}$/', $currency) === 1;
            $runningBase[$currentAccountId] ??= '0.0000';
            $runningNative[$currentAccountId] ??= [];
            $runningBase[$currentAccountId] = bcadd(
                $runningBase[$currentAccountId],
                bcsub((string) $line->base_debit, (string) $line->base_credit, 4),
                4,
            );
            if ($hasNativeAmounts) {
                $runningNative[$currentAccountId][$currency] ??= '0.0000';
                $runningNative[$currentAccountId][$currency] = bcadd(
                    $runningNative[$currentAccountId][$currency],
                    bcsub((string) $line->debit, (string) $line->credit, 4),
                    4,
                );
            } else {
                $unresolved[] = [
                    'issue' => 'journal_line_native_amount_or_currency_missing',
                    'journal_id' => (int) $journal->getKey(),
                    'line_id' => (int) $line->getKey(),
                ];
            }

            if ($date < $fromDate) {
                continue;
            }

            $source = $journal->sourceable;
            $transactions[] = [
                'account_id' => $currentAccountId,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'accounting_date' => $date,
                'journal_id' => (int) $journal->getKey(),
                'journal_reference' => $journal->journal_number,
                'description' => $line->description ?? $journal->description,
                'debit' => $hasNativeAmounts ? (string) $line->debit : null,
                'credit' => $hasNativeAmounts ? (string) $line->credit : null,
                'currency' => $hasNativeAmounts ? $currency : null,
                'base_debit' => (string) $line->base_debit,
                'base_credit' => (string) $line->base_credit,
                'base_currency' => strtoupper((string) $company->base_currency),
                'running_balance_base' => $runningBase[$currentAccountId],
                'running_balance_native' => $hasNativeAmounts ? $runningNative[$currentAccountId][$currency] : null,
                'running_balances_by_currency' => $runningNative[$currentAccountId],
                'source' => [
                    'type' => $journal->sourceable_type,
                    'id' => $journal->sourceable_id === null ? null : (int) $journal->sourceable_id,
                    'reference' => $this->sourceReference($source),
                ],
            ];
        }

        return [
            'company_id' => (int) $company->getKey(),
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'base_currency' => strtoupper((string) $company->base_currency),
            'account_filter' => $accountId,
            'transactions' => $transactions,
            'status' => $warnings === [] && $unresolved === [] ? 'complete' : 'incomplete',
            'warnings' => $warnings,
            'unresolved' => $unresolved,
        ];
    }

    public function incomeStatement(
        Company $company,
        string $fromDate,
        string $toDate,
        ?string $comparisonFromDate = null,
        ?string $comparisonToDate = null,
    ): array {
        $trialBalance = $this->trialBalance($company, $fromDate, $toDate);
        $statement = $this->statementFromTrialBalance($company, $trialBalance);

        if (($comparisonFromDate === null) !== ($comparisonToDate === null)) {
            throw ValidationException::withMessages([
                'comparison_period' => 'Both comparison period dates must be provided together.',
            ]);
        }

        if ($comparisonFromDate !== null && $comparisonToDate !== null) {
            [$comparisonFromDate, $comparisonToDate] = $this->validatedRange($comparisonFromDate, $comparisonToDate);
            $comparison = $this->statementFromTrialBalance(
                $company,
                $this->trialBalance($company, $comparisonFromDate, $comparisonToDate),
            );
            foreach (['revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'other_income', 'other_expenses', 'net_profit'] as $metric) {
                $current = $statement['metrics'][$metric]['value'];
                $previous = $comparison['metrics'][$metric]['value'];
                $difference = $current === null || $previous === null
                    ? null
                    : bcsub($current, $previous, 4);
                $statement['metrics'][$metric]['comparison'] = [
                    'value' => $previous,
                    'difference' => $difference,
                    'percentage_change' => $previous === null || $current === null || bccomp($previous, '0', 4) === 0
                        ? null
                        : bcdiv(bcmul($difference, '100', 8), $this->absolute($previous), 4),
                    'period' => [
                        'from' => $comparisonFromDate,
                        'to' => $comparisonToDate,
                    ],
                ];
            }
        }

        return $statement;
    }

    public function incomeStatementTrend(Company $company, string $fromDate, string $toDate): array
    {
        [$fromDate, $toDate] = $this->validatedRange($fromDate, $toDate);
        $months = [];
        $cursor = CarbonImmutable::parse($fromDate)->startOfMonth();
        $lastMonth = CarbonImmutable::parse($toDate)->startOfMonth();
        while ($cursor <= $lastMonth) {
            $months[$cursor->format('Y-m')] = $this->emptyStatementAmounts();
            $cursor = $cursor->addMonth();
        }

        $accounts = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->get()
            ->keyBy(fn (FinancialAccount $account): int => (int) $account->getKey());
        $unresolved = [];
        $warnings = $this->postedJournalTimestampIssues($company, $toDate);

        foreach ($this->postedLines($company, $toDate) as $line) {
            $journal = $line->journal;
            $date = $journal->entry_date->toDateString();
            if ($date < $fromDate) {
                continue;
            }

            $account = $accounts->get((int) $line->financial_account_id);
            if ($account === null || ! $this->isProfitAndLossAccount($account)) {
                continue;
            }
            if (! $this->hasValidClassification($account)) {
                $unresolved[(int) $account->getKey()] = [
                    'issue' => 'profit_and_loss_account_unclassified',
                    'account_id' => (int) $account->getKey(),
                    'account_code' => $account->code,
                ];

                continue;
            }
            if (
                $line->base_debit === null
                || $line->base_credit === null
                || strtoupper((string) $line->base_currency) !== strtoupper((string) $company->base_currency)
            ) {
                $unresolved[] = [
                    'issue' => 'journal_line_missing_company_base_amount',
                    'journal_id' => (int) $journal->getKey(),
                    'line_id' => (int) $line->getKey(),
                ];

                continue;
            }

            $category = $account->statement_classification;
            $amount = $this->classifiedAmount($category, (string) $line->base_debit, (string) $line->base_credit);
            $monthKey = CarbonImmutable::parse($date)->format('Y-m');
            $months[$monthKey][$category] = bcadd($months[$monthKey][$category], $amount, 4);
        }

        foreach ($months as $month => &$amounts) {
            $amounts['gross_profit'] = bcsub($amounts['revenue'], $amounts['cost_of_goods_sold'], 4);
            $amounts['net_profit'] = bcadd(
                bcsub(
                    bcadd($amounts['gross_profit'], $amounts['other_income'], 4),
                    bcadd($amounts['operating_expense'], $amounts['other_expense'], 4),
                    4,
                ),
                '0',
                4,
            );
        }
        unset($amounts);

        return [
            'company_id' => (int) $company->getKey(),
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'base_currency' => strtoupper((string) $company->base_currency),
            'granularity' => 'month',
            'months' => $months,
            'status' => $unresolved === [] && $warnings === [] ? 'complete' : 'incomplete',
            'unresolved' => array_values($unresolved),
            'warnings' => $warnings,
        ];
    }

    public function balanceSheet(
        Company $company,
        string $asOfDate,
        ?string $currentPeriodStart = null,
    ): array {
        $asOfDate = $this->validatedDate($asOfDate);
        $currentPeriodStart ??= CarbonImmutable::parse($asOfDate)->startOfYear()->toDateString();
        [$currentPeriodStart, $asOfDate] = $this->validatedRange($currentPeriodStart, $asOfDate);
        $openingDate = '0001-01-01';
        $allActivity = $this->trialBalance($company, $openingDate, $asOfDate);
        $currentPeriod = $this->incomeStatement($company, $currentPeriodStart, $asOfDate);
        $allEarnings = $this->statementFromTrialBalance($company, $allActivity);
        $assets = [];
        $liabilities = [];
        $equityAccounts = [];
        $totalAssets = '0.0000';
        $totalLiabilities = '0.0000';
        $totalEquity = '0.0000';
        $unresolved = $allActivity['unresolved'];
        $warnings = $allActivity['warnings'];

        foreach ($allActivity['accounts'] as $account) {
            $accountType = strtolower((string) $account['account_type']);
            $debit = $account['closing']['debit'];
            $credit = $account['closing']['credit'];

            if ($accountType === 'asset') {
                $balance = bcsub($debit, $credit, 4);
                $assets[] = $this->balanceSheetAccount($account, $balance);
                $totalAssets = bcadd($totalAssets, $balance, 4);
            } elseif ($accountType === 'liability') {
                $balance = bcsub($credit, $debit, 4);
                $liabilities[] = $this->balanceSheetAccount($account, $balance);
                $totalLiabilities = bcadd($totalLiabilities, $balance, 4);
            } elseif ($accountType === 'equity') {
                $balance = bcsub($credit, $debit, 4);
                $equityAccounts[] = $this->balanceSheetAccount($account, $balance);
                $totalEquity = bcadd($totalEquity, $balance, 4);
            } elseif (
                $this->isProfitAndLossAccountType($accountType)
                && ! $this->hasValidClassificationValues($account['account_type'], $account['statement_classification'])
                && $this->hasMovement($account['period'])
            ) {
                $unresolved[] = [
                    'issue' => 'profit_and_loss_account_unclassified',
                    'account_id' => $account['account_id'],
                    'account_code' => $account['code'],
                ];
            }
        }

        $currentEarnings = $currentPeriod['metrics']['net_profit']['value'];
        $accumulatedEarnings = $allEarnings['metrics']['net_profit']['value'];
        $priorUnclosedEarnings = $currentEarnings === null || $accumulatedEarnings === null
            ? null
            : bcsub($accumulatedEarnings, $currentEarnings, 4);
        $totalEquity = bcadd($totalEquity, $accumulatedEarnings ?? '0.0000', 4);
        $difference = bcsub($totalAssets, bcadd($totalLiabilities, $totalEquity, 4), 4);

        return [
            'company_id' => (int) $company->getKey(),
            'as_of_date' => $asOfDate,
            'current_period' => ['from' => $currentPeriodStart, 'to' => $asOfDate],
            'base_currency' => strtoupper((string) $company->base_currency),
            'assets' => ['accounts' => $assets, 'total' => $totalAssets],
            'liabilities' => ['accounts' => $liabilities, 'total' => $totalLiabilities],
            'equity' => [
                'accounts' => $equityAccounts,
                'account_balances_total' => bcsub($totalEquity, $accumulatedEarnings ?? '0.0000', 4),
                'current_period_earnings' => $currentEarnings,
                'prior_unclosed_earnings' => $priorUnclosedEarnings,
                'total' => $totalEquity,
            ],
            'total_liabilities_and_equity' => bcadd($totalLiabilities, $totalEquity, 4),
            'equation_difference' => $difference,
            'equation_balances' => bccomp($difference, '0', 4) === 0 && $unresolved === [] && $warnings === [],
            'status' => $unresolved === [] && $warnings === []
                ? (bccomp($difference, '0', 4) === 0 ? 'balanced' : 'discrepant')
                : 'incomplete',
            'warnings' => $warnings,
            'unresolved' => $unresolved,
        ];
    }

    private function statementFromTrialBalance(Company $company, array $trialBalance): array
    {
        $amounts = $this->emptyStatementAmounts();
        $nativeAmounts = [];
        $unresolved = [];
        $unclassifiedTypes = [];

        foreach ($trialBalance['accounts'] as $account) {
            $classification = $account['statement_classification'];
            if (! $this->isProfitAndLossAccountType(strtolower((string) $account['account_type']))) {
                continue;
            }

            if (! $this->hasValidClassificationValues($account['account_type'], $classification)) {
                if (! $this->hasMovement($account['period'])) {
                    continue;
                }

                $unresolved[] = [
                    'issue' => 'profit_and_loss_account_unclassified',
                    'account_id' => $account['account_id'],
                    'account_code' => $account['code'],
                ];
                $unclassifiedTypes[strtolower((string) $account['account_type'])] = true;

                continue;
            }

            $amount = $this->classifiedAmount(
                $classification,
                $account['period']['debit'],
                $account['period']['credit'],
            );
            $amounts[$classification] = bcadd($amounts[$classification], $amount, 4);

            foreach ($account['native_balances'] as $currency => $native) {
                $nativeAmounts[$currency] ??= $this->emptyStatementAmounts();
                $nativeAmount = $this->classifiedAmount(
                    $classification,
                    $native['period']['debit'],
                    $native['period']['credit'],
                );
                $nativeAmounts[$currency][$classification] = bcadd(
                    $nativeAmounts[$currency][$classification],
                    $nativeAmount,
                    4,
                );
            }
        }

        $grossProfit = bcsub($amounts['revenue'], $amounts['cost_of_goods_sold'], 4);
        $netProfit = bcsub(
            bcadd($grossProfit, $amounts['other_income'], 4),
            bcadd($amounts['operating_expense'], $amounts['other_expense'], 4),
            4,
        );
        $margin = bccomp($amounts['revenue'], '0', 4) === 0
            ? null
            : bcdiv(bcmul($grossProfit, '100', 8), $amounts['revenue'], 4);
        $availability = $trialBalance['status'] === 'complete' && $unresolved === []
            ? 'available'
            : 'incomplete';
        $revenueValue = isset($unclassifiedTypes['revenue']) ? null : $amounts['revenue'];
        $expenseTypeUnclassified = isset($unclassifiedTypes['expense']);
        $otherIncomeValue = isset($unclassifiedTypes['income']) ? null : $amounts['other_income'];
        $grossProfitValue = $revenueValue === null || $expenseTypeUnclassified ? null : $grossProfit;
        $netProfitValue = $grossProfitValue === null || $otherIncomeValue === null
            ? null
            : $netProfit;

        return [
            'company_id' => (int) $company->getKey(),
            'period' => $trialBalance['period'],
            'base_currency' => strtoupper((string) $company->base_currency),
            'metrics' => [
                'revenue' => $this->metric($revenueValue, $availability),
                'cost_of_goods_sold' => $this->metric($expenseTypeUnclassified ? null : $amounts['cost_of_goods_sold'], $availability),
                'gross_profit' => $this->metric($grossProfitValue, $availability),
                'gross_margin_percentage' => $this->metric(
                    $revenueValue === null || $grossProfitValue === null ? null : $margin,
                    $availability,
                ),
                'operating_expenses' => $this->metric(
                    $expenseTypeUnclassified ? null : $amounts['operating_expense'],
                    $availability,
                ),
                'other_income' => $this->metric($otherIncomeValue, $availability),
                'other_expenses' => $this->metric($expenseTypeUnclassified ? null : $amounts['other_expense'], $availability),
                'net_profit' => $this->metric($netProfitValue, $availability),
            ],
            'native_currency_totals' => $nativeAmounts,
            'status' => $trialBalance['status'] === 'complete' && $unresolved === [] ? 'complete' : 'incomplete',
            'unresolved' => array_merge($trialBalance['unresolved'], $unresolved),
            'warnings' => $trialBalance['warnings'],
        ];
    }

    private function postedLines(Company $company, string $toDate, ?int $accountId = null): Collection
    {
        return $this->companyContext->runForCompany($company, function () use ($company, $toDate, $accountId): Collection {
            $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
                ->when($accountId !== null, fn ($query) => $query->where('financial_account_id', $accountId))
                ->whereHas('journal', fn ($query) => $query
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->where('status', 'posted')
                    ->whereNotNull('posted_at')
                    ->whereDate('entry_date', '<=', $toDate))
                ->with(['journal.sourceable', 'financialAccount', 'party'])
                ->orderBy('accounting_journal_id')
                ->orderBy('line_number')
                ->get();

            return $lines->sort(function (JournalLine $left, JournalLine $right): int {
                return [
                    $left->journal->entry_date->toDateString(),
                    (int) $left->accounting_journal_id,
                    (int) $left->line_number,
                ] <=> [
                    $right->journal->entry_date->toDateString(),
                    (int) $right->accounting_journal_id,
                    (int) $right->line_number,
                ];
            })->values();
        });
    }

    private function postedJournalTimestampIssues(Company $company, string $toDate): array
    {
        return AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->whereNull('posted_at')
            ->whereDate('entry_date', '<=', $toDate)
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (AccountingJournal $journal): array => [
                'issue' => 'posted_journal_missing_posted_at',
                'journal_id' => (int) $journal->getKey(),
            ])
            ->all();
    }

    private function validatedRange(string $fromDate, string $toDate): array
    {
        $fromDate = $this->validatedDate($fromDate);
        $toDate = $this->validatedDate($toDate);

        if ($fromDate > $toDate) {
            throw ValidationException::withMessages([
                'period' => 'The reporting period start must not be after its end.',
            ]);
        }

        return [$fromDate, $toDate];
    }

    private function validatedDate(string|DateTimeInterface $date): string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'date' => 'A valid reporting date is required.',
            ]);
        }
    }

    private function sourceReference(?object $source): ?string
    {
        if ($source === null) {
            return null;
        }

        foreach (['journal_number', 'revenue_number', 'purchase_number', 'expense_number', 'payment_number', 'return_number', 'cutover_number', 'adjustment_number'] as $field) {
            if (isset($source->{$field})) {
                return (string) $source->{$field};
            }
        }

        return null;
    }

    private function emptyStatementAmounts(): array
    {
        return [
            'revenue' => '0.0000',
            'cost_of_goods_sold' => '0.0000',
            'operating_expense' => '0.0000',
            'other_income' => '0.0000',
            'other_expense' => '0.0000',
        ];
    }

    private function isProfitAndLossAccount(FinancialAccount $account): bool
    {
        return $this->isProfitAndLossAccountType(strtolower((string) $account->account_type));
    }

    private function isProfitAndLossAccountType(string $accountType): bool
    {
        return in_array($accountType, ['revenue', 'income', 'expense'], true);
    }

    private function classifiedAmount(string $classification, string $debit, string $credit): string
    {
        return in_array($classification, ['revenue', 'other_income'], true)
            ? bcsub($credit, $debit, 4)
            : bcsub($debit, $credit, 4);
    }

    private function metric(?string $value, string $availability = 'available'): array
    {
        return [
            'value' => $value,
            'availability' => $value === null ? 'unavailable' : $availability,
            'source' => 'posted_general_ledger',
        ];
    }

    private function hasValidClassification(FinancialAccount $account): bool
    {
        return $this->hasValidClassificationValues($account->account_type, $account->statement_classification);
    }

    private function hasValidClassificationValues(?string $accountType, ?string $classification): bool
    {
        $validClassifications = match (strtolower((string) $accountType)) {
            'revenue' => ['revenue', 'other_income'],
            'income' => ['other_income'],
            'expense' => ['cost_of_goods_sold', 'operating_expense', 'other_expense'],
            default => [],
        };

        return $classification !== null && in_array($classification, $validClassifications, true);
    }

    private function balanceSides(string $net): array
    {
        return [
            'debit' => bccomp($net, '0', 4) > 0 ? $net : '0.0000',
            'credit' => bccomp($net, '0', 4) < 0 ? $this->absolute($net) : '0.0000',
        ];
    }

    private function hasMovement(array $period): bool
    {
        return bccomp($period['debit'], '0', 4) !== 0 || bccomp($period['credit'], '0', 4) !== 0;
    }

    private function addBalanceSides(array &$totals, array $amounts): void
    {
        $totals['debit'] = bcadd($totals['debit'], $amounts['debit'], 4);
        $totals['credit'] = bcadd($totals['credit'], $amounts['credit'], 4);
    }

    private function emptyBalanceSections(): array
    {
        return [
            'opening' => ['debit' => '0.0000', 'credit' => '0.0000'],
            'period' => ['debit' => '0.0000', 'credit' => '0.0000'],
            'closing' => ['debit' => '0.0000', 'credit' => '0.0000'],
        ];
    }

    private function balanceSheetAccount(array $account, string $balance): array
    {
        return [
            'account_id' => $account['account_id'],
            'code' => $account['code'],
            'name' => $account['name'],
            'balance' => $balance,
        ];
    }

    private function absolute(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }
}
