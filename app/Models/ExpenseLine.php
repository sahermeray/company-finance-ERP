<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ExpenseLine extends Model
{
    use TenantScoped;

    public function tenantParentRelation(): string
    {
        return 'expense';
    }

    protected $fillable = [
        'company_id',
        'expense_id',
        'expense_account_id',
        'line_number',
        'category',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->assertExpenseIsDraft();
        });

        static::updating(function (self $line): void {
            $line->assertExpenseIsDraft();
        });

        static::deleting(function (self $line): void {
            $line->assertExpenseIsDraft();
        });
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'expense_account_id');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(ExpenseTaxLine::class);
    }

    private function assertExpenseIsDraft(): void
    {
        $expense = Expense::withoutGlobalScope(CompanyScope::class)
            ->whereKey($this->expense_id)
            ->first();

        if ($expense === null || $expense->status !== 'draft') {
            throw ValidationException::withMessages([
                'expense_line' => 'Expense lines can only be created, updated, or deleted on a draft expense.',
            ]);
        }

        if ((int) $expense->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages([
                'company_id' => 'The expense line must belong to the same company as its expense.',
            ]);
        }
    }
}
