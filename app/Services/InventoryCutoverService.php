<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\InventoryCutoverLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InventoryCutoverService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    /**
     * @param  array{effective_at: mixed, inventory_asset_account_id: mixed, opening_equity_account_id: mixed, description?: ?string}  $attributes
     */
    public function createDraft(array $attributes): InventoryCutover
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->requireAuthorizedUser($company);
        $effectiveAt = $this->parseEffectiveAt($attributes['effective_at'] ?? null);
        $inventoryAssetAccount = $this->resolveAccount(
            $attributes['inventory_asset_account_id'] ?? null,
            $company,
            'asset',
            'inventory_asset_account_id',
        );
        $openingEquityAccount = $this->resolveAccount(
            $attributes['opening_equity_account_id'] ?? null,
            $company,
            'equity',
            'opening_equity_account_id',
        );
        $description = $attributes['description'] ?? null;

        if ($description !== null && (! is_string($description) || mb_strlen($description) > 2000)) {
            $this->fail('description', 'The cutover explanation must be no more than 2000 characters.');
        }

        return DB::transaction(function () use (
            $company,
            $user,
            $effectiveAt,
            $inventoryAssetAccount,
            $openingEquityAccount,
            $description,
        ): InventoryCutover {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            if (InventoryCutover::query()->where('company_id', $company->getKey())->exists()) {
                $this->fail('inventory_cutover', 'An inventory cutover has already been created for this company.');
            }

            return InventoryCutover::create([
                'company_id' => $company->getKey(),
                'created_by' => $user->getKey(),
                'inventory_asset_account_id' => $inventoryAssetAccount->getKey(),
                'opening_equity_account_id' => $openingEquityAccount->getKey(),
                'effective_at' => $effectiveAt,
                'movement_id_boundary' => (int) (StockMovement::query()->max('id') ?? 0),
                'status' => 'draft',
                'description' => $description,
            ]);
        });
    }

    public function setOpeningBalance(
        InventoryCutover $cutover,
        Product $product,
        Warehouse $warehouse,
        mixed $quantity,
        mixed $openingValue,
        mixed $valuationReference,
    ): InventoryCutoverLine {
        $company = $this->companyContext->requireCurrent();
        $this->assertCutoverBelongsToCompany($cutover, $company);
        $this->requireAuthorizedUser($company);

        $quantity = $this->normalizeDecimal($quantity, 18, 4, 'opening_quantity');
        $openingValue = $this->normalizeDecimal($openingValue, 24, 4, 'opening_value');

        if (! is_string($valuationReference) || trim($valuationReference) === '' || mb_strlen($valuationReference) > 255) {
            $this->fail('valuation_reference', 'A valuation source or explanation of no more than 255 characters is required.');
        }

        $quantityIsZero = bccomp($quantity, '0', 4) === 0;
        $valueIsZero = bccomp($openingValue, '0', 4) === 0;

        if ($quantityIsZero && ! $valueIsZero) {
            $this->fail('opening_value', 'A zero opening quantity must have zero inventory value.');
        }

        if (! $quantityIsZero && $valueIsZero) {
            $this->fail('opening_value', 'Positive opening quantities require a positive approved inventory value.');
        }

        return DB::transaction(function () use (
            $cutover,
            $product,
            $warehouse,
            $quantity,
            $openingValue,
            $valuationReference,
            $company,
        ): InventoryCutoverLine {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCutover = $this->lockDraftCutover($cutover, $company);
            $lockedProduct = Product::query()
                ->where('company_id', $company->getKey())
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedProduct === null) {
                $this->fail('product_id', 'The product must belong to the active company.');
            }

            if (! $lockedProduct->tracks_inventory) {
                $this->fail('product_id', 'Only inventory-tracked products can have opening balances.');
            }

            $lockedWarehouse = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereKey($warehouse->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedWarehouse === null) {
                $this->fail('warehouse_id', 'The warehouse must belong to the active company.');
            }

            $baseUnitCost = bccomp($quantity, '0', 4) === 0
                ? null
                : $this->roundToScale(bcdiv($openingValue, $quantity, 20), 10);

            if ($baseUnitCost !== null) {
                $this->assertDecimalFits($baseUnitCost, 24, 10, 'base_unit_cost');
            }

            $line = InventoryCutoverLine::query()
                ->where('inventory_cutover_id', $lockedCutover->getKey())
                ->where('product_id', $lockedProduct->getKey())
                ->where('warehouse_id', $lockedWarehouse->getKey())
                ->first() ?? new InventoryCutoverLine;

            $line->fill([
                'inventory_cutover_id' => $lockedCutover->getKey(),
                'company_id' => $company->getKey(),
                'product_id' => $lockedProduct->getKey(),
                'warehouse_id' => $lockedWarehouse->getKey(),
                'opening_quantity' => $quantity,
                'opening_value' => $openingValue,
                'base_unit_cost' => $baseUnitCost,
                'valuation_reference' => trim($valuationReference),
            ]);
            $line->save();

            return $line;
        });
    }

    public function updateDraftAccounts(
        InventoryCutover $cutover,
        mixed $inventoryAssetAccountId,
        mixed $openingEquityAccountId,
    ): InventoryCutover {
        $company = $this->companyContext->requireCurrent();
        $this->assertCutoverBelongsToCompany($cutover, $company);
        $this->requireAuthorizedUser($company);
        $inventoryAssetAccount = $this->resolveAccount($inventoryAssetAccountId, $company, 'asset', 'inventory_asset_account_id');
        $openingEquityAccount = $this->resolveAccount($openingEquityAccountId, $company, 'equity', 'opening_equity_account_id');

        return DB::transaction(function () use ($cutover, $company, $inventoryAssetAccount, $openingEquityAccount): InventoryCutover {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCutover = $this->lockDraftCutover($cutover, $company);
            $lockedCutover->inventory_asset_account_id = $inventoryAssetAccount->getKey();
            $lockedCutover->opening_equity_account_id = $openingEquityAccount->getKey();
            $lockedCutover->save();

            return $lockedCutover;
        });
    }

    public function cancelDraft(InventoryCutover $cutover): void
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertCutoverBelongsToCompany($cutover, $company);
        $this->requireAuthorizedUser($company);

        DB::transaction(function () use ($cutover, $company): void {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCutover = $this->lockDraftCutover($cutover, $company);
            $lines = $lockedCutover->lines()->get();
            $lineIds = $lines->modelKeys();

            if (($lineIds !== [] && StockMovement::query()
                ->where('company_id', $company->getKey())
                ->whereIn('inventory_cutover_line_id', $lineIds)
                ->exists())
                || AccountingJournal::query()
                    ->where('company_id', $company->getKey())
                    ->where('sourceable_type', InventoryCutover::class)
                    ->where('sourceable_id', $lockedCutover->getKey())
                    ->exists()) {
                $this->fail('inventory_cutover', 'A cutover with posted stock movements or an accounting journal cannot be cancelled.');
            }

            foreach ($lines as $line) {
                $line->delete();
            }

            $lockedCutover->delete();
        });
    }

    public function post(InventoryCutover $cutover): InventoryCutover
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertCutoverBelongsToCompany($cutover, $company);
        $this->requireAuthorizedUser($company);

        return DB::transaction(function () use ($cutover, $company): InventoryCutover {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedCutover = InventoryCutover::query()
                ->where('company_id', $company->getKey())
                ->whereKey($cutover->getKey())
                ->lockForUpdate()
                ->with('lines')
                ->first();

            if ($lockedCutover === null) {
                $this->fail('inventory_cutover', 'The cutover could not be found in the active company.');
            }

            if ($lockedCutover->status !== 'draft') {
                $this->fail('inventory_cutover', 'Only a draft inventory cutover can be posted.');
            }

            if ($lockedCutover->accounting_journal_id !== null) {
                $this->fail('accounting_journal_id', 'This cutover already has an accounting journal.');
            }

            if (AccountingJournal::query()
                ->where('company_id', $company->getKey())
                ->where('sourceable_type', InventoryCutover::class)
                ->where('sourceable_id', $lockedCutover->getKey())
                ->exists()) {
                $this->fail('accounting_journal_id', 'An accounting journal is already linked to this cutover.');
            }

            $lines = $lockedCutover->lines()
                ->orderBy('product_id')
                ->orderBy('warehouse_id')
                ->get();
            $this->assertOpeningBalanceCoverage($lockedCutover, $lines, $company);

            $productIds = $lines->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
            $this->inventory->lockProductsForValuation($productIds, true);

            $trackedProductCount = Product::query()
                ->where('company_id', $company->getKey())
                ->where('tracks_inventory', true)
                ->whereIn('id', $productIds)
                ->count();

            if ($trackedProductCount !== count($productIds)) {
                $this->fail('product_id', 'Every opening balance must reference an active, tracked product in the active company.');
            }

            $inventoryAccount = $this->lockAndValidateAccounts($lockedCutover, $company);
            if (bccomp($inventoryAccount->baseCurrencyBalance(), '0', 10) !== 0) {
                $this->fail(
                    'inventory_asset_account_id',
                    'The inventory asset account must be reconciled to zero before posting an opening cutover.',
                );
            }

            $warehouseIds = $lines->pluck('warehouse_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $lockedWarehouses = Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($lockedWarehouses !== $warehouseIds) {
                $this->fail('warehouse_id', 'Every opening balance must reference a warehouse in the active company.');
            }

            $lineIds = $lines->pluck('id')->all();
            if ($lineIds !== [] && StockMovement::query()
                ->where('company_id', $company->getKey())
                ->whereIn('inventory_cutover_line_id', $lineIds)
                ->exists()) {
                $this->fail('stock_movement', 'An opening stock movement already exists for a cutover line.');
            }

            $totalOpeningValue = '0.0000';
            $hasPositiveQuantity = false;

            foreach ($lines as $line) {
                if ((int) $line->company_id !== (int) $company->getKey()) {
                    $this->fail('company_id', 'Every opening balance must belong to the cutover company.');
                }

                $this->validatePersistedLine($line);
                $totalOpeningValue = bcadd($totalOpeningValue, (string) $line->opening_value, 4);
                $hasPositiveQuantity = $hasPositiveQuantity || bccomp((string) $line->opening_quantity, '0', 4) > 0;
            }

            if ($hasPositiveQuantity && bccomp($totalOpeningValue, '0', 4) <= 0) {
                $this->fail('opening_value', 'A cutover with opening stock must have a positive total inventory value.');
            }

            $journal = null;
            if (bccomp($totalOpeningValue, '0', 4) > 0) {
                $this->assertJournalAmountFits($totalOpeningValue);
                $journal = $this->createOpeningJournal($lockedCutover, $company, $totalOpeningValue);
            }

            foreach ($lines as $line) {
                if (bccomp((string) $line->opening_quantity, '0', 4) === 0) {
                    continue;
                }

                StockMovement::create([
                    'company_id' => $company->getKey(),
                    'product_id' => $line->product_id,
                    'warehouse_id' => $line->warehouse_id,
                    'created_by' => Auth::id(),
                    'sourceable_type' => InventoryCutoverLine::class,
                    'sourceable_id' => $line->getKey(),
                    'inventory_cutover_line_id' => $line->getKey(),
                    'movement_type' => 'opening_balance',
                    'direction' => 'in',
                    'quantity' => $line->opening_quantity,
                    'base_cost_amount' => $line->opening_value,
                    'base_unit_cost' => $line->base_unit_cost,
                    'occurred_at' => $lockedCutover->effective_at,
                    'reference' => $line->valuation_reference,
                    'notes' => $lockedCutover->description,
                ]);
            }

            if ($journal !== null) {
                $this->accounting->postJournal($journal);
            }

            $lockedCutover->accounting_journal_id = $journal?->getKey();
            $lockedCutover->status = 'posted';
            $lockedCutover->posted_at = now();
            $lockedCutover->save();

            return $lockedCutover->fresh(['lines.openingMovement', 'journal.lines']);
        });
    }

    private function createOpeningJournal(InventoryCutover $cutover, Company $company, string $totalOpeningValue): AccountingJournal
    {
        $journal = $this->accounting->createDraftJournal([
            'created_by' => Auth::id(),
            'sourceable_type' => InventoryCutover::class,
            'sourceable_id' => $cutover->getKey(),
            'entry_date' => $cutover->effective_at->toDateString(),
            'description' => 'Inventory opening cutover #'.$cutover->getKey(),
        ], $company);
        $currency = $company->base_currency;

        $this->accounting->addJournalLine($journal, [
            'financial_account_id' => $cutover->inventory_asset_account_id,
            'description' => 'Opening inventory asset',
            'debit' => $totalOpeningValue,
            'credit' => '0',
            'currency' => $currency,
            'base_currency' => $currency,
            'exchange_rate' => '1',
            'base_debit' => $totalOpeningValue,
            'base_credit' => '0',
        ]);
        $this->accounting->addJournalLine($journal, [
            'financial_account_id' => $cutover->opening_equity_account_id,
            'description' => 'Opening balance equity',
            'debit' => '0',
            'credit' => $totalOpeningValue,
            'currency' => $currency,
            'base_currency' => $currency,
            'exchange_rate' => '1',
            'base_debit' => '0',
            'base_credit' => $totalOpeningValue,
        ]);

        return $journal;
    }

    /**
     * @param  Collection<int, InventoryCutoverLine>  $lines
     */
    private function assertOpeningBalanceCoverage(InventoryCutover $cutover, $lines, Company $company): void
    {
        $hasDeletedInventoryHistory = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->where('stock_movements.company_id', $company->getKey())
            ->where('stock_movements.id', '<=', $cutover->movement_id_boundary)
            ->where('products.company_id', $company->getKey())
            ->where('products.tracks_inventory', true)
            ->where(function ($query): void {
                $query->whereNotNull('products.deleted_at')->orWhereNotNull('warehouses.deleted_at');
            })
            ->exists();

        if ($hasDeletedInventoryHistory) {
            $this->fail('product_id', 'Restore tracked products and warehouses with historical stock movements before posting the cutover.');
        }

        $productIds = Product::query()
            ->where('company_id', $company->getKey())
            ->where('tracks_inventory', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $warehouseIds = Warehouse::query()
            ->where('company_id', $company->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($productIds !== [] && $warehouseIds === []) {
            $this->fail('warehouse_id', 'Create an active warehouse before posting tracked inventory cutover balances.');
        }

        $linePairs = $lines->mapWithKeys(static fn (InventoryCutoverLine $line): array => [
            (int) $line->product_id.':'.(int) $line->warehouse_id => true,
        ]);

        foreach ($productIds as $productId) {
            foreach ($warehouseIds as $warehouseId) {
                if (! $linePairs->has($productId.':'.$warehouseId)) {
                    $this->fail('lines', 'Add an opening balance, including zero balances, for every tracked product and warehouse.');
                }
            }
        }
    }

    private function lockDraftCutover(InventoryCutover $cutover, Company $company): InventoryCutover
    {
        $lockedCutover = InventoryCutover::query()
            ->where('company_id', $company->getKey())
            ->whereKey($cutover->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedCutover === null) {
            $this->fail('inventory_cutover', 'The cutover could not be found in the active company.');
        }

        if ($lockedCutover->status !== 'draft') {
            $this->fail('inventory_cutover', 'Only a draft inventory cutover can be changed.');
        }

        return $lockedCutover;
    }

    private function resolveAccount(mixed $accountId, Company $company, string $type, string $field): FinancialAccount
    {
        if (! is_int($accountId) && (! is_string($accountId) || ! ctype_digit($accountId))) {
            $this->fail($field, 'A valid account in the active company is required.');
        }

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereKey((int) $accountId)
            ->first();

        if ($account === null
            || strtolower($account->account_type) !== $type
            || $account->currency !== $company->base_currency
            || ! $account->is_active) {
            $this->fail($field, 'The account must be active, belong to the active company, use its base currency, and have type '.$type.'.');
        }

        return $account;
    }

    private function lockAndValidateAccounts(InventoryCutover $cutover, Company $company): FinancialAccount
    {
        $accountIds = [
            (int) $cutover->inventory_asset_account_id,
            (int) $cutover->opening_equity_account_id,
        ];
        sort($accountIds, SORT_NUMERIC);

        $accounts = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $inventoryAccount = $accounts->get($cutover->inventory_asset_account_id);
        $equityAccount = $accounts->get($cutover->opening_equity_account_id);

        foreach ([
            [$inventoryAccount, 'asset', 'inventory_asset_account_id'],
            [$equityAccount, 'equity', 'opening_equity_account_id'],
        ] as [$account, $type, $field]) {
            if ($account === null
                || strtolower($account->account_type) !== $type
                || $account->currency !== $company->base_currency
                || ! $account->is_active) {
                $this->fail($field, 'The account must be active, use the company base currency, and have type '.$type.'.');
            }
        }

        return $inventoryAccount;
    }

    private function validatePersistedLine(InventoryCutoverLine $line): void
    {
        $quantity = $this->normalizeDecimal((string) $line->opening_quantity, 18, 4, 'opening_quantity');
        $openingValue = $this->normalizeDecimal((string) $line->opening_value, 24, 4, 'opening_value');
        $quantityIsZero = bccomp($quantity, '0', 4) === 0;
        $valueIsZero = bccomp($openingValue, '0', 4) === 0;

        if (trim($line->valuation_reference) === '') {
            $this->fail('valuation_reference', 'Every opening balance requires a valuation source or explanation.');
        }

        if ($quantityIsZero && (! $valueIsZero || $line->base_unit_cost !== null)) {
            $this->fail('opening_value', 'Zero opening quantities must have zero value and no unit cost.');
        }

        if (! $quantityIsZero) {
            if ($valueIsZero || $line->base_unit_cost === null) {
                $this->fail('opening_value', 'Positive opening quantities require a positive approved value and derived unit cost.');
            }

            $expectedUnitCost = $this->roundToScale(bcdiv($openingValue, $quantity, 20), 10);
            if (bccomp($expectedUnitCost, (string) $line->base_unit_cost, 10) !== 0) {
                $this->fail('base_unit_cost', 'The stored unit cost does not match the approved opening quantity and value.');
            }
        }
    }

    private function parseEffectiveAt(mixed $effectiveAt): Carbon
    {
        if (! is_string($effectiveAt) || trim($effectiveAt) === '') {
            $this->fail('effective_at', 'A valid inventory cutover date and time is required.');
        }

        try {
            return Carbon::parse($effectiveAt);
        } catch (InvalidFormatException|InvalidArgumentException) {
            $this->fail('effective_at', 'A valid inventory cutover date and time is required.');
        }
    }

    private function normalizeDecimal(mixed $value, int $precision, int $scale, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, 'Financial quantities and amounts must be decimal strings or integers.');
        }

        $value = (string) $value;
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            $this->fail($field, 'The value must be a non-negative decimal.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($whole) > $precision - $scale || strlen($fraction) > $scale) {
            $this->fail($field, 'The value exceeds the supported decimal precision.');
        }

        return bcadd($value, '0', $scale);
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

    private function assertJournalAmountFits(string $amount): void
    {
        $this->assertDecimalFits($amount, 19, 4, 'opening_value');
    }

    private function assertDecimalFits(string $amount, int $precision, int $scale, string $field): void
    {
        [$whole] = explode('.', $amount, 2);
        if (strlen($whole) > $precision - $scale) {
            $this->fail($field, 'The value exceeds the supported decimal precision.');
        }
    }

    private function assertCutoverBelongsToCompany(InventoryCutover $cutover, Company $company): void
    {
        if ((int) $cutover->company_id !== (int) $company->getKey()) {
            $this->fail('company_id', 'The cutover must belong to the active company.');
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

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
