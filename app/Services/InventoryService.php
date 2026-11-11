<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InventoryCutover;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryCostingService $costing,
    ) {}

    public function getProductStock(Product $product): float
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, $company);

        return $this->stockTotalForQuery(
            $this->costing->applyPostCutoverBoundary(
                StockMovement::query()->where('company_id', $company->getKey())->where('product_id', $product->getKey()),
                (int) $company->getKey(),
            )
        );
    }

    public function getProductStockInWarehouse(Product $product, Warehouse $warehouse): float
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, $company);
        $this->assertWarehouseBelongsToCompany($warehouse, $company);

        return $this->stockTotalForQuery(
            $this->costing->applyPostCutoverBoundary(
                StockMovement::query()->where('company_id', $company->getKey())
                    ->where('product_id', $product->getKey())
                    ->where('warehouse_id', $warehouse->getKey()),
                (int) $company->getKey(),
            )
        );
    }

    public function getWarehouseStock(Warehouse $warehouse): float
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertWarehouseBelongsToCompany($warehouse, $company);

        return $this->stockTotalForQuery(
            $this->costing->applyPostCutoverBoundary(
                StockMovement::query()->where('company_id', $company->getKey())->where('warehouse_id', $warehouse->getKey()),
                (int) $company->getKey(),
            )
        );
    }

    public function getTotalProductStock(Product $product): float
    {
        return $this->getProductStock($product);
    }

    public function recordOpeningBalance(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        return $this->createMovement($product, $warehouse, 'opening_balance', 'in', $quantity, $unit, $attributes);
    }

    /**
     * Valued receipts require base_cost_amount. Legacy quantity-only callers must
     * explicitly set allow_unvalued_legacy until their workflow is valuation-aware.
     */
    public function receiveStock(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        $hasValuation = array_key_exists('base_cost_amount', $attributes);
        $baseCostAmount = $hasValuation ? $attributes['base_cost_amount'] : null;
        $allowUnvaluedLegacy = $attributes['allow_unvalued_legacy'] ?? false;

        if ($hasValuation && (! is_string($baseCostAmount) && ! is_int($baseCostAmount))) {
            $this->fail('base_cost_amount', 'A base-currency cost amount is required as a decimal string.');
        }

        if (! is_bool($allowUnvaluedLegacy) || ($hasValuation && $allowUnvaluedLegacy)) {
            $this->fail('valuation', 'Choose either an explicit receipt cost or the legacy unvalued mode.');
        }

        return $this->createMovement(
            $product,
            $warehouse,
            'receipt',
            'in',
            $quantity,
            $unit,
            $attributes,
            $baseCostAmount === null ? null : (string) $baseCostAmount,
            false,
            $allowUnvaluedLegacy,
        );
    }

    /**
     * Tracked issues require value_at_moving_average or allow_unvalued_legacy.
     */
    public function issueStock(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        if (array_key_exists('base_cost_amount', $attributes)) {
            $this->fail('base_cost_amount', 'Issue costs are calculated from the current moving average.');
        }

        $calculateMovingAverage = $attributes['value_at_moving_average'] ?? false;
        $allowUnvaluedLegacy = $attributes['allow_unvalued_legacy'] ?? false;
        if (! is_bool($calculateMovingAverage) || ! is_bool($allowUnvaluedLegacy)
            || ($calculateMovingAverage && $allowUnvaluedLegacy)) {
            $this->fail('value_at_moving_average', 'The moving-average valuation option must be boolean.');
        }

        return $this->createMovement(
            $product,
            $warehouse,
            'issue',
            'out',
            $quantity,
            $unit,
            $attributes,
            null,
            $calculateMovingAverage,
            $allowUnvaluedLegacy,
        );
    }

    public function adjustStock(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        string $movementType,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        $direction = match ($movementType) {
            'adjustment_in' => 'in',
            'adjustment_out' => 'out',
            default => throw ValidationException::withMessages(['movement_type' => 'The movement type is invalid.']),
        };

        $baseCostAmount = $attributes['base_cost_amount'] ?? null;
        if ($baseCostAmount !== null && ! is_string($baseCostAmount) && ! is_int($baseCostAmount)) {
            $this->fail('base_cost_amount', 'A base-currency cost amount must be a decimal string or integer.');
        }

        $valueAtMovingAverage = $attributes['value_at_moving_average'] ?? false;
        $allowUnvaluedLegacy = $attributes['allow_unvalued_legacy']
            ?? ($baseCostAmount === null && $valueAtMovingAverage === false);

        if (! is_bool($valueAtMovingAverage) || ! is_bool($allowUnvaluedLegacy)) {
            $this->fail('valuation', 'Inventory adjustment valuation options must be boolean.');
        }

        return $this->createMovement(
            $product,
            $warehouse,
            $movementType,
            $direction,
            $quantity,
            $unit,
            $attributes,
            $baseCostAmount === null ? null : (string) $baseCostAmount,
            $valueAtMovingAverage,
            $allowUnvaluedLegacy,
        );
    }

    public function returnStock(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        string $movementType,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        return match ($movementType) {
            'purchase_return' => $this->issueSupplierReturn($product, $warehouse, $quantity, $unit, $attributes),
            'sales_return' => $this->receiveSalesReturnFromAttributes($product, $warehouse, $quantity, $unit, $attributes),
            default => throw ValidationException::withMessages(['movement_type' => 'The stock return movement type is invalid.']),
        };
    }

    public function receiveCustomerReturn(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        string $baseCostAmount,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        return $this->createMovement(
            $product,
            $warehouse,
            'sales_return',
            'in',
            $quantity,
            $unit,
            $attributes,
            $baseCostAmount,
        );
    }

    public function issueSupplierReturn(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): StockMovement {
        if (array_key_exists('base_cost_amount', $attributes)) {
            $this->fail('base_cost_amount', 'Supplier returns use the current moving-average carrying value.');
        }

        return $this->createMovement(
            $product,
            $warehouse,
            'purchase_return',
            'out',
            $quantity,
            $unit,
            $attributes,
            null,
            true,
        );
    }

    private function receiveSalesReturnFromAttributes(
        Product $product,
        Warehouse $warehouse,
        float|string $quantity,
        ?UnitOfMeasure $unit,
        array $attributes,
    ): StockMovement {
        $baseCostAmount = $attributes['base_cost_amount'] ?? null;
        if (! is_string($baseCostAmount) && ! is_int($baseCostAmount)) {
            $this->fail('base_cost_amount', 'Customer returns require the original issue value in company base currency.');
        }

        return $this->receiveCustomerReturn(
            $product,
            $warehouse,
            $quantity,
            (string) $baseCostAmount,
            $unit,
            $attributes,
        );
    }

    public function transferStock(
        Product $product,
        Warehouse $sourceWarehouse,
        Warehouse $destinationWarehouse,
        float $quantity,
        ?UnitOfMeasure $unit = null,
        array $attributes = [],
    ): array {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, $company);
        $this->assertWarehouseBelongsToCompany($sourceWarehouse, $company);
        $this->assertWarehouseBelongsToCompany($destinationWarehouse, $company);
        $this->assertUnitCompatible($product, $unit);

        if ((int) $sourceWarehouse->getKey() === (int) $destinationWarehouse->getKey()) {
            $this->fail('warehouse_id', 'The source and destination warehouses must be different.');
        }

        $this->validatePositiveQuantity($quantity);

        return DB::transaction(function () use ($product, $sourceWarehouse, $destinationWarehouse, $quantity, $unit, $attributes, $company): array {
            $this->lockProductsForValuation([(int) $product->getKey()]);

            $lockedProduct = Product::query()
                ->where('company_id', $company->getKey())
                ->whereKey($product->getKey())
                ->firstOrFail();
            $lockedWarehouses = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', [$sourceWarehouse->getKey(), $destinationWarehouse->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedWarehouses->count() !== 2) {
                $this->fail('warehouse_id', 'Both warehouses must belong to the active company.');
            }

            $source = $lockedWarehouses->get($sourceWarehouse->getKey());
            $destination = $lockedWarehouses->get($destinationWarehouse->getKey());

            $this->assertProductBelongsToCompany($lockedProduct, $company);
            $this->assertWarehouseBelongsToCompany($source, $company);
            $this->assertWarehouseBelongsToCompany($destination, $company);

            $available = $this->costing->quantityInWarehouse($lockedProduct, $source);
            $movementQuantity = $this->formatQuantity($quantity);

            if (bccomp($available, $movementQuantity, 4) < 0) {
                $this->fail('quantity', 'Insufficient stock in the source warehouse.');
            }

            $transferGroup = (string) Str::uuid();
            $transferAttributes = [
                'transfer_group' => $transferGroup,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
            ];
            $valueTransfer = $lockedProduct->tracks_inventory
                && $this->costing->valuationHistoryStatus($lockedProduct) === 'valued';

            $outMovement = $this->createMovement(
                $lockedProduct,
                $source,
                'transfer',
                'out',
                $quantity,
                $unit,
                [...$attributes, ...$transferAttributes],
                null,
                $valueTransfer,
            );
            $inMovement = $this->createMovement(
                $lockedProduct,
                $destination,
                'transfer',
                'in',
                $quantity,
                $unit,
                [...$attributes, ...$transferAttributes],
                $valueTransfer ? (string) $outMovement->base_cost_amount : null,
            );

            return [$outMovement, $inMovement];
        });
    }

    /**
     * Lock product rows in ascending ID order before a transaction processes multiple products.
     *
     * @param  array<int, int>  $productIds
     */
    public function lockProductsForValuation(array $productIds, bool $allowDraftCutover = false): void
    {
        if (DB::transactionLevel() === 0) {
            $this->fail('transaction', 'Product valuation locks must be acquired inside a database transaction.');
        }

        $company = $this->companyContext->requireCurrent();
        Company::query()
            ->whereKey($company->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $allowDraftCutover && InventoryCutover::query()
            ->where('company_id', $company->getKey())
            ->where('status', 'draft')
            ->exists()) {
            $this->fail('inventory_cutover', 'Stock movements are paused while an inventory cutover is in progress.');
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds, SORT_NUMERIC);

        if ($productIds === []) {
            return;
        }

        $lockedProducts = Product::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($lockedProducts !== $productIds) {
            $this->fail('product_id', 'All locked products must belong to the active company.');
        }
    }

    private function createMovement(
        Product $product,
        Warehouse $warehouse,
        string $movementType,
        string $direction,
        float|string $quantity,
        ?UnitOfMeasure $unit,
        array $attributes = [],
        ?string $baseCostAmount = null,
        bool $valueAtMovingAverage = false,
        bool $allowUnvaluedLegacy = false,
    ): StockMovement {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthenticatedUser();

        $this->assertProductBelongsToCompany($product, $company);
        $this->assertWarehouseBelongsToCompany($warehouse, $company);
        $this->assertUnitCompatible($product, $unit);
        $this->validatePositiveQuantity($quantity);

        $direction = strtolower($direction);
        if (! in_array($direction, ['in', 'out'], true)) {
            $this->fail('direction', 'The movement direction is invalid.');
        }

        $validMovementTypes = [
            'opening_balance',
            'receipt',
            'issue',
            'transfer',
            'adjustment_in',
            'adjustment_out',
            'purchase_return',
            'sales_return',
        ];

        if (! in_array($movementType, $validMovementTypes, true)) {
            $this->fail('movement_type', 'The movement type is invalid.');
        }

        if ($baseCostAmount !== null && $valueAtMovingAverage) {
            $this->fail('valuation', 'A movement cannot use both an explicit cost and moving-average cost.');
        }

        if (($baseCostAmount !== null || $valueAtMovingAverage) && $allowUnvaluedLegacy) {
            $this->fail('valuation', 'A movement cannot use both valued and legacy unvalued modes.');
        }

        if ($valueAtMovingAverage && $direction !== 'out') {
            $this->fail('valuation', 'Moving-average valuation is only available for stock issues.');
        }

        $occurredAt = $attributes['occurred_at'] ?? now();

        return DB::transaction(function () use (
            $product,
            $warehouse,
            $movementType,
            $direction,
            $quantity,
            $attributes,
            $baseCostAmount,
            $valueAtMovingAverage,
            $allowUnvaluedLegacy,
            $occurredAt,
            $company,
            $user,
        ): StockMovement {
            $this->lockProductsForValuation([(int) $product->getKey()]);

            $lockedProduct = Product::query()
                ->where('company_id', $company->getKey())
                ->whereKey($product->getKey())
                ->firstOrFail();
            $lockedWarehouse = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereKey($warehouse->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertProductBelongsToCompany($lockedProduct, $company);
            $this->assertWarehouseBelongsToCompany($lockedWarehouse, $company);
            $this->assertNotBackdated($lockedProduct, $occurredAt);
            $hasPostedCutover = InventoryCutover::query()
                ->where('company_id', $company->getKey())
                ->where('status', 'posted')
                ->exists();

            $movementQuantity = $this->formatQuantity($quantity);

            if ($direction === 'out') {
                $available = $this->costing->quantityInWarehouse($lockedProduct, $lockedWarehouse);

                if (bccomp($available, $movementQuantity, 4) < 0) {
                    $this->fail('quantity', 'Insufficient stock for this warehouse.');
                }
            }

            $valuation = null;
            if (! $lockedProduct->tracks_inventory && ($baseCostAmount !== null || $valueAtMovingAverage)) {
                $this->fail('valuation', 'Non-inventory-tracked products cannot create valuation movements.');
            }

            if ($lockedProduct->tracks_inventory && in_array($movementType, ['receipt', 'adjustment_in', 'sales_return'], true)
                && $baseCostAmount === null && ! $allowUnvaluedLegacy) {
                $this->fail('base_cost_amount', 'Tracked receipts and positive adjustments require a base-currency cost amount.');
            }

            if ($lockedProduct->tracks_inventory && in_array($movementType, ['issue', 'adjustment_out', 'purchase_return'], true)
                && ! $valueAtMovingAverage && ! $allowUnvaluedLegacy) {
                $this->fail('value_at_moving_average', 'Tracked issues and negative adjustments require moving-average valuation.');
            }

            if ($lockedProduct->tracks_inventory && $baseCostAmount !== null) {
                if ($direction !== 'in' || ! in_array($movementType, ['receipt', 'adjustment_in', 'transfer', 'sales_return'], true)) {
                    $this->fail('base_cost_amount', 'Explicit base cost amounts are only accepted for receipts, positive adjustments, and transfer receipts.');
                }

                $valuation = $this->costing->receiptCost($lockedProduct, $movementQuantity, $baseCostAmount);
            } elseif ($lockedProduct->tracks_inventory && $valueAtMovingAverage) {
                $valuation = $this->costing->issueCost($lockedProduct, $movementQuantity);
            } elseif ($lockedProduct->tracks_inventory
                && $this->costing->valuationHistoryStatus($lockedProduct) === 'valued') {
                $this->fail('valuation', 'Valued inventory movements must include their required base-currency cost.');
            }

            if ($lockedProduct->tracks_inventory && $hasPostedCutover && $valuation === null) {
                $this->fail('valuation', 'Post-cutover tracked stock movements require an explicit valuation method.');
            }

            $costSourceId = $attributes['cost_source_stock_movement_id'] ?? null;
            if ($costSourceId !== null) {
                $costSource = StockMovement::query()
                    ->where('company_id', $company->getKey())
                    ->where('product_id', $lockedProduct->getKey())
                    ->whereKey($costSourceId)
                    ->first();

                if ($costSource === null || $costSource->base_cost_amount === null) {
                    $this->fail('cost_source_stock_movement_id', 'The cost source must be a valued movement for this company and product.');
                }
            }

            $movementAttributes = [
                'company_id' => $company->getKey(),
                'product_id' => $lockedProduct->getKey(),
                'warehouse_id' => $lockedWarehouse->getKey(),
                'movement_type' => $movementType,
                'direction' => $direction,
                'quantity' => $movementQuantity,
                'base_cost_amount' => $valuation['base_cost_amount'] ?? null,
                'base_unit_cost' => $valuation['base_unit_cost'] ?? null,
                'cost_source_stock_movement_id' => $costSourceId,
                'occurred_at' => $occurredAt,
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $user->getKey(),
                'project_id' => $attributes['project_id'] ?? null,
                'party_id' => $attributes['party_id'] ?? null,
                'sourceable_type' => $attributes['sourceable_type'] ?? null,
                'sourceable_id' => $attributes['sourceable_id'] ?? null,
                'revenue_item_id' => $attributes['revenue_item_id'] ?? null,
                'purchase_item_id' => $attributes['purchase_item_id'] ?? null,
                'transfer_group' => $attributes['transfer_group'] ?? null,
            ];

            return StockMovement::create($movementAttributes);
        });
    }

    private function assertNotBackdated(Product $product, mixed $occurredAt): void
    {
        $company = $this->companyContext->requireCurrent();

        try {
            $requestedAt = Carbon::parse($occurredAt);
        } catch (InvalidFormatException) {
            $this->fail('occurred_at', 'The stock movement date is invalid.');
        }

        $cutover = InventoryCutover::query()
            ->where('company_id', $company->getKey())
            ->where('status', 'posted')
            ->first();

        if ($cutover !== null && $requestedAt->lt($cutover->effective_at)) {
            $this->fail('occurred_at', 'Stock movements cannot precede the inventory cutover boundary.');
        }

        $latestOccurredAt = $this->costing->applyPostCutoverBoundary(
            StockMovement::query()
                ->where('company_id', $company->getKey())
                ->where('product_id', $product->getKey()),
            (int) $company->getKey(),
        )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('occurred_at');

        if ($latestOccurredAt === null) {
            return;
        }

        $latestAt = Carbon::parse($latestOccurredAt);

        if ($requestedAt->lt($latestAt)) {
            $this->fail('occurred_at', 'Backdated stock movements are not allowed.');
        }
    }

    private function formatQuantity(float|string $quantity): string
    {
        if (is_float($quantity)) {
            $quantity = number_format($quantity, 10, '.', '');
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1) {
            $this->fail('quantity', 'Stock quantities must be valid decimal values.');
        }

        $truncatedFive = bcadd($quantity, '0', 5);
        $formatted = bcadd($quantity, '0', 4);
        $dotPosition = strpos($truncatedFive, '.');
        $fifthDigit = $dotPosition !== false && isset($truncatedFive[$dotPosition + 5])
            ? $truncatedFive[$dotPosition + 5]
            : '0';

        if ($fifthDigit >= '5') {
            $formatted = bcadd($formatted, '0.0001', 4);
        }

        if (bccomp($formatted, '0', 4) <= 0) {
            $this->fail('quantity', 'Stock quantities must be at least 0.0001.');
        }

        return $formatted;
    }

    private function validatePositiveQuantity(float|string $quantity): void
    {
        $quantity = is_float($quantity)
            ? number_format($quantity, 10, '.', '')
            : $quantity;

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1 || bccomp($quantity, '0', 10) <= 0) {
            $this->fail('quantity', 'The quantity must be greater than zero.');
        }
    }

    private function requireAuthenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $this->fail('user', 'An authenticated user is required to record stock movements.');
        }

        $company = $this->companyContext->requireCurrent();

        if (! $this->companyAccess->canAccessCompany($user, $company)) {
            $this->fail('company_id', 'The user does not have access to the active company.');
        }

        return $user;
    }

    private function assertProductBelongsToCompany(Product $product, Company $company): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            $this->fail('product_id', 'The selected product does not belong to the active company.');
        }
    }

    private function assertWarehouseBelongsToCompany(Warehouse $warehouse, Company $company): void
    {
        if ((int) $warehouse->getAttribute('company_id') !== (int) $company->getKey()) {
            $this->fail('warehouse_id', 'The selected warehouse does not belong to the active company.');
        }
    }

    private function assertUnitCompatible(Product $product, ?UnitOfMeasure $unit): void
    {
        if ($unit === null) {
            return;
        }

        if ((int) $product->getAttribute('unit_of_measure_id') !== (int) $unit->getKey()) {
            $this->fail('unit', 'The selected unit must match the product unit of measure.');
        }

        if ((int) $unit->getAttribute('company_id') !== (int) $product->getAttribute('company_id')) {
            $this->fail('unit', 'The selected unit must belong to the same company as the product.');
        }
    }

    private function stockTotalForQuery($query): float
    {
        $total = $query->selectRaw('SUM(CASE WHEN direction = ? THEN quantity ELSE -quantity END) as total', ['in'])
            ->value('total');

        return (float) ($total ?? 0);
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
