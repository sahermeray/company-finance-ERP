<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\Party;
use App\Models\Product;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly InventoryService $inventory,
        private readonly InventoryCostingService $costing,
        private readonly AccountingService $accounting,
    ) {}

    // -------------------------------------------------------------------------
    // Public API — draft creation
    // -------------------------------------------------------------------------

    public function createDraftRevenue(array $attributes = [], ?Company $company = null): Revenue
    {
        $company ??= $this->companyContext->requireCurrent();

        if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== (int) $company->getKey()) {
            $this->fail('company_id', 'The selected company does not match the active company context.');
        }

        $currency = strtoupper((string) ($attributes['currency'] ?? $company->base_currency));
        $baseCurrency = strtoupper((string) ($attributes['base_currency'] ?? $company->base_currency));
        $payload = [
            'company_id' => $company->getKey(),
            'party_id' => $attributes['party_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? Auth::id(),
            'revenue_number' => $attributes['revenue_number'] ?? $this->generateRevenueNumber($company),
            'revenue_date' => $attributes['revenue_date'] ?? now()->toDateString(),
            'due_date' => $attributes['due_date'] ?? null,
            'status' => 'draft',
            'currency' => $currency,
            'exchange_rate' => $attributes['exchange_rate'] ?? ($currency === $baseCurrency ? '1' : null),
            'base_currency' => $baseCurrency,
            'subtotal' => $attributes['subtotal'] ?? '0',
            'tax_total' => $attributes['tax_total'] ?? '0',
            'total' => $attributes['total'] ?? '0',
            'base_subtotal' => $attributes['base_subtotal'] ?? '0',
            'base_tax_total' => $attributes['base_tax_total'] ?? '0',
            'base_total' => $attributes['base_total'] ?? '0',
            'notes' => $attributes['notes'] ?? null,
        ];

        $revenue = Revenue::create($payload);

        return $revenue->fresh();
    }

    // -------------------------------------------------------------------------
    // Public API — line items
    // -------------------------------------------------------------------------

    public function addRevenueItem(Revenue $revenue, array $attributes): RevenueItem
    {
        $this->assertRevenueBelongsToActiveCompany($revenue);

        if ($revenue->status !== 'draft') {
            $this->fail('revenue', 'Only draft revenue documents can accept new items.');
        }

        $product = Product::query()->find($attributes['product_id'] ?? null);
        if ($product === null) {
            $this->fail('product_id', 'The selected product does not exist.');
        }

        $this->assertProductBelongsToCompany($product, $revenue->company);

        $warehouseId = $attributes['warehouse_id'] ?? null;
        if ($warehouseId !== null) {
            $this->resolveWarehouse(['warehouse_id' => $warehouseId], $revenue->company);
        }

        $unitId = $attributes['unit_of_measure_id'] ?? null;
        $unit = $unitId !== null
            ? UnitOfMeasure::query()->find($unitId)
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

        $item = RevenueItem::create([
            'revenue_id' => $revenue->getKey(),
            'product_id' => $product->getKey(),
            'warehouse_id' => $warehouseId,
            'unit_of_measure_id' => $unit?->getKey(),
            'line_number' => $this->nextLineNumber($revenue),
            'description' => $attributes['description'] ?? $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ]);

        $this->recalculateTotals($revenue);

        return $item->fresh();
    }

    // -------------------------------------------------------------------------
    // Public API — totals
    // -------------------------------------------------------------------------

    public function recalculateTotals(Revenue $revenue): Revenue
    {
        $this->assertRevenueBelongsToActiveCompany($revenue);

        if ($revenue->status !== 'draft') {
            $this->fail('revenue', 'Only draft revenue documents can be rebalanced.');
        }

        $subtotal = '0';
        foreach ($revenue->items()->get() as $item) {
            $subtotal = bcadd($subtotal, $this->normalizeAmount((string) ($item->line_total ?? '0'), 'line_total'), 10);
        }

        $taxTotal = $this->normalizeAmount((string) ($revenue->tax_total ?? '0'), 'tax_total');
        $total = bcadd($subtotal, $taxTotal, 10);
        $exchangeRate = $this->resolveExchangeRate($revenue);
        $baseCurrency = strtoupper((string) ($revenue->base_currency ?? $revenue->company?->base_currency ?? 'SYP'));

        $revenue->subtotal = $subtotal;
        $revenue->tax_total = $taxTotal;
        $revenue->total = $total;
        $revenue->exchange_rate = $exchangeRate;
        $revenue->base_currency = $baseCurrency;
        $revenue->base_subtotal = $this->convertAmount($subtotal, $exchangeRate, $revenue->currency, $baseCurrency);
        $revenue->base_tax_total = $this->convertAmount($taxTotal, $exchangeRate, $revenue->currency, $baseCurrency);
        $revenue->base_total = $this->convertAmount($total, $exchangeRate, $revenue->currency, $baseCurrency);
        $revenue->save();

        return $revenue->fresh();
    }

    // -------------------------------------------------------------------------
    // Public API — posting
    // -------------------------------------------------------------------------

    /**
     * Post a draft revenue document.
     *
     * Accounting journal (credit sale — receivable):
     *   Dr  Accounts Receivable   (asset)    — receivable_account_id
     *   Cr  Revenue               (revenue/income) — revenue_account_id
     *
     * Accounting journal (cash sale — cash/bank):
     *   Dr  Cash / Bank           (asset)    — cash_account_id
     *   Cr  Revenue               (revenue/income) — revenue_account_id
     *
     * For a credit sale pass receivable_account_id.
     * For a cash sale pass cash_account_id.
     * Exactly one of the two must be provided.
     *
     * Tracked stock is issued from the line warehouse or the document warehouse.
     * Valued issues require explicit inventory_account_id and cogs_account_id
     * accounts denominated in the company's base currency.
     * Pre-cutover legacy unvalued history remains unvalued.
     *
     * Required options:
     *   warehouse_id         — where goods are issued from
     *   revenue_account_id   — revenue or income account to credit
     *   inventory_account_id — base-currency inventory asset for valued issues
     *   cogs_account_id      — base-currency COGS expense for valued issues
     * Plus exactly one of:
     *   receivable_account_id — asset account to debit (credit sale)
     *   cash_account_id       — asset account to debit (cash sale)
     */
    public function postRevenue(Revenue $revenue, array $options = []): Revenue
    {
        return DB::transaction(function () use ($revenue, $options): Revenue {
            $this->assertRevenueBelongsToActiveCompany($revenue);

            $company = Company::query()
                ->whereKey($revenue->company_id)
                ->lockForUpdate()
                ->first();

            if ($company === null) {
                $this->fail('company_id', 'The revenue company could not be found.');
            }

            $revenue = Revenue::query()
                ->where('company_id', $company->getKey())
                ->whereKey($revenue->getKey())
                ->lockForUpdate()
                ->with(['items.product.unitOfMeasure', 'items.unitOfMeasure', 'items.warehouse', 'company', 'party'])
                ->first();

            if ($revenue === null) {
                $this->fail('revenue', 'The revenue document could not be found in the active company.');
            }

            $this->assertRevenueBelongsToActiveCompany($revenue);

            if ($revenue->status === 'posted') {
                $this->fail('status', 'A posted revenue document cannot be posted again.');
            }

            if ($revenue->status !== 'draft') {
                $this->fail('status', 'Only draft revenue documents can be posted.');
            }

            $items = $revenue->items()
                ->with(['product.unitOfMeasure', 'unitOfMeasure', 'warehouse'])
                ->orderBy('id')
                ->get();
            if ($items->isEmpty()) {
                $this->fail('items', 'A revenue document must contain at least one item before it can be posted.');
            }

            if (AccountingJournal::query()
                ->where('company_id', $company->getKey())
                ->where('sourceable_type', Revenue::class)
                ->where('sourceable_id', $revenue->getKey())
                ->exists()) {
                $this->fail('accounting_journal_id', 'An accounting journal is already linked to this revenue document.');
            }

            if ($revenue->stockMovements()->exists()) {
                $this->fail('stock_movement', 'Stock issues are already linked to this revenue document.');
            }

            $revenue->currency = strtoupper((string) ($revenue->currency ?? $company->base_currency));
            $revenue->base_currency = strtoupper((string) ($revenue->base_currency ?? $company->base_currency));
            if ($revenue->base_currency !== strtoupper((string) $company->base_currency)) {
                $this->fail('base_currency', 'The revenue base currency must match the company base currency.');
            }

            $this->validateRevenueForPosting($revenue, $items, $options);

            // Finalise currency fields.
            $revenue->exchange_rate = $this->resolveExchangeRate($revenue);
            $revenue->subtotal = $this->normalizeAmount((string) ($revenue->subtotal ?? '0'), 'subtotal');
            $revenue->tax_total = $this->normalizeAmount((string) ($revenue->tax_total ?? '0'), 'tax_total');
            $revenue->total = bcadd($revenue->subtotal, $revenue->tax_total, 10);
            $revenue->base_subtotal = $this->convertAmount($revenue->subtotal, $revenue->exchange_rate, $revenue->currency, $revenue->base_currency);
            $revenue->base_tax_total = $this->convertAmount($revenue->tax_total, $revenue->exchange_rate, $revenue->currency, $revenue->base_currency);
            $revenue->base_total = bcadd($revenue->base_subtotal, $revenue->base_tax_total, 10);
            $revenue->save();

            $productIds = $items
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $this->inventory->lockProductsForValuation($productIds);
            $items = $revenue->items()
                ->with(['product.unitOfMeasure', 'unitOfMeasure', 'warehouse'])
                ->orderBy('id')
                ->get();
            $this->validateRevenueForPosting($revenue, $items, $options);

            $hasPostedCutover = InventoryCutover::query()
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->exists();
            $trackedItems = $items->filter(fn (RevenueItem $item): bool => (bool) $item->product->tracks_inventory);
            $warehouseByItem = [];
            foreach ($trackedItems as $item) {
                $warehouseByItem[$item->getKey()] = $this->resolveWarehouse(
                    $options,
                    $company,
                    $item->warehouse_id === null ? null : (int) $item->warehouse_id,
                );
            }

            $warehouseIds = array_values(array_unique(array_map(
                static fn (Warehouse $warehouse): int => (int) $warehouse->getKey(),
                array_values($warehouseByItem),
            )));
            sort($warehouseIds, SORT_NUMERIC);
            $lockedWarehouses = $warehouseIds === []
                ? collect()
                : Warehouse::query()
                    ->where('company_id', $company->getKey())
                    ->whereIn('id', $warehouseIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            if ($lockedWarehouses->count() !== count($warehouseIds)) {
                $this->fail('warehouse_id', 'Every sales warehouse must belong to the active company.');
            }

            foreach ($warehouseByItem as $itemId => $warehouse) {
                $warehouseByItem[$itemId] = $lockedWarehouses->get($warehouse->getKey());
            }

            $valuationByProduct = [];
            foreach ($trackedItems as $item) {
                $productId = (int) $item->product_id;
                $historyStatus = $valuationByProduct[$productId]['history_status']
                    ?? $this->costing->valuationHistoryStatus($item->product);
                $valuationByProduct[$productId] = [
                    'history_status' => $historyStatus,
                    'value_issue' => $hasPostedCutover || $historyStatus === 'valued',
                ];
            }

            $hasValuedTrackedItems = collect($valuationByProduct)
                ->contains(static fn (array $valuation): bool => $valuation['value_issue']);
            $inventoryAccountId = null;
            $cogsAccountId = null;
            if ($hasValuedTrackedItems) {
                if (empty($options['inventory_account_id'])) {
                    $this->fail('inventory_account_id', 'An inventory asset account in company base currency is required for valued sales.');
                }

                if (empty($options['cogs_account_id'])) {
                    $this->fail('cogs_account_id', 'A cost-of-goods-sold expense account in company base currency is required for valued sales.');
                }

                $inventoryAccountId = $this->resolveAccountId(
                    $company,
                    (int) $options['inventory_account_id'],
                    ['asset'],
                    'inventory_account_id',
                    'Inventory asset account',
                    $revenue->base_currency,
                );
                $cogsAccountId = $this->resolveAccountId(
                    $company,
                    (int) $options['cogs_account_id'],
                    ['expense'],
                    'cogs_account_id',
                    'Cost of goods sold account',
                    $revenue->base_currency,
                );
            }

            // Determine the debit-side account (receivable or cash).
            $debitAccountId = $this->resolveDebitAccount($company, $options, $revenue->currency);

            // Revenue/income account to credit.
            $revenueAccountId = $this->resolveAccountId(
                $company,
                isset($options['revenue_account_id']) ? (int) $options['revenue_account_id'] : null,
                ['revenue', 'income'],
                'revenue_account_id',
                'Revenue account',
                $revenue->currency,
            );

            // Build the journal.
            $journal = $this->accounting->createDraftJournal([
                'company_id' => $company->getKey(),
                'project_id' => $revenue->project_id,
                'created_by' => $revenue->created_by,
                'sourceable_type' => Revenue::class,
                'sourceable_id' => $revenue->getKey(),
                'journal_number' => $options['journal_number'] ?? 'REV-'.strtoupper($revenue->revenue_number),
                'entry_date' => $revenue->revenue_date->toDateString(),
                'description' => 'Revenue posting for '.$revenue->revenue_number,
            ], $company);

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $debitAccountId,
                'party_id' => $revenue->party_id,
                'project_id' => $revenue->project_id,
                'debit' => $revenue->total,
                'currency' => $revenue->currency,
                'exchange_rate' => $revenue->exchange_rate,
                'base_currency' => $revenue->base_currency,
                'description' => 'Receivable / cash from revenue '.$revenue->revenue_number,
            ]);

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $revenueAccountId,
                'party_id' => $revenue->party_id,
                'project_id' => $revenue->project_id,
                'credit' => $revenue->total,
                'currency' => $revenue->currency,
                'exchange_rate' => $revenue->exchange_rate,
                'base_currency' => $revenue->base_currency,
                'description' => 'Revenue recognised for '.$revenue->revenue_number,
            ]);

            $totalCogs = '0.0000';
            foreach ($items as $item) {
                if (! $item->product->tracks_inventory) {
                    continue;
                }

                $productValuation = $valuationByProduct[(int) $item->product_id];
                $movement = $this->inventory->issueStock(
                    $item->product,
                    $warehouseByItem[$item->getKey()],
                    (string) $item->quantity,
                    $item->unitOfMeasure,
                    [
                        'reference' => $revenue->revenue_number,
                        'notes' => $revenue->notes,
                        'sourceable_type' => Revenue::class,
                        'sourceable_id' => $revenue->getKey(),
                        'revenue_item_id' => $item->getKey(),
                        'project_id' => $revenue->project_id,
                        'party_id' => $revenue->party_id,
                        'occurred_at' => $revenue->revenue_date->copy()->endOfDay(),
                        'value_at_moving_average' => $productValuation['value_issue'],
                        'allow_unvalued_legacy' => ! $productValuation['value_issue'],
                    ],
                );
                if ($productValuation['value_issue']) {
                    $totalCogs = bcadd($totalCogs, (string) $movement->base_cost_amount, 4);
                }
            }

            if (bccomp($totalCogs, '0', 4) > 0) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $cogsAccountId,
                    'party_id' => $revenue->party_id,
                    'project_id' => $revenue->project_id,
                    'debit' => $totalCogs,
                    'base_debit' => $totalCogs,
                    'currency' => $revenue->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $revenue->base_currency,
                    'description' => 'Cost of goods sold for revenue '.$revenue->revenue_number,
                ]);
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $inventoryAccountId,
                    'party_id' => $revenue->party_id,
                    'project_id' => $revenue->project_id,
                    'credit' => $totalCogs,
                    'base_credit' => $totalCogs,
                    'currency' => $revenue->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $revenue->base_currency,
                    'description' => 'Inventory relieved for revenue '.$revenue->revenue_number,
                ]);
            }

            $this->accounting->postJournal($journal);

            $revenue->status = 'posted';
            $revenue->save();

            return $revenue->fresh(['items', 'company', 'party']);
        });
    }

    // -------------------------------------------------------------------------
    // Private — validation
    // -------------------------------------------------------------------------

    private function validateRevenueForPosting(Revenue $revenue, Collection $items, array $options): void
    {
        if ($revenue->party_id === null) {
            $this->fail('party_id', 'A revenue document must reference a customer party before it can be posted.');
        }

        $party = Party::query()->find($revenue->party_id);
        if ($party === null) {
            $this->fail('party_id', 'The selected customer does not exist.');
        }

        if ((int) $party->company_id !== (int) $revenue->company_id) {
            $this->fail('party_id', 'The customer must belong to the same company as the revenue document.');
        }

        if (bccomp($this->normalizeAmount((string) ($revenue->total ?? '0'), 'total'), '0', 10) <= 0) {
            $this->fail('total', 'The revenue total must be greater than zero.');
        }

        if (strtoupper((string) $revenue->base_currency) !== strtoupper((string) $revenue->company->base_currency)) {
            $this->fail('base_currency', 'The revenue base currency must match the company base currency.');
        }

        foreach ($items as $item) {
            if ($item->product_id === null || $item->unit_of_measure_id === null) {
                $this->fail('item', 'Each revenue item must reference a valid product and unit.');
            }

            $this->assertProductBelongsToCompany($item->product, $revenue->company);
            $this->assertUnitCompatible($item->product, $item->unitOfMeasure);

            if (bccomp($this->normalizeAmount((string) ($item->quantity ?? '0'), 'quantity'), '0', 10) <= 0) {
                $this->fail('quantity', 'Each revenue item quantity must be greater than zero.');
            }

            if (bccomp($this->normalizeAmount((string) ($item->unit_price ?? '0'), 'unit_price'), '0', 10) < 0) {
                $this->fail('unit_price', 'Each revenue item unit price cannot be negative.');
            }

            if ($item->product->tracks_inventory) {
                $this->resolveWarehouse(
                    $options,
                    $revenue->company,
                    $item->warehouse_id === null ? null : (int) $item->warehouse_id,
                );
            } elseif ($item->warehouse_id !== null) {
                $this->resolveWarehouse($options, $revenue->company, (int) $item->warehouse_id);
            }
        }

        // Require exactly one of receivable_account_id or cash_account_id.
        $hasReceivable = ! empty($options['receivable_account_id']);
        $hasCash = ! empty($options['cash_account_id']);

        if (! $hasReceivable && ! $hasCash) {
            $this->fail(
                'receivable_account_id',
                'Either a receivable account (receivable_account_id) or a cash account (cash_account_id) is required.',
            );
        }

        if ($hasReceivable && $hasCash) {
            $this->fail(
                'receivable_account_id',
                'Provide either receivable_account_id or cash_account_id, not both.',
            );
        }

        $rate = $this->resolveExchangeRate($revenue);
        if (bccomp($rate, '0', 10) <= 0) {
            $this->fail('exchange_rate', 'The exchange rate must be greater than zero.');
        }
    }

    private function resolveDebitAccount(Company $company, array $options, string $currency): int
    {
        if (! empty($options['receivable_account_id'])) {
            return $this->resolveAccountId(
                $company,
                (int) $options['receivable_account_id'],
                ['asset'],
                'receivable_account_id',
                'Accounts receivable account',
                $currency,
            );
        }

        return $this->resolveAccountId(
            $company,
            (int) $options['cash_account_id'],
            ['asset'],
            'cash_account_id',
            'Cash account',
            $currency,
        );
    }

    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    private function resolveWarehouse(array $options, Company $company, ?int $warehouseId = null): Warehouse
    {
        $warehouseId ??= isset($options['warehouse_id']) ? (int) $options['warehouse_id'] : null;

        if ($warehouseId === null) {
            $this->fail('warehouse_id', 'A warehouse is required when posting a revenue document.');
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null) {
            $this->fail('warehouse_id', 'The selected warehouse does not exist.');
        }

        if ((int) $warehouse->company_id !== (int) $company->getKey()) {
            $this->fail('warehouse_id', 'The selected warehouse must belong to the same company as the revenue document.');
        }

        return $warehouse;
    }

    private function resolveAccountId(
        Company $company,
        ?int $accountId,
        array $allowedTypes,
        string $attribute,
        string $label,
        ?string $currency = null,
    ): int {
        if ($accountId !== null) {
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

            if ($currency !== null && strtoupper((string) $account->currency) !== strtoupper($currency)) {
                $this->fail($attribute, 'The selected '.$label.' must use '.$currency.' currency.');
            }

            return $account->getKey();
        }

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereIn('account_type', $allowedTypes)
            ->when($currency !== null, fn ($query) => $query->where('currency', strtoupper($currency)))
            ->orderBy('code')
            ->first();

        if ($account === null) {
            $this->fail($attribute, 'No valid '.$label.' could be found for this company.');
        }

        return $account->getKey();
    }

    private function resolveExchangeRate(Revenue $revenue): string
    {
        $currency = strtoupper((string) ($revenue->currency ?? $revenue->company?->base_currency ?? 'SYP'));
        $baseCurrency = strtoupper((string) ($revenue->base_currency ?? $revenue->company?->base_currency ?? 'SYP'));

        if ($currency === $baseCurrency) {
            return '1';
        }

        $stored = $revenue->exchange_rate;
        if ($stored !== null && bccomp($this->normalizeAmount((string) $stored, 'exchange_rate'), '0', 10) > 0) {
            return $this->normalizeAmount((string) $stored, 'exchange_rate');
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $revenue->company_id)
            ->where('currency', $currency)
            ->where('base_currency', $baseCurrency)
            ->where('effective_at', '<=', $revenue->revenue_date->copy()->endOfDay())
            ->orderByDesc('effective_at')
            ->value('rate');

        if ($rate !== null && bccomp((string) $rate, '0', 10) > 0) {
            return $this->normalizeAmount((string) $rate, 'rate');
        }

        $this->fail('exchange_rate', 'A valid exchange rate is required for this currency conversion.');
    }

    private function convertAmount(string $amount, string $exchangeRate, string $currency, string $baseCurrency): string
    {
        $amount = $this->normalizeAmount($amount, 'amount');

        if (strtoupper($currency) === strtoupper($baseCurrency) || bccomp($exchangeRate, '0', 10) <= 0) {
            return $amount;
        }

        return bcmul($amount, $exchangeRate, 10);
    }

    private function nextLineNumber(Revenue $revenue): int
    {
        return (int) ($revenue->items()->max('line_number') ?? 0) + 1;
    }

    private function assertRevenueBelongsToActiveCompany(Revenue $revenue): void
    {
        $company = $this->companyContext->requireCurrent();
        if ((int) $revenue->company_id !== (int) $company->getKey()) {
            $this->fail('company_id', 'The revenue document must belong to the active company.');
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

        // Reject negative monetary amounts — revenue line items, totals, and
        // exchange rates must never be negative.  Callers additionally validate
        // domain rules (e.g. quantity must be > 0), but this guard ensures that
        // no negative value can silently slip through the normalization path.
        if (bccomp($value, '0', 20) < 0) {
            $this->fail($field, 'The value must not be negative.');
        }

        // Use bcmath for decimal-safe rounding to 4 places (round-half-up)
        // without floating-point arithmetic.  We truncate to 5 places first
        // to inspect the 5th digit, then conditionally add 0.0001 to the
        // 4-place truncation — avoiding carry-propagation edge cases that
        // arise from adding 0.00005 directly to large values.
        $truncated5 = bcadd($value, '0', 5);   // 5 decimal places
        $truncated4 = bcadd($value, '0', 4);   // 4 decimal places

        $dotPos = strpos($truncated5, '.');
        $fifthDigit = ($dotPos !== false && isset($truncated5[$dotPos + 5]))
            ? $truncated5[$dotPos + 5]
            : '0';

        if ($fifthDigit >= '5') {
            return bcadd($truncated4, '0.0001', 4);
        }

        return $truncated4;
    }

    private function generateRevenueNumber(Company $company): string
    {
        $count = Revenue::query()->where('company_id', $company->getKey())->count() + 1;

        return 'REV-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
