<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class FinancialAccount extends Model
{
    use TenantScoped;

    protected const VALID_ACCOUNT_TYPES = [
        'asset',
        'liability',
        'equity',
        'revenue',
        'income',
        'expense',
    ];

    protected $fillable = [
        'company_id',
        'parent_account_id',
        'code',
        'name',
        'account_type',
        'currency',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->validateAccountType();
        });

        static::updating(function (self $account): void {
            $account->validateAccountType();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function postedJournalLines(): HasMany
    {
        return $this->journalLines()
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'));
    }

    /**
     * Sum of debit entries on this account's posted journal lines.
     *
     * @deprecated This sums native-currency amounts across all posted journal
     *             lines regardless of currency. For an account that holds journal
     *             lines in more than one currency (e.g. a shared payable account
     *             with both SYP and USD postings) the result is meaningless — it
     *             adds SYP and USD figures together. Use nativeCurrencyBalance()
     *             for a single-currency total, or baseCurrencyBalance() for an
     *             aggregated base-currency total. Only safe for single-currency
     *             accounts.
     */
    public function debitTotal(): string
    {
        $totals = $this->postedJournalLines()
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total')
            ->first();

        return $this->formatMoney((string) ($totals->debit_total ?? '0'));
    }

    /**
     * Sum of credit entries on this account's posted journal lines.
     *
     * @deprecated Same multi-currency caveat as debitTotal(). Use
     *             nativeCurrencyBalance() or baseCurrencyBalance() instead for
     *             any account that may hold more than one currency.
     */
    public function creditTotal(): string
    {
        $totals = $this->postedJournalLines()
            ->selectRaw('COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return $this->formatMoney((string) ($totals->credit_total ?? '0'));
    }

    /**
     * Net balance (debit − credit) across all posted journal lines.
     *
     * @deprecated This is debitTotal() − creditTotal(), which sums native-currency
     *             amounts without currency filtering. For a multi-currency account
     *             (e.g. SYP + USD lines on the same account) the result mixes
     *             currencies and is unsafe for reporting. Use:
     *               - nativeCurrencyBalance($currency) for a single-currency total;
     *               - baseCurrencyBalance() for an aggregated base-currency total;
     *               - balanceByCurrency() for a per-currency breakdown.
     *             Only safe for accounts known to hold a single currency.
     */
    public function balance(): string
    {
        return $this->formatMoney(bcsub($this->debitTotal(), $this->creditTotal(), 10));
    }

    /**
     * Net balance (debit − credit) restricted to posted journal lines in the
     * given currency. Safe for multi-currency accounts: lines in other currencies
     * are excluded from the sum.
     */
    public function nativeCurrencyBalance(string $currency): string
    {
        $currency = strtoupper($currency);

        $totals = $this->postedJournalLines()
            ->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        return $this->formatMoney(bcsub((string) ($totals->debit_total ?? '0'), (string) ($totals->credit_total ?? '0'), 10));
    }

    public function balanceByCurrency(): array
    {
        return $this->postedJournalLines()
            ->selectRaw('currency, COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->currency => [
                    'currency' => $row->currency,
                    'debit' => (string) ($row->debit_total ?? '0'),
                    'credit' => (string) ($row->credit_total ?? '0'),
                    'balance' => bcsub((string) ($row->debit_total ?? '0'), (string) ($row->credit_total ?? '0'), 10),
                ],
            ])
            ->all();
    }

    public function baseCurrencyBalance(): string
    {
        $totals = $this->postedJournalLines()
            ->selectRaw('COALESCE(SUM(base_debit), 0) as debit_total, COALESCE(SUM(base_credit), 0) as credit_total')
            ->first();

        return $this->formatMoney(bcsub((string) ($totals->debit_total ?? '0'), (string) ($totals->credit_total ?? '0'), 10));
    }

    protected function formatMoney(string $value): string
    {
        return preg_match('/^\d+$/', $value) === 1 ? $value.'.0000' : $value;
    }

    protected function validateAccountType(): void
    {
        if (! in_array(strtolower((string) $this->account_type), self::VALID_ACCOUNT_TYPES, true)) {
            throw ValidationException::withMessages([
                'account_type' => 'The account type must be asset, liability, equity, revenue, income, or expense.',
            ]);
        }
    }
}
