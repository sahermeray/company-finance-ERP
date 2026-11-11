<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
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

class CustomerReturnService
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAccessService $companyAccess,
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function createDraft(array $attributes): CustomerReturn
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->authorizedUser($company);
        $revenueId = $this->positiveId($attributes['original_revenue_id'] ?? null, 'original_revenue_id');
        $reason = $this->requiredReason($attributes['reason'] ?? null);
        $returnDate = $this->returnDate($attributes['return_date'] ?? now()->toDateString());

        return DB::transaction(function () use ($company, $user, $revenueId, $reason, $returnDate, $attributes): CustomerReturn {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $revenue = Revenue::query()
                ->where('company_id', $company->getKey())
                ->whereKey($revenueId)
                ->lockForUpdate()
                ->first();

            if ($revenue === null || $revenue->status !== 'posted') {
                $this->fail('original_revenue_id', 'A customer return must reference a posted revenue document in the active company.');
            }

            $originalJournal = $this->originalJournal($revenue);
            $originalLines = $originalJournal->lines()->with('financialAccount')->get();
            $incomeLine = $originalLines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 4) > 0
                && in_array(strtolower((string) $line->financialAccount?->account_type), ['revenue', 'income'], true)
            );
            $refundLine = $originalLines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 4) > 0
                && $line->currency === $revenue->currency
                && in_array(strtolower((string) $line->financialAccount?->account_type), ['asset', 'liability'], true)
                && str_starts_with((string) $line->description, 'Receivable / cash from revenue ')
            );

            if ($incomeLine === null || $refundLine === null) {
                $this->fail('original_revenue_id', 'The original revenue journal does not contain its revenue and customer-balance accounts.');
            }

            $inventoryAccountId = $this->optionalId($attributes['inventory_asset_account_id'] ?? null, 'inventory_asset_account_id');
            $cogsAccountId = $this->optionalId($attributes['cogs_account_id'] ?? null, 'cogs_account_id');
            $inventoryAccountId ??= $this->accountIdByDescription($originalLines, 'Inventory relieved for revenue ');
            $cogsAccountId ??= $this->accountIdByDescription($originalLines, 'Cost of goods sold for revenue ');

            $refundAccountId = $this->optionalId($attributes['refund_account_id'] ?? null, 'refund_account_id')
                ?? (int) $refundLine->financial_account_id;

            return CustomerReturn::create([
                'company_id' => $company->getKey(),
                'original_revenue_id' => $revenue->getKey(),
                'party_id' => $revenue->party_id,
                'created_by' => $user->getKey(),
                'refund_account_id' => $refundAccountId,
                'revenue_account_id' => $incomeLine->financial_account_id,
                'inventory_asset_account_id' => $inventoryAccountId,
                'cogs_account_id' => $cogsAccountId,
                'return_number' => 'CR-'.strtoupper(Str::random(12)),
                'return_date' => $returnDate,
                'status' => 'draft',
                'currency' => strtoupper((string) $revenue->currency),
                'exchange_rate' => (string) $revenue->exchange_rate,
                'base_currency' => strtoupper((string) $revenue->base_currency),
                'reason' => $reason,
                'subtotal' => '0.0000',
                'tax_total' => '0.0000',
                'total' => '0.0000',
                'base_subtotal' => '0.0000',
                'base_tax_total' => '0.0000',
                'base_total' => '0.0000',
            ]);
        });
    }

    public function addLine(CustomerReturn $return, array $attributes): CustomerReturnLine
    {
        $company = $this->companyContext->requireCurrent();
        $this->assertReturnCompany($return, $company);
        $user = $this->authorizedUser($company);
        $itemId = $this->positiveId($attributes['revenue_item_id'] ?? null, 'revenue_item_id');
        $quantity = $this->quantity($attributes['quantity'] ?? null);
        $warehouseId = $this->optionalId($attributes['warehouse_id'] ?? null, 'warehouse_id');

        return DB::transaction(function () use ($return, $company, $itemId, $quantity, $warehouseId): CustomerReturnLine {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedReturn = CustomerReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedReturn->status !== 'draft') {
                $this->fail('customer_return', 'Only draft customer returns can accept lines.');
            }

            $item = RevenueItem::query()->whereKey($itemId)->first();
            if ($item === null || (int) $item->revenue_id !== (int) $lockedReturn->original_revenue_id) {
                $this->fail('revenue_item_id', 'The selected item does not belong to the original revenue document.');
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
                $this->resolveIssueMovement($lockedReturn, $item);
            }

            if (bccomp($this->returnedQuantity($item, $lockedReturn), (string) $item->quantity, 4) >= 0
                || bccomp($quantity, bcsub((string) $item->quantity, $this->returnedQuantity($item, $lockedReturn), 4), 4) > 0) {
                $this->fail('quantity', 'The return quantity exceeds the original sold quantity remaining.');
            }

            return $lockedReturn->lines()->create([
                'revenue_item_id' => $item->getKey(),
                'product_id' => $product->getKey(),
                'warehouse_id' => $warehouse?->getKey(),
                'quantity' => $quantity,
            ]);
        });
    }

    public function post(CustomerReturn $return): CustomerReturn
    {
        $company = $this->companyContext->requireCurrent();
        $user = $this->authorizedUser($company);
        $this->assertReturnCompany($return, $company);

        return DB::transaction(function () use ($return, $company, $user): CustomerReturn {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $lockedReturn = CustomerReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedReturn->status !== 'draft' || $lockedReturn->accounting_journal_id !== null) {
                $this->fail('customer_return', 'Only an unposted draft customer return can be posted.');
            }

            $revenue = Revenue::query()
                ->where('company_id', $company->getKey())
                ->whereKey($lockedReturn->original_revenue_id)
                ->lockForUpdate()
                ->first();
            if ($revenue === null || $revenue->status !== 'posted'
                || strtoupper((string) $revenue->currency) !== $lockedReturn->currency
                || bccomp((string) $revenue->exchange_rate, (string) $lockedReturn->exchange_rate, 10) !== 0) {
                $this->fail('original_revenue_id', 'The original posted revenue document is unavailable or its historical currency data is invalid.');
            }

            $lines = $lockedReturn->lines()->with(['revenueItem.product', 'revenueItem.unitOfMeasure'])->orderBy('id')->get();
            if ($lines->isEmpty()) {
                $this->fail('lines', 'A customer return must contain at least one line.');
            }

            $itemIds = $lines->pluck('product_id')->map(static fn ($id): int => (int) $id)->all();
            $this->inventory->lockProductsForValuation($itemIds);
            $warehouseByLine = [];
            $issueByLine = [];
            foreach ($lines as $line) {
                $item = $line->revenueItem;
                if ($item === null || (int) $item->revenue_id !== (int) $revenue->getKey()
                    || (int) $item->product_id !== (int) $line->product_id) {
                    $this->fail('revenue_item_id', 'A return line no longer matches an item on its original revenue document.');
                }

                $product = Product::query()->where('company_id', $company->getKey())->whereKey($line->product_id)->first();
                if ($product === null) {
                    $this->fail('product_id', 'A returned product does not belong to the active company.');
                }

                $remaining = bcsub((string) $item->quantity, $this->returnedQuantity($item), 4);
                if (bccomp((string) $line->quantity, $remaining, 4) > 0) {
                    $this->fail('quantity', 'The return quantity exceeds the original sold quantity remaining.');
                }

                if ($product->tracks_inventory) {
                    $issue = $this->resolveIssueMovement($lockedReturn, $item);
                    if ($issue->base_cost_amount === null || $issue->base_unit_cost === null
                        || $issue->direction !== 'out'
                        || bccomp((string) $issue->quantity, (string) $item->quantity, 4) !== 0) {
                        $this->fail('valuation', 'A customer return requires the original valued stock issue for its revenue item.');
                    }

                    if (strtoupper((string) $issue->company?->base_currency) !== $lockedReturn->base_currency) {
                        $this->fail('base_currency', 'The original stock issue must use the company base currency.');
                    }

                    $warehouseId = $line->warehouse_id ?? $issue->warehouse_id;
                    $warehouse = Warehouse::query()
                        ->where('company_id', $company->getKey())
                        ->whereKey($warehouseId)
                        ->first();
                    if ($warehouse === null) {
                        $this->fail('warehouse_id', 'The return warehouse must belong to the active company.');
                    }
                    $warehouseByLine[$line->getKey()] = $warehouse;
                    $issueByLine[$line->getKey()] = $issue;
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

            $originalJournal = $this->originalJournal($revenue);
            if ($originalJournal->reversed_by_journal_id !== null) {
                $this->fail('original_revenue_id', 'A return cannot be posted against a reversed revenue journal.');
            }
            $originalLines = $originalJournal->lines()->with('financialAccount')->get();
            $hasTrackedLines = $lines->contains(fn (CustomerReturnLine $line): bool => (bool) ($line->revenueItem?->product?->tracks_inventory)
            );
            $this->validateAccount($company, $lockedReturn->refund_account_id, ['asset', 'liability'], $lockedReturn->currency, 'refund_account_id');
            $this->validateAccount($company, $lockedReturn->revenue_account_id, ['revenue', 'income'], $lockedReturn->currency, 'revenue_account_id');
            if ($hasTrackedLines) {
                $this->validateAccount($company, $lockedReturn->inventory_asset_account_id, ['asset'], $lockedReturn->base_currency, 'inventory_asset_account_id');
                $this->validateAccount($company, $lockedReturn->cogs_account_id, ['expense'], $lockedReturn->base_currency, 'cogs_account_id');
            }

            $priorSubtotal = $this->postedSubtotal($revenue);
            $priorTax = $this->postedTax($revenue);
            $currentSubtotal = '0.0000';
            $costTotal = '0.0000';
            $nativeTotal = '0.0000';
            foreach ($lines as $line) {
                $item = $line->revenueItem;
                $previousQuantity = $this->returnedQuantity($item);
                $previousAmount = $this->returnedNativeAmount($item);
                $cumulativeAmount = $this->allocate($item->line_total, bcadd($previousQuantity, (string) $line->quantity, 4), $item->quantity);
                $nativeAmount = bcsub($cumulativeAmount, $previousAmount, 4);
                $baseAmount = $this->toBaseCurrency($nativeAmount, (string) $revenue->exchange_rate, $revenue->currency, $revenue->base_currency);

                $inventoryCost = null;
                if (isset($issueByLine[$line->getKey()])) {
                    $issue = $issueByLine[$line->getKey()];
                    $priorIssueQuantity = $this->returnedIssueQuantity($issue);
                    $priorIssueCost = $this->returnedIssueCost($issue);
                    $cumulativeIssueQuantity = bcadd($priorIssueQuantity, (string) $line->quantity, 4);
                    $cumulativeIssueCost = bccomp($cumulativeIssueQuantity, (string) $issue->quantity, 4) === 0
                        ? (string) $issue->base_cost_amount
                        : $this->allocate((string) $issue->base_cost_amount, $cumulativeIssueQuantity, (string) $issue->quantity);
                    $inventoryCost = bcsub($cumulativeIssueCost, $priorIssueCost, 4);
                    if (bccomp($inventoryCost, '0', 4) < 0) {
                        $this->fail('valuation', 'The remaining historical issue value cannot be negative.');
                    }
                    $costTotal = bcadd($costTotal, $inventoryCost, 4);
                    $warehouseByLine[$line->getKey()] = $lockedWarehouses->get($warehouseByLine[$line->getKey()]->getKey());
                }

                $line->native_amount = $nativeAmount;
                $line->base_amount = $baseAmount;
                $line->inventory_cost_amount = $inventoryCost;
                $line->save();
                $currentSubtotal = bcadd($currentSubtotal, $nativeAmount, 4);
                $nativeTotal = bcadd($nativeTotal, $nativeAmount, 4);
            }

            $originalSubtotal = (string) $revenue->subtotal;
            $cumulativeSubtotal = bcadd($priorSubtotal, $currentSubtotal, 4);
            $taxCumulative = bccomp($originalSubtotal, '0', 4) === 0
                ? (string) $revenue->tax_total
                : $this->allocate((string) $revenue->tax_total, $cumulativeSubtotal, $originalSubtotal);
            if (bccomp($cumulativeSubtotal, $originalSubtotal, 4) === 0) {
                $taxCumulative = (string) $revenue->tax_total;
            }
            $taxTotal = bcsub($taxCumulative, $priorTax, 4);
            if (bccomp($taxTotal, '0', 4) < 0) {
                $this->fail('tax_total', 'The remaining historical tax amount cannot be negative.');
            }
            $nativeTotal = bcadd($currentSubtotal, $taxTotal, 4);
            $baseSubtotal = $this->toBaseCurrency($currentSubtotal, (string) $revenue->exchange_rate, $revenue->currency, $revenue->base_currency);
            $baseTax = $this->toBaseCurrency($taxTotal, (string) $revenue->exchange_rate, $revenue->currency, $revenue->base_currency);
            $baseTotal = bcadd($baseSubtotal, $baseTax, 4);

            foreach ($lines as $line) {
                if (! isset($issueByLine[$line->getKey()])) {
                    continue;
                }
                $movement = $this->inventory->receiveCustomerReturn(
                    Product::query()->where('company_id', $company->getKey())->findOrFail($line->product_id),
                    $warehouseByLine[$line->getKey()],
                    (string) $line->quantity,
                    (string) $line->inventory_cost_amount,
                    $line->revenueItem->unitOfMeasure,
                    [
                        'occurred_at' => Carbon::parse($lockedReturn->return_date)->endOfDay(),
                        'reference' => $lockedReturn->return_number,
                        'notes' => $lockedReturn->reason,
                        'sourceable_type' => CustomerReturn::class,
                        'sourceable_id' => $lockedReturn->getKey(),
                        'revenue_item_id' => $line->revenue_item_id,
                        'party_id' => $revenue->party_id,
                        'project_id' => $revenue->project_id,
                    ],
                );
                $line->stock_movement_id = $movement->getKey();
                $line->save();
            }

            if (bccomp($nativeTotal, '0', 4) === 0 && bccomp($costTotal, '0', 4) === 0) {
                $this->fail('total', 'The return has no refundable amount or historical inventory value.');
            }

            $journal = $this->accounting->createDraftJournal([
                'company_id' => $company->getKey(),
                'created_by' => $user->getKey(),
                'sourceable_type' => CustomerReturn::class,
                'sourceable_id' => $lockedReturn->getKey(),
                'entry_date' => $lockedReturn->return_date->toDateString(),
                'description' => 'Customer return '.$lockedReturn->return_number.' for '.$revenue->revenue_number,
            ], $company);

            if (bccomp($nativeTotal, '0', 4) > 0) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->revenue_account_id,
                    'party_id' => $revenue->party_id,
                    'debit' => $nativeTotal,
                    'base_debit' => $baseTotal,
                    'currency' => $revenue->currency,
                    'exchange_rate' => $revenue->exchange_rate,
                    'base_currency' => $revenue->base_currency,
                    'description' => 'Revenue reversed for customer return '.$lockedReturn->return_number,
                ]);
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->refund_account_id,
                    'party_id' => $revenue->party_id,
                    'credit' => $nativeTotal,
                    'base_credit' => $baseTotal,
                    'currency' => $revenue->currency,
                    'exchange_rate' => $revenue->exchange_rate,
                    'base_currency' => $revenue->base_currency,
                    'description' => 'Customer refund or credit for '.$lockedReturn->return_number,
                ]);
            }

            if (bccomp($costTotal, '0', 4) > 0) {
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->inventory_asset_account_id,
                    'party_id' => $revenue->party_id,
                    'debit' => $costTotal,
                    'base_debit' => $costTotal,
                    'currency' => $revenue->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $revenue->base_currency,
                    'description' => 'Inventory restored at original issue cost for '.$lockedReturn->return_number,
                ]);
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $lockedReturn->cogs_account_id,
                    'party_id' => $revenue->party_id,
                    'credit' => $costTotal,
                    'base_credit' => $costTotal,
                    'currency' => $revenue->base_currency,
                    'exchange_rate' => '1',
                    'base_currency' => $revenue->base_currency,
                    'description' => 'COGS reversed at original issue cost for '.$lockedReturn->return_number,
                ]);
            }

            $this->accounting->postJournal($journal);
            $lockedReturn->forceFill([
                'subtotal' => $currentSubtotal,
                'tax_total' => $taxTotal,
                'total' => $nativeTotal,
                'base_subtotal' => $baseSubtotal,
                'base_tax_total' => $baseTax,
                'base_total' => $baseTotal,
                'accounting_journal_id' => $journal->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
                'status' => 'posted',
            ])->save();

            return $lockedReturn->fresh(['lines', 'journal']);
        }, attempts: 3);
    }

    private function originalJournal(Revenue $revenue): AccountingJournal
    {
        $journal = AccountingJournal::query()
            ->where('company_id', $revenue->company_id)
            ->where('sourceable_type', Revenue::class)
            ->where('sourceable_id', $revenue->getKey())
            ->where('status', 'posted')
            ->first();
        if ($journal === null) {
            $this->fail('original_revenue_id', 'The original revenue document must have a posted accounting journal.');
        }

        return $journal;
    }

    private function resolveIssueMovement(CustomerReturn $return, RevenueItem $item): StockMovement
    {
        $query = StockMovement::query()
            ->where('company_id', $return->company_id)
            ->where('product_id', $item->product_id)
            ->where('direction', 'out')
            ->where('sourceable_type', Revenue::class)
            ->where('sourceable_id', $return->original_revenue_id);
        $movement = $query->where('revenue_item_id', $item->getKey())->first();
        if ($movement === null) {
            $candidates = $query->whereNull('revenue_item_id')->get();
            if ($item->warehouse_id !== null) {
                $candidates = $candidates->where('warehouse_id', $item->warehouse_id);
            }
            $candidates = $candidates->filter(fn (StockMovement $candidate): bool => bccomp((string) $candidate->quantity, (string) $item->quantity, 4) === 0
            );
            if ($candidates->count() === 1) {
                $movement = $candidates->first();
            }
        }
        if ($movement === null) {
            $this->fail('valuation', 'The original valued stock issue for this revenue item could not be identified.');
        }

        return $movement;
    }

    private function returnedQuantity(RevenueItem $item, ?CustomerReturn $excludeReturn = null): string
    {
        $query = CustomerReturnLine::query()
            ->where('revenue_item_id', $item->getKey())
            ->whereHas('customerReturn', fn ($builder) => $builder->where('status', 'posted'));
        if ($excludeReturn !== null) {
            $query->where('customer_return_id', '!=', $excludeReturn->getKey());
        }

        $quantity = '0.0000';
        foreach ($query->get(['quantity']) as $line) {
            $quantity = bcadd($quantity, (string) $line->quantity, 4);
        }

        return $quantity;
    }

    private function returnedNativeAmount(RevenueItem $item): string
    {
        $lines = CustomerReturnLine::query()
            ->where('revenue_item_id', $item->getKey())
            ->whereHas('customerReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['native_amount']);
        $amount = '0.0000';
        foreach ($lines as $line) {
            $amount = bcadd($amount, (string) $line->native_amount, 4);
        }

        return $amount;
    }

    private function returnedIssueQuantity(StockMovement $issue): string
    {
        $lines = CustomerReturnLine::query()
            ->where('stock_movement_id', $issue->getKey())
            ->whereHas('customerReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['quantity']);
        $quantity = '0.0000';
        foreach ($lines as $line) {
            $quantity = bcadd($quantity, (string) $line->quantity, 4);
        }

        return $quantity;
    }

    private function returnedIssueCost(StockMovement $issue): string
    {
        $lines = CustomerReturnLine::query()
            ->where('stock_movement_id', $issue->getKey())
            ->whereHas('customerReturn', fn ($builder) => $builder->where('status', 'posted'))
            ->get(['inventory_cost_amount']);
        $amount = '0.0000';
        foreach ($lines as $line) {
            $amount = bcadd($amount, (string) ($line->inventory_cost_amount ?? '0'), 4);
        }

        return $amount;
    }

    private function postedSubtotal(Revenue $revenue): string
    {
        $returns = CustomerReturn::query()->where('original_revenue_id', $revenue->getKey())
            ->where('status', 'posted')->get(['subtotal']);
        $subtotal = '0.0000';
        foreach ($returns as $return) {
            $subtotal = bcadd($subtotal, (string) $return->subtotal, 4);
        }

        return $subtotal;
    }

    private function postedTax(Revenue $revenue): string
    {
        $returns = CustomerReturn::query()->where('original_revenue_id', $revenue->getKey())
            ->where('status', 'posted')->get(['tax_total']);
        $tax = '0.0000';
        foreach ($returns as $return) {
            $tax = bcadd($tax, (string) $return->tax_total, 4);
        }

        return $tax;
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

    private function assertReturnCompany(CustomerReturn $return, Company $company): void
    {
        if ((int) $return->company_id !== (int) $company->getKey()) {
            $this->fail('customer_return', 'The customer return does not belong to the active company.');
        }
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
