<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\InventoryCutover;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InventoryCostingService
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    /**
     * @return array{quantity: string, inventory_value: string, average_unit_cost: ?string}
     */
    public function currentValuation(Product $product): array
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, (int) $company->getKey());
        $product = $this->freshProduct($product, (int) $company->getKey());

        if (! $product->tracks_inventory) {
            $this->fail('product_id', 'Moving-average valuation is only available for inventory-tracked products.');
        }

        $quantity = '0.0000';
        $inventoryValue = '0.0000';
        $unvaluedMovements = 0;

        $movements = $this->applyPostCutoverBoundary(
            StockMovement::query()
                ->where('company_id', $company->getKey())
                ->where('product_id', $product->getKey()),
            (int) $company->getKey(),
        )
            ->orderBy('id')
            ->get(['direction', 'quantity', 'base_cost_amount', 'base_unit_cost']);

        foreach ($movements as $movement) {
            $movementQuantity = $this->normalizeQuantity((string) $movement->quantity);
            $movementValue = $movement->base_cost_amount;

            if (! in_array($movement->direction, ['in', 'out'], true)) {
                $this->fail('valuation', 'A stock movement has an invalid direction.');
            }

            if (($movementValue === null) !== ($movement->base_unit_cost === null)) {
                $this->fail('valuation', 'A stock movement has incomplete valuation data.');
            }

            if ($movementValue === null) {
                $unvaluedMovements++;
            } else {
                $movementValue = $this->normalizeMoney((string) $movementValue, 'base_cost_amount');
                $expectedUnitCost = $this->roundToScale(
                    bcdiv($movementValue, $movementQuantity, 20),
                    10,
                );

                if (bccomp($expectedUnitCost, (string) $movement->base_unit_cost, 10) !== 0) {
                    $this->fail('valuation', 'A stock movement has inconsistent unit-cost data.');
                }

                $inventoryValue = $movement->direction === 'in'
                    ? bcadd($inventoryValue, $movementValue, 4)
                    : bcsub($inventoryValue, $movementValue, 4);
            }

            $quantity = $movement->direction === 'in'
                ? bcadd($quantity, $movementQuantity, 4)
                : bcsub($quantity, $movementQuantity, 4);
        }

        if ($unvaluedMovements > 0) {
            $this->fail('valuation', 'Inventory valuation cannot be calculated while stock movements remain unvalued.');
        }

        if (bccomp($quantity, '0', 4) < 0 || bccomp($inventoryValue, '0', 4) < 0) {
            $this->fail('valuation', 'Inventory quantity and value cannot be negative.');
        }

        if (bccomp($quantity, '0', 4) === 0) {
            if (bccomp($inventoryValue, '0', 4) !== 0) {
                $this->fail('valuation', 'Zero inventory quantity cannot have a non-zero inventory value.');
            }

            return [
                'quantity' => $quantity,
                'inventory_value' => $inventoryValue,
                'average_unit_cost' => null,
            ];
        }

        return [
            'quantity' => $quantity,
            'inventory_value' => $inventoryValue,
            'average_unit_cost' => $this->roundToScale(bcdiv($inventoryValue, $quantity, 20), 10),
        ];
    }

    public function quantityInWarehouse(Product $product, Warehouse $warehouse): string
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, (int) $company->getKey());

        if ((int) $warehouse->company_id !== (int) $company->getKey()) {
            $this->fail('warehouse_id', 'The selected warehouse does not belong to the active company.');
        }

        $quantity = '0.0000';

        $movements = $this->applyPostCutoverBoundary(
            StockMovement::query()
                ->where('company_id', $company->getKey())
                ->where('product_id', $product->getKey())
                ->where('warehouse_id', $warehouse->getKey()),
            (int) $company->getKey(),
        )
            ->orderBy('id')
            ->get(['direction', 'quantity']);

        foreach ($movements as $movement) {
            if (! in_array($movement->direction, ['in', 'out'], true)) {
                $this->fail('quantity', 'A stock movement has an invalid direction.');
            }

            $movementQuantity = $this->normalizeQuantity((string) $movement->quantity);
            $quantity = $movement->direction === 'in'
                ? bcadd($quantity, $movementQuantity, 4)
                : bcsub($quantity, $movementQuantity, 4);
        }

        return $quantity;
    }

    public function valuationHistoryStatus(Product $product): string
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertProductBelongsToCompany($product, (int) $company->getKey());
        $product = $this->freshProduct($product, (int) $company->getKey());

        if (! $product->tracks_inventory) {
            return 'not_tracked';
        }

        $hasValuedMovement = false;
        $hasUnvaluedMovement = false;

        $movements = $this->applyPostCutoverBoundary(
            StockMovement::query()
                ->where('company_id', $company->getKey())
                ->where('product_id', $product->getKey()),
            (int) $company->getKey(),
        )
            ->get(['base_cost_amount', 'base_unit_cost']);

        foreach ($movements as $movement) {
            if (($movement->base_cost_amount === null) !== ($movement->base_unit_cost === null)) {
                $this->fail('valuation', 'A stock movement has incomplete valuation data.');
            }

            if ($movement->base_cost_amount === null) {
                $hasUnvaluedMovement = true;
            } else {
                $hasValuedMovement = true;
            }
        }

        if ($hasValuedMovement && $hasUnvaluedMovement) {
            $this->fail('valuation', 'Valued and unvalued stock movements cannot be mixed.');
        }

        return match (true) {
            $hasValuedMovement => 'valued',
            $hasUnvaluedMovement => 'unvalued',
            default => 'empty',
        };
    }

    public function applyPostCutoverBoundary(Builder $query, int $companyId): Builder
    {
        $movementIdBoundary = InventoryCutover::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->value('movement_id_boundary');

        if ($movementIdBoundary !== null) {
            $query->where('id', '>', $movementIdBoundary);
        }

        return $query;
    }

    /**
     * @return array{base_cost_amount: string, base_unit_cost: string, new_quantity: string}
     */
    public function receiptCost(Product $product, string $quantity, string|int $baseCostAmount): array
    {
        $quantity = $this->normalizePositiveQuantity($quantity);
        $baseCostAmount = $this->normalizeMoney($baseCostAmount, 'base_cost_amount');
        $state = $this->currentValuation($product);
        $newQuantity = bcadd($state['quantity'], $quantity, 4);

        return [
            'base_cost_amount' => $baseCostAmount,
            'base_unit_cost' => $this->roundToScale(bcdiv($baseCostAmount, $quantity, 20), 10),
            'new_quantity' => $newQuantity,
        ];
    }

    /**
     * @return array{base_cost_amount: string, base_unit_cost: string}
     */
    public function issueCost(Product $product, string $quantity): array
    {
        $quantity = $this->normalizePositiveQuantity($quantity);
        $state = $this->currentValuation($product);

        if (bccomp($state['quantity'], $quantity, 4) < 0) {
            $this->fail('quantity', 'Insufficient inventory for a valued stock issue.');
        }

        $remainingQuantity = bcsub($state['quantity'], $quantity, 4);
        $baseCostAmount = bccomp($remainingQuantity, '0', 4) === 0
            ? $state['inventory_value']
            : $this->roundToScale(
                bcmul($quantity, (string) $state['average_unit_cost'], 20),
                4,
            );

        if (bccomp($baseCostAmount, $state['inventory_value'], 4) > 0) {
            $baseCostAmount = $state['inventory_value'];
        }

        return [
            'base_cost_amount' => $baseCostAmount,
            'base_unit_cost' => $this->roundToScale(bcdiv($baseCostAmount, $quantity, 20), 10),
        ];
    }

    private function normalizeMoney(string|int $amount, string $field): string
    {
        $amount = (string) $amount;

        if (preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1 || bccomp($amount, '0', 20) < 0) {
            $this->fail($field, 'The base-currency cost must be a non-negative decimal string.');
        }

        return $this->roundToScale($amount, 4);
    }

    private function normalizeQuantity(string $quantity): string
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1) {
            $this->fail('quantity', 'Stock quantities must be valid decimal values.');
        }

        return bcadd($quantity, '0', 4);
    }

    private function normalizePositiveQuantity(string $quantity): string
    {
        $quantity = $this->normalizeQuantity($quantity);

        if (bccomp($quantity, '0', 4) <= 0) {
            $this->fail('quantity', 'Stock quantities must be greater than zero.');
        }

        return $quantity;
    }

    private function roundToScale(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale + 1);
        [$whole, $fraction] = array_pad(explode('.', $truncated, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $roundingDigit = (int) $fraction[$scale];
        $rounded = $scale === 0 ? $whole : $whole.'.'.substr($fraction, 0, $scale);

        if ($roundingDigit >= 5) {
            $increment = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
            $rounded = bcadd($rounded, $increment, $scale);
        }

        return bcadd($rounded, '0', $scale);
    }

    private function assertProductBelongsToCompany(Product $product, int $companyId): void
    {
        if ((int) $product->company_id !== $companyId) {
            $this->fail('product_id', 'The selected product does not belong to the active company.');
        }
    }

    private function freshProduct(Product $product, int $companyId): Product
    {
        $freshProduct = Product::query()
            ->where('company_id', $companyId)
            ->whereKey($product->getKey())
            ->first();

        if ($freshProduct === null) {
            $this->fail('product_id', 'The selected product could not be found in the active company.');
        }

        return $freshProduct;
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
