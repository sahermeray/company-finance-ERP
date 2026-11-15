<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCutover;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdvisorDataGateway
{
    private const MAX_PERIOD_DAYS = 366;

    private const MAX_RESULTS = 200;

    public function __construct(
        private readonly BusinessInsightsService $businessInsights,
        private readonly FinancialReportingService $financialReports,
        private readonly OperationalSubledgerReconciliationService $subledgers,
        private readonly AccountingReconciliationService $reconciliation,
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly CrossCompanyValidationService $crossCompanyValidation,
    ) {}

    public function companyOverview(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        $insights = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->businessInsights->insights($company, $fromDate, $toDate),
        );
        $unsupported = [
            $this->unsupportedMetric(
                'cash_and_bank',
                'No independent operational cashbook exists; General Ledger balances are not an operational cash/bank reconciliation.',
            ),
            $this->unsupportedMetric(
                'low_stock_indicators',
                'Products do not have a reliable minimum-stock or reorder-point setting.',
            ),
            $this->unsupportedMetric(
                'product_profitability',
                'The available sales data does not provide a complete, reliable product-level cost basis.',
            ),
            $this->unsupportedMetric(
                'unpaid_expenses_separate_total',
                'Payable expenses are included in supplier payables; the reconciliation service does not expose a distinct expense-only total.',
            ),
        ];

        foreach (['customer_receivables', 'supplier_payables'] as $category) {
            if ($insights['receivables_and_payables'][$category]['status'] === 'unsupported') {
                $unsupported[] = $this->unsupportedMetric(
                    $category,
                    implode(' ', $insights['receivables_and_payables'][$category]['limitations']),
                );
            }

        }

        $inventorySummary = $this->inventorySummary($insights['inventory']);
        $receivablesSummary = $this->subledgerSummary(
            $insights['receivables_and_payables']['customer_receivables'],
        );
        $payablesSummary = $this->subledgerSummary(
            $insights['receivables_and_payables']['supplier_payables'],
        );
        $data = [
            'company' => [
                'id' => (int) $company->getKey(),
                'name' => $company->name,
                'legal_name' => $company->legal_name,
            ],
            'financial_performance' => $insights['financial_performance'],
            'sales_and_purchases' => $insights['sales_and_purchases'],
            'inventory' => $inventorySummary,
            'receivables_and_payables' => [
                'customer_receivables' => $receivablesSummary,
                'supplier_payables' => $payablesSummary,
            ],
            'cash_and_bank' => $insights['cash_and_bank'],
        ];
        $warnings = $insights['warnings'];
        foreach ([$inventorySummary, $receivablesSummary, $payablesSummary] as $summary) {
            if ($summary['details_result']['has_more']) {
                $warnings[] = [
                    'issue' => 'result_limit_reached',
                    'limit' => self::MAX_RESULTS,
                    'message' => 'Some detail lists are truncated; request the corresponding analysis operation for a bounded result.',
                ];
            }
        }
        $status = $this->combinedStatus([
            $insights['financial_performance']['status'],
            $insights['sales_and_purchases']['sales_total']['status'],
            $insights['sales_and_purchases']['purchases_total']['status'],
            $insights['inventory']['status'],
            $insights['receivables_and_payables']['customer_receivables']['status'],
            $insights['receivables_and_payables']['supplier_payables']['status'],
        ], $warnings, $unsupported);

        return $this->response(
            $company,
            'company_overview',
            ['from' => $fromDate, 'to' => $toDate],
            $status,
            $data,
            $warnings,
            $unsupported,
            [
                ...$this->sourceReferencesFromSubledger($insights['receivables_and_payables']['customer_receivables']),
                ...$this->sourceReferencesFromSubledger($insights['receivables_and_payables']['supplier_payables']),
                ...$insights['sales_and_purchases']['source_references'],
            ],
            ['BusinessInsightsService', 'FinancialReportingService', 'AccountingReconciliationService'],
        );
    }

    public function trialBalance(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        $report = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->financialReports->trialBalance($company, $fromDate, $toDate),
        );
        $truncated = $this->boundReportList($report, 'accounts', 'account_results');
        $truncated = $this->boundReportList($report, 'unresolved', 'unresolved_results') || $truncated;
        $truncated = $this->boundReportList($report, 'warnings', 'warning_results') || $truncated;
        if ($truncated) {
            $report['status'] = 'incomplete';
            $report['warnings'][] = [
                'issue' => 'result_limit_reached',
                'limit' => self::MAX_RESULTS,
                'message' => 'One or more trial-balance detail lists are truncated.',
            ];
        }

        return $this->response(
            $company,
            'trial_balance',
            ['from' => $fromDate, 'to' => $toDate],
            $this->reportStatus($report['status']),
            $report,
            [...$report['warnings'], ...$report['unresolved']],
            [],
            [],
            ['FinancialReportingService', 'posted_general_ledger'],
        );
    }

    public function generalLedger(
        User $user,
        int $companyId,
        int $accountId,
        string $fromDate,
        string $toDate,
        int $limit = self::MAX_RESULTS,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        $this->validateLimit($limit);

        if (! FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->exists()) {
            throw ValidationException::withMessages([
                'account_id' => 'The selected account does not belong to the selected company.',
            ]);
        }

        $report = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->financialReports->generalLedger(
                $company,
                $fromDate,
                $toDate,
                $accountId,
            ),
        );
        $allTransactions = $report['transactions'];
        $transactions = array_slice($allTransactions, 0, $limit);
        $truncated = count($allTransactions) > $limit;
        $report['transactions'] = $transactions;
        $report['total_results'] = count($allTransactions);
        $report['returned_results'] = count($transactions);
        $report['has_more'] = $truncated;
        $truncated = $this->boundReportList($report, 'unresolved', 'unresolved_results') || $truncated;
        $truncated = $this->boundReportList($report, 'warnings', 'warning_results') || $truncated;
        $warnings = [...$report['warnings'], ...$report['unresolved']];

        if ($truncated) {
            $warnings[] = [
                'issue' => 'result_limit_reached',
                'limit' => $limit,
                'message' => 'The ledger result is truncated; request a narrower period to retrieve all transactions.',
            ];
        }

        $sourceReferences = [];
        foreach ($transactions as $transaction) {
            if ($transaction['source']['id'] !== null) {
                $sourceReferences[] = [
                    'type' => $transaction['source']['type'],
                    'id' => $transaction['source']['id'],
                    'reference' => $transaction['source']['reference'],
                ];
            }
        }

        return $this->response(
            $company,
            'general_ledger',
            ['from' => $fromDate, 'to' => $toDate],
            $truncated ? 'partially_available' : $this->reportStatus($report['status']),
            $report,
            $warnings,
            [],
            $sourceReferences,
            ['FinancialReportingService', 'posted_general_ledger'],
        );
    }

    public function incomeStatement(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
        ?string $comparisonFromDate = null,
        ?string $comparisonToDate = null,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);

        if (($comparisonFromDate === null) !== ($comparisonToDate === null)) {
            throw ValidationException::withMessages([
                'comparison_period' => 'Both comparison period dates must be provided together.',
            ]);
        }

        if ($comparisonFromDate === null || $comparisonToDate === null) {
            $periodDays = CarbonImmutable::parse($fromDate)->diffInDays(CarbonImmutable::parse($toDate)) + 1;
            $comparisonToDate = CarbonImmutable::parse($fromDate)->subDay()->toDateString();
            $comparisonFromDate = CarbonImmutable::parse($comparisonToDate)
                ->subDays($periodDays - 1)
                ->toDateString();
        }

        [$comparisonFromDate, $comparisonToDate] = $this->validatedPeriod(
            $comparisonFromDate,
            $comparisonToDate,
        );
        $report = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->financialReports->incomeStatement(
                $company,
                $fromDate,
                $toDate,
                $comparisonFromDate,
                $comparisonToDate,
            ),
        );
        $truncated = $this->boundReportList($report, 'unresolved', 'unresolved_results');
        $truncated = $this->boundReportList($report, 'warnings', 'warning_results') || $truncated;
        if ($truncated) {
            $report['status'] = 'incomplete';
            $report['warnings'][] = [
                'issue' => 'result_limit_reached',
                'limit' => self::MAX_RESULTS,
                'message' => 'One or more income-statement warning lists are truncated.',
            ];
        }

        return $this->response(
            $company,
            'income_statement',
            [
                'from' => $fromDate,
                'to' => $toDate,
                'comparison' => ['from' => $comparisonFromDate, 'to' => $comparisonToDate],
            ],
            $this->reportStatus($report['status']),
            $report,
            [...$report['warnings'], ...$report['unresolved']],
            [],
            [],
            ['FinancialReportingService', 'posted_general_ledger', 'stored_historical_base_amounts'],
        );
    }

    public function balanceSheet(
        User $user,
        int $companyId,
        string $asOfDate,
        ?string $currentPeriodStart = null,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        $asOfDate = $this->validatedDate($asOfDate);
        $currentPeriodStart ??= CarbonImmutable::parse($asOfDate)->startOfYear()->toDateString();
        [$currentPeriodStart, $asOfDate] = $this->validatedPeriod($currentPeriodStart, $asOfDate);
        $report = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->financialReports->balanceSheet(
                $company,
                $asOfDate,
                $currentPeriodStart,
            ),
        );
        $truncated = false;
        foreach (['assets', 'liabilities', 'equity'] as $section) {
            $accountCount = count($report[$section]['accounts']);
            if ($accountCount > self::MAX_RESULTS) {
                $report[$section]['accounts'] = array_slice(
                    $report[$section]['accounts'],
                    0,
                    self::MAX_RESULTS,
                );
                $report[$section]['account_results'] = [
                    'total_results' => $accountCount,
                    'returned_results' => self::MAX_RESULTS,
                    'has_more' => true,
                ];
                $truncated = true;
            }
        }
        $truncated = $this->boundReportList($report, 'unresolved', 'unresolved_results') || $truncated;
        $truncated = $this->boundReportList($report, 'warnings', 'warning_results') || $truncated;
        if ($truncated) {
            $report['status'] = 'incomplete';
            $report['warnings'][] = [
                'issue' => 'result_limit_reached',
                'limit' => self::MAX_RESULTS,
                'message' => 'One or more balance-sheet detail lists are truncated.',
            ];
        }

        return $this->response(
            $company,
            'balance_sheet',
            ['as_of' => $asOfDate, 'current_period_from' => $currentPeriodStart],
            $this->reportStatus($report['status']),
            $report,
            [...$report['warnings'], ...$report['unresolved']],
            [],
            [],
            ['FinancialReportingService', 'posted_general_ledger', 'stored_historical_base_amounts'],
        );
    }

    public function salesAndPurchasingAnalysis(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        $insights = $this->companyContext->runForCompany(
            $company,
            fn (): array => $this->businessInsights->insights($company, $fromDate, $toDate),
        );
        $data = $insights['sales_and_purchases'];
        $unsupported = [
            $this->unsupportedMetric(
                'product_profitability',
                'The available sales data does not provide a complete, reliable product-level cost basis.',
            ),
        ];
        $status = $this->combinedStatus(
            [
                $data['sales_total']['status'],
                $data['purchases_total']['status'],
                $data['sales_trend']['status'],
                $data['purchase_trend']['status'],
                $data['top_customers']['availability'],
                $data['top_products']['availability'],
            ],
            $data['warnings'],
            $unsupported,
        );

        return $this->response(
            $company,
            'sales_and_purchasing_analysis',
            ['from' => $fromDate, 'to' => $toDate],
            $status,
            $data,
            $data['warnings'],
            $unsupported,
            $data['source_references'],
            ['BusinessInsightsService', 'posted_source_documents', 'posted_general_ledger'],
        );
    }

    public function inventoryAnalysis(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
        ?int $productId = null,
        ?int $warehouseId = null,
        int $limit = self::MAX_RESULTS,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        $this->validateLimit($limit);
        $this->validateInventoryFilters($company, $productId, $warehouseId);

        [$inventory, $movements, $movementWarnings, $hasMore] = $this->companyContext->runForCompany(
            $company,
            function () use ($company, $fromDate, $toDate, $productId, $warehouseId, $limit): array {
                $reconciliation = $this->reconciliation->reconcile($company)['inventory'];
                $query = StockMovement::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->whereDate('occurred_at', '>=', $fromDate)
                    ->whereDate('occurred_at', '<=', $toDate)
                    ->when($productId !== null, fn ($builder) => $builder->where('product_id', $productId))
                    ->when($warehouseId !== null, fn ($builder) => $builder->where('warehouse_id', $warehouseId))
                    ->with([
                        'product:id,name,sku',
                        'warehouse:id,code,name',
                    ])
                    ->orderBy('occurred_at')
                    ->orderBy('id');
                $rows = $query->limit($limit + 1)->get();
                $hasMore = $rows->count() > $limit;
                $movementWarnings = [];
                $movements = [];

                foreach ($rows->take($limit) as $movement) {
                    try {
                        $this->crossCompanyValidation->validate($movement);
                    } catch (ValidationException $exception) {
                        $movementWarnings[] = [
                            'issue' => 'inventory_movement_relationship_inconsistent',
                            'movement_id' => (int) $movement->getKey(),
                            'details' => $exception->errors(),
                        ];

                        continue;
                    }

                    $movements[] = [
                        'movement_id' => (int) $movement->getKey(),
                        'product' => $movement->product === null ? null : [
                            'id' => (int) $movement->product->getKey(),
                            'sku' => $movement->product->sku,
                            'name' => $movement->product->name,
                        ],
                        'warehouse' => $movement->warehouse === null ? null : [
                            'id' => (int) $movement->warehouse->getKey(),
                            'code' => $movement->warehouse->code,
                            'name' => $movement->warehouse->name,
                        ],
                        'movement_type' => $movement->movement_type,
                        'direction' => $movement->direction,
                        'quantity' => (string) $movement->quantity,
                        'base_cost_amount' => $movement->base_cost_amount === null
                            ? null
                            : (string) $movement->base_cost_amount,
                        'base_unit_cost' => $movement->base_unit_cost === null
                            ? null
                            : (string) $movement->base_unit_cost,
                        'base_currency' => strtoupper((string) $company->base_currency),
                        'occurred_at' => $movement->occurred_at?->toIso8601String(),
                        'source' => [
                            'type' => $this->movementSourceType($movement->sourceable_type),
                            'id' => $movement->sourceable_id === null ? null : (int) $movement->sourceable_id,
                        ],
                    ];
                }

                return [$reconciliation, $movements, $movementWarnings, $hasMore];
            },
        );

        $productIds = collect($inventory['product_balances'])->pluck('product_id')->unique()->all();
        $warehouseIds = collect($inventory['warehouse_balances'])->pluck('warehouse_id')->unique()->all();
        $products = Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('id', $productIds)
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');
        $warehouses = Warehouse::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $productBalances = array_values(array_filter(array_map(
            function (array $balance) use ($products, $productId): ?array {
                if ($productId !== null && (int) $balance['product_id'] !== $productId) {
                    return null;
                }

                $product = $products->get($balance['product_id']);

                return [
                    ...$balance,
                    'product' => $product === null ? null : [
                        'id' => (int) $product->getKey(),
                        'sku' => $product->sku,
                        'name' => $product->name,
                    ],
                ];
            },
            $inventory['product_balances'],
        )));
        $warehouseBalances = array_values(array_filter(array_map(
            function (array $balance) use ($products, $warehouses, $productId, $warehouseId): ?array {
                if (
                    ($productId !== null && (int) $balance['product_id'] !== $productId)
                    || ($warehouseId !== null && (int) $balance['warehouse_id'] !== $warehouseId)
                ) {
                    return null;
                }

                $product = $products->get($balance['product_id']);
                $warehouse = $warehouses->get($balance['warehouse_id']);

                return [
                    ...$balance,
                    'product' => $product === null ? null : [
                        'id' => (int) $product->getKey(),
                        'sku' => $product->sku,
                        'name' => $product->name,
                    ],
                    'warehouse' => $warehouse === null ? null : [
                        'id' => (int) $warehouse->getKey(),
                        'code' => $warehouse->code,
                        'name' => $warehouse->name,
                    ],
                ];
            },
            $inventory['warehouse_balances'],
        )));

        $inventoryDetailsTruncated = count($productBalances) > $limit
            || count($warehouseBalances) > $limit
            || count($inventory['unvalued_products']) > $limit
            || count($inventory['source_records']) > $limit
            || count($inventory['discrepancies']) > $limit
            || count($inventory['valuation_limitations']) > $limit;
        $productBalances = array_slice($productBalances, 0, $limit);
        $warehouseBalances = array_slice($warehouseBalances, 0, $limit);
        $unvaluedProducts = array_slice($inventory['unvalued_products'], 0, $limit);
        $inventorySourceRecords = array_slice($inventory['source_records'], 0, $limit);
        $unsupported = [
            $this->unsupportedMetric(
                'warehouse_inventory_value',
                'InventoryCostingService provides company-wide product WAC, not warehouse-specific monetary valuation.',
            ),
            $this->unsupportedMetric(
                'low_stock_indicators',
                'Products do not have a reliable minimum-stock or reorder-point setting.',
            ),
        ];
        $warnings = [...$inventory['discrepancies'], ...$inventory['limitations'], ...$movementWarnings];

        if ($hasMore || $inventoryDetailsTruncated) {
            $warnings[] = [
                'issue' => 'result_limit_reached',
                'limit' => $limit,
                'message' => 'One or more inventory result lists are truncated; apply narrower filters to retrieve all records.',
            ];
        }

        $data = [
            'current_valued_inventory' => [
                'value' => $inventory['operational_balance'],
                'currency' => $inventory['currency'],
                'availability' => $inventory['valuation_limitations'] !== []
                    ? 'partially_available'
                    : ($inventory['unvalued_products'] !== [] ? 'partially_available' : 'available'),
            ],
            'product_balances' => $productBalances,
            'warehouse_quantities' => $warehouseBalances,
            'inventory_movements' => $movements,
            'movement_result' => [
                'returned_results' => count($movements),
                'has_more' => $hasMore,
            ],
            'unvalued_legacy_inventory' => $unvaluedProducts,
            'reconciliation' => $this->inventoryReconciliationSummary(
                $inventory,
                $inventorySourceRecords,
                $productBalances,
                $warehouseBalances,
                $unvaluedProducts,
                $limit,
            ),
            'filters' => ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ];
        $status = $this->reportStatus($inventory['status']);
        if ($hasMore || $inventoryDetailsTruncated || $movementWarnings !== []) {
            $status = 'partially_available';
        }

        return $this->response(
            $company,
            'inventory_analysis',
            ['from' => $fromDate, 'to' => $toDate],
            $status,
            $data,
            $warnings,
            $unsupported,
            array_map(
                fn (array $movement): array => [
                    'type' => $movement['source']['type'],
                    'id' => $movement['source']['id'],
                    'reference' => null,
                ],
                array_filter(
                    $movements,
                    fn (array $movement): bool => $movement['source']['id'] !== null
                        && $movement['source']['type'] !== null,
                ),
            ),
            ['AccountingReconciliationService', 'InventoryCostingService', 'StockMovement'],
        );
    }

    public function receivablesAndPayables(
        User $user,
        int $companyId,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$receivables, $payables] = $this->companyContext->runForCompany(
            $company,
            fn (): array => [
                $this->subledgers->reconcileReceivables($company),
                $this->subledgers->reconcilePayables($company),
            ],
        );
        $unsupported = [];

        foreach ([
            'customer_receivables' => $receivables,
            'supplier_payables' => $payables,
        ] as $category => $report) {
            if (! $report['supported']) {
                $unsupported[] = $this->unsupportedMetric(
                    $category,
                    implode(' ', $report['limitations']),
                );
            }
        }

        $unsupported[] = $this->unsupportedMetric(
            'unpaid_expenses_separate_total',
            'Payable expenses are included in supplier payables; no separate expense-only total is exposed.',
        );
        $warnings = [
            ...$receivables['discrepancies'],
            ...$receivables['limitations'],
            ...$payables['discrepancies'],
            ...$payables['limitations'],
        ];
        $status = $this->combinedStatus(
            [$receivables['status'], $payables['status']],
            $warnings,
            $unsupported,
        );

        return $this->response(
            $company,
            'receivables_and_payables',
            null,
            $status,
            [
                'customer_receivables' => $this->subledgerSummary($receivables),
                'supplier_payables' => $this->subledgerSummary($payables),
            ],
            $warnings,
            $unsupported,
            [
                ...$this->sourceReferencesFromSubledger($receivables),
                ...$this->sourceReferencesFromSubledger($payables),
            ],
            ['OperationalSubledgerReconciliationService', 'posted_general_ledger'],
        );
    }

    public function businessHealth(
        User $user,
        int $companyId,
        string $fromDate,
        string $toDate,
    ): array {
        $company = $this->authorizedCompany($user, $companyId);
        [$fromDate, $toDate] = $this->validatedPeriod($fromDate, $toDate);
        [$insights, $reconciliation] = $this->companyContext->runForCompany(
            $company,
            fn (): array => [
                $this->businessInsights->insights($company, $fromDate, $toDate),
                $this->reconciliation->reconcile($company),
            ],
        );
        $accountClassificationIssues = array_values(array_filter(
            $insights['financial_performance']['unresolved'],
            fn (array $issue): bool => $issue['issue'] === 'profit_and_loss_account_unclassified',
        ));
        $actualBusinessRisks = [];
        $dataQualityLimitations = [];
        $missingMappings = [];
        $reconciliationReports = [
            'customer_receivables' => $this->subledgerSummary($reconciliation['customer_receivables']),
            'supplier_payables' => $this->subledgerSummary($reconciliation['supplier_payables']),
            'inventory' => $this->healthInventorySummary($reconciliation['inventory']),
            'cash_and_bank' => [
                'status' => $reconciliation['cash_and_bank']['status'],
                'supported' => $reconciliation['cash_and_bank']['supported'],
                'limitations' => $reconciliation['cash_and_bank']['limitations'],
            ],
        ];

        foreach (['customer_receivables', 'supplier_payables', 'inventory'] as $category) {
            $report = $reconciliation[$category];
            foreach ($report['missing_mappings'] ?? [] as $mapping) {
                $missingMappings[] = is_array($mapping)
                    ? ['category' => $category, ...$mapping]
                    : ['category' => $category, 'mapping' => $mapping];
            }
            foreach ($report['limitations'] ?? [] as $limitation) {
                $dataQualityLimitations[] = ['category' => $category, 'message' => $limitation];
            }
            foreach ($report['discrepancies'] ?? [] as $discrepancy) {
                $dataQualityLimitations[] = ['category' => $category, ...$discrepancy];
            }

            if (($report['difference_classification'] ?? null) === 'accounting_discrepancy') {
                $actualBusinessRisks[] = [
                    'category' => $category,
                    'issue' => 'operational_to_gl_difference',
                    'difference' => $report['difference'],
                    'currency' => $report['currency'],
                    'control_account' => $report['control_account'],
                ];
            }
        }

        foreach ($insights['inventory']['unvalued_legacy_inventory'] as $unvaluedProduct) {
            $dataQualityLimitations[] = [
                'category' => 'inventory',
                'issue' => 'unvalued_legacy_inventory',
                ...$unvaluedProduct,
            ];
        }

        foreach ($reconciliation['payment_integrity']['discrepancies'] as $issue) {
            $dataQualityLimitations[] = ['category' => 'payment_integrity', ...$issue];
        }

        foreach ($insights['warnings'] as $issue) {
            $dataQualityLimitations[] = ['category' => 'business_insights', ...$issue];
        }

        $unsupported = [
            $this->unsupportedMetric('cash_and_bank', $reconciliation['cash_and_bank']['limitations'][0]),
            $this->unsupportedMetric(
                'low_stock_indicators',
                'Products do not have a reliable minimum-stock or reorder-point setting.',
            ),
            $this->unsupportedMetric(
                'product_profitability',
                'The available sales data does not provide a complete, reliable product-level cost basis.',
            ),
        ];
        $warnings = [...$actualBusinessRisks, ...$dataQualityLimitations];
        $status = $warnings === [] && $missingMappings === [] && $unsupported === []
            ? 'available'
            : 'partially_available';
        $healthDetailsResult = [
            'business_risk_count' => count($actualBusinessRisks),
            'data_quality_limitation_count' => count($dataQualityLimitations),
            'missing_mapping_count' => count($missingMappings),
            'has_more' => count($actualBusinessRisks) > self::MAX_RESULTS
                || count($dataQualityLimitations) > self::MAX_RESULTS
                || count($missingMappings) > self::MAX_RESULTS
                || count($accountClassificationIssues) > self::MAX_RESULTS
                || count($insights['inventory']['unvalued_legacy_inventory']) > self::MAX_RESULTS
                || count($reconciliation['payment_integrity']['discrepancies']) > self::MAX_RESULTS,
        ];

        return $this->response(
            $company,
            'business_health',
            ['from' => $fromDate, 'to' => $toDate],
            $status,
            [
                'actual_business_risks' => array_slice($actualBusinessRisks, 0, self::MAX_RESULTS),
                'data_quality_limitations' => array_slice($dataQualityLimitations, 0, self::MAX_RESULTS),
                'unresolved_account_classifications' => array_slice($accountClassificationIssues, 0, self::MAX_RESULTS),
                'reconciliation' => $reconciliationReports,
                'missing_mappings' => array_slice($missingMappings, 0, self::MAX_RESULTS),
                'unvalued_legacy_inventory' => array_slice(
                    $insights['inventory']['unvalued_legacy_inventory'],
                    0,
                    self::MAX_RESULTS,
                ),
                'payment_integrity_issues' => array_slice(
                    $reconciliation['payment_integrity']['discrepancies'],
                    0,
                    self::MAX_RESULTS,
                ),
                'details_result' => $healthDetailsResult,
            ],
            $warnings,
            $unsupported,
            [
                ...$this->sourceReferencesFromSubledger($reconciliation['customer_receivables']),
                ...$this->sourceReferencesFromSubledger($reconciliation['supplier_payables']),
            ],
            [
                'BusinessInsightsService',
                'AccountingReconciliationService',
                'OperationalSubledgerReconciliationService',
            ],
        );
    }

    private function authorizedCompany(User $user, int $companyId): Company
    {
        if (Auth::id() === null || (int) Auth::id() !== (int) $user->getKey()) {
            throw new AuthorizationException('An authenticated user is required for this company.');
        }

        $company = $user->companies()->whereKey($companyId)->first();
        if ($company === null) {
            throw (new ModelNotFoundException)->setModel(Company::class, [$companyId]);
        }

        if (! $this->companyAccess->canAccessCompany($user, $company)) {
            throw new AuthorizationException('The user is not authorized to access this company.');
        }

        return $company;
    }

    private function validatedPeriod(string $fromDate, string $toDate): array
    {
        $fromDate = $this->validatedDate($fromDate);
        $toDate = $this->validatedDate($toDate);

        if ($fromDate > $toDate) {
            throw ValidationException::withMessages([
                'period' => 'The reporting period start must not be after its end.',
            ]);
        }

        $periodDays = CarbonImmutable::parse($fromDate)->diffInDays(CarbonImmutable::parse($toDate)) + 1;
        if ($periodDays > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'period' => 'The reporting period cannot exceed 366 days.',
            ]);
        }

        return [$fromDate, $toDate];
    }

    private function validatedDate(string $date): string
    {
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
            || ! checkdate(
                (int) substr($date, 5, 2),
                (int) substr($date, 8, 2),
                (int) substr($date, 0, 4),
            )
        ) {
            throw ValidationException::withMessages([
                'date' => 'Dates must use a valid YYYY-MM-DD format.',
            ]);
        }

        return $date;
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_RESULTS) {
            throw ValidationException::withMessages([
                'limit' => 'The result limit must be between 1 and 200.',
            ]);
        }
    }

    private function boundReportList(array &$report, string $key, string $resultKey): bool
    {
        if (! isset($report[$key]) || ! is_array($report[$key])) {
            return false;
        }

        $count = count($report[$key]);
        if ($count <= self::MAX_RESULTS) {
            return false;
        }

        $report[$key] = array_slice($report[$key], 0, self::MAX_RESULTS);
        $report[$resultKey] = [
            'total_results' => $count,
            'returned_results' => self::MAX_RESULTS,
            'has_more' => true,
        ];

        return true;
    }

    private function validateInventoryFilters(
        Company $company,
        ?int $productId,
        ?int $warehouseId,
    ): void {
        if ($productId !== null && ! Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('tracks_inventory', true)
            ->whereKey($productId)
            ->exists()) {
            throw ValidationException::withMessages([
                'product_id' => 'The selected tracked product does not belong to the selected company.',
            ]);
        }

        if ($warehouseId !== null && ! Warehouse::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereKey($warehouseId)
            ->exists()) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'The selected warehouse does not belong to the selected company.',
            ]);
        }
    }

    private function response(
        Company $company,
        string $operation,
        ?array $reportingPeriod,
        string $status,
        array $data,
        array $warnings,
        array $unsupportedMetrics,
        array $sourceReferences,
        array $sources,
    ): array {
        $sourceReferences = collect($sourceReferences)
            ->filter(fn (array $reference): bool => $reference['id'] !== null)
            ->unique(fn (array $reference): string => implode(':', [
                (string) $reference['type'],
                (string) $reference['id'],
            ]))
            ->values()
            ->all();
        $warningCount = count($warnings);
        $warnings = array_slice(array_values($warnings), 0, self::MAX_RESULTS);
        if ($warningCount > self::MAX_RESULTS) {
            $warnings[] = [
                'issue' => 'warning_limit_reached',
                'limit' => self::MAX_RESULTS,
                'total_results' => $warningCount,
            ];
        }

        return [
            'company_id' => (int) $company->getKey(),
            'reporting_period' => $reportingPeriod,
            'generated_at' => now()->toIso8601String(),
            'base_currency' => strtoupper((string) $company->base_currency),
            'operation' => $operation,
            'status' => $status,
            'data' => $data,
            'warnings' => array_values($warnings),
            'unsupported_metrics' => array_slice(array_values($unsupportedMetrics), 0, self::MAX_RESULTS),
            'source_references' => array_slice($sourceReferences, 0, self::MAX_RESULTS),
            'reliability' => [
                'status' => match ($status) {
                    'available' => 'verified',
                    'unsupported' => 'unsupported',
                    'unavailable' => 'unavailable',
                    default => 'partial',
                },
                'sources' => array_values(array_unique($sources)),
            ],
        ];
    }

    private function reportStatus(string $status): string
    {
        return match ($status) {
            'unsupported' => 'unsupported',
            'unavailable' => 'unavailable',
            'complete', 'consistent', 'balanced', 'available' => 'available',
            default => 'partially_available',
        };
    }

    private function combinedStatus(array $statuses, array $warnings, array $unsupportedMetrics): string
    {
        $normalized = array_map(fn (string $status): string => $this->reportStatus($status), $statuses);
        if ($normalized !== [] && count(array_filter(
            $normalized,
            fn (string $status): bool => $status === 'unsupported',
        )) === count($normalized)) {
            return 'unsupported';
        }

        if ($normalized !== [] && count(array_filter(
            $normalized,
            fn (string $status): bool => $status === 'unavailable',
        )) === count($normalized)) {
            return 'unavailable';
        }

        return in_array('partially_available', $normalized, true)
            || in_array('unsupported', $normalized, true)
            || in_array('unavailable', $normalized, true)
            || $warnings !== []
            || $unsupportedMetrics !== []
            ? 'partially_available'
            : 'available';
    }

    private function unsupportedMetric(string $metric, string $reason): array
    {
        return [
            'metric' => $metric,
            'status' => 'unsupported',
            'reason' => $reason,
        ];
    }

    private function subledgerSummary(array $report): array
    {
        $report = $report['reconciliation'] ?? $report;
        $partyBalances = $report['party_balances'];
        $sourceRecords = $report['source_records'];
        $discrepancies = $report['discrepancies'];

        return [
            'status' => $report['status'],
            'supported' => $report['supported'],
            'control_account' => $report['control_account'],
            'operational_balance' => $report['operational_balance'],
            'general_ledger_balance' => $report['general_ledger_balance'],
            'difference' => $report['difference'],
            'currency' => $report['currency'],
            'native_balances' => $report['native_balances'],
            'party_balances' => array_slice($partyBalances, 0, self::MAX_RESULTS),
            'source_records' => array_slice($sourceRecords, 0, self::MAX_RESULTS),
            'missing_mappings' => $report['missing_mappings'],
            'difference_classification' => $report['difference_classification'],
            'limitations' => $report['limitations'],
            'discrepancies' => array_slice($discrepancies, 0, self::MAX_RESULTS),
            'details_result' => [
                'party_count' => count($partyBalances),
                'source_record_count' => count($sourceRecords),
                'discrepancy_count' => count($discrepancies),
                'has_more' => count($partyBalances) > self::MAX_RESULTS
                    || count($sourceRecords) > self::MAX_RESULTS
                    || count($discrepancies) > self::MAX_RESULTS,
            ],
        ];
    }

    private function inventorySummary(array $inventory): array
    {
        $productBalances = $inventory['product_quantities_and_values'];
        $warehouseBalances = $inventory['warehouse_quantities'];
        $unvaluedProducts = $inventory['unvalued_legacy_inventory'];
        $hasMore = count($productBalances) > self::MAX_RESULTS
            || count($warehouseBalances) > self::MAX_RESULTS
            || count($unvaluedProducts) > self::MAX_RESULTS;

        return [
            'status' => $inventory['status'],
            'total_valued_inventory' => $inventory['total_valued_inventory'],
            'currency' => $inventory['currency'],
            'product_quantities_and_values' => array_slice($productBalances, 0, self::MAX_RESULTS),
            'warehouse_quantities' => array_slice($warehouseBalances, 0, self::MAX_RESULTS),
            'unvalued_legacy_inventory' => array_slice($unvaluedProducts, 0, self::MAX_RESULTS),
            'details_result' => [
                'has_more' => $hasMore,
                'product_count' => count($productBalances),
                'warehouse_balance_count' => count($warehouseBalances),
                'unvalued_product_count' => count($unvaluedProducts),
            ],
            'reconciliation' => [
                'status' => $inventory['reconciliation']['status'],
                'supported' => $inventory['reconciliation']['supported'],
                'control_account' => $inventory['reconciliation']['control_account'],
                'operational_balance' => $inventory['reconciliation']['operational_balance'],
                'general_ledger_balance' => $inventory['reconciliation']['general_ledger_balance'],
                'difference' => $inventory['reconciliation']['difference'],
                'currency' => $inventory['reconciliation']['currency'],
                'missing_mappings' => $inventory['reconciliation']['missing_mappings'],
                'discrepancies' => array_slice($inventory['reconciliation']['discrepancies'], 0, self::MAX_RESULTS),
                'limitations' => $inventory['reconciliation']['limitations'],
            ],
        ];
    }

    private function healthInventorySummary(array $report): array
    {
        return [
            'status' => $report['status'],
            'supported' => $report['supported'],
            'control_account' => $report['control_account'],
            'operational_balance' => $report['operational_balance'],
            'general_ledger_balance' => $report['general_ledger_balance'],
            'difference' => $report['difference'],
            'currency' => $report['currency'],
            'difference_classification' => $report['difference_classification'],
            'missing_mappings' => $report['missing_mappings'],
            'discrepancies' => array_slice($report['discrepancies'], 0, self::MAX_RESULTS),
            'limitations' => $report['limitations'],
            'source_records' => array_slice($report['source_records'], 0, self::MAX_RESULTS),
        ];
    }

    private function inventoryReconciliationSummary(
        array $inventory,
        array $sourceRecords,
        array $productBalances,
        array $warehouseBalances,
        array $unvaluedProducts,
        int $limit,
    ): array {
        return [
            'status' => $inventory['status'],
            'supported' => $inventory['supported'],
            'control_account' => $inventory['control_account'],
            'operational_balance' => $inventory['operational_balance'],
            'general_ledger_balance' => $inventory['general_ledger_balance'],
            'difference' => $inventory['difference'],
            'currency' => $inventory['currency'],
            'difference_classification' => $inventory['difference_classification'],
            'source_records' => $sourceRecords,
            'missing_mappings' => $inventory['missing_mappings'],
            'discrepancies' => array_slice($inventory['discrepancies'], 0, $limit),
            'limitations' => $inventory['limitations'],
            'product_balances' => $productBalances,
            'warehouse_balances' => $warehouseBalances,
            'unvalued_products' => $unvaluedProducts,
            'valuation_limitations' => array_slice($inventory['valuation_limitations'], 0, $limit),
        ];
    }

    private function sourceReferencesFromSubledger(array $report): array
    {
        $report = $report['reconciliation'] ?? $report;

        return array_map(
            fn (array $record): array => [
                'type' => class_basename($record['source_type']),
                'id' => (int) $record['source_id'],
                'reference' => $record['document_number'],
            ],
            $report['source_records'],
        );
    }

    private function movementSourceType(?string $type): ?string
    {
        return match ($type) {
            Purchase::class => 'purchase',
            Revenue::class => 'revenue',
            CustomerReturn::class => 'customer_return',
            SupplierReturn::class => 'supplier_return',
            InventoryAdjustment::class => 'inventory_adjustment',
            InventoryCutover::class => 'inventory_cutover',
            default => null,
        };
    }
}
