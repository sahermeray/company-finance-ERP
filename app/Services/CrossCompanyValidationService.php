<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryCutover;
use App\Models\InventoryCutoverLine;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseLandedCost;
use App\Models\PurchaseLandedCostAllocation;
use App\Models\PurchaseTaxLine;
use App\Models\Revenue;
use App\Models\RevenueItem;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CrossCompanyValidationService
{
    private const RELATION_MODELS = [
        AccountingJournal::class => ['project_id' => Project::class],
        Expense::class => [
            'party_id' => Party::class,
            'project_id' => Project::class,
            'financial_account_id' => FinancialAccount::class,
        ],
        FinancialAccount::class => ['parent_account_id' => FinancialAccount::class],
        InventoryCutover::class => [
            'inventory_asset_account_id' => FinancialAccount::class,
            'opening_equity_account_id' => FinancialAccount::class,
        ],
        InventoryCutoverLine::class => [
            'inventory_cutover_id' => InventoryCutover::class,
            'product_id' => Product::class,
            'warehouse_id' => Warehouse::class,
        ],
        JournalLine::class => [
            'accounting_journal_id' => AccountingJournal::class,
            'financial_account_id' => FinancialAccount::class,
            'party_id' => Party::class,
            'project_id' => Project::class,
        ],
        Payment::class => [
            'party_id' => Party::class,
            'project_id' => Project::class,
            'financial_account_id' => FinancialAccount::class,
        ],
        Product::class => [
            'product_category_id' => ProductCategory::class,
            'unit_of_measure_id' => UnitOfMeasure::class,
        ],
        ProductCategory::class => ['parent_id' => ProductCategory::class],
        Project::class => ['party_id' => Party::class],
        Purchase::class => [
            'party_id' => Party::class,
            'project_id' => Project::class,
        ],
        PurchaseItem::class => [
            'purchase_id' => Purchase::class,
            'product_id' => Product::class,
            'unit_of_measure_id' => UnitOfMeasure::class,
            'warehouse_id' => Warehouse::class,
            'expense_account_id' => FinancialAccount::class,
        ],
        PurchaseTaxLine::class => [
            'purchase_id' => Purchase::class,
            'purchase_item_id' => PurchaseItem::class,
            'tax_asset_account_id' => FinancialAccount::class,
        ],
        PurchaseLandedCost::class => [
            'purchase_id' => Purchase::class,
            'expense_account_id' => FinancialAccount::class,
            'payable_account_id' => FinancialAccount::class,
        ],
        PurchaseLandedCostAllocation::class => [
            'purchase_landed_cost_id' => PurchaseLandedCost::class,
            'purchase_item_id' => PurchaseItem::class,
        ],
        Revenue::class => [
            'party_id' => Party::class,
            'project_id' => Project::class,
        ],
        RevenueItem::class => [
            'revenue_id' => Revenue::class,
            'product_id' => Product::class,
            'unit_of_measure_id' => UnitOfMeasure::class,
        ],
        StockMovement::class => [
            'product_id' => Product::class,
            'warehouse_id' => Warehouse::class,
            'project_id' => Project::class,
            'party_id' => Party::class,
            'inventory_cutover_line_id' => InventoryCutoverLine::class,
        ],
    ];

    private const USER_COLUMNS = [
        AccountingJournal::class => ['created_by'],
        Attachment::class => ['uploaded_by'],
        AuditLog::class => ['user_id'],
        Expense::class => ['created_by'],
        ExchangeRate::class => ['created_by'],
        InventoryCutover::class => ['created_by'],
        Payment::class => ['created_by'],
        Purchase::class => ['created_by'],
        Revenue::class => ['created_by'],
        StockMovement::class => ['created_by'],
    ];

    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function validate(Model $record): void
    {
        $companyId = $this->ownerCompanyId($record);
        $activeCompanyId = $this->companyContext->id();

        if (Auth::check() && $activeCompanyId === null) {
            $this->fail('company_id', 'An active company context is required.');
        }

        if ($companyId === null
            && $activeCompanyId !== null
            && in_array('company_id', $record->getFillable(), true)) {
            $record->setAttribute('company_id', $activeCompanyId);
            $companyId = $activeCompanyId;
        }

        if ($companyId !== null && $activeCompanyId !== null && $companyId !== $activeCompanyId) {
            $this->fail('company_id', 'The record must belong to the active company.');
        }

        foreach (self::RELATION_MODELS[$record::class] ?? [] as $foreignKey => $relatedModel) {
            $relatedId = $record->getAttribute($foreignKey);

            if ($relatedId === null) {
                continue;
            }

            $relatedRecord = $relatedModel::withoutGlobalScope(CompanyScope::class)->find($relatedId);

            if ($relatedRecord === null || $this->ownerCompanyId($relatedRecord) !== $companyId) {
                $this->fail($foreignKey, 'The selected record must belong to the same company.');
            }
        }

        foreach (self::USER_COLUMNS[$record::class] ?? [] as $foreignKey) {
            $userId = $record->getAttribute($foreignKey);

            if ($userId === null) {
                continue;
            }

            $userQuery = User::query()->whereKey($userId);
            $belongsToCompany = $companyId === null
                ? $userQuery->exists()
                : $userQuery->whereHas(
                    'companies',
                    fn (Builder $query) => $query->whereKey($companyId),
                )->exists();

            if (! $belongsToCompany) {
                $this->fail($foreignKey, 'The selected user must belong to the same company.');
            }

            if (Auth::check() && $activeCompanyId !== null && (int) $userId !== (int) Auth::id()) {
                $this->fail($foreignKey, 'The user must be the authenticated user.');
            }
        }

        $this->validateMorphReference($record, 'sourceable');

        if ($record instanceof AuditLog) {
            $this->validateMorphReference($record, 'auditable');
        }

        if ($record instanceof Payment) {
            $this->validateMorphReference($record, 'payable');
        }
    }

    public function assertSameCompany(Model $record, Model $relatedRecord, string $attribute = 'record'): void
    {
        if ($this->ownerCompanyId($record) === null
            || $this->ownerCompanyId($record) !== $this->ownerCompanyId($relatedRecord)) {
            $this->fail($attribute, 'The selected record must belong to the same company.');
        }
    }

    private function ownerCompanyId(Model $record): ?int
    {
        if ($record->getAttribute('company_id') !== null) {
            return (int) $record->getAttribute('company_id');
        }

        $parentForeignKey = match ($record::class) {
            JournalLine::class => 'accounting_journal_id',
            PurchaseItem::class => 'purchase_id',
            RevenueItem::class => 'revenue_id',
            default => null,
        };
        $parentModel = match ($record::class) {
            JournalLine::class => AccountingJournal::class,
            PurchaseItem::class => Purchase::class,
            RevenueItem::class => Revenue::class,
            default => null,
        };

        if ($parentForeignKey === null || $record->getAttribute($parentForeignKey) === null) {
            return null;
        }

        $parent = $parentModel::withoutGlobalScope(CompanyScope::class)
            ->find($record->getAttribute($parentForeignKey));

        return $parent?->getAttribute('company_id') === null
            ? null
            : (int) $parent->getAttribute('company_id');
    }

    private function validateMorphReference(Model $record, string $name): void
    {
        $type = $record->getAttribute("{$name}_type");
        $id = $record->getAttribute("{$name}_id");

        if ($type === null && $id === null) {
            return;
        }

        $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : null;

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            $this->fail("{$name}_type", 'The selected record type is invalid.');
        }

        $relatedRecord = $class::withoutGlobalScope(CompanyScope::class)->find($id);

        if ($relatedRecord === null || $this->ownerCompanyId($relatedRecord) !== $this->ownerCompanyId($record)) {
            $this->fail("{$name}_id", 'The selected record must belong to the same company.');
        }
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
