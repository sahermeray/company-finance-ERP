<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockCountService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryService $inventory,
        private readonly InventoryCostingService $costing,
        private readonly InventoryAdjustmentService $adjustments,
    ) {}

    /**
     * @param  array{
     *     inventory_asset_account_id: mixed,
     *     positive_variance_account_id: mixed,
     *     negative_variance_account_id: mixed,
     *     counted_at?: mixed,
     *     description: mixed,
     *     lines: array<int, array{product_id: mixed, warehouse_id: mixed, counted_quantity: mixed, approved_unit_cost?: mixed}>
     * }  $attributes
     */
    public function createDraft(array $attributes): StockCount
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $inventoryAssetAccountId = $this->normalizeId($attributes['inventory_asset_account_id'] ?? null, 'inventory_asset_account_id');
        $positiveVarianceAccountId = $this->normalizeId($attributes['positive_variance_account_id'] ?? null, 'positive_variance_account_id');
        $negativeVarianceAccountId = $this->normalizeId($attributes['negative_variance_account_id'] ?? null, 'negative_variance_account_id');
        $countedAt = $this->parseDate($attributes['counted_at'] ?? now()->toDateTimeString(), 'counted_at');
        $description = $attributes['description'] ?? null;
        if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 1950) {
            $this->fail('description', 'A stock-count reason of no more than 1950 characters is required.');
        }

        $submittedLines = $attributes['lines'] ?? null;
        if (! is_array($submittedLines) || $submittedLines === []) {
            $this->fail('lines', 'At least one product and warehouse count is required.');
        }

        $lines = [];
        $uniqueLines = [];
        foreach ($submittedLines as $line) {
            if (! is_array($line)) {
                $this->fail('lines', 'Each stock-count line must contain a product, warehouse, and counted quantity.');
            }

            $productId = $this->normalizeId($line['product_id'] ?? null, 'product_id');
            $warehouseId = $this->normalizeId($line['warehouse_id'] ?? null, 'warehouse_id');
            $countedQuantity = $this->normalizeQuantity($line['counted_quantity'] ?? null, 'counted_quantity');
            $key = $productId.':'.$warehouseId;
            if (isset($uniqueLines[$key])) {
                $this->fail('lines', 'A product and warehouse can appear only once in a stock count.');
            }
            $uniqueLines[$key] = true;

            $lines[] = [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'counted_quantity' => $countedQuantity,
                'approved_unit_cost' => $line['approved_unit_cost'] ?? null,
            ];
        }

        usort($lines, static fn (array $first, array $second): int => [$first['product_id'], $first['warehouse_id']] <=> [$second['product_id'], $second['warehouse_id']]);

        return DB::transaction(function () use (
            $company,
            $user,
            $inventoryAssetAccountId,
            $positiveVarianceAccountId,
            $negativeVarianceAccountId,
            $countedAt,
            $description,
            $lines,
        ): StockCount {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $productIds = array_values(array_unique(array_column($lines, 'product_id')));
            sort($productIds, SORT_NUMERIC);
            $this->inventory->lockProductsForValuation($productIds);

            $products = Product::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($products->count() !== count($productIds)) {
                $this->fail('product_id', 'Every counted product must belong to the active company.');
            }

            foreach ($products as $product) {
                if (! $product->tracks_inventory) {
                    $this->fail('product_id', 'Only inventory-tracked products can be counted.');
                }
            }

            $warehouseIds = array_values(array_unique(array_column($lines, 'warehouse_id')));
            sort($warehouseIds, SORT_NUMERIC);
            $warehouses = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($warehouses->count() !== count($warehouseIds)) {
                $this->fail('warehouse_id', 'Every counted warehouse must belong to the active company.');
            }

            $this->adjustments->validateAccountsForAdjustment(
                $company,
                $inventoryAssetAccountId,
                $positiveVarianceAccountId,
                true,
            );
            $this->adjustments->validateAccountsForAdjustment(
                $company,
                $inventoryAssetAccountId,
                $negativeVarianceAccountId,
                false,
            );

            $count = StockCount::create([
                'company_id' => $company->getKey(),
                'created_by' => $user->getKey(),
                'inventory_asset_account_id' => $inventoryAssetAccountId,
                'positive_variance_account_id' => $positiveVarianceAccountId,
                'negative_variance_account_id' => $negativeVarianceAccountId,
                'counted_at' => $countedAt,
                'description' => trim($description),
                'status' => 'draft',
            ]);

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                $warehouse = $warehouses->get($line['warehouse_id']);
                $systemQuantity = $this->costing->quantityInWarehouse($product, $warehouse);
                $variance = bcsub($line['counted_quantity'], $systemQuantity, 4);
                $unitCost = null;

                if (bccomp($variance, '0', 4) > 0) {
                    $unitCost = $this->normalizeMoney($line['approved_unit_cost'], 'approved_unit_cost');
                    if (bccomp($unitCost, '0', 10) <= 0) {
                        $this->fail('approved_unit_cost', 'Positive stock-count variances require an explicitly approved unit cost greater than zero in company base currency.');
                    }
                } elseif ($line['approved_unit_cost'] !== null) {
                    $this->fail('approved_unit_cost', 'An approved unit cost is only accepted for a positive stock-count variance.');
                }

                StockCountLine::create([
                    'stock_count_id' => $count->getKey(),
                    'company_id' => $company->getKey(),
                    'product_id' => $product->getKey(),
                    'warehouse_id' => $warehouse->getKey(),
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $line['counted_quantity'],
                    'variance_quantity' => $variance,
                    'approved_unit_cost' => $unitCost,
                ]);
            }

            return $count->fresh(['lines']);
        });
    }

    public function approve(StockCount $count): StockCount
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $this->assertCountBelongsToCompany($count, $company);

        return DB::transaction(function () use ($count, $company, $user): StockCount {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCount = $this->lockCount($count, $company);

            if ($lockedCount->status !== 'draft') {
                $this->fail('stock_count', 'Only a draft stock count can be approved.');
            }

            $lines = $lockedCount->lines()->orderBy('product_id')->orderBy('warehouse_id')->get();
            if ($lines->isEmpty()) {
                $this->fail('lines', 'A stock count must contain at least one product and warehouse.');
            }

            foreach ($lines as $line) {
                if (bccomp((string) $line->variance_quantity, '0', 4) > 0
                    && ($line->approved_unit_cost === null || bccomp((string) $line->approved_unit_cost, '0', 10) <= 0)) {
                    $this->fail('approved_unit_cost', 'Every positive stock-count variance requires an explicitly approved base-currency unit cost.');
                }
            }

            $this->adjustments->validateAccountsForAdjustment(
                $company,
                (int) $lockedCount->inventory_asset_account_id,
                (int) $lockedCount->positive_variance_account_id,
                true,
            );
            $this->adjustments->validateAccountsForAdjustment(
                $company,
                (int) $lockedCount->inventory_asset_account_id,
                (int) $lockedCount->negative_variance_account_id,
                false,
            );

            $lockedCount->approved_by = $user->getKey();
            $lockedCount->approved_at = now();
            $lockedCount->status = 'approved';
            $lockedCount->save();

            return $lockedCount->fresh(['lines']);
        });
    }

    public function post(StockCount $count): StockCount
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $this->assertCountBelongsToCompany($count, $company);

        return DB::transaction(function () use ($count, $company, $user): StockCount {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCount = $this->lockCount($count, $company);

            if ($lockedCount->status !== 'approved'
                || $lockedCount->approved_by === null
                || $lockedCount->approved_at === null
                || $lockedCount->posted_at !== null) {
                $this->fail('stock_count', 'Only an approved, unposted stock count can be posted.');
            }

            $lines = $lockedCount->lines()->orderBy('product_id')->orderBy('warehouse_id')->get();
            $productIds = $lines->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $warehouseIds = $lines->pluck('warehouse_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $this->inventory->lockProductsForValuation($productIds);

            $products = Product::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $warehouses = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds) || $warehouses->count() !== count($warehouseIds)) {
                $this->fail('lines', 'Every stock-count line must belong to the active company.');
            }

            $this->adjustments->validateAccountsForAdjustment(
                $company,
                (int) $lockedCount->inventory_asset_account_id,
                (int) $lockedCount->positive_variance_account_id,
                true,
            );
            $this->adjustments->validateAccountsForAdjustment(
                $company,
                (int) $lockedCount->inventory_asset_account_id,
                (int) $lockedCount->negative_variance_account_id,
                false,
            );

            foreach ($lines as $line) {
                $product = $products->get($line->product_id);
                $warehouse = $warehouses->get($line->warehouse_id);
                if (! $product->tracks_inventory) {
                    $this->fail('product_id', 'Only inventory-tracked products can be posted from a stock count.');
                }

                $currentQuantity = $this->costing->quantityInWarehouse($product, $warehouse);
                if (bccomp($currentQuantity, (string) $line->system_quantity, 4) !== 0) {
                    $this->fail('stock_count', 'Stock changed after the count was recorded. Create a new count before posting variances.');
                }
            }

            foreach ($lines as $line) {
                $variance = (string) $line->variance_quantity;
                if (bccomp($variance, '0', 4) === 0) {
                    continue;
                }

                $isPositive = bccomp($variance, '0', 4) > 0;
                $adjustment = $this->adjustments->createDraft([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $line->warehouse_id,
                    'adjustment_type' => $isPositive ? 'positive' : 'negative',
                    'quantity' => $isPositive ? $variance : bcsub('0', $variance, 4),
                    'base_unit_cost' => $isPositive ? (string) $line->approved_unit_cost : null,
                    'inventory_asset_account_id' => $lockedCount->inventory_asset_account_id,
                    'offset_account_id' => $isPositive
                        ? $lockedCount->positive_variance_account_id
                        : $lockedCount->negative_variance_account_id,
                    'occurred_at' => $lockedCount->counted_at->toDateTimeString(),
                    'reason' => trim($lockedCount->description).' (physical stock-count variance)',
                    'stock_count_line_id' => $line->getKey(),
                ]);
                $this->adjustments->approve($adjustment);
                $this->adjustments->post($adjustment);
            }

            $lockedCount->posted_by = $user->getKey();
            $lockedCount->posted_at = now();
            $lockedCount->status = 'posted';
            $lockedCount->save();

            return $lockedCount->fresh(['lines.adjustment.movement', 'lines.adjustment.journal']);
        });
    }

    private function lockCount(StockCount $count, Company $company): StockCount
    {
        $lockedCount = StockCount::query()
            ->where('company_id', $company->getKey())
            ->whereKey($count->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedCount === null) {
            $this->fail('stock_count', 'The stock count could not be found in the active company.');
        }

        return $lockedCount;
    }

    private function assertCountBelongsToCompany(StockCount $count, Company $company): void
    {
        if ((int) $count->company_id !== (int) $company->getKey()) {
            $this->fail('company_id', 'The stock count must belong to the active company.');
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

    private function normalizeMoney(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, 'Amounts must be supplied as decimal strings or integers.');
        }

        $value = (string) $value;
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            $this->fail($field, 'The amount must be a non-negative decimal value.');
        }

        $rounded = $this->roundToScale($value, 10);
        [$whole] = explode('.', $rounded, 2);
        if (strlen($whole) > 14) {
            $this->fail($field, 'The amount exceeds the supported base-currency precision.');
        }

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
