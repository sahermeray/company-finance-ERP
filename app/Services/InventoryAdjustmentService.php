<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockCountLine;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InventoryAdjustmentService
{
    private const POSITIVE_TYPES = ['revenue', 'income', 'equity'];

    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryService $inventory,
        private readonly InventoryCostingService $costing,
        private readonly AccountingService $accounting,
    ) {}

    public function createDraft(array $attributes): InventoryAdjustment
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $productId = $this->normalizeId($attributes['product_id'] ?? null, 'product_id');
        $warehouseId = $this->normalizeId($attributes['warehouse_id'] ?? null, 'warehouse_id');
        $inventoryAssetAccountId = $this->normalizeId($attributes['inventory_asset_account_id'] ?? null, 'inventory_asset_account_id');
        $offsetAccountId = $this->normalizeId($attributes['offset_account_id'] ?? null, 'offset_account_id');
        $adjustmentType = $attributes['adjustment_type'] ?? null;

        if (! in_array($adjustmentType, ['positive', 'negative', 'damaged', 'lost', 'unusable'], true)) {
            $this->fail('adjustment_type', 'Choose a positive, negative, damaged, lost, or unusable inventory adjustment.');
        }

        $quantity = $this->normalizeQuantity($attributes['quantity'] ?? null, 'quantity');
        if (bccomp($quantity, '0', 4) <= 0) {
            $this->fail('quantity', 'The adjustment quantity must be greater than zero.');
        }

        $reason = $attributes['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            $this->fail('reason', 'A reason of no more than 2000 characters is required.');
        }

        $isPositive = $adjustmentType === 'positive';
        $unitCost = null;
        if ($isPositive) {
            $unitCost = $this->normalizeMoney($attributes['base_unit_cost'] ?? null, 10, 'base_unit_cost');
            if (bccomp($unitCost, '0', 10) <= 0) {
                $this->fail('base_unit_cost', 'Positive adjustments require an explicitly approved unit cost greater than zero in company base currency.');
            }
        } elseif (isset($attributes['base_unit_cost'])) {
            $this->fail('base_unit_cost', 'Only positive adjustments accept an explicitly approved unit cost.');
        }

        $occurredAt = $this->parseDate($attributes['occurred_at'] ?? now()->toDateTimeString(), 'occurred_at');
        $stockCountLineId = isset($attributes['stock_count_line_id'])
            ? $this->normalizeId($attributes['stock_count_line_id'], 'stock_count_line_id')
            : null;

        return DB::transaction(function () use (
            $company,
            $user,
            $productId,
            $warehouseId,
            $inventoryAssetAccountId,
            $offsetAccountId,
            $adjustmentType,
            $quantity,
            $unitCost,
            $occurredAt,
            $reason,
            $stockCountLineId,
            $isPositive,
        ): InventoryAdjustment {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $this->inventory->lockProductsForValuation([$productId]);

            $product = Product::query()
                ->where('company_id', $company->getKey())
                ->whereKey($productId)
                ->first();
            if ($product === null || ! $product->tracks_inventory) {
                $this->fail('product_id', 'The selected product must be inventory-tracked and belong to the active company.');
            }

            $warehouse = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereKey($warehouseId)
                ->lockForUpdate()
                ->first();
            if ($warehouse === null) {
                $this->fail('warehouse_id', 'The selected warehouse does not belong to the active company.');
            }

            $this->assertValuationHistoryCanBeExtended($product);
            $this->validateAccountsForAdjustment($company, $inventoryAssetAccountId, $offsetAccountId, $isPositive);
            $this->assertStockCountLine($stockCountLineId, $company, $product, $warehouse, $adjustmentType, $quantity, $unitCost);

            return InventoryAdjustment::create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'created_by' => $user->getKey(),
                'inventory_asset_account_id' => $inventoryAssetAccountId,
                'offset_account_id' => $offsetAccountId,
                'stock_count_line_id' => $stockCountLineId,
                'adjustment_type' => $adjustmentType,
                'quantity' => $quantity,
                'base_unit_cost' => $unitCost,
                'occurred_at' => $occurredAt,
                'reason' => trim($reason),
                'status' => 'draft',
            ]);
        });
    }

    public function approve(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $this->assertAdjustmentBelongsToCompany($adjustment, $company);

        return DB::transaction(function () use ($adjustment, $company, $user): InventoryAdjustment {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedAdjustment = $this->lockAdjustment($adjustment, $company);
            $this->inventory->lockProductsForValuation([(int) $lockedAdjustment->product_id]);
            $this->lockAdjustmentWarehouse($lockedAdjustment, $company);

            if ($lockedAdjustment->status !== 'draft') {
                $this->fail('inventory_adjustment', 'Only a draft inventory adjustment can be approved.');
            }

            $isPositive = $lockedAdjustment->adjustment_type === 'positive';
            $this->validateAccountsForAdjustment(
                $company,
                (int) $lockedAdjustment->inventory_asset_account_id,
                (int) $lockedAdjustment->offset_account_id,
                $isPositive,
            );
            $product = $this->lockedProduct($lockedAdjustment, $company);
            $this->assertValuationHistoryCanBeExtended($product);

            if ($isPositive) {
                $amount = $this->roundToScale(
                    bcmul((string) $lockedAdjustment->quantity, (string) $lockedAdjustment->base_unit_cost, 20),
                    4,
                );
                if (bccomp($amount, '0', 4) <= 0) {
                    $this->fail('base_unit_cost', 'The approved unit cost and quantity must produce a positive base-currency value.');
                }

                $this->assertAmountFitsJournal($amount);
                $lockedAdjustment->base_cost_amount = $amount;
            }

            $lockedAdjustment->approved_by = $user->getKey();
            $lockedAdjustment->approved_at = now();
            $lockedAdjustment->status = 'approved';
            $lockedAdjustment->save();

            return $lockedAdjustment->fresh();
        });
    }

    public function post(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $this->assertAdjustmentBelongsToCompany($adjustment, $company);

        return DB::transaction(function () use ($adjustment, $company, $user): InventoryAdjustment {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedAdjustment = $this->lockAdjustment($adjustment, $company);

            if ($lockedAdjustment->status !== 'approved'
                || $lockedAdjustment->approved_by === null
                || $lockedAdjustment->approved_at === null
                || $lockedAdjustment->movement_id !== null
                || $lockedAdjustment->accounting_journal_id !== null) {
                $this->fail('inventory_adjustment', 'Only an approved, unposted inventory adjustment can be posted.');
            }

            $this->inventory->lockProductsForValuation([(int) $lockedAdjustment->product_id]);
            $warehouse = $this->lockAdjustmentWarehouse($lockedAdjustment, $company);
            $product = $this->lockedProduct($lockedAdjustment, $company);
            $isPositive = $lockedAdjustment->adjustment_type === 'positive';

            $this->validateAccountsForAdjustment(
                $company,
                (int) $lockedAdjustment->inventory_asset_account_id,
                (int) $lockedAdjustment->offset_account_id,
                $isPositive,
            );
            $this->assertValuationHistoryCanBeExtended($product);

            $isReceipt = $lockedAdjustment->adjustment_type === 'positive';
            $movement = $this->inventory->adjustStock(
                $product,
                $warehouse,
                (string) $lockedAdjustment->quantity,
                $isReceipt ? 'adjustment_in' : 'adjustment_out',
                null,
                [
                    ...($isReceipt ? ['base_cost_amount' => (string) $lockedAdjustment->base_cost_amount] : ['value_at_moving_average' => true]),
                    'occurred_at' => $lockedAdjustment->occurred_at,
                    'reference' => 'Inventory adjustment #'.$lockedAdjustment->getKey(),
                    'notes' => $lockedAdjustment->reason,
                    'sourceable_type' => InventoryAdjustment::class,
                    'sourceable_id' => $lockedAdjustment->getKey(),
                ],
            );

            if ($movement->base_cost_amount === null || bccomp((string) $movement->base_cost_amount, '0', 4) <= 0) {
                $this->fail('valuation', 'A posted tracked inventory adjustment must have a positive base-currency value.');
            }

            $this->assertAmountFitsJournal((string) $movement->base_cost_amount);
            $journal = $this->createAdjustmentJournal($lockedAdjustment, $company, (string) $movement->base_cost_amount);
            $this->accounting->postJournal($journal);

            $lockedAdjustment->movement_id = $movement->getKey();
            $lockedAdjustment->accounting_journal_id = $journal->getKey();
            $lockedAdjustment->base_cost_amount = $movement->base_cost_amount;
            $lockedAdjustment->posted_by = $user->getKey();
            $lockedAdjustment->posted_at = now();
            $lockedAdjustment->status = 'posted';
            $lockedAdjustment->save();

            return $lockedAdjustment->fresh(['movement', 'journal', 'stockCountLine']);
        });
    }

    /**
     * @return array{inventory_asset: FinancialAccount, offset: FinancialAccount}
     */
    public function validateAccountsForAdjustment(
        Company $company,
        int $inventoryAssetAccountId,
        int $offsetAccountId,
        bool $isPositive,
    ): array {
        $accountIds = array_values(array_unique([$inventoryAssetAccountId, $offsetAccountId]));
        sort($accountIds, SORT_NUMERIC);

        $accounts = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $inventoryAsset = $accounts->get($inventoryAssetAccountId);
        $offset = $accounts->get($offsetAccountId);

        if ($inventoryAsset === null
            || strtolower($inventoryAsset->account_type) !== 'asset'
            || $inventoryAsset->currency !== $company->base_currency
            || ! $inventoryAsset->is_active) {
            $this->fail('inventory_asset_account_id', 'The inventory asset account must be active, belong to the active company, use its base currency, and have type asset.');
        }

        $allowedOffsetTypes = $isPositive ? self::POSITIVE_TYPES : ['expense'];
        if ($offset === null
            || ! in_array(strtolower($offset->account_type), $allowedOffsetTypes, true)
            || $offset->currency !== $company->base_currency
            || ! $offset->is_active) {
            $expected = $isPositive ? 'an active revenue, income, or equity account' : 'an active expense account';
            $this->fail('offset_account_id', 'The offset account must be '.$expected.' in the active company and its base currency.');
        }

        return ['inventory_asset' => $inventoryAsset, 'offset' => $offset];
    }

    private function createAdjustmentJournal(
        InventoryAdjustment $adjustment,
        Company $company,
        string $baseAmount,
    ): AccountingJournal {
        $positive = $adjustment->adjustment_type === 'positive';
        $description = match ($adjustment->adjustment_type) {
            'positive' => 'Positive inventory adjustment #'.$adjustment->getKey(),
            'negative' => 'Negative inventory adjustment #'.$adjustment->getKey(),
            default => 'Inventory write-off ('.$adjustment->adjustment_type.') #'.$adjustment->getKey(),
        };
        $journal = $this->accounting->createDraftJournal([
            'created_by' => Auth::id(),
            'sourceable_type' => InventoryAdjustment::class,
            'sourceable_id' => $adjustment->getKey(),
            'entry_date' => $adjustment->occurred_at->toDateString(),
            'description' => $description.': '.mb_substr($adjustment->reason, 0, 160),
        ], $company);
        $currency = $company->base_currency;
        $inventoryLine = [
            'financial_account_id' => $adjustment->inventory_asset_account_id,
            'description' => $description,
            'currency' => $currency,
            'exchange_rate' => '1',
            'base_currency' => $currency,
        ];
        $offsetLine = [
            'financial_account_id' => $adjustment->offset_account_id,
            'description' => $description,
            'currency' => $currency,
            'exchange_rate' => '1',
            'base_currency' => $currency,
        ];

        if ($positive) {
            $inventoryLine['debit'] = $baseAmount;
            $offsetLine['credit'] = $baseAmount;
        } else {
            $offsetLine['debit'] = $baseAmount;
            $inventoryLine['credit'] = $baseAmount;
        }

        $this->accounting->addJournalLine($journal, $inventoryLine);
        $this->accounting->addJournalLine($journal, $offsetLine);

        return $journal;
    }

    private function lockAdjustment(InventoryAdjustment $adjustment, Company $company): InventoryAdjustment
    {
        $lockedAdjustment = InventoryAdjustment::query()
            ->where('company_id', $company->getKey())
            ->whereKey($adjustment->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedAdjustment === null) {
            $this->fail('inventory_adjustment', 'The adjustment could not be found in the active company.');
        }

        return $lockedAdjustment;
    }

    private function lockedProduct(InventoryAdjustment $adjustment, Company $company): Product
    {
        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->whereKey($adjustment->product_id)
            ->first();

        if ($product === null || ! $product->tracks_inventory) {
            $this->fail('product_id', 'The adjustment product must be inventory-tracked and belong to the active company.');
        }

        return $product;
    }

    private function lockAdjustmentWarehouse(InventoryAdjustment $adjustment, Company $company): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('company_id', $company->getKey())
            ->whereKey($adjustment->warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($warehouse === null) {
            $this->fail('warehouse_id', 'The adjustment warehouse does not belong to the active company.');
        }

        return $warehouse;
    }

    private function assertStockCountLine(
        ?int $stockCountLineId,
        Company $company,
        Product $product,
        Warehouse $warehouse,
        string $adjustmentType,
        string $quantity,
        ?string $unitCost,
    ): void {
        if ($stockCountLineId === null) {
            return;
        }

        $line = StockCountLine::query()
            ->with('stockCount')
            ->where('company_id', $company->getKey())
            ->whereKey($stockCountLineId)
            ->first();

        $expectedType = $line !== null && bccomp((string) $line->variance_quantity, '0', 4) > 0
            ? 'positive'
            : 'negative';
        $expectedQuantity = $line === null
            ? null
            : (bccomp((string) $line->variance_quantity, '0', 4) > 0
                ? (string) $line->variance_quantity
                : bcsub('0', (string) $line->variance_quantity, 4));

        if ($line === null
            || $line->stockCount?->status !== 'approved'
            || InventoryAdjustment::query()->where('stock_count_line_id', $line->getKey())->exists()
            || (int) $line->product_id !== (int) $product->getKey()
            || (int) $line->warehouse_id !== (int) $warehouse->getKey()
            || $expectedType !== $adjustmentType
            || bccomp((string) $expectedQuantity, $quantity, 4) !== 0
            || ($expectedType === 'positive' && bccomp((string) $line->approved_unit_cost, (string) $unitCost, 10) !== 0)) {
            $this->fail('stock_count_line_id', 'A count variance adjustment must match an approved stock-count line.');
        }
    }

    private function assertValuationHistoryCanBeExtended(Product $product): void
    {
        if ($this->costing->valuationHistoryStatus($product) === 'unvalued') {
            $this->fail('valuation', 'Valued adjustments cannot be mixed with legacy unvalued stock history.');
        }

        $this->costing->currentValuation($product);
    }

    private function assertAdjustmentBelongsToCompany(InventoryAdjustment $adjustment, Company $company): void
    {
        if ((int) $adjustment->company_id !== (int) $company->getKey()) {
            $this->fail('company_id', 'The adjustment must belong to the active company.');
        }
    }

    private function requireAuthorizedUser(Company $company): User
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->companyAccess->canAccessCompany($user, $company)) {
            $this->fail('company_id', 'An authenticated user with access to the active company is required.');
        }

        return $user;
    }

    private function normalizeId(mixed $value, string $field): int
    {
        if ((! is_int($value) && (! is_string($value) || ctype_digit($value) !== true)) || (int) $value < 1) {
            $this->fail($field, 'A valid record in the active company is required.');
        }

        return (int) $value;
    }

    private function normalizeQuantity(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, 'Quantities must be supplied as decimal strings or integers.');
        }

        $value = (string) $value;
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            $this->fail($field, 'The quantity must be a non-negative decimal value.');
        }

        $rounded = $this->roundToScale($value, 4);
        [$whole] = explode('.', $rounded, 2);
        if (strlen($whole) > 14) {
            $this->fail($field, 'The quantity exceeds the supported inventory precision.');
        }

        return $rounded;
    }

    private function normalizeMoney(mixed $value, int $scale, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, 'Amounts must be supplied as decimal strings or integers.');
        }

        $value = (string) $value;
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            $this->fail($field, 'The amount must be a non-negative decimal value.');
        }

        $rounded = $this->roundToScale($value, $scale);
        $this->assertDecimalPrecision($rounded, 24 - $scale, $field);

        return $rounded;
    }

    private function parseDate(mixed $value, string $field): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($field, 'A valid date and time is required.');
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException|InvalidArgumentException) {
            $this->fail($field, 'A valid date and time is required.');
        }
    }

    private function assertAmountFitsJournal(string $amount): void
    {
        $this->assertDecimalPrecision($amount, 15, 'base_cost_amount');
    }

    private function assertDecimalPrecision(string $amount, int $maximumWholeDigits, string $field): void
    {
        [$whole] = explode('.', $amount, 2);
        if (strlen($whole) > $maximumWholeDigits) {
            $this->fail($field, 'The value exceeds the supported accounting precision.');
        }
    }

    private function roundToScale(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale + 1);
        [$whole, $fraction] = array_pad(explode('.', $truncated, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $rounded = $scale === 0 ? $whole : $whole.'.'.substr($fraction, 0, $scale);

        if ((int) $fraction[$scale] >= 5) {
            $increment = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
            $rounded = bcadd($rounded, $increment, $scale);
        }

        return bcadd($rounded, '0', $scale);
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
