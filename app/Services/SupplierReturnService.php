<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseTaxLine;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\SupplierReturnLineTax;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierReturnService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function createDraft(array $attributes): SupplierReturn
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->authorizedUser($company);
        $purchaseId = $this->positiveId($attributes['original_purchase_id'] ?? null, 'original_purchase_id');
        $reason = $this->requiredReason($attributes['reason'] ?? null);
        $returnDate = $this->returnDate($attributes['return_date'] ?? now()->toDateString());
        $varianceAccountId = $this->optionalId($attributes['purchase_return_variance_account_id'] ?? null, 'purchase_return_variance_account_id');
        $inventoryAccountId = $this->optionalId($attributes['inventory_asset_account_id'] ?? null, 'inventory_asset_account_id');

        return DB::transaction(function () use ($company, $user, $purchaseId, $reason, $returnDate, $varianceAccountId, $inventoryAccountId): SupplierReturn {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $purchase = Purchase::query()
                ->where('company_id', $company->getKey())
                ->whereKey($purchaseId)
                ->lockForUpdate()
                ->first();
            if ($purchase === null || $purchase->status !== 'posted') {
                $this->fail('original_purchase_id', 'A supplier return must reference a posted purchase in the active company.');
            }

            $journal = $this->originalJournal($purchase);
            $journalLines = $journal->lines()->with('financialAccount')->get();
            $payableLine = $journalLines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 4) > 0
                && $line->currency === $purchase->currency
                && strtolower((string) $line->financialAccount?->account_type) === 'liability'
                && str_starts_with((string) $line->description, 'Supplier payable for purchase ')
            );
            $inventoryAccountId ??= $this->accountIdByDescription($journalLines, 'Inventory received from purchase ');
            if ($payableLine === null) {
                $this->fail('original_purchase_id', 'The original purchase journal does not contain its supplier payable account.');
            }

            return SupplierReturn::create([
                'company_id' => $company->getKey(),
                'original_purchase_id' => $purchase->getKey(),
                'party_id' => $purchase->party_id,
                'created_by' => $user->getKey(),
                'payable_account_id' => $payableLine->financial_account_id,
                'inventory_asset_account_id' => $inventoryAccountId,
                'purchase_return_variance_account_id' => $varianceAccountId,
                'return_number' => 'SR-'.strtoupper(Str::random(12)),
                'return_date' => $returnDate,
                'status' => 'draft',
                'currency' => strtoupper((string) $purchase->currency),
                'exchange_rate' => (string) $purchase->exchange_rate,
                'base_currency' => strtoupper((string) $purchase->base_currency),
                'reason' => $reason,
                'total' => '0.0000',
                'base_total' => '0.0000',
                'inventory_cost_total' => '0.0000',
                'variance_total' => '0.0000',
            ]);
        });
    }

    public function addLine(SupplierReturn $return, array $attributes): SupplierReturnLine
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertReturnCompany($return, $company);
        $this->authorizedUser($company);
        $itemId = $this->positiveId($attributes['purchase_item_id'] ?? null, 'purchase_item_id');
        $quantity = $this->quantity($attributes['quantity'] ?? null);
        $warehouseId = $this->optionalId($attributes['warehouse_id'] ?? null, 'warehouse_id');
        $expenseAccountId = $this->optionalId($attributes['expense_account_id'] ?? null, 'expense_account_id');

        return DB::transaction(function () use ($return, $company, $itemId, $quantity, $warehouseId, $expenseAccountId): SupplierReturnLine {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedReturn = SupplierReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedReturn->status !== 'draft') {
                $this->fail('supplier_return', 'Only draft supplier returns can accept lines.');
            }
            $item = PurchaseItem::query()->whereKey($itemId)->first();
            if ($item === null || (int) $item->purchase_id !== (int) $lockedReturn->original_purchase_id) {
                $this->fail('purchase_item_id', 'The selected item does not belong to the original purchase.');
            }
            $product = Product::query()->where('company_id', $company->getKey())->whereKey($item->product_id)->first();
            if ($product === null) {
                $this->fail('product_id', 'The original product does not belong to the active company.');
            }
            $warehouse = $warehouseId === null ? null : Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereKey($warehouseId)
                ->first();
            if ($warehouseId !== null && $warehouse === null) {
                $this->fail('warehouse_id', 'The return warehouse must belong to the active company.');
            }
            if ($product->tracks_inventory && $warehouseId === null) {
                $this->resolveReceiptMovement($lockedReturn, $item);
            }
            $alreadyReturned = $this->returnedQuantity($item);
            if (bccomp($quantity, bcsub((string) $item->quantity, $alreadyReturned, 4), 4) > 0) {
                $this->fail('quantity', 'The return quantity exceeds the originally received quantity remaining.');
            }

            return $lockedReturn->lines()->create([
                'purchase_item_id' => $item->getKey(),
                'product_id' => $product->getKey(),
                'warehouse_id' => $warehouse?->getKey(),
                'expense_account_id' => $expenseAccountId,
                'quantity' => $quantity,
            ]);
        });
    }

    public function post(SupplierReturn $return): SupplierReturn
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->authorizedUser($company);
        $this->assertReturnCompany($return, $company);

        return DB::transaction(function () use ($return, $company, $user): SupplierReturn {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedReturn = SupplierReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedReturn->status !== 'draft' || $lockedReturn->accounting_journal_id !== null) {
                $this->fail('supplier_return', 'Only an unposted draft supplier return can be posted.');
            }
            $purchase = Purchase::query()
                ->where('company_id', $company->getKey())
                ->whereKey($lockedReturn->original_purchase_id)
                ->lockForUpdate()
                ->first();
            if ($purchase === null || $purchase->status !== 'posted'
                || strtoupper((string) $purchase->currency) !== $lockedReturn->currency
                || bccomp((string) $purchase->exchange_rate, (string) $lockedReturn->exchange_rate, 10) !== 0
                || strtoupper((string) $purchase->base_currency) !== $lockedReturn->base_currency) {
                $this->fail('original_purchase_id', 'The original purchase is unavailable or its historical currency data is invalid.');
            }

            $lines = $lockedReturn->lines()->with(['purchaseItem.product', 'purchaseItem.unitOfMeasure', 'purchaseItem.taxLines'])->orderBy('id')->get();
            if ($lines->isEmpty()) {
                $this->fail('lines', 'A supplier return must contain at least one line.');
            }
            $productIds = $lines->pluck('product_id')->map(static fn ($id): int => (int) $id)->all();
            $this->inventory->lockProductsForValuation($productIds);
            $receiptByLine = [];
            $warehouseByLine = [];
            $trackedLineIds = [];
            foreach ($lines as $line) {
                $item = $line->purchaseItem;
                if ($item === null || (int) $item->purchase_id !== (int) $purchase->getKey()
                    || (int) $item->product_id !== (int) $line->product_id) {
                    $this->fail('purchase_item_id', 'A return line no longer matches an item on its original purchase.');
                }
                $product = Product::query()->where('company_id', $company->getKey())->whereKey($line->product_id)->first();
                if ($product === null) {
                    $this->fail('product_id', 'A returned product does not belong to the active company.');
                }
                if (bccomp((string) $line->quantity, bcsub((string) $item->quantity, $this->returnedQuantity($item), 4), 4) > 0) {
                    $this->fail('quantity', 'The return quantity exceeds the originally received quantity remaining.');
                }
                if ($product->tracks_inventory) {
                    $receipt = $this->resolveReceiptMovement($lockedReturn, $item);
                    if ($receipt->direction !== 'in') {
                        $this->fail('stock_movement', 'The original purchase item does not have a valid receipt movement.');
                    }
                    $warehouseId = $line->warehouse_id ?? $receipt->warehouse_id;
                    $warehouse = Warehouse::query()->where('company_id', $company->getKey())->whereKey($warehouseId)->first();
                    if ($warehouse === null) {
                        $this->fail('warehouse_id', 'The return warehouse must belong to the active company.');
                    }
                    $receiptByLine[$line->getKey()] = $receipt;
                    $warehouseByLine[$line->getKey()] = $warehouse;
                    $trackedLineIds[] = (int) $line->getKey();
                }
            }

            $warehouseIds = collect($warehouseByLine)->map(fn (Warehouse $warehouse): int => (int) $warehouse->getKey())
                ->unique()->sort()->values()->all();
            $lockedWarehouses = $warehouseIds === [] ? collect() : Warehouse::query()
                ->where('company_id', $company->getKey())
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($lockedWarehouses->count() !== count($warehouseIds)) {
                $this->fail('warehouse_id', 'Every return warehouse must belong to the active company.');
            }
            foreach ($warehouseByLine as $lineId => $warehouse) {
                $warehouseByLine[$lineId] = $lockedWarehouses->get($warehouse->getKey());
            }

            $originalJournal = $this->originalJournal($purchase);
            if ($originalJournal->reversed_by_journal_id !== null) {
                $this->fail('original_purchase_id', 'A return cannot be posted against a reversed purchase journal.');
            }
            $originalJournalLines = $originalJournal->lines()->with('financialAccount')->get();
            $hasTrackedLines = $trackedLineIds !== [];
            $this->validateAccount($company, $lockedReturn->payable_account_id, ['liability'], $lockedReturn->currency, 'payable_account_id');
            if ($hasTrackedLines) {
                $this->validateAccount($company, $lockedReturn->inventory_asset_account_id, ['asset'], $lockedReturn->base_currency, 'inventory_asset_account_id');
                $this->validateAccount($company, $lockedReturn->purchase_return_variance_account_id, ['expense', 'income', 'revenue'], $lockedReturn->base_currency, 'purchase_return_variance_account_id');
            }

            $nativeTotal = '0.0000';
            $baseTotal = '0.0000';
            $inventoryCostTotal = '0.0000';
            $varianceTotal = '0.0000';
            $taxCreditByAccount = [];
            $expenseCredits = [];
            $baseSubtotalByItem = $this->purchaseBaseSubtotalByItem($purchase);
            foreach ($lines as $line) {
                $item = $line->purchaseItem;
                $alreadyReturnedQuantity = $this->returnedQuantity($item);
                $cumulativeQuantity = bcadd($alreadyReturnedQuantity, (string) $line->quantity, 4);
                $previousNativeAmount = bcsub(
                    $this->returnedNativeAmount($item),
                    $this->returnedItemTaxAmount($item, 'native_amount'),
                    4,
                );
                $previousBaseAmount = bcsub(
                    $this->returnedBaseAmount($item),
                    $this->returnedItemTaxAmount($item, 'base_amount'),
                    4,
                );
                $nativeNetTarget = $this->allocate((string) $item->line_total, $cumulativeQuantity, (string) $item->quantity);
                $nativeNet = bcsub($nativeNetTarget, $previousNativeAmount, 4);
                $baseNetTarget = $this->allocate(
                    $baseSubtotalByItem[$item->getKey()] ?? '0.0000',
                    $cumulativeQuantity,
                    (string) $item->quantity,
                );
                $baseNet = bcsub($baseNetTarget, $previousBaseAmount, 4);
                $nativeAmount = $nativeNet;
                $baseAmount = $baseNet;
                $recoverableNative = '0.0000';
                $recoverableBase = '0.0000';
                foreach ($item->taxLines as $taxLine) {
                    $priorTaxNative = $this->returnedTaxAmount($taxLine, 'native_amount');
                    $priorTaxBase = $this->returnedTaxAmount($taxLine, 'base_amount');
                    $nativeTaxTarget = $this->allocate((string) $taxLine->amount, $cumulativeQuantity, (string) $item->quantity);
                    $baseTaxTarget = $this->allocate((string) $taxLine->base_amount, $cumulativeQuantity, (string) $item->quantity);
                    $nativeTax = bcsub($nativeTaxTarget, $priorTaxNative, 4);
                    $baseTax = bcsub($baseTaxTarget, $priorTaxBase, 4);
                    if (bccomp($nativeTax, '0', 4) < 0 || bccomp($baseTax, '0', 4) < 0) {
                        $this->fail('tax_total', 'The remaining historical purchase tax cannot be negative.');
                    }
                    $nativeAmount = bcadd($nativeAmount, $nativeTax, 4);
                    $baseAmount = bcadd($baseAmount, $baseTax, 4);
                    if ($taxLine->treatment === 'recoverable' && bccomp($nativeTax, '0', 4) > 0) {
                        $this->validateAccount($company, $taxLine->tax_asset_account_id, ['asset'], $purchase->currency, 'tax_asset_account_id');
                        $recoverableNative = bcadd($recoverableNative, $nativeTax, 4);
                        $recoverableBase = bcadd($recoverableBase, $baseTax, 4);
                        $taxCreditByAccount[$taxLine->tax_asset_account_id] ??= ['native' => '0.0000', 'base' => '0.0000'];
                        $taxCreditByAccount[$taxLine->tax_asset_account_id]['native'] = bcadd($taxCreditByAccount[$taxLine->tax_asset_account_id]['native'], $nativeTax, 4);
                        $taxCreditByAccount[$taxLine->tax_asset_account_id]['base'] = bcadd($taxCreditByAccount[$taxLine->tax_asset_account_id]['base'], $baseTax, 4);
                    }
                    if (bccomp($nativeTax, '0', 4) > 0 || bccomp($baseTax, '0', 4) > 0) {
                        SupplierReturnLineTax::create([
                            'company_id' => $company->getKey(),
                            'supplier_return_line_id' => $line->getKey(),
                            'purchase_tax_line_id' => $taxLine->getKey(),
                            'native_amount' => $nativeTax,
                            'base_amount' => $baseTax,
                        ]);
                    }
                }

                $inventoryCost = '0.0000';
                $variance = '0.0000';
                if (isset($receiptByLine[$line->getKey()])) {
                    $product = Product::query()->where('company_id', $company->getKey())->findOrFail($line->product_id);
                    $movement = $this->inventory->issueSupplierReturn(
                        $product,
                        $warehouseByLine[$line->getKey()],
                        (string) $line->quantity,
                        $item->unitOfMeasure,
                        [
                            'occurred_at' => Carbon::parse($lockedReturn->return_date)->endOfDay(),
                            'reference' => $lockedReturn->return_number,
                            'notes' => $lockedReturn->reason,
                            'sourceable_type' => SupplierReturn::class,
                            'sourceable_id' => $lockedReturn->getKey(),
                            'purchase_item_id' => $item->getKey(),
                            'party_id' => $purchase->party_id,
                            'project_id' => $purchase->project_id,
                        ],
                    );
                    if ($movement->base_cost_amount === null) {
                        $this->fail('valuation', 'A supplier return requires reliable current moving-average valuation.');
                    }
                    $inventoryCost = (string) $movement->base_cost_amount;
                    $variance = bcsub(bcsub($baseAmount, $inventoryCost, 4), $recoverableBase, 4);
                    $inventoryCostTotal = bcadd($inventoryCostTotal, $inventoryCost, 4);
                    $varianceTotal = bcadd($varianceTotal, $variance, 4);
                    $line->stock_movement_id = $movement->getKey();
                } else {
                    $expenseAccountId = $line->expense_account_id ?? $item->expense_account_id
                        ?? $this->singleOriginalExpenseAccountId($originalJournalLines, $purchase);
                    $expenseAccount = $expenseAccountId === null ? null : FinancialAccount::query()
                        ->where('company_id', $company->getKey())
                        ->whereKey($item->expense_account_id)
                        ->first();
                    if ($expenseAccount === null) {
                        $this->fail('expense_account_id', 'A non-tracked purchase return requires the original purchase expense account.');
                    }
                    $this->validateAccount($company, (int) $expenseAccount->getKey(), ['expense'], $purchase->currency, 'expense_account_id');
                    $expenseAmount = bcsub($nativeAmount, $recoverableNative, 4);
                    $expenseBase = bcsub($baseAmount, $recoverableBase, 4);
                    if (bccomp($expenseAmount, '0', 4) > 0) {
                        $expenseCredits[$expenseAccount->getKey()] ??= ['native' => '0.0000', 'base' => '0.0000'];
                        $expenseCredits[$expenseAccount->getKey()]['native'] = bcadd($expenseCredits[$expenseAccount->getKey()]['native'], $expenseAmount, 4);
                        $expenseCredits[$expenseAccount->getKey()]['base'] = bcadd($expenseCredits[$expenseAccount->getKey()]['base'], $expenseBase, 4);
                    }
                }

                $line->native_amount = $nativeAmount;
                $line->base_amount = $baseAmount;
                $line->inventory_cost_amount = $inventoryCost;
                $line->variance_amount = $variance;
                $line->save();
                $nativeTotal = bcadd($nativeTotal, $nativeAmount, 4);
                $baseTotal = bcadd($baseTotal, $baseAmount, 4);
            }

            if (bccomp($varianceTotal, '0', 4) > 0) {
                $this->validateAccount($company, $lockedReturn->purchase_return_variance_account_id, ['income', 'revenue'], $lockedReturn->base_currency, 'purchase_return_variance_account_id');
            } elseif (bccomp($varianceTotal, '0', 4) < 0) {
                $this->validateAccount($company, $lockedReturn->purchase_return_variance_account_id, ['expense'], $lockedReturn->base_currency, 'purchase_return_variance_account_id');
            }

            $journal = $this->accounting->createDraftJournal([
                'company_id' => $company->getKey(),
                'created_by' => $user->getKey(),
                'sourceable_type' => SupplierReturn::class,
                'sourceable_id' => $lockedReturn->getKey(),
                'entry_date' => $lockedReturn->return_date->toDateString(),
                'description' => 'Supplier return '.$lockedReturn->return_number.' for '.$purchase->purchase_number,
            ], $company);

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $lockedReturn->payable_account_id,
                'party_id' => $purchase->party_id,
                'debit' => $nativeTotal,
                'base_debit' => $baseTotal,
                'currency' => $purchase->currency,
                'exchange_rate' => $purchase->exchange_rate,
                'base_currency' => $purchase->base_currency,
                'description' => 'Supplier credit for '.$lockedReturn->return_number,
            ]);
            if (bccomp($inventoryCostTotal, '0', 4) > 0) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->inventory_asset_account_id,
                    'party_id' => $purchase->party_id,
                    'credit' => $inventoryCostTotal,
                    'base_credit' => $inventoryCostTotal,
                    'currency' => $purchase->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $purchase->base_currency,
                    'description' => 'Inventory relieved at carrying value for '.$lockedReturn->return_number,
                ]);
            }
            foreach ($taxCreditByAccount as $accountId => $amounts) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $accountId,
                    'party_id' => $purchase->party_id,
                    'credit' => $amounts['native'],
                    'base_credit' => $amounts['base'],
                    'currency' => $purchase->currency,
                    'exchange_rate' => $purchase->exchange_rate,
                    'base_currency' => $purchase->base_currency,
                    'description' => 'Recoverable purchase tax reversed for '.$lockedReturn->return_number,
                ]);
            }
            foreach ($expenseCredits as $accountId => $amounts) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $accountId,
                    'party_id' => $purchase->party_id,
                    'credit' => $amounts['native'],
                    'base_credit' => $amounts['base'],
                    'currency' => $purchase->currency,
                    'exchange_rate' => $purchase->exchange_rate,
                    'base_currency' => $purchase->base_currency,
                    'description' => 'Purchase expense credited for '.$lockedReturn->return_number,
                ]);
            }
            if (bccomp($varianceTotal, '0', 4) !== 0) {
                $variance = bccomp($varianceTotal, '0', 4) > 0
                    ? $varianceTotal
                    : bcsub('0', $varianceTotal, 4);
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->purchase_return_variance_account_id,
                    'party_id' => $purchase->party_id,
                    ...(bccomp($varianceTotal, '0', 4) > 0
                        ? ['credit' => $variance, 'base_credit' => $variance]
                        : ['debit' => $variance, 'base_debit' => $variance]),
                    'currency' => $purchase->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $purchase->base_currency,
                    'description' => 'Purchase return carrying-value variance for '.$lockedReturn->return_number,
                ]);
            }

            $this->accounting->postJournal($journal);
            $lockedReturn->forceFill([
                'total' => $nativeTotal,
                'base_total' => $baseTotal,
                'inventory_cost_total' => $inventoryCostTotal,
                'variance_total' => $varianceTotal,
                'accounting_journal_id' => $journal->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
                'status' => 'posted',
            ])->save();

            return $lockedReturn->fresh(['lines.taxes', 'journal']);
        }, attempts: 3);
    }

    private function originalJournal(Purchase $purchase): AccountingJournal
    {
        $journal = AccountingJournal::query()
            ->where('company_id', $purchase->company_id)
            ->where('sourceable_type', Purchase::class)
            ->where('sourceable_id', $purchase->getKey())
            ->where('status', 'posted')
            ->first();
        if ($journal === null) {
            $this->fail('original_purchase_id', 'The original purchase must have a posted accounting journal.');
        }

        return $journal;
    }

    private function resolveReceiptMovement(SupplierReturn $return, PurchaseItem $item): StockMovement
    {
        $query = StockMovement::query()
            ->where('company_id', $return->company_id)
            ->where('product_id', $item->product_id)
            ->where('direction', 'in')
            ->where('sourceable_type', Purchase::class)
            ->where('sourceable_id', $return->original_purchase_id);
        $movement = $query->where('purchase_item_id', $item->getKey())->first();
        if ($movement === null) {
            $candidates = $query->whereNull('purchase_item_id')->get()
                ->filter(fn (StockMovement $candidate): bool => bccomp((string) $candidate->quantity, (string) $item->quantity, 4) === 0);
            if ($item->warehouse_id !== null) {
                $candidates = $candidates->where('warehouse_id', $item->warehouse_id);
            }
            if ($candidates->count() === 1) {
                $movement = $candidates->first();
            }
        }
        if ($movement === null) {
            $this->fail('stock_movement', 'The original receipt movement for this purchase item could not be identified.');
        }

        return $movement;
    }

    private function returnedQuantity(PurchaseItem $item): string
    {
        $lines = SupplierReturnLine::query()
            ->where('purchase_item_id', $item->getKey())
            ->whereHas('supplierReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['quantity']);
        $quantity = '0.0000';
        foreach ($lines as $line) {
            $quantity = bcadd($quantity, (string) $line->quantity, 4);
        }

        return $quantity;
    }

    private function returnedNativeAmount(PurchaseItem $item): string
    {
        $lines = SupplierReturnLine::query()
            ->where('purchase_item_id', $item->getKey())
            ->whereHas('supplierReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['native_amount']);
        $amount = '0.0000';
        foreach ($lines as $line) {
            $amount = bcadd($amount, (string) $line->native_amount, 4);
        }

        return $amount;
    }

    private function returnedBaseAmount(PurchaseItem $item): string
    {
        $lines = SupplierReturnLine::query()
            ->where('purchase_item_id', $item->getKey())
            ->whereHas('supplierReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['base_amount']);
        $amount = '0.0000';
        foreach ($lines as $line) {
            $amount = bcadd($amount, (string) $line->base_amount, 4);
        }

        return $amount;
    }

    private function returnedTaxAmount(PurchaseTaxLine $taxLine, string $column): string
    {
        $taxes = SupplierReturnLineTax::query()
            ->where('purchase_tax_line_id', $taxLine->getKey())
            ->whereHas('supplierReturnLine.supplierReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get([$column]);
        $amount = '0.0000';
        foreach ($taxes as $tax) {
            $amount = bcadd($amount, (string) $tax->getAttribute($column), 4);
        }

        return $amount;
    }

    private function returnedItemTaxAmount(PurchaseItem $item, string $column): string
    {
        $taxes = SupplierReturnLineTax::query()
            ->whereHas('purchaseTaxLine', fn ($query) => $query->where('purchase_item_id', $item->getKey()))
            ->whereHas('supplierReturnLine.supplierReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get([$column]);
        $amount = '0.0000';
        foreach ($taxes as $tax) {
            $amount = bcadd($amount, (string) $tax->getAttribute($column), 4);
        }

        return $amount;
    }

    private function validateAccount(Company $company, ?int $accountId, array $types, string $currency, string $attribute): void
    {
        $account = $accountId === null ? null : FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->first();
        if ($account === null || ! $account->is_active
            || ! in_array(strtolower((string) $account->account_type), $types, true)
            || strtoupper((string) $account->currency) !== strtoupper($currency)) {
            $this->fail($attribute, 'The account must be active, belong to the active company, have an allowed type, and use the required currency.');
        }
    }

    private function accountIdByDescription(Collection $lines, string $prefix): ?int
    {
        $line = $lines->first(fn (JournalLine $line): bool => str_starts_with((string) $line->description, $prefix));

        return $line === null ? null : (int) $line->financial_account_id;
    }

    private function singleOriginalExpenseAccountId(Collection $lines, Purchase $purchase): ?int
    {
        $accountIds = $lines->filter(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 4) > 0
            && $line->currency === $purchase->currency
            && strtolower((string) $line->financialAccount?->account_type) === 'expense'
            && str_starts_with((string) $line->description, 'Purchase expense for ')
        )->pluck('financial_account_id')->unique()->values();

        return $accountIds->count() === 1 ? (int) $accountIds->first() : null;
    }

    /**
     * @return array<int, string>
     */
    private function purchaseBaseSubtotalByItem(Purchase $purchase): array
    {
        $items = $purchase->items()->orderBy('id')->get(['id', 'line_total']);
        $eligibleItems = $items->filter(fn (PurchaseItem $item): bool => bccomp((string) $item->line_total, '0', 4) > 0);
        $weights = [];
        $totalWeight = '0.0000';
        foreach ($eligibleItems as $item) {
            $weights[$item->getKey()] = (string) $item->line_total;
            $totalWeight = bcadd($totalWeight, (string) $item->line_total, 4);
        }
        $allocations = array_fill_keys($items->modelKeys(), '0.0000');
        if ($weights === [] || bccomp($totalWeight, '0', 4) <= 0) {
            return $allocations;
        }

        $lastItemId = array_key_last($weights);
        $allocated = '0.0000';
        foreach ($weights as $itemId => $weight) {
            if ($itemId === $lastItemId) {
                $share = bcsub((string) $purchase->base_subtotal, $allocated, 4);
            } else {
                $share = bcadd(
                    bcdiv(bcmul((string) $purchase->base_subtotal, $weight, 12), $totalWeight, 12),
                    '0',
                    4,
                );
                $remaining = bcsub((string) $purchase->base_subtotal, $allocated, 4);
                if (bccomp($share, $remaining, 4) > 0) {
                    $share = $remaining;
                }
            }
            $allocations[$itemId] = $share;
            $allocated = bcadd($allocated, $share, 4);
        }

        return $allocations;
    }

    private function toBaseCurrency(string $amount, string $rate, string $currency, string $baseCurrency): string
    {
        return strtoupper($currency) === strtoupper($baseCurrency)
            ? $this->roundMoney($amount)
            : $this->roundMoney(bcmul($amount, $rate, 16));
    }

    private function allocate(string $amount, string $cumulativeQuantity, string $originalQuantity): string
    {
        if (bccomp($originalQuantity, '0', 4) <= 0) {
            $this->fail('quantity', 'The original document quantity must be greater than zero.');
        }

        return $this->roundMoney(bcmul($amount, bcdiv($cumulativeQuantity, $originalQuantity, 16), 16));
    }

    private function roundMoney(string $amount): string
    {
        return bcadd($amount, '0.00005', 4);
    }

    private function quantity(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            $this->fail('quantity', 'Return quantities must be positive decimal values.');
        }
        $quantity = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity) !== 1 || bccomp($quantity, '0', 4) <= 0) {
            $this->fail('quantity', 'Return quantities must be positive and may have up to four decimal places.');
        }

        return bcadd($quantity, '0', 4);
    }

    private function positiveId(mixed $value, string $attribute): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->fail($attribute, 'A valid record ID is required.');
        }
        $id = (int) $value;
        if ($id < 1) {
            $this->fail($attribute, 'A valid record ID is required.');
        }

        return $id;
    }

    private function optionalId(mixed $value, string $attribute): ?int
    {
        return $value === null || $value === '' ? null : $this->positiveId($value, $attribute);
    }

    private function requiredReason(mixed $reason): string
    {
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            $this->fail('reason', 'A reason of no more than 2000 characters is required.');
        }

        return trim($reason);
    }

    private function returnDate(mixed $date): string
    {
        if (! is_string($date) && ! $date instanceof DateTimeInterface) {
            $this->fail('return_date', 'A valid return date is required.');
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (InvalidFormatException) {
            $this->fail('return_date', 'A valid return date is required.');
        }
    }

    private function authorizedUser(Company $company): User
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $this->companyAccess->canAccessCompany($user, $company)) {
            $this->fail('company_id', 'An authorized user in the active company is required.');
        }

        return $user;
    }

    private function assertReturnCompany(SupplierReturn $return, Company $company): void
    {
        if ((int) $return->company_id !== (int) $company->getKey()) {
            $this->fail('supplier_return', 'The supplier return does not belong to the active company.');
        }
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
