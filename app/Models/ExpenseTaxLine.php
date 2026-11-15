<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ExpenseTaxLine extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'expense_line_id',
        'treatment',
        'amount',
        'base_amount',
        'tax_asset_account_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $taxLine): void {
            $taxLine->assertExpenseIsDraft();
        });

        static::updating(function (self $taxLine): void {
            $taxLine->assertExpenseIsDraft();
        });

        static::deleting(function (self $taxLine): void {
            $taxLine->assertExpenseIsDraft();
        });
    }

    public function expenseLine(): BelongsTo
    {
        return $this->belongsTo(ExpenseLine::class);
    }

    public function taxAssetAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'tax_asset_account_id');
    }

    private function assertExpenseIsDraft(): void
    {
        $expenseId = ExpenseLine::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->expense_line_id)
            ->value('expense_id');

        $expense = $expenseId === null
            ? null
            : Expense::withoutGlobalScope(CompanyScope::class)->find($expenseId);

        if (
            $expense === null
            || $expense->status !== 'draft'
            || (int) $expense->company_id !== (int) $this->company_id
        ) {
            throw ValidationException::withMessages([
                'expense_tax_line' => 'Tax classification can only be created, updated, or deleted on a draft expense line.',
            ]);
        }
    }
}
