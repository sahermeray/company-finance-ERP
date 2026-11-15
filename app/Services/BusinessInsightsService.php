<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\SupplierReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BusinessInsightsService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly FinancialReportingService $financialReports,
        private readonly AccountingReconciliationService $reconciliation,
    ) {}

    public function insights(Company $company, string $fromDate, string $toDate): array
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();
        if ($from > $to) {
            throw new \InvalidArgumentException('The reporting period start must not be after its end.');
        }

        $periodDays = $from->diff($to)->days + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($periodDays - 1);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $previousFromDate = $previousFrom->toDateString();
        $previousToDate = $previousTo->toDateString();

        return $this->companyContext->runForCompany($company, function () use (
            $company,
            $fromDate,
            $toDate,
            $previousFromDate,
            $previousToDate,
        ): array {
            $incomeStatement = $this->financialReports->incomeStatement(
                $company,
                $fromDate,
                $toDate,
                $previousFromDate,
                $previousToDate,
            );
            $reconciliation = $this->reconciliation->reconcile($company);
            $sales = $this->sourceDocumentTotals($company, Revenue::class, $fromDate, $toDate);
            $salesReturns = $this->sourceDocumentTotals($company, CustomerReturn::class, $fromDate, $toDate);
            $purchases = $this->sourceDocumentTotals($company, Purchase::class, $fromDate, $toDate);
            $purchaseReturns = $this->sourceDocumentTotals($company, SupplierReturn::class, $fromDate, $toDate);
            $topCustomers = $this->topCustomers($company, $fromDate, $toDate);
            $topProducts = $this->topProducts($company, $fromDate, $toDate);
            $salesTrend = $this->financialReports->incomeStatementTrend($company, $fromDate, $toDate);
            $warnings = $this->financialWarnings($incomeStatement, $reconciliation, $sales, $salesReturns, $purchases, $purchaseReturns);
            $sourceReferences = collect([
                ...$sales['source_references'],
                ...$salesReturns['source_references'],
                ...$purchases['source_references'],
                ...$purchaseReturns['source_references'],
            ])
                ->unique(fn (array $reference): string => $reference['type'].':'.$reference['id'])
                ->values()
                ->all();

            return [
                'company_id' => (int) $company->getKey(),
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                    'comparison' => [
                        'from' => $previousFromDate,
                        'to' => $previousToDate,
                    ],
                ],
                'base_currency' => strtoupper((string) $company->base_currency),
                'financial_performance' => [
                    'metrics' => $incomeStatement['metrics'],
                    'status' => $incomeStatement['status'],
                    'source' => 'posted_general_ledger',
                    'native_currency_totals' => $incomeStatement['native_currency_totals'],
                    'trend' => $salesTrend,
                    'unresolved' => $incomeStatement['unresolved'],
                ],
                'sales_and_purchases' => [
                    'sales_total' => $this->metric($sales['base_total'], 'posted_revenue_documents', $sales['status'], (string) $company->base_currency),
                    'sales_returns' => $this->metric($salesReturns['base_total'], 'posted_customer_return_documents', $salesReturns['status'], (string) $company->base_currency),
                    'net_sales' => $this->metric(
                        bcsub($sales['base_total'], $salesReturns['base_total'], 4),
                        'posted_revenue_documents_less_posted_customer_returns',
                        $sales['status'] === 'complete' && $salesReturns['status'] === 'complete' ? 'complete' : 'incomplete',
                        (string) $company->base_currency,
                    ),
                    'purchases_total' => $this->metric($purchases['base_total'], 'posted_purchase_documents', $purchases['status'], (string) $company->base_currency),
                    'supplier_returns' => $this->metric($purchaseReturns['base_total'], 'posted_supplier_return_documents', $purchaseReturns['status'], (string) $company->base_currency),
                    'net_purchases' => $this->metric(
                        bcsub($purchases['base_total'], $purchaseReturns['base_total'], 4),
                        'posted_purchase_documents_less_posted_supplier_returns',
                        $purchases['status'] === 'complete' && $purchaseReturns['status'] === 'complete' ? 'complete' : 'incomplete',
                        (string) $company->base_currency,
                    ),
                    'native_currency_totals' => [
                        'sales' => $sales['native_totals'],
                        'sales_returns' => $salesReturns['native_totals'],
                        'purchases' => $purchases['native_totals'],
                        'supplier_returns' => $purchaseReturns['native_totals'],
                    ],
                    'sales_trend' => $this->netSalesTrend($sales, $salesReturns),
                    'purchase_trend' => $this->netPurchaseTrend($purchases, $purchaseReturns),
                    'top_customers' => $topCustomers,
                    'top_products' => $topProducts,
                    'source_references' => array_slice($sourceReferences, 0, 200),
                    'source_reference_count' => count($sourceReferences),
                    'warnings' => [
                        ...$sales['unresolved'],
                        ...$salesReturns['unresolved'],
                        ...$purchases['unresolved'],
                        ...$purchaseReturns['unresolved'],
                    ],
                ],
                'inventory' => [
                    'status' => $reconciliation['inventory']['status'],
                    'total_valued_inventory' => $this->metric(
                        $reconciliation['inventory']['supported']
                            ? (string) $reconciliation['inventory']['operational_balance']
                            : null,
                        'InventoryCostingService',
                        $reconciliation['inventory']['status'],
                        $reconciliation['inventory']['currency'],
                    ),
                    'currency' => $reconciliation['inventory']['currency'],
                    'product_quantities_and_values' => $reconciliation['inventory']['product_balances'],
                    'warehouse_quantities' => $reconciliation['inventory']['warehouse_balances'],
                    'unvalued_legacy_inventory' => $reconciliation['inventory']['unvalued_products'],
                    'low_stock_indicators' => [
                        'value' => null,
                        'availability' => 'unsupported',
                        'reason' => 'Products have no minimum-stock or reorder-point setting.',
                    ],
                    'reconciliation' => $reconciliation['inventory'],
                ],
                'receivables_and_payables' => [
                    'customer_receivables' => $this->subledgerMetric($reconciliation['customer_receivables']),
                    'supplier_payables' => $this->subledgerMetric($reconciliation['supplier_payables']),
                ],
                'cash_and_bank' => [
                    'value' => null,
                    'availability' => 'unsupported',
                    'reason' => 'No independent operational cashbook exists; GL balances are not an independent cash/bank subledger.',
                ],
                'warnings' => $warnings,
            ];
        });
    }

    private function sourceDocumentTotals(
        Company $company,
        string $sourceType,
        string $fromDate,
        string $toDate,
    ): array {
        $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('sourceable_type', $sourceType)
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereDate('entry_date', '<=', $toDate)
            ->with('sourceable')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->groupBy('sourceable_id');
        $baseTotal = '0.0000';
        $nativeTotals = [];
        $monthlyTotals = [];
        $unresolved = [];
        $sourceReferences = [];

        foreach ($journals as $sourceId => $sourceJournals) {
            $source = $sourceJournals->first()->sourceable;
            $originalJournals = $sourceJournals->filter(fn (AccountingJournal $journal): bool => $journal->reversal_of_journal_id === null);
            $reversalJournals = $sourceJournals->filter(fn (AccountingJournal $journal): bool => $journal->reversal_of_journal_id !== null);
            if (
                $source === null
                || (int) $source->company_id !== (int) $company->getKey()
                || $source->status !== 'posted'
                || $originalJournals->count() !== 1
                || $reversalJournals->count() > 1
                || (
                    $reversalJournals->isNotEmpty()
                    && (
                        (int) $reversalJournals->first()->reversal_of_journal_id !== (int) $originalJournals->first()?->getKey()
                        || (int) $originalJournals->first()?->reversed_by_journal_id !== (int) $reversalJournals->first()->getKey()
                    )
                )
            ) {
                $unresolved[] = [
                    'issue' => $source === null ? 'source_document_missing' : 'source_document_journal_relationship_inconsistent',
                    'source_type' => $sourceType,
                    'source_id' => is_numeric($sourceId) ? (int) $sourceId : null,
                    'journal_ids' => $sourceJournals->modelKeys(),
                ];

                continue;
            }

            if ($source->base_total === null || $source->total === null || $source->currency === null) {
                $unresolved[] = [
                    'issue' => 'source_document_missing_historical_amount_or_currency',
                    'source_type' => $sourceType,
                    'source_id' => (int) $source->getKey(),
                ];

                continue;
            }

            $hasPeriodJournal = false;
            foreach ($sourceJournals as $journal) {
                $date = $journal->entry_date->toDateString();
                if ($date < $fromDate || $date > $toDate) {
                    continue;
                }
                $hasPeriodJournal = true;

                $sign = $journal->reversal_of_journal_id === null ? '1.0000' : '-1.0000';
                $amount = bcmul((string) $source->base_total, $sign, 4);
                $baseTotal = bcadd($baseTotal, $amount, 4);
                $currency = strtoupper((string) $source->currency);
                $nativeAmount = bcmul((string) $source->total, $sign, 4);
                $nativeTotals[$currency] = bcadd($nativeTotals[$currency] ?? '0.0000', $nativeAmount, 4);

                if (in_array($sourceType, [Revenue::class, CustomerReturn::class, Purchase::class, SupplierReturn::class], true)) {
                    $month = CarbonImmutable::parse($date)->format('Y-m');
                    $monthlyTotals[$month] = bcadd($monthlyTotals[$month] ?? '0.0000', $amount, 4);
                }
            }

            if ($hasPeriodJournal) {
                $sourceReferences[] = [
                    'type' => class_basename($sourceType),
                    'id' => (int) $source->getKey(),
                    'reference' => match ($sourceType) {
                        Revenue::class => $source->revenue_number,
                        CustomerReturn::class, SupplierReturn::class => $source->return_number,
                        Purchase::class => $source->purchase_number,
                        default => null,
                    },
                ];
            }
        }
        ksort($nativeTotals);
        ksort($monthlyTotals);
        $sourceReferences = collect($sourceReferences)
            ->unique(fn (array $reference): string => $reference['type'].':'.$reference['id'])
            ->values()
            ->all();

        return [
            'base_total' => $baseTotal,
            'native_totals' => $nativeTotals,
            'monthly_totals' => $monthlyTotals,
            'source_references' => array_slice($sourceReferences, 0, 200),
            'source_reference_count' => count($sourceReferences),
            'unresolved' => $unresolved,
            'status' => $unresolved === [] ? 'complete' : 'incomplete',
        ];
    }

    private function topCustomers(Company $company, string $fromDate, string $toDate): array
    {
        $journals = $this->salesJournals($company, $fromDate, $toDate);
        $accounts = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->get()
            ->keyBy(fn (FinancialAccount $account): int => (int) $account->getKey());
        $totals = [];
        $unresolved = [];

        foreach ($journals as $journal) {
            if ($journal->entry_date->toDateString() < $fromDate) {
                continue;
            }

            if (
                $journal->sourceable === null
                || (int) $journal->sourceable->company_id !== (int) $company->getKey()
                || $journal->sourceable->status !== 'posted'
            ) {
                $unresolved[] = [
                    'issue' => 'sales_journal_missing_or_inconsistent_source',
                    'journal_id' => (int) $journal->getKey(),
                ];

                continue;
            }

            foreach ($journal->lines as $line) {
                $account = $accounts->get((int) $line->financial_account_id);
                if ($account === null) {
                    continue;
                }
                if (strtolower((string) $account->account_type) !== 'revenue') {
                    continue;
                }
                if ($account->statement_classification === null) {
                    $unresolved[] = [
                        'issue' => 'sales_account_classification_missing',
                        'journal_id' => (int) $journal->getKey(),
                        'account_id' => (int) $account->getKey(),
                    ];

                    continue;
                }
                if ($account->statement_classification !== 'revenue') {
                    continue;
                }
                if ($line->base_debit === null || $line->base_credit === null) {
                    $unresolved[] = [
                        'issue' => 'sales_journal_line_missing_base_amount',
                        'journal_id' => (int) $journal->getKey(),
                        'line_id' => (int) $line->getKey(),
                    ];

                    continue;
                }
                if ($line->party_id === null || $line->party === null || (int) $line->party->company_id !== (int) $company->getKey()) {
                    $unresolved[] = [
                        'issue' => 'sales_journal_party_dimension_missing',
                        'journal_id' => (int) $journal->getKey(),
                        'line_id' => (int) $line->getKey(),
                    ];

                    continue;
                }

                $partyId = (int) $line->party_id;
                $totals[$partyId] ??= [
                    'party_id' => $partyId,
                    'party_name' => $line->party->name,
                    'base_currency' => strtoupper((string) $company->base_currency),
                    'sales_base_amount' => '0.0000',
                ];
                $totals[$partyId]['sales_base_amount'] = bcadd(
                    $totals[$partyId]['sales_base_amount'],
                    bcsub((string) $line->base_credit, (string) $line->base_debit, 4),
                    4,
                );
            }
        }

        $customers = array_values($totals);
        usort($customers, fn (array $left, array $right): int => bccomp(
            $right['sales_base_amount'],
            $left['sales_base_amount'],
            4,
        ));

        return [
            'value' => $unresolved === [] ? array_slice($customers, 0, 10) : null,
            'availability' => $unresolved === [] ? 'available' : 'incomplete',
            'source' => 'posted_revenue_journal_lines_with_party_dimension',
            'unresolved' => $unresolved,
        ];
    }

    private function topProducts(Company $company, string $fromDate, string $toDate): array
    {
        $journals = $this->salesJournals($company, $fromDate, $toDate);
        $totals = [];
        $unresolved = [];

        foreach ($journals as $journal) {
            if ($journal->entry_date->toDateString() < $fromDate) {
                continue;
            }

            $source = $journal->sourceable;
            if (
                $source === null
                || (int) $source->company_id !== (int) $company->getKey()
                || $source->status !== 'posted'
            ) {
                $unresolved[] = [
                    'issue' => 'sales_journal_missing_or_inconsistent_source',
                    'journal_id' => (int) $journal->getKey(),
                ];

                continue;
            }
            if ($source instanceof Revenue) {
                if ($source->status !== 'posted') {
                    continue;
                }
                $sign = $journal->reversal_of_journal_id === null ? '1.0000' : '-1.0000';
                foreach ($source->items as $item) {
                    if ($item->product === null || (int) $item->product->company_id !== (int) $company->getKey()) {
                        $unresolved[] = [
                            'issue' => 'sales_item_product_missing_or_foreign',
                            'journal_id' => (int) $journal->getKey(),
                            'item_id' => (int) $item->getKey(),
                        ];

                        continue;
                    }
                    $rate = strtoupper((string) $source->currency) === strtoupper((string) $company->base_currency)
                        ? '1'
                        : (string) $source->exchange_rate;
                    if ($rate === '' || bccomp($rate, '0', 10) <= 0) {
                        $unresolved[] = [
                            'issue' => 'sales_document_historical_exchange_rate_missing',
                            'source_id' => (int) $source->getKey(),
                        ];

                        continue;
                    }
                    $this->addProductTotal(
                        $totals,
                        $item->product,
                        bcmul((string) $item->line_total, $rate, 4),
                        (string) $item->quantity,
                        $sign,
                    );
                }
            } elseif ($source instanceof CustomerReturn) {
                if ($source->status !== 'posted') {
                    continue;
                }
                foreach ($source->lines as $line) {
                    $product = $line->product;
                    if ($product === null || (int) $product->company_id !== (int) $company->getKey()) {
                        $unresolved[] = [
                            'issue' => 'return_item_product_missing_or_foreign',
                            'journal_id' => (int) $journal->getKey(),
                            'return_line_id' => (int) $line->getKey(),
                        ];

                        continue;
                    }
                    $this->addProductTotal(
                        $totals,
                        $product,
                        (string) $line->base_amount,
                        (string) $line->quantity,
                        '-1.0000',
                    );
                }
            }
        }

        $products = array_values($totals);
        usort($products, fn (array $left, array $right): int => bccomp(
            $right['sales_base_amount'],
            $left['sales_base_amount'],
            4,
        ));

        return [
            'value' => $unresolved === [] ? array_slice($products, 0, 10) : null,
            'availability' => $unresolved === [] ? 'available' : 'incomplete',
            'source' => 'posted_revenue_items_and_customer_return_lines',
            'unresolved' => $unresolved,
        ];
    }

    private function salesJournals(Company $company, string $fromDate, string $toDate): Collection
    {
        return AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('sourceable_type', [Revenue::class, CustomerReturn::class])
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereDate('entry_date', '<=', $toDate)
            ->with([
                'sourceable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        Revenue::class => ['items.product'],
                        CustomerReturn::class => ['lines.product'],
                    ]);
                },
                'lines.party',
            ])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    private function addProductTotal(
        array &$totals,
        Product $product,
        string $baseAmount,
        string $quantity,
        string $sign,
    ): void {
        $productId = (int) $product->getKey();
        $totals[$productId] ??= [
            'product_id' => $productId,
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => '0.0000',
            'sales_base_amount' => '0.0000',
        ];
        $totals[$productId]['quantity'] = bcadd(
            $totals[$productId]['quantity'],
            bcmul($quantity, $sign, 4),
            4,
        );
        $totals[$productId]['sales_base_amount'] = bcadd(
            $totals[$productId]['sales_base_amount'],
            bcmul($baseAmount, $sign, 4),
            4,
        );
    }

    private function netPurchaseTrend(array $purchases, array $returns): array
    {
        $months = array_unique(array_merge(
            array_keys($purchases['monthly_totals']),
            array_keys($returns['monthly_totals']),
        ));
        sort($months);
        $trend = [];

        foreach ($months as $month) {
            $grossPurchases = $purchases['monthly_totals'][$month] ?? '0.0000';
            $supplierReturns = $returns['monthly_totals'][$month] ?? '0.0000';
            $trend[$month] = [
                'purchases' => $grossPurchases,
                'supplier_returns' => $supplierReturns,
                'net_purchases' => bcsub($grossPurchases, $supplierReturns, 4),
            ];
        }

        return [
            'months' => $trend,
            'status' => $purchases['status'] === 'complete' && $returns['status'] === 'complete'
                ? 'complete'
                : 'incomplete',
        ];
    }

    private function netSalesTrend(array $sales, array $returns): array
    {
        $months = array_unique(array_merge(
            array_keys($sales['monthly_totals']),
            array_keys($returns['monthly_totals']),
        ));
        sort($months);
        $trend = [];

        foreach ($months as $month) {
            $grossSales = $sales['monthly_totals'][$month] ?? '0.0000';
            $customerReturns = $returns['monthly_totals'][$month] ?? '0.0000';
            $trend[$month] = [
                'sales' => $grossSales,
                'customer_returns' => $customerReturns,
                'net_sales' => bcsub($grossSales, $customerReturns, 4),
            ];
        }

        return [
            'months' => $trend,
            'status' => $sales['status'] === 'complete' && $returns['status'] === 'complete'
                ? 'complete'
                : 'incomplete',
        ];
    }

    private function subledgerMetric(array $report): array
    {
        return [
            'status' => $report['status'],
            'supported' => $report['supported'],
            'operational_balance' => $report['operational_balance'],
            'general_ledger_balance' => $report['general_ledger_balance'],
            'difference' => $report['difference'],
            'currency' => $report['currency'],
            'native_balances' => $report['native_balances'],
            'party_balances' => $report['party_balances'],
            'difference_classification' => $report['difference_classification'],
            'limitations' => $report['limitations'],
            'reconciliation' => $report,
        ];
    }

    private function metric(?string $value, string $source, string $status, string $currency): array
    {
        return [
            'value' => $value,
            'availability' => $status === 'unsupported'
                ? 'unsupported'
                : ($value === null ? 'unavailable' : ($status === 'complete' ? 'available' : 'incomplete')),
            'currency' => strtoupper($currency),
            'source' => $source,
            'status' => $status,
        ];
    }

    private function financialWarnings(array $incomeStatement, array $reconciliation, array ...$sourceReports): array
    {
        $warnings = [];
        foreach ($incomeStatement['unresolved'] as $issue) {
            $warnings[] = ['category' => 'financial_performance', ...$issue];
        }
        foreach ($incomeStatement['warnings'] as $issue) {
            $warnings[] = ['category' => 'financial_performance', ...$issue];
        }
        foreach (['customer_receivables', 'supplier_payables', 'inventory'] as $category) {
            if ($reconciliation[$category]['status'] !== 'consistent') {
                $warnings[] = [
                    'category' => $category,
                    'status' => $reconciliation[$category]['status'],
                    'limitations' => $reconciliation[$category]['limitations'] ?? [],
                    'discrepancies' => $reconciliation[$category]['discrepancies'] ?? [],
                ];
            }
        }
        foreach ($sourceReports as $report) {
            foreach ($report['unresolved'] as $issue) {
                $warnings[] = ['category' => 'source_documents', ...$issue];
            }
        }

        return $warnings;
    }
}
