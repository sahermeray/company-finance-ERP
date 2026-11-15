<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CompanyAccountMapping;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCutover;
use App\Models\InventoryCutoverLine;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class AccountingReconciliationService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly InventoryCostingService $inventoryCosting,
        private readonly OperationalSubledgerReconciliationService $subledgers,
    ) {}

    /**
     * @return array{
     *     company_id: int,
     *     base_currency: string,
     *     customer_receivables: array<string, mixed>,
     *     supplier_payables: array<string, mixed>,
     *     inventory: array<string, mixed>,
     *     cash_and_bank: array<string, mixed>,
     *     payment_integrity: array<string, mixed>
     * }
     */
    public function reconcile(Company $company): array
    {
        return [
            'company_id' => (int) $company->getKey(),
            'base_currency' => strtoupper((string) $company->base_currency),
            'customer_receivables' => $this->companyContext->runForCompany(
                $company,
                fn (): array => $this->subledgers->reconcileReceivables($company),
            ),
            'supplier_payables' => $this->companyContext->runForCompany(
                $company,
                fn (): array => $this->subledgers->reconcilePayables($company),
            ),
            'inventory' => $this->companyContext->runForCompany(
                $company,
                fn (): array => $this->reconcileInventory($company),
            ),
            'cash_and_bank' => [
                'status' => 'unsupported',
                'supported' => false,
                'company_id' => (int) $company->getKey(),
                'category' => 'cash_and_bank',
                'control_account' => null,
                'operational_balance' => null,
                'general_ledger_balance' => null,
                'expected_amount' => null,
                'actual_amount' => null,
                'difference' => null,
                'currency' => strtoupper((string) $company->base_currency),
                'native_balances' => [],
                'source_records' => [],
                'missing_mappings' => [],
                'discrepancies' => [],
                'difference_classification' => 'unsupported_comparison',
                'timing_differences_supported' => false,
                'timing_limitation' => 'Cash and bank movements are not independently tracked, and no reconciliation cutoff is available.',
                'limitations' => [
                    'FinancialAccount has no operational cash/bank balance or cashbook. Its balance is calculated from posted journal lines, so comparing it to itself would not be an operational reconciliation.',
                    'Financial accounts do not identify which asset accounts are cash or bank accounts.',
                ],
            ],
            'payment_integrity' => $this->reconcilePaymentIntegrity($company),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileInventory(Company $company): array
    {
        $inventoryValue = '0.0000';
        $unvaluedProducts = [];
        $valuationLimitations = [];
        $productBalances = [];
        $warehouseBalances = [];
        $valuedProducts = 0;

        $this->companyContext->runForCompany($company, function () use (
            $company,
            &$inventoryValue,
            &$unvaluedProducts,
            &$valuationLimitations,
            &$productBalances,
            &$warehouseBalances,
            &$valuedProducts,
        ): void {
            $products = Product::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('tracks_inventory', true)
                ->orderBy('id')
                ->get();
            $warehouses = Warehouse::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->orderBy('id')
                ->get();

            foreach ($products as $product) {
                try {
                    $historyStatus = $this->inventoryCosting->valuationHistoryStatus($product);

                    if ($historyStatus === 'unvalued') {
                        $unvaluedProducts[] = [
                            'source_type' => Product::class,
                            'source_id' => (int) $product->getKey(),
                            'expected_amount' => null,
                            'actual_amount' => null,
                            'difference' => null,
                            'limitation' => 'Legacy inventory has no known monetary valuation and is excluded from the valued total.',
                        ];

                        foreach ($warehouses as $warehouse) {
                            $warehouseBalances[] = [
                                'product_id' => (int) $product->getKey(),
                                'warehouse_id' => (int) $warehouse->getKey(),
                                'quantity' => $this->inventoryCosting->quantityInWarehouse($product, $warehouse),
                                'inventory_value' => null,
                            ];
                        }

                        continue;
                    }

                    if ($historyStatus === 'valued') {
                        $valuation = $this->inventoryCosting->currentValuation($product);
                        $inventoryValue = bcadd($inventoryValue, $valuation['inventory_value'], 4);
                        $valuedProducts++;
                        $productBalances[] = [
                            'product_id' => (int) $product->getKey(),
                            'quantity' => $valuation['quantity'],
                            'inventory_value' => $valuation['inventory_value'],
                            'average_unit_cost' => $valuation['average_unit_cost'],
                            'currency' => strtoupper((string) $company->base_currency),
                        ];

                        foreach ($warehouses as $warehouse) {
                            $warehouseBalances[] = [
                                'product_id' => (int) $product->getKey(),
                                'warehouse_id' => (int) $warehouse->getKey(),
                                'quantity' => $this->inventoryCosting->quantityInWarehouse($product, $warehouse),
                                'inventory_value' => null,
                            ];
                        }
                    }
                } catch (ValidationException $exception) {
                    $valuationLimitations[] = [
                        'source_type' => Product::class,
                        'source_id' => (int) $product->getKey(),
                        'limitation' => implode(' ', array_merge(...array_values($exception->errors()))),
                    ];
                }
            }
        });

        $mapping = CompanyAccountMapping::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('purpose', CompanyAccountMapping::INVENTORY_ASSET)
            ->first();
        $inventoryAccount = $mapping === null
            ? null
            : FinancialAccount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->find($mapping->financial_account_id);

        $mappedAccountIds = collect([
            ...InventoryCutover::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
            ...InventoryAdjustment::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
            ...CustomerReturn::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
            ...SupplierReturn::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
            ...Purchase::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
            ...Revenue::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('status', 'posted')->pluck('inventory_asset_account_id')->all(),
        ])->filter()->unique()->values();

        $accountMappings = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('id', $mappedAccountIds)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'currency'])
            ->map(fn (FinancialAccount $account): array => [
                'account_id' => (int) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'currency' => $account->currency,
            ])
            ->all();

        $inventoryJournalData = $this->inventoryJournalDiscrepancies($company);
        $discrepancies = $inventoryJournalData['discrepancies'];
        $account = $inventoryAccount;
        $actualAmount = null;
        $difference = null;
        $controlAccount = null;

        if ($account !== null) {
            $controlAccount = [
                'account_id' => (int) $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
            ];
            $actualAmount = '0.0000';
            $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
                ->where('financial_account_id', $account->getKey())
                ->whereHas('journal', fn ($query) => $query
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->where('status', 'posted'))
                ->with('journal')
                ->orderBy('accounting_journal_id')
                ->orderBy('line_number')
                ->get();

            foreach ($lines as $line) {
                if (
                    $line->base_debit === null
                    || $line->base_credit === null
                    || strtoupper((string) $line->base_currency) !== strtoupper((string) $company->base_currency)
                ) {
                    $discrepancies[] = [
                        'issue' => 'inventory_control_account_line_missing_company_base_amount',
                        'journal_id' => (int) $line->accounting_journal_id,
                        'line_id' => (int) $line->getKey(),
                    ];

                    continue;
                }

                $actualAmount = bcadd(
                    $actualAmount,
                    bcsub((string) $line->base_debit, (string) $line->base_credit, 4),
                    4,
                );
            }

            $difference = bcsub($inventoryValue, $actualAmount, 4);
        }

        $limitations = [
            'Inventory valuation comes from InventoryCostingService and includes only movements with known valuation; legacy unvalued inventory is not assigned an amount.',
            'Warehouse quantities are reported, but InventoryCostingService provides product-level company-wide WAC only; warehouse monetary values are not fabricated.',
        ];

        if ($mapping === null) {
            $limitations[] = 'No company-level inventory asset control account is configured.';
        } elseif (
            $account === null
            || (int) $account->company_id !== (int) $company->getKey()
            || strtolower((string) $account->account_type) !== 'asset'
            || strtoupper((string) $account->currency) !== strtoupper((string) $company->base_currency)
        ) {
            $limitations[] = 'The configured inventory account no longer satisfies company, account-type, or base-currency requirements.';
        }

        $otherAccountIds = $mappedAccountIds
            ->reject(fn ($accountId): bool => $mapping !== null && (int) $accountId === (int) $mapping->financial_account_id);
        if ($otherAccountIds->isNotEmpty()) {
            $discrepancies[] = [
                'issue' => 'inventory_transactions_use_multiple_asset_accounts',
                'account_ids' => $otherAccountIds->map(static fn ($id): int => (int) $id)->all(),
            ];
            $limitations[] = 'Posted inventory workflows use asset accounts other than the configured control account; their balances are listed but not silently combined.';
        }

        foreach ([
            Purchase::class => Purchase::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->whereNull('inventory_asset_account_id')
                ->whereHas('stockMovements', fn ($query) => $query->whereNotNull('base_cost_amount'))
                ->pluck('id'),
            Revenue::class => Revenue::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->whereNull('inventory_asset_account_id')
                ->whereHas('stockMovements', fn ($query) => $query->whereNotNull('base_cost_amount'))
                ->pluck('id'),
        ] as $sourceType => $unresolvedSourceIds) {
            foreach ($unresolvedSourceIds as $sourceId) {
                $discrepancies[] = [
                    'issue' => 'inventory_source_account_mapping_unresolved',
                    'source_type' => $sourceType,
                    'source_id' => (int) $sourceId,
                ];
            }
        }

        if ($discrepancies !== []) {
            $limitations[] = 'One or more inventory source relationships or account assignments are incomplete; see discrepancies for source IDs.';
        }

        if ($unvaluedProducts !== []) {
            $limitations[] = 'One or more products have legacy unvalued stock movements. Their value is unknown and they are excluded from the operational valuation total.';
        }

        if ($valuationLimitations !== []) {
            $limitations[] = 'One or more product valuations are incomplete or invalid; details are listed by product.';
        }

        $supported = $mapping !== null && $account !== null
            && (int) $account->company_id === (int) $company->getKey()
            && strtolower((string) $account->account_type) === 'asset'
            && strtoupper((string) $account->currency) === strtoupper((string) $company->base_currency);
        $status = ! $supported
            ? 'incomplete'
            : ($discrepancies !== [] || $valuationLimitations !== [] || $unvaluedProducts !== []
                ? 'incomplete'
                : (bccomp((string) $difference, '0', 4) === 0 ? 'consistent' : 'discrepant'));

        return [
            'status' => $status,
            'supported' => $supported,
            'company_id' => (int) $company->getKey(),
            'category' => 'inventory',
            'control_account' => $controlAccount,
            'operational_balance' => $inventoryValue,
            'general_ledger_balance' => $actualAmount,
            'expected_amount' => $inventoryValue,
            'actual_amount' => $actualAmount,
            'difference' => $difference,
            'currency' => strtoupper((string) $company->base_currency),
            'native_balances' => [
                strtoupper((string) $company->base_currency) => [
                    'currency' => strtoupper((string) $company->base_currency),
                    'operational_balance' => $inventoryValue,
                    'general_ledger_balance' => $actualAmount,
                    'difference' => $difference,
                ],
            ],
            'valued_product_count' => $valuedProducts,
            'account_mappings' => $accountMappings,
            'product_balances' => $productBalances,
            'warehouse_balances' => $warehouseBalances,
            'source_records' => $inventoryJournalData['source_records'],
            'missing_mappings' => $mapping === null ? [CompanyAccountMapping::INVENTORY_ASSET] : [],
            'unvalued_products' => $unvaluedProducts,
            'valuation_limitations' => $valuationLimitations,
            'discrepancies' => $discrepancies,
            'difference_classification' => ! $supported
                ? 'unsupported_comparison'
                : ($discrepancies !== []
                    ? 'incomplete_data'
                    : (bccomp((string) $difference, '0', 4) === 0 ? 'no_difference' : 'accounting_discrepancy')),
            'timing_differences_supported' => false,
            'timing_limitation' => 'The reconciliation has no as-of date or period parameter, so timing differences cannot be isolated.',
            'limitations' => $limitations,
        ];
    }

    /**
     * @return array{discrepancies: array<int, array<string, mixed>>, source_records: array<int, array<string, mixed>>}
     */
    private function inventoryJournalDiscrepancies(Company $company): array
    {
        $knownTypes = [
            Purchase::class,
            Revenue::class,
            CustomerReturn::class,
            SupplierReturn::class,
            InventoryAdjustment::class,
        ];
        $sourceIds = [];
        $movements = StockMovement::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNotNull('base_cost_amount')
            ->whereNotNull('sourceable_type')
            ->whereNotNull('sourceable_id')
            ->orderBy('id')
            ->get(['sourceable_type', 'sourceable_id']);

        foreach ($movements as $movement) {
            if (in_array($movement->sourceable_type, $knownTypes, true)) {
                $sourceIds[$movement->sourceable_type][(int) $movement->sourceable_id] = true;
            } elseif ($movement->sourceable_type === InventoryCutoverLine::class) {
                $line = InventoryCutoverLine::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->find($movement->sourceable_id);
                if ($line !== null) {
                    $cutoverId = (int) $line->inventory_cutover_id;
                    $sourceIds[InventoryCutover::class][$cutoverId] = true;
                }
            }
        }

        $sourceRecords = [];
        $issues = [];
        foreach ($sourceIds as $sourceType => $ids) {
            foreach (array_keys($ids) as $sourceId) {
                $source = $sourceType::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->find($sourceId);

                if ($source === null) {
                    $issues[] = [
                        'issue' => 'inventory_movement_linked_to_missing_source',
                        'source_type' => $sourceType,
                        'source_id' => (int) $sourceId,
                    ];

                    continue;
                }

                $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
                    ->where('sourceable_type', $sourceType)
                    ->where('sourceable_id', $sourceId)
                    ->orderBy('id')
                    ->get();
                if (
                    $journals->count() !== 1
                    || $journals->first()->status !== 'posted'
                    || $journals->first()->posted_at === null
                    || (int) $journals->first()->company_id !== (int) $company->getKey()
                    || $journals->first()->reversed_by_journal_id !== null
                ) {
                    $issues[] = [
                        'issue' => $journals->count() > 1
                            ? 'inventory_source_duplicate_journals'
                            : 'inventory_source_missing_or_inconsistent_journal',
                        'source_type' => $sourceType,
                        'source_id' => (int) $sourceId,
                        'journal_ids' => $journals->modelKeys(),
                    ];

                    continue;
                }

                if (
                    in_array($sourceType, [InventoryCutover::class, InventoryAdjustment::class, CustomerReturn::class, SupplierReturn::class], true)
                    && (int) $source->accounting_journal_id !== (int) $journals->first()->getKey()
                ) {
                    $issues[] = [
                        'issue' => 'inventory_source_journal_foreign_key_mismatch',
                        'source_type' => $sourceType,
                        'source_id' => (int) $sourceId,
                        'journal_id' => (int) $journals->first()->getKey(),
                    ];
                }

                $sourceRecords[] = [
                    'source_type' => $sourceType,
                    'source_id' => (int) $sourceId,
                    'journal_id' => (int) $journals->first()->getKey(),
                ];
            }
        }

        foreach (AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('sourceable_type', [...$knownTypes, InventoryCutover::class])
            ->where('status', 'posted')
            ->with('sourceable')
            ->orderBy('id')
            ->get() as $journal) {
            if ($journal->sourceable === null || (int) $journal->sourceable->company_id !== (int) $company->getKey()) {
                $issues[] = [
                    'issue' => 'inventory_journal_linked_to_missing_or_foreign_source',
                    'journal_id' => (int) $journal->getKey(),
                    'source_type' => $journal->sourceable_type,
                    'source_id' => $journal->sourceable_id === null ? null : (int) $journal->sourceable_id,
                ];
            }
        }

        return [
            'discrepancies' => $issues,
            'source_records' => $sourceRecords,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcilePaymentIntegrity(Company $company): array
    {
        $companyPayments = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->orderBy('id')
            ->get();
        $companyPaymentIds = $companyPayments->modelKeys();

        $journalQuery = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class);

        $journalQuery->where(function ($query) use ($company, $companyPaymentIds): void {
            $query->where('company_id', $company->getKey());

            if ($companyPaymentIds !== []) {
                $query->orWhereIn('sourceable_id', $companyPaymentIds);
            }
        });

        $journals = $journalQuery->orderBy('id')->get();
        $paymentIdsReferencedByJournals = $journals->pluck('sourceable_id')->filter()->unique()->values();
        $referencedPayments = Payment::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $paymentIdsReferencedByJournals)
            ->get()
            ->keyBy('id');
        $journalsByPayment = $journals->groupBy('sourceable_id');
        $discrepancies = [];

        foreach ($companyPayments as $payment) {
            $associatedJournals = $journalsByPayment->get((string) $payment->getKey(), collect());
            $postedJournals = $associatedJournals->where('status', 'posted');

            if ($payment->status === 'posted' && ($postedJournals->count() !== 1 || $payment->posted_at === null)) {
                $discrepancies[] = $this->paymentIssue(
                    $company,
                    $payment,
                    $postedJournals->count() === 0
                        ? 'posted_payment_missing_journal'
                        : ($postedJournals->count() > 1 ? 'posted_payment_duplicate_journals' : 'posted_payment_timestamp_missing'),
                    '1',
                    (string) $postedJournals->count(),
                );
            }

            if ($payment->status === 'reversed') {
                $originalJournal = $postedJournals->first();
                $reversalPayment = $payment->reversed_by_payment_id === null
                    ? null
                    : Payment::withoutGlobalScope(CompanyScope::class)->find($payment->reversed_by_payment_id);
                $reversalJournals = $reversalPayment === null
                    ? collect()
                    : ($journalsByPayment->get((string) $reversalPayment->getKey(), collect())->where('status', 'posted'));
                $reversalJournal = $reversalJournals->first();

                if (
                    $originalJournal === null
                    || $originalJournal->reversed_by_journal_id === null
                    || $reversalJournals->count() !== 1
                    || $reversalPayment === null
                    || $reversalPayment->status !== 'posted'
                    || (int) $reversalPayment->company_id !== (int) $company->getKey()
                    || (int) $reversalPayment->reversal_of_payment_id !== (int) $payment->getKey()
                    || (int) $reversalJournal?->reversal_of_journal_id !== (int) $originalJournal?->getKey()
                    || (int) $reversalJournal?->getKey() !== (int) $originalJournal?->reversed_by_journal_id
                ) {
                    $discrepancies[] = $this->paymentIssue(
                        $company,
                        $payment,
                        'reversed_payment_journal_active_or_incomplete',
                        'Posted reversal payment and matching posted reversal journal',
                        $originalJournal?->reversed_by_journal_id === null ? 'active or missing' : 'incomplete reversal links',
                    );
                }
            }

            $this->appendPaymentRelationshipIssues($discrepancies, $company, $payment);
        }

        foreach ($journals as $journal) {
            $linkedPayment = $referencedPayments->get($journal->sourceable_id);

            if ($linkedPayment === null) {
                $discrepancies[] = [
                    'company_id' => (int) $company->getKey(),
                    'account_id' => null,
                    'source_type' => Payment::class,
                    'source_id' => $journal->sourceable_id,
                    'issue' => 'journal_linked_to_missing_payment',
                    'expected_amount' => null,
                    'actual_amount' => null,
                    'difference' => null,
                    'journal_id' => (int) $journal->getKey(),
                    'detail' => 'A payment-source journal does not resolve to an existing payment record.',
                ];

                continue;
            }

            if (
                (int) $linkedPayment->company_id !== (int) $journal->company_id
                || (int) $journal->company_id !== (int) $company->getKey()
            ) {
                $discrepancies[] = $this->paymentIssue(
                    $company,
                    $linkedPayment,
                    'payment_journal_company_mismatch',
                    'Payment and journal company IDs to match',
                    'payment='.$linkedPayment->company_id.', journal='.$journal->company_id,
                    (int) $journal->getKey(),
                );
            }

            if (! in_array($linkedPayment->status, ['posted', 'reversed'], true) || $linkedPayment->posted_at === null) {
                $discrepancies[] = $this->paymentIssue(
                    $company,
                    $linkedPayment,
                    'journal_linked_to_invalid_payment',
                    'A posted or reversed payment with posted_at',
                    $linkedPayment->status,
                    (int) $journal->getKey(),
                );
            }
        }

        return [
            'status' => $discrepancies === [] ? 'consistent' : 'discrepant',
            'supported' => true,
            'company_id' => (int) $company->getKey(),
            'category' => 'payment_integrity',
            'control_account' => null,
            'expected_amount' => null,
            'actual_amount' => null,
            'operational_balance' => null,
            'general_ledger_balance' => null,
            'difference' => null,
            'currency' => strtoupper((string) $company->base_currency),
            'native_balances' => [],
            'source_records' => [],
            'missing_mappings' => [],
            'discrepancies' => $discrepancies,
            'difference_classification' => 'not_applicable',
            'timing_differences_supported' => false,
            'limitations' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $discrepancies
     */
    private function appendPaymentRelationshipIssues(array &$discrepancies, Company $company, Payment $payment): void
    {
        $baseCurrency = strtoupper((string) $company->base_currency);
        $paymentCurrency = strtoupper((string) $payment->currency);
        $paymentBaseCurrency = strtoupper((string) $payment->base_currency);
        $issue = null;
        $expected = null;
        $actual = null;

        if (
            preg_match('/^[A-Z]{3}$/', $paymentCurrency) !== 1
            || preg_match('/^[A-Z]{3}$/', $paymentBaseCurrency) !== 1
            || $paymentBaseCurrency !== $baseCurrency
        ) {
            $issue = 'payment_currency_or_company_mismatch';
            $expected = 'Valid payment currency and base currency '.$baseCurrency;
            $actual = $paymentCurrency.'/'.$paymentBaseCurrency;
        } elseif ($payment->party_id !== null) {
            $party = Party::withoutGlobalScope(CompanyScope::class)->find($payment->party_id);

            if ($party === null || (int) $party->company_id !== (int) $payment->company_id) {
                $issue = 'payment_party_company_mismatch';
                $expected = 'Party belonging to payment company';
                $actual = $party === null ? 'missing party' : 'company '.$party->company_id;
            }
        }

        if ($issue === null && $payment->financial_account_id !== null) {
            $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($payment->financial_account_id);

            if (
                $account === null
                || (int) $account->company_id !== (int) $payment->company_id
                || strtoupper((string) $account->currency) !== $paymentCurrency
            ) {
                $issue = 'payment_account_relationship_mismatch';
                $expected = 'Payment-company account in '.$paymentCurrency;
                $actual = $account === null
                    ? 'missing account'
                    : 'company '.$account->company_id.' / '.$account->currency;
            }
        }

        if ($issue === null && ($payment->payable_type === null) !== ($payment->payable_id === null)) {
            $issue = 'payment_source_relationship_incomplete';
            $expected = 'Both source type and source ID, or neither';
            $actual = (string) $payment->payable_type.'/'.$payment->payable_id;
        } elseif ($issue === null && $payment->payable_type !== null) {
            $source = match ($payment->payable_type) {
                Purchase::class => Purchase::withoutGlobalScope(CompanyScope::class)->find($payment->payable_id),
                Revenue::class => Revenue::withoutGlobalScope(CompanyScope::class)->find($payment->payable_id),
                default => null,
            };

            if ($source === null || (int) $source->company_id !== (int) $payment->company_id) {
                $issue = 'payment_source_missing_or_company_mismatch';
                $expected = 'Existing source record belonging to payment company';
                $actual = $source === null ? 'missing or unsupported source type' : 'company '.$source->company_id;
            } elseif (
                $payment->applied_currency !== null
                && strtoupper((string) $payment->applied_currency) !== strtoupper((string) $source->currency)
            ) {
                $issue = 'payment_source_currency_mismatch';
                $expected = strtoupper((string) $source->currency);
                $actual = strtoupper((string) $payment->applied_currency);
            }
        }

        if ($issue !== null) {
            $discrepancies[] = $this->paymentIssue($company, $payment, $issue, $expected, $actual);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentIssue(
        Company $company,
        Payment $payment,
        string $issue,
        ?string $expected,
        ?string $actual,
        ?int $journalId = null,
    ): array {
        $hasNumericAmounts = $expected !== null
            && $actual !== null
            && preg_match('/^-?\d+(?:\.\d+)?$/', $expected) === 1
            && preg_match('/^-?\d+(?:\.\d+)?$/', $actual) === 1;

        return [
            'company_id' => (int) $company->getKey(),
            'account_id' => $payment->financial_account_id === null ? null : (int) $payment->financial_account_id,
            'source_type' => Payment::class,
            'source_id' => (int) $payment->getKey(),
            'issue' => $issue,
            'expected_amount' => $hasNumericAmounts ? $expected : null,
            'actual_amount' => $hasNumericAmounts ? $actual : null,
            'difference' => $hasNumericAmounts ? bcsub($actual, $expected, 10) : null,
            'expected' => $expected,
            'actual' => $actual,
            'journal_id' => $journalId,
            'detail' => $issue,
        ];
    }
}
