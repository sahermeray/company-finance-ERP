<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expense extends Model
{
    use TenantScoped;

    protected $fillable = [
        'company_id',
        'party_id',
        'project_id',
        'financial_account_id',
        'created_by',
        'expense_number',
        'expense_date',
        'status',
        'amount',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'description',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'base_amount' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function accountingJournals(): MorphMany
    {
        return $this->morphMany(AccountingJournal::class, 'sourceable');
    }

    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}
