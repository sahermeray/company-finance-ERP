<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseLandedCost;
use App\Models\PurchaseLandedCostAllocation;
use App\Models\PurchaseTaxLine;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly InventoryService $inventory,
        private readonly InventoryCostingService $costing,
        private readonly AccountingService $accounting,
        private readonly PaymentService $payments,
    ) {}

    public function createDraftPurchase(array $attributes = [], ?Company $company = null): Purchase
    {
        $company ??= $this->companyContext->requireCurrent();

        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $company->getKey()) {
            $this->fail('company_id', 'The selected company does not match the active company context.');
        }

        $payload = [
            'company_id' => $company->getKey(),
            'party_id' => $attributes['party_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? Auth::id(),
            'purchase_number' => $attributes['purchase_number'] ?? $this->generatePurchaseNumber($company),
            'purchase_date' => $attributes['purchase_date'] ?? now()->toDateString(),
            'due_date' => $attributes['due_date'] ?? null,
            'status' => $attributes['status'] ?? 'draft',
            'currency' => strtoupper((string) ($attributes['currency'] ?? $company->base_currency)),
            'exchange_rate' => $attributes['exchange_rate'] ?? null,
            'base_currency' => strtoupper((string) ($attributes['base_currency'] ?? $company->base_currency)),
            'subtotal' => $attributes['subtotal'] ?? '0',
            'tax_total' => $attributes['tax_total'] ?? '0',
            'total' => $attributes['total'] ?? '0',
            'base_subtotal' => $attributes['base_subtotal'] ?? '0',
            'base_tax_total' => $attributes['base_tax_total'] ?? '0',
            'base_total' => $attributes['base_total'] ?? '0',
            'notes' => $attributes['notes'] ?? null,
        ];

        $purchase = Purchase::create($payload);

        if ($purchase->status !== 'draft') {
            $this->fail('status', 'Draft purchases must remain in draft status until posted.');
        }

        $purchase->exchange_rate = $this->resolveExchangeRate($purchase, $purchase->exchange_rate);
        $purchase->save();

        return $purchase->fresh();
    }

    public function addPurchaseItem(Purchase $purchase, array $attributes): PurchaseItem
    {
        $this->assertPurchaseBelongsToActiveCompany($purchase);

        if ($purchase->status !== 'draft') {
            $this->fail('purchase', 'Only draft purchases can accept new items.');
        }

        $product = Product::query()->find($attributes['product_id'] ?? null);
        if ($product === null) {
            $this->fail('product_id', 'The selected product does not exist.');
        }

        $this->assertProductBelongsToCompany($product, $purchase->company);

        $warehouseId = $attributes['warehouse_id'] ?? null;
        if ($warehouseId !== null) {
            $warehouse = Warehouse::query()->find($warehouseId);
            if ($warehouse === null || (int) $warehouse->company_id !== (int) $purchase->company_id) {
                $this->fail('warehouse_id', 'The selected warehouse must belong to the purchase company.');
            }
        }

        $unit = $attributes['unit_of_measure_id'] !== null
            ? UnitOfMeasure::query()->find($attributes['unit_of_measure_id'])
            : $product->unitOfMeasure()->first();

        $this->assertUnitCompatible($product, $unit);

        $quantity = $this->normalizeAmount((string) ($attributes['quantity'] ?? '0'), 'quantity');
        $unitPrice = $this->normalizeAmount((string) ($attributes['unit_price'] ?? '0'), 'unit_price');

        if (bccomp($quantity, '0', 10) <= 0) {
            $this->fail('quantity', 'The quantity must be greater than zero.');
        }

        if (bccomp($unitPrice, '0', 10) < 0) {
            $this->fail('unit_price', 'The unit price cannot be negative.');
        }

        $lineTotal = isset($attributes['line_total'])
            ? $this->normalizeAmount((string) $attributes['line_total'], 'line_total')
            : bcmul($quantity, $unitPrice, 10);

        $item = PurchaseItem::create([
            'purchase_id' => $purchase->getKey(),
            'product_id' => $product->getKey(),
            'warehouse_id' => $attributes['warehouse_id'] ?? null,
            'expense_account_id' => $attributes['expense_account_id'] ?? null,
            'unit_of_measure_id' => $unit?->getKey(),
            'line_number' => $this->nextLineNumber($purchase),
            'description' => $attributes['description'] ?? $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ]);

        $this->recalculateTotals($purchase);

        return $item->fresh();
    }

    public function addPurchaseTaxLine(Purchase $purchase, PurchaseItem $item, array $attributes): PurchaseTaxLine
    {
        $this->assertPurchaseBelongsToActiveCompany($purchase);

        if ($purchase->status !== 'draft' || (int) $item->purchase_id !== (int) $purchase->getKey()) {
            $this->fail('purchase_tax_line', 'Tax classification can only be added to an item on a draft purchase.');
        }

        $treatment = $attributes['treatment'] ?? null;
        if (! in_array($treatment, ['recoverable', 'non_recoverable'], true)) {
            $this->fail('treatment', 'Tax treatment must be recoverable or non-recoverable.');
        }

        $amount = $this->normalizeAmount((string) ($attributes['amount'] ?? '0'), 'amount');
        if (bccomp($amount, '0', 4) <= 0) {
            $this->fail('amount', 'A tax line amount must be greater than zero.');
        }

        $line = DB::transaction(function () use ($purchase, $item, $attributes, $treatment, $amount): PurchaseTaxLine {
            $line = $purchase->taxLines()->create([
                'company_id' => $purchase->company_id,
                'purchase_item_id' => $item->getKey(),
                'treatment' => $treatment,
                'amount' => $amount,
                'tax_asset_account_id' => $attributes['tax_asset_account_id'] ?? null,
            ]);

            $taxTotal = '0.0000';
            foreach ($purchase->taxLines()->get(['amount']) as $taxLine) {
                $taxTotal = bcadd($taxTotal, (string) $taxLine->amount, 4);
            }
            $purchase->tax_total = $taxTotal;
            $purchase->save();
            $this->recalculateTotals($purchase);

            return $line;
        });

        return $line->fresh();
    }

    public function addLandedCost(Purchase $purchase, array $attributes): PurchaseLandedCost
    {
        $this->assertPurchaseBelongsToActiveCompany($purchase);

        if ($purchase->status !== 'draft') {
            $this->fail('purchase', 'Landed costs can only be added to a draft purchase.');
        }

        $costType = $attributes['cost_type'] ?? null;
        if (! in_array($costType, ['direct_freight', 'direct_handling', 'administrative'], true)) {
            $this->fail('cost_type', 'Landed cost type must be direct freight, direct handling, or administrative.');
        }

        $amount = $this->normalizeAmount((string) ($attributes['amount'] ?? '0'), 'amount');
        if (bccomp($amount, '0', 4) <= 0) {
            $this->fail('amount', 'A landed cost amount must be greater than zero.');
        }

        $currency = strtoupper((string) ($attributes['currency'] ?? $purchase->currency));
        $baseCurrency = strtoupper((string) $purchase->company->base_currency);
        $exchangeRate = $this->resolveStoredRate(
            $currency,
            $baseCurrency,
            $attributes['exchange_rate'] ?? null,
            $purchase->purchase_date->toDateString(),
            (int) $purchase->company_id,
            'exchange_rate',
        );
        $baseAmount = $this->convertCurrencyAmount($amount, $exchangeRate, $currency, $baseCurrency);
        $expenseAccountId = $attributes['expense_account_id'] ?? null;
        if ($expenseAccountId !== null) {
            $this->resolveAccount(
                $purchase->company,
                $expenseAccountId,
                ['expense'],
                $currency,
                'expense_account_id',
                'Landed-cost expense account',
            );
        }
        $payableAccount = $this->resolveAccount(
            $purchase->company,
            $attributes['payable_account_id'] ?? null,
            ['liability'],
            $currency,
            'payable_account_id',
            'Landed-cost payable account',
        );

        return $purchase->landedCosts()->create([
            'company_id' => $purchase->company_id,
            'cost_type' => $costType,
            'amount' => $amount,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'base_currency' => $baseCurrency,
            'base_amount' => $baseAmount,
            'expense_account_id' => $expenseAccountId,
            'payable_account_id' => $payableAccount->getKey(),
            'description' => $attributes['description'] ?? null,
        ]);
    }

    public function recalculateTotals(Purchase $purchase): Purchase
    {
        $this->assertPurchaseBelongsToActiveCompany($purchase);

        if ($purchase->status !== 'draft') {
            $this->fail('purchase', 'Only draft purchases can be rebalanced.');
        }

        $subtotal = '0';
        foreach ($purchase->items()->get() as $item) {
            $subtotal = bcadd($subtotal, $this->normalizeAmount((string) ($item->line_total ?? '0'), 'line_total'), 10);
        }

        $taxTotal = $this->normalizeAmount((string) ($purchase->tax_total ?? '0'), 'tax_total');
        $total = bcadd($subtotal, $taxTotal, 10);
        $exchangeRate = $this->resolveExchangeRate($purchase, $purchase->exchange_rate ?? null);
        $currency = strtoupper((string) $purchase->currency);
        $baseCurrency = strtoupper((string) ($purchase->base_currency ?? $purchase->company?->base_currency ?? 'SYP'));

        $purchase->subtotal = $subtotal;
        $purchase->tax_total = $taxTotal;
        $purchase->total = $total;
        $purchase->currency = $currency;
        $purchase->exchange_rate = $exchangeRate;
        $purchase->base_currency = $baseCurrency;
        $purchase->base_subtotal = $this->convertCurrencyAmount($subtotal, $exchangeRate, $purchase->currency, $baseCurrency);
        $purchase->base_tax_total = $this->convertCurrencyAmount($taxTotal, $exchangeRate, $purchase->currency, $baseCurrency);
        $purchase->base_total = bcadd($purchase->base_subtotal, $purchase->base_tax_total, 4);
        $purchase->save();

        return $purchase->fresh();
    }

    public function postPurchase(Purchase $purchase, array $options = []): Purchase
    {
        $this->assertPurchaseBelongsToActiveCompany($purchase);

        return DB::transaction(function () use ($purchase, $options): Purchase {
            $company = Company::query()
                ->whereKey($purchase->company_id)
                ->lockForUpdate()
                ->first();

            if ($company === null) {
                $this->fail('company_id', 'The purchase company could not be found.');
            }

            $lockedPurchase = Purchase::query()
                ->where('company_id', $company->getKey())
                ->whereKey($purchase->getKey())
                ->lockForUpdate()
                ->with(['company', 'party'])
                ->first();

            if ($lockedPurchase === null) {
                $this->fail('purchase', 'The purchase could not be found in the active company.');
            }

            $this->assertPurchaseBelongsToActiveCompany($lockedPurchase);

            if ($lockedPurchase->status === 'posted') {
                $this->fail('status', 'A posted purchase cannot be posted again.');
            }

            if ($lockedPurchase->status !== 'draft') {
                $this->fail('status', 'Only draft purchases can be posted.');
            }

            if (AccountingJournal::query()
                ->where('company_id', $company->getKey())
                ->where('sourceable_type', Purchase::class)
                ->where('sourceable_id', $lockedPurchase->getKey())
                ->exists()) {
                $this->fail('accounting_journal_id', 'An accounting journal is already linked to this purchase.');
            }

            if ($lockedPurchase->stockMovements()->exists()) {
                $this->fail('stock_movement', 'Stock receipts are already linked to this purchase.');
            }

            $items = $lockedPurchase->items()
                ->with(['product.unitOfMeasure', 'unitOfMeasure', 'warehouse', 'expenseAccount', 'taxLines'])
                ->orderBy('id')
                ->get();
            if ($items->isEmpty()) {
                $this->fail('items', 'A purchase must contain at least one item before it can be posted.');
            }

            $this->validatePurchaseForPosting($lockedPurchase, $items, $options);

            $productIds = $items
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $this->inventory->lockProductsForValuation($productIds);
            $items = $lockedPurchase->items()
                ->with(['product.unitOfMeasure', 'unitOfMeasure', 'warehouse', 'expenseAccount', 'taxLines'])
                ->orderBy('id')
                ->get();
            $this->validatePurchaseForPosting($lockedPurchase, $items, $options);
            $productIds = $items
                ->filter(fn (PurchaseItem $item): bool => (bool) $item->product->tracks_inventory)
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $lockedPurchase->currency = strtoupper((string) $lockedPurchase->currency);
            $lockedPurchase->base_currency = strtoupper((string) $lockedPurchase->base_currency);
            $lockedPurchase->exchange_rate = $this->resolveExchangeRate($lockedPurchase, $lockedPurchase->exchange_rate);
            $lockedPurchase->subtotal = $this->normalizeAmount((string) $lockedPurchase->subtotal, 'subtotal');
            $lockedPurchase->tax_total = $this->normalizeAmount((string) $lockedPurchase->tax_total, 'tax_total');
            $lockedPurchase->total = bcadd($lockedPurchase->subtotal, $lockedPurchase->tax_total, 4);
            $lockedPurchase->base_subtotal = $this->convertCurrencyAmount(
                $lockedPurchase->subtotal,
                $lockedPurchase->exchange_rate,
                $lockedPurchase->currency,
                $lockedPurchase->base_currency,
            );
            $lockedPurchase->base_tax_total = $this->convertCurrencyAmount(
                $lockedPurchase->tax_total,
                $lockedPurchase->exchange_rate,
                $lockedPurchase->currency,
                $lockedPurchase->base_currency,
            );
            $lockedPurchase->base_total = bcadd($lockedPurchase->base_subtotal, $lockedPurchase->base_tax_total, 4);
            $lockedPurchase->save();

            $cutover = InventoryCutover::query()
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->first();

            if ($cutover !== null && Carbon::parse($lockedPurchase->purchase_date)->endOfDay()->lt($cutover->effective_at)) {
                $this->fail('purchase_date', 'A purchase dated before the inventory cutover cannot be posted as a new receipt.');
            }

            $defaultWarehouse = isset($options['warehouse_id'])
                ? $this->resolveWarehouse($options, $company)
                : null;
            $inventoryAccount = $productIds === []
                ? null
                : $this->resolveAccount(
                    $company,
                    $options['inventory_account_id'] ?? null,
                    ['asset'],
                    $lockedPurchase->base_currency,
                    'inventory_account_id',
                    'Inventory asset account',
                );
            $payableAccount = $this->resolveAccount(
                $company,
                $options['payable_account_id'] ?? null,
                ['liability'],
                $lockedPurchase->currency,
                'payable_account_id',
                'Supplier payable account',
            );
            $breakdown = $this->buildPurchasePostingBreakdown($lockedPurchase, $items, $options);

            $journal = $this->accounting->createDraftJournal([
                'company_id' => $company->getKey(),
                'project_id' => $lockedPurchase->project_id,
                'created_by' => $lockedPurchase->created_by,
                'sourceable_type' => Purchase::class,
                'sourceable_id' => $lockedPurchase->getKey(),
                'journal_number' => $options['journal_number'] ?? 'PUR-'.strtoupper($lockedPurchase->purchase_number),
                'entry_date' => $lockedPurchase->purchase_date->toDateString(),
                'description' => 'Purchase posting for '.$lockedPurchase->purchase_number,
            ]);

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $payableAccount->getKey(),
                'party_id' => $lockedPurchase->party_id,
                'project_id' => $lockedPurchase->project_id,
                'credit' => $lockedPurchase->total,
                'base_credit' => $lockedPurchase->base_total,
                'currency' => $lockedPurchase->currency,
                'exchange_rate' => $lockedPurchase->exchange_rate,
                'base_currency' => $lockedPurchase->base_currency,
                'description' => 'Supplier payable for purchase '.$lockedPurchase->purchase_number,
            ]);

            $this->postPurchaseDebits($journal, $lockedPurchase, $items, $inventoryAccount, $breakdown);
            $this->postLandedCostLines($journal, $lockedPurchase, $inventoryAccount, $breakdown);
            $this->accounting->postJournal($journal);

            foreach ($items as $item) {
                if (! $item->product->tracks_inventory) {
                    continue;
                }

                $warehouse = $item->warehouse ?? $defaultWarehouse;
                if ($warehouse === null) {
                    $this->fail('warehouse_id', 'A warehouse is required for every tracked purchase item.');
                }

                $baseCost = $breakdown['inventory_cost_by_item'][$item->getKey()];
                $valuationStatus = $this->costing->valuationHistoryStatus($item->product);
                $requiresValuation = $cutover !== null || $valuationStatus === 'valued';
                $movementOptions = [
                    'reference' => $lockedPurchase->purchase_number,
                    'notes' => $lockedPurchase->notes,
                    'sourceable_type' => Purchase::class,
                    'sourceable_id' => $lockedPurchase->getKey(),
                    'purchase_item_id' => $item->getKey(),
                    'project_id' => $lockedPurchase->project_id,
                    'party_id' => $lockedPurchase->party_id,
                    'occurred_at' => Carbon::parse($lockedPurchase->purchase_date)->endOfDay(),
                ];
                if ($requiresValuation) {
                    $movementOptions['base_cost_amount'] = $baseCost;
                } else {
                    $movementOptions['allow_unvalued_legacy'] = true;
                }

                $this->inventory->receiveStock(
                    $item->product,
                    $warehouse,
                    (string) $item->quantity,
                    $item->unitOfMeasure,
                    $movementOptions,
                );
            }

            $lockedPurchase->status = 'posted';
            $lockedPurchase->save();

            return $lockedPurchase->fresh(['items', 'company', 'party', 'taxLines', 'landedCosts']);
        });
    }

    public function post(Purchase $purchase, array $options = []): Purchase
    {
        return $this->postPurchase($purchase, $options);
    }

    public function calculateOutstanding(Purchase $purchase): string
    {
        // Delegate to the single authoritative implementation in PaymentService so
        // both callers always use the same definition: only posted payments count,
        // draft and reversed payments are excluded, and tenant isolation is enforced
        // even when there is no active CompanyContextService session.
        return $this->payments->calculateOutstandingFor($purchase);
    }

    /**
     * @return array{
     *     base_subtotal_by_item: array<int, string>,
     *     base_tax_by_line: array<int, string>,
     *     nonrecoverable_amount_by_item: array<int, string>,
     *     nonrecoverable_base_by_item: array<int, string>,
     *     recoverable_tax_accounts: array<int, FinancialAccount>,
     *     expense_accounts_by_item: array<int, FinancialAccount>,
     *     landed_costs: array<int, array{record: PurchaseLandedCost, capitalized: bool, allocations: array<int, string>, expense_account: ?FinancialAccount, payable_account: FinancialAccount}>,
     *     inventory_cost_by_item: array<int, string>
     * }
     */
    private function buildPurchasePostingBreakdown(Purchase $purchase, Collection $items, array $options): array
    {
        $baseSubtotalByItem = $this->allocateBaseAmount(
            (string) $purchase->base_subtotal,
            $items->mapWithKeys(fn (PurchaseItem $item): array => [
                (int) $item->getKey() => (string) $item->line_total,
            ])->all(),
        );
        $taxLines = PurchaseTaxLine::query()
            ->where('purchase_id', $purchase->getKey())
            ->orderBy('id')
            ->get();
        $baseTaxByLine = $this->allocateBaseAmount(
            (string) $purchase->base_tax_total,
            $taxLines->mapWithKeys(fn (PurchaseTaxLine $line): array => [
                (int) $line->getKey() => (string) $line->amount,
            ])->all(),
        );

        $nonrecoverableAmountByItem = [];
        $nonrecoverableBaseByItem = [];
        $recoverableTaxAccounts = [];

        foreach ($items as $item) {
            $nonrecoverableAmountByItem[$item->getKey()] = '0.0000';
            $nonrecoverableBaseByItem[$item->getKey()] = '0.0000';
        }

        foreach ($taxLines as $taxLine) {
            $taxLine->base_amount = $baseTaxByLine[$taxLine->getKey()] ?? '0.0000';
            $taxLine->save();

            if ($taxLine->treatment === 'non_recoverable') {
                $itemId = (int) $taxLine->purchase_item_id;
                $nonrecoverableAmountByItem[$itemId] = bcadd(
                    $nonrecoverableAmountByItem[$itemId],
                    (string) $taxLine->amount,
                    4,
                );
                $nonrecoverableBaseByItem[$itemId] = bcadd(
                    $nonrecoverableBaseByItem[$itemId],
                    (string) $taxLine->base_amount,
                    4,
                );

                continue;
            }

            $recoverableTaxAccounts[$taxLine->getKey()] = $this->resolveAccount(
                $purchase->company,
                $taxLine->tax_asset_account_id,
                ['asset'],
                $purchase->currency,
                'tax_asset_account_id',
                'Recoverable tax asset account',
            );
        }

        $expenseAccountsByItem = [];
        foreach ($items as $item) {
            if ($item->product->tracks_inventory) {
                continue;
            }

            $sourceExpenseAmount = bcadd(
                (string) $item->line_total,
                $nonrecoverableAmountByItem[$item->getKey()],
                4,
            );
            if (bccomp($sourceExpenseAmount, '0', 4) === 0) {
                continue;
            }

            $expenseAccountsByItem[$item->getKey()] = $this->resolveAccount(
                $purchase->company,
                $item->expense_account_id ?? $options['expense_account_id'] ?? null,
                ['expense'],
                $purchase->currency,
                'expense_account_id',
                'Purchase expense account',
            );
        }

        $trackedItemWeights = $items
            ->filter(fn (PurchaseItem $item): bool => $item->product->tracks_inventory
                && bccomp((string) $item->line_total, '0', 4) > 0)
            ->mapWithKeys(fn (PurchaseItem $item): array => [
                (int) $item->getKey() => (string) $item->line_total,
            ])
            ->all();

        $landedCosts = [];
        foreach ($purchase->landedCosts()->orderBy('id')->get() as $landedCost) {
            if (strtoupper((string) $landedCost->base_currency) !== $purchase->base_currency
                || bccomp((string) $landedCost->exchange_rate, '0', 10) <= 0) {
                $this->fail('purchase_landed_cost', 'Landed cost currency valuation is incomplete or inconsistent.');
            }

            $expectedBaseAmount = $this->convertCurrencyAmount(
                (string) $landedCost->amount,
                (string) $landedCost->exchange_rate,
                strtoupper((string) $landedCost->currency),
                $purchase->base_currency,
            );
            if (bccomp($expectedBaseAmount, (string) $landedCost->base_amount, 4) !== 0) {
                $this->fail('purchase_landed_cost', 'The stored landed-cost base amount does not match its stored exchange rate.');
            }

            $payableAccount = $this->resolveAccount(
                $purchase->company,
                $landedCost->payable_account_id,
                ['liability'],
                strtoupper((string) $landedCost->currency),
                'payable_account_id',
                'Landed-cost payable account',
            );
            $capitalized = in_array($landedCost->cost_type, ['direct_freight', 'direct_handling'], true)
                && $trackedItemWeights !== [];
            $expenseAccount = null;
            if (! $capitalized || $landedCost->expense_account_id !== null) {
                $expenseAccount = $this->resolveAccount(
                    $purchase->company,
                    $landedCost->expense_account_id,
                    ['expense'],
                    strtoupper((string) $landedCost->currency),
                    'expense_account_id',
                    'Landed-cost expense account',
                );
            }
            $allocations = [];
            if ($capitalized) {
                if ($landedCost->allocations()->exists()) {
                    $this->fail('purchase_landed_cost', 'Landed-cost allocations already exist for this draft purchase.');
                }

                $allocations = $this->allocateBaseAmount((string) $landedCost->base_amount, $trackedItemWeights);
                $sourceAllocations = $this->allocateBaseAmount((string) $landedCost->amount, $trackedItemWeights);
                foreach ($allocations as $itemId => $baseAmount) {
                    $sourceAmount = $sourceAllocations[$itemId] ?? '0.0000';
                    if (bccomp($sourceAmount, '0', 4) === 0 && bccomp($baseAmount, '0', 4) === 0) {
                        continue;
                    }

                    PurchaseLandedCostAllocation::create([
                        'company_id' => $purchase->company_id,
                        'purchase_landed_cost_id' => $landedCost->getKey(),
                        'purchase_item_id' => $itemId,
                        'allocated_amount' => $sourceAmount,
                        'base_amount' => $baseAmount,
                    ]);
                }
            }

            $landedCosts[$landedCost->getKey()] = [
                'record' => $landedCost,
                'capitalized' => $capitalized,
                'allocations' => $allocations,
                'expense_account' => $expenseAccount,
                'payable_account' => $payableAccount,
            ];
        }

        $inventoryCostByItem = [];
        foreach ($items as $item) {
            $inventoryCost = bcadd(
                $baseSubtotalByItem[$item->getKey()] ?? '0.0000',
                $nonrecoverableBaseByItem[$item->getKey()],
                4,
            );

            foreach ($landedCosts as $landedCost) {
                $inventoryCost = bcadd(
                    $inventoryCost,
                    $landedCost['allocations'][$item->getKey()] ?? '0.0000',
                    4,
                );
            }

            $inventoryCostByItem[$item->getKey()] = $inventoryCost;
        }

        return [
            'base_subtotal_by_item' => $baseSubtotalByItem,
            'base_tax_by_line' => $baseTaxByLine,
            'nonrecoverable_amount_by_item' => $nonrecoverableAmountByItem,
            'nonrecoverable_base_by_item' => $nonrecoverableBaseByItem,
            'recoverable_tax_accounts' => $recoverableTaxAccounts,
            'expense_accounts_by_item' => $expenseAccountsByItem,
            'landed_costs' => $landedCosts,
            'inventory_cost_by_item' => $inventoryCostByItem,
        ];
    }

    private function postPurchaseDebits(
        AccountingJournal $journal,
        Purchase $purchase,
        Collection $items,
        ?FinancialAccount $inventoryAccount,
        array $breakdown,
    ): void {
        foreach ($items as $item) {
            $sourceAmount = bcadd(
                (string) $item->line_total,
                $breakdown['nonrecoverable_amount_by_item'][$item->getKey()],
                4,
            );
            $baseAmount = bcadd(
                $breakdown['base_subtotal_by_item'][$item->getKey()] ?? '0.0000',
                $breakdown['nonrecoverable_base_by_item'][$item->getKey()],
                4,
            );

            if ($item->product->tracks_inventory) {
                if (bccomp($baseAmount, '0', 4) > 0) {
                    $this->accounting->addJournalLine($journal, [
                        'financial_account_id' => $inventoryAccount?->getKey(),
                        'party_id' => $purchase->party_id,
                        'project_id' => $purchase->project_id,
                        'debit' => $baseAmount,
                        'base_debit' => $baseAmount,
                        'currency' => $purchase->base_currency,
                        'exchange_rate' => '1',
                        'base_currency' => $purchase->base_currency,
                        'description' => 'Inventory received from purchase '.$purchase->purchase_number,
                    ]);
                }

                continue;
            }

            if (bccomp($sourceAmount, '0', 4) > 0) {
                $expenseAccount = $breakdown['expense_accounts_by_item'][$item->getKey()];
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $expenseAccount->getKey(),
                    'party_id' => $purchase->party_id,
                    'project_id' => $purchase->project_id,
                    'debit' => $sourceAmount,
                    'base_debit' => $baseAmount,
                    'currency' => $purchase->currency,
                    'exchange_rate' => $purchase->exchange_rate,
                    'base_currency' => $purchase->base_currency,
                    'description' => 'Purchase expense for '.$purchase->purchase_number,
                ]);
            }
        }

        $taxLines = PurchaseTaxLine::query()
            ->where('purchase_id', $purchase->getKey())
            ->where('treatment', 'recoverable')
            ->orderBy('id')
            ->get();
        foreach ($taxLines as $taxLine) {
            $taxAccount = $breakdown['recoverable_tax_accounts'][$taxLine->getKey()];
            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $taxAccount->getKey(),
                'party_id' => $purchase->party_id,
                'project_id' => $purchase->project_id,
                'debit' => $taxLine->amount,
                'base_debit' => $taxLine->base_amount,
                'currency' => $purchase->currency,
                'exchange_rate' => $purchase->exchange_rate,
                'base_currency' => $purchase->base_currency,
                'description' => 'Recoverable purchase tax for '.$purchase->purchase_number,
            ]);
        }
    }

    private function postLandedCostLines(
        AccountingJournal $journal,
        Purchase $purchase,
        ?FinancialAccount $inventoryAccount,
        array $breakdown,
    ): void {
        foreach ($breakdown['landed_costs'] as $entry) {
            /** @var PurchaseLandedCost $landedCost */
            $landedCost = $entry['record'];

            if ($entry['capitalized']) {
                foreach ($entry['allocations'] as $itemId => $baseAmount) {
                    if (bccomp($baseAmount, '0', 4) <= 0) {
                        continue;
                    }

                    $this->accounting->addJournalLine($journal, [
                        'financial_account_id' => $inventoryAccount?->getKey(),
                        'party_id' => $purchase->party_id,
                        'project_id' => $purchase->project_id,
                        'debit' => $baseAmount,
                        'base_debit' => $baseAmount,
                        'currency' => $purchase->base_currency,
                        'exchange_rate' => '1',
                        'base_currency' => $purchase->base_currency,
                        'description' => 'Capitalized landed cost for purchase '.$purchase->purchase_number,
                    ]);
                }
            } else {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $entry['expense_account']->getKey(),
                    'party_id' => $purchase->party_id,
                    'project_id' => $purchase->project_id,
                    'debit' => $landedCost->amount,
                    'base_debit' => $landedCost->base_amount,
                    'currency' => $landedCost->currency,
                    'exchange_rate' => $landedCost->exchange_rate,
                    'base_currency' => $landedCost->base_currency,
                    'description' => $landedCost->description ?? 'Landed cost for purchase '.$purchase->purchase_number,
                ]);
            }

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $entry['payable_account']->getKey(),
                'party_id' => $purchase->party_id,
                'project_id' => $purchase->project_id,
                'credit' => $landedCost->amount,
                'base_credit' => $landedCost->base_amount,
                'currency' => $landedCost->currency,
                'exchange_rate' => $landedCost->exchange_rate,
                'base_currency' => $landedCost->base_currency,
                'description' => 'Landed-cost payable for purchase '.$purchase->purchase_number,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $weights
     * @return array<int, string>
     */
    private function allocateBaseAmount(string $total, array $weights): array
    {
        $total = $this->normalizeAmount($total, 'base_amount');
        $allocations = array_fill_keys(array_keys($weights), '0.0000');

        if (bccomp($total, '0', 4) === 0) {
            return $allocations;
        }

        $eligibleWeights = array_filter(
            $weights,
            fn (string $weight): bool => bccomp($this->normalizeAmount($weight, 'allocation_weight'), '0', 4) > 0,
        );
        $totalWeight = '0.0000';
        foreach ($eligibleWeights as $weight) {
            $totalWeight = bcadd($totalWeight, $this->normalizeAmount($weight, 'allocation_weight'), 4);
        }

        if ($eligibleWeights === [] || bccomp($totalWeight, '0', 4) <= 0) {
            $this->fail('allocation', 'A positive amount requires positive eligible purchase-line value.');
        }

        $lastKey = array_key_last($eligibleWeights);
        $allocated = '0.0000';
        foreach ($eligibleWeights as $key => $weight) {
            if ($key === $lastKey) {
                $share = bcsub($total, $allocated, 4);
            } else {
                $share = $this->normalizeAmount(
                    bcdiv(bcmul($total, $this->normalizeAmount($weight, 'allocation_weight'), 12), $totalWeight, 12),
                    'allocation',
                );
                $remaining = bcsub($total, $allocated, 4);
                if (bccomp($share, $remaining, 4) > 0) {
                    $share = $remaining;
                }
            }

            $allocations[$key] = $share;
            $allocated = bcadd($allocated, $share, 4);
        }

        return $allocations;
    }

    private function validatePurchaseForPosting(Purchase $purchase, Collection $items, array $options): void
    {
        if ($purchase->party_id === null) {
            $this->fail('party_id', 'A purchase must reference a supplier party before it can be posted.');
        }

        $party = Party::query()->find($purchase->party_id);
        if ($party === null) {
            $this->fail('party_id', 'The selected supplier does not exist.');
        }

        if ((int) $party->company_id !== (int) $purchase->company_id) {
            $this->fail('party_id', 'The supplier must belong to the same company as the purchase.');
        }

        $currency = strtoupper((string) ($purchase->currency ?? $purchase->company?->base_currency ?? 'SYP'));
        $baseCurrency = strtoupper((string) ($purchase->base_currency ?? $purchase->company?->base_currency ?? 'SYP'));
        $companyBaseCurrency = strtoupper((string) ($purchase->company?->base_currency ?? 'SYP'));

        if ($currency !== $purchase->currency) {
            $purchase->currency = $currency;
        }

        if ($baseCurrency !== $companyBaseCurrency) {
            $this->fail('base_currency', 'The purchase base currency must match the company base currency.');
        }

        $subtotal = '0.0000';
        foreach ($items as $item) {
            if ($item->product_id === null || $item->unit_of_measure_id === null) {
                $this->fail('item', 'Each purchase item must reference a valid product and unit.');
            }

            $product = $item->product;
            $unit = $item->unitOfMeasure;

            $this->assertProductBelongsToCompany($product, $purchase->company);
            $this->assertUnitCompatible($product, $unit);

            $quantity = $this->normalizeAmount((string) ($item->quantity ?? '0'), 'quantity');
            $unitPrice = $this->normalizeAmount((string) ($item->unit_price ?? '0'), 'unit_price');
            $lineTotal = $this->normalizeAmount((string) ($item->line_total ?? '0'), 'line_total');

            if (bccomp($quantity, '0', 4) <= 0) {
                $this->fail('quantity', 'Each purchase item quantity must be greater than zero.');
            }

            if (bccomp($unitPrice, '0', 4) < 0 || bccomp($lineTotal, '0', 4) < 0) {
                $this->fail('unit_price', 'Purchase item costs cannot be negative.');
            }

            if ($item->warehouse_id !== null) {
                $warehouse = $item->warehouse;
                if ($warehouse === null || (int) $warehouse->company_id !== (int) $purchase->company_id) {
                    $this->fail('warehouse_id', 'The selected warehouse must belong to the purchase company.');
                }
            }

            if ($product->tracks_inventory && $item->warehouse_id === null && ! isset($options['warehouse_id'])) {
                $this->fail('warehouse_id', 'A warehouse is required for every tracked purchase item.');
            }

            $subtotal = bcadd($subtotal, $lineTotal, 4);
        }

        if (bccomp($subtotal, $this->normalizeAmount((string) $purchase->subtotal, 'subtotal'), 4) !== 0) {
            $this->fail('subtotal', 'The purchase subtotal must equal the sum of its purchase lines.');
        }

        $taxLines = PurchaseTaxLine::query()
            ->where('purchase_id', $purchase->getKey())
            ->orderBy('id')
            ->get();
        $taxTotal = '0.0000';
        foreach ($taxLines as $taxLine) {
            if (! in_array($taxLine->treatment, ['recoverable', 'non_recoverable'], true)) {
                $this->fail('tax_total', 'Every tax line must have an explicit recoverable or non-recoverable treatment.');
            }

            $taxItemExists = $items->contains(
                fn (PurchaseItem $item): bool => (int) $item->getKey() === (int) $taxLine->purchase_item_id,
            );
            if (! $taxItemExists || (int) $taxLine->purchase_id !== (int) $purchase->getKey()) {
                $this->fail('tax_total', 'Every tax classification must belong to an item on this purchase.');
            }

            $taxAmount = $this->normalizeAmount((string) $taxLine->amount, 'tax_amount');
            if (bccomp($taxAmount, '0', 4) <= 0) {
                $this->fail('tax_total', 'Tax classification amounts must be greater than zero.');
            }

            if ($taxLine->treatment === 'recoverable' && $taxLine->tax_asset_account_id === null) {
                $this->fail('tax_asset_account_id', 'Recoverable tax requires a designated tax asset account.');
            }

            $taxTotal = bcadd($taxTotal, $taxAmount, 4);
        }

        $documentTaxTotal = $this->normalizeAmount((string) ($purchase->tax_total ?? '0'), 'tax_total');
        if (bccomp($taxTotal, $documentTaxTotal, 4) !== 0) {
            $message = bccomp($documentTaxTotal, '0', 4) > 0 && $taxLines->isEmpty()
                ? 'Tax treatment must be classified before posting this purchase.'
                : 'Classified tax lines must reconcile exactly with the purchase tax total.';
            $this->fail('tax_total', $message);
        }

        $documentTotal = bcadd($subtotal, $taxTotal, 4);
        if (bccomp($this->normalizeAmount((string) ($purchase->total ?? '0'), 'total'), $documentTotal, 4) !== 0) {
            $this->fail('total', 'The purchase total must equal subtotal plus classified tax.');
        }

        if (bccomp($documentTotal, '0', 4) <= 0) {
            $this->fail('total', 'The purchase total must be greater than zero.');
        }

        $rate = $this->resolveExchangeRate($purchase, $purchase->exchange_rate ?? null);
        if (bccomp($rate, '0', 10) <= 0) {
            $this->fail('exchange_rate', 'The exchange rate must be greater than zero.');
        }
    }

    private function resolveWarehouse(array $options, Company $company): Warehouse
    {
        $warehouseId = $options['warehouse_id'] ?? null;

        if ($warehouseId === null) {
            $this->fail('warehouse_id', 'A warehouse is required when posting a purchase.');
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null) {
            $this->fail('warehouse_id', 'The selected warehouse does not exist.');
        }

        if ((int) $warehouse->company_id !== (int) $company->getKey()) {
            $this->fail('warehouse_id', 'The selected warehouse must belong to the same company as the purchase.');
        }

        return $warehouse;
    }

    private function resolveAccount(
        Company $company,
        mixed $accountId,
        array $allowedTypes,
        string $currency,
        string $attribute,
        string $label,
    ): FinancialAccount {
        if ($accountId !== null) {
            if ((! is_int($accountId) && ! (is_string($accountId) && ctype_digit($accountId)))
                || (int) $accountId <= 0) {
                $this->fail($attribute, 'The selected '.$label.' is invalid.');
            }

            $account = FinancialAccount::query()->find($accountId);
            if ($account === null) {
                $this->fail($attribute, 'The selected '.$label.' does not exist.');
            }

            if ((int) $account->company_id !== (int) $company->getKey()) {
                $this->fail($attribute, 'The selected '.$label.' must belong to the same company.');
            }

            if (! in_array(strtolower((string) $account->account_type), $allowedTypes, true)) {
                $this->fail($attribute, 'The selected '.$label.' must be of type: '.implode(', ', $allowedTypes).'.');
            }

            if (! $account->is_active) {
                $this->fail($attribute, 'The selected '.$label.' must be active.');
            }

            if (strtoupper((string) $account->currency) !== strtoupper($currency)) {
                $this->fail($attribute, 'The selected '.$label.' must use '.$currency.' currency.');
            }

            return $account;
        }

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereIn('account_type', $allowedTypes)
            ->where('currency', strtoupper($currency))
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        if ($account === null) {
            $this->fail($attribute, 'No valid '.$label.' could be found for this company.');
        }

        return $account;
    }

    private function resolveExchangeRate(Purchase $purchase, ?string $exchangeRate = null): string
    {
        $currency = strtoupper((string) ($purchase->currency ?? $purchase->company?->base_currency ?? 'SYP'));
        $baseCurrency = strtoupper((string) ($purchase->base_currency ?? $purchase->company?->base_currency ?? 'SYP'));

        return $this->resolveStoredRate(
            $currency,
            $baseCurrency,
            $exchangeRate,
            $purchase->purchase_date->toDateString(),
            (int) $purchase->company_id,
            'exchange_rate',
        );
    }

    private function resolveStoredRate(
        string $currency,
        string $baseCurrency,
        mixed $exchangeRate,
        string $effectiveDate,
        int $companyId,
        string $field,
    ): string {
        if ($currency === $baseCurrency) {
            return '1';
        }

        if ($exchangeRate !== null && $exchangeRate !== '') {
            if (! is_numeric((string) $exchangeRate) || bccomp((string) $exchangeRate, '0', 10) <= 0) {
                $this->fail($field, 'The stored exchange rate must be a positive decimal value.');
            }

            return $this->normalizeExchangeRate((string) $exchangeRate, $field);
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $companyId)
            ->where('currency', $currency)
            ->where('base_currency', $baseCurrency)
            ->where('effective_at', '<=', Carbon::parse($effectiveDate)->endOfDay())
            ->orderByDesc('effective_at')
            ->value('rate');

        if ($rate !== null && bccomp((string) $rate, '0', 10) > 0) {
            return $this->normalizeExchangeRate((string) $rate, $field);
        }

        $this->fail($field, 'A valid exchange rate effective on the transaction date is required.');
    }

    private function convertCurrencyAmount(string $amount, string $exchangeRate, string $currency, string $baseCurrency): string
    {
        $amount = $this->normalizeAmount($amount, 'amount');

        if (strtoupper($currency) === strtoupper($baseCurrency) || bccomp($exchangeRate, '0', 10) <= 0) {
            return $amount;
        }

        return $this->normalizeAmount(bcmul($amount, $exchangeRate, 10), 'base_amount');
    }

    private function normalizeExchangeRate(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1 || bccomp($value, '0', 10) <= 0) {
            $this->fail($field, 'The exchange rate must be a positive decimal value.');
        }

        $truncatedEleven = bcadd($value, '0', 11);
        $truncatedTen = bcadd($value, '0', 10);
        $dotPosition = strpos($truncatedEleven, '.');
        $eleventhDigit = $dotPosition !== false && isset($truncatedEleven[$dotPosition + 11])
            ? $truncatedEleven[$dotPosition + 11]
            : '0';

        if ($eleventhDigit >= '5') {
            $truncatedTen = bcadd($truncatedTen, '0.0000000001', 10);
        }

        return bcadd($truncatedTen, '0', 10);
    }

    private function nextLineNumber(Purchase $purchase): int
    {
        return (int) ($purchase->items()->max('line_number') ?? 0) + 1;
    }

    private function assertPurchaseBelongsToActiveCompany(Purchase $purchase): void
    {
        $company = $this->companyContext->requireCurrent();
        if ((int) $purchase->company_id !== (int) $company->getKey()) {
            $this->fail('company_id', 'The purchase must belong to the active company.');
        }
    }

    private function assertProductBelongsToCompany(Product $product, Company $company): void
    {
        if ((int) $product->company_id !== (int) $company->getKey()) {
            $this->fail('product_id', 'The selected product does not belong to the active company.');
        }
    }

    private function assertUnitCompatible(Product $product, ?UnitOfMeasure $unit): void
    {
        if ($unit === null) {
            return;
        }

        if ((int) $product->unit_of_measure_id !== (int) $unit->getKey()) {
            $this->fail('unit_of_measure_id', 'The selected unit must match the product unit of measure.');
        }

        if ((int) $unit->company_id !== (int) $product->company_id) {
            $this->fail('unit_of_measure_id', 'The selected unit must belong to the same company as the product.');
        }
    }

    private function normalizeAmount(string $value, string $field): string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === null) {
            return '0';
        }

        if (! is_numeric($value)) {
            $this->fail($field, 'The value must be numeric.');
        }

        // Use bcmath for decimal-safe rounding to 4 places (round-half-up)
        // without floating-point arithmetic.  We truncate to 5 places first
        // to inspect the 5th digit, then conditionally add 0.0001 to the
        // 4-place truncation — avoiding carry-propagation edge cases that
        // arise from adding 0.00005 directly to large values.
        $isNegative = bccomp($value, '0', 20) < 0;
        $absValue = $isNegative ? bcmul($value, '-1', 20) : $value;

        $truncated5 = bcadd($absValue, '0', 5);   // 5 decimal places
        $truncated4 = bcadd($absValue, '0', 4);   // 4 decimal places

        $dotPos = strpos($truncated5, '.');
        $fifthDigit = ($dotPos !== false && isset($truncated5[$dotPos + 5]))
            ? $truncated5[$dotPos + 5]
            : '0';

        if ($fifthDigit >= '5') {
            $result = bcadd($truncated4, '0.0001', 4);
        } else {
            $result = $truncated4;
        }

        return $isNegative ? bcmul($result, '-1', 4) : $result;
    }

    private function generatePurchaseNumber(Company $company): string
    {
        $count = Purchase::query()->where('company_id', $company->getKey())->count() + 1;

        return 'PUR-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
