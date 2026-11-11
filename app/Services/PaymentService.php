<?php

namespace App\Services;

use App\Eloquent\CompanyScope;
use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private const SERVICE_CONTROLLED_PAYMENT_ATTRIBUTES = [
        'created_by',
        'posted_at',
        'sourceable_type',
        'sourceable_id',
        'journal_id',
        'accounting_journal_id',
        'reversal_of_payment_id',
        'reversed_by_payment_id',
    ];

    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly AccountingService $accounting,
    ) {}

    // -------------------------------------------------------------------------
    // Public API — draft creation
    // -------------------------------------------------------------------------

    public function createDraftPayment(array $attributes = [], ?Company $company = null): Payment
    {
        $company ??= $this->companyContext->requireCurrent();

        $this->assertDraftPaymentAttributes($attributes);

        return DB::transaction(fn (): Payment => $this->persistDraftPayment($attributes, $company));
    }

    // -------------------------------------------------------------------------
    // Public API — generic draft-only recording
    // -------------------------------------------------------------------------

    /**
     * Record a draft payment. Posted payments must use a dedicated accounting workflow.
     */
    public function recordPayment(array $attributes = [], ?Company $company = null): Payment
    {
        if (($attributes['status'] ?? null) !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Generic payment recording only supports explicit drafts. Use a dedicated payment workflow to post a payment.',
            ]);
        }

        return $this->createDraftPayment($attributes, $company);
    }

    // -------------------------------------------------------------------------
    // Public API — supplier payment with journal entry
    // -------------------------------------------------------------------------

    /**
     * Record a supplier payment and post the corresponding accounting journal.
     *
     * Expected journal (in payment currency, converted to base):
     *   Dr  Supplier Payable account   (liability)
     *   Cr  Cash / Bank account        (asset)
     *
     * Required options:
     *   payable_account_id  — liability account to debit (supplier payable).
     *                         Falls back to the first liability account for the company.
     *   financial_account_id — asset account to credit (cash/bank, must match payment currency).
     *
     * Optional options (passed through to the internal payment preparer):
     *   amount, currency, exchange_rate, base_currency, base_amount,
     *   applied_amount, applied_currency, applied_exchange_rate, applied_base_amount,
     *   party_id, project_id, reference, notes, paid_at, payment_number.
     */
    public function recordSupplierPayment(Purchase $purchase, array $attributes = []): Payment
    {
        // Reload from database to avoid stale status data, then validate.
        $purchase->refresh();

        if ($purchase->status !== 'posted') {
            throw ValidationException::withMessages([
                'purchase' => 'Only posted purchases can be settled with a supplier payment.',
            ]);
        }

        $company = $purchase->company ?: $this->companyContext->requireCurrent();

        $payload = array_merge([
            'company_id' => $company->getKey(),
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->getKey(),
            'direction' => 'outbound',
            'status' => 'posted',
            'paid_at' => now(),
            'currency' => strtoupper((string) ($purchase->currency ?? $company->base_currency)),
            'base_currency' => strtoupper((string) ($purchase->base_currency ?? $company->base_currency)),
            'exchange_rate' => isset($attributes['exchange_rate'])
                ? $attributes['exchange_rate']
                : $this->resolveExchangeRate(
                    $purchase->currency ?? $company->base_currency,
                    $purchase->base_currency ?? $company->base_currency,
                    $company,
                ),
            'amount' => '0',
            // applied_amount intentionally absent: preparePaymentAttributes
            // derives it from `amount` when the caller does not supply it.
        ], $attributes);

        $payload = $this->preparePostedPaymentPayload(
            $payload,
            $attributes,
            $company,
            Purchase::class,
            $purchase->getKey(),
            'outbound',
        );

        return DB::transaction(function () use ($purchase, $company, $payload, $attributes): Payment {
            // Refresh company to pick up any newly added forex account columns
            $company = $company->fresh();

            // Create and post the payment row first.
            $payment = $this->persistPostedPayment($payload, $company);

            // Resolve the payable (liability) account to debit.
            $payableAccountId = $this->resolveAccountId(
                $company,
                isset($attributes['payable_account_id']) ? (int) $attributes['payable_account_id'] : null,
                ['liability'],
                'payable_account_id',
                'Supplier payable account',
            );

            // The cash/bank account to credit comes from financial_account_id,
            // which is already validated by assertCurrencyMatchesAccount.
            $cashAccountId = $payment->financial_account_id;
            if ($cashAccountId === null) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A cash or bank account (financial_account_id) is required to post a supplier payment journal.',
                ]);
            }

            // Resolve forex accounts (required for cross-currency payments).
            $exchangeGainAccountId = $this->resolveForexAccountId(
                $company,
                isset($attributes['exchange_gain_account_id']) ? (int) $attributes['exchange_gain_account_id'] : null,
                $company->exchange_gain_account_id ? (int) $company->exchange_gain_account_id : null,
                ['income'],
                'exchange_gain_account_id',
                'Exchange gain account',
            );

            $exchangeLossAccountId = $this->resolveForexAccountId(
                $company,
                isset($attributes['exchange_loss_account_id']) ? (int) $attributes['exchange_loss_account_id'] : null,
                $company->exchange_loss_account_id ? (int) $company->exchange_loss_account_id : null,
                ['expense'],
                'exchange_loss_account_id',
                'Exchange loss account',
            );

            $this->postPaymentJournal(
                $payment,
                $purchase,
                $company,
                debitAccountId: $payableAccountId,
                creditAccountId: $cashAccountId,
                description: 'Supplier payment '.$payment->payment_number.' for '.$purchase->purchase_number,
                exchangeGainAccountId: $exchangeGainAccountId,
                exchangeLossAccountId: $exchangeLossAccountId,
            );

            return $payment->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Public API — customer receipt with journal entry
    // -------------------------------------------------------------------------

    /**
     * Record a customer receipt and post the corresponding accounting journal.
     *
     * Expected journal (in payment currency, converted to base):
     *   Dr  Cash / Bank account           (asset)
     *   Cr  Customer Receivable account   (asset — receivable type)
     *
     * Required options:
     *   financial_account_id   — asset account to debit (cash/bank, must match receipt currency).
     *   receivable_account_id  — asset account to credit (customer receivable).
     *                            Falls back to the first asset account for the company.
     *
     * Note: this method does not require the Revenue to be in a "posted" status
     * because the Revenue model has no status lifecycle managed by this system yet.
     * The caller is responsible for passing a valid, company-owned Revenue.
     */
    public function recordCustomerReceipt(Revenue $revenue, array $attributes = []): Payment
    {
        $company = $revenue->company ?: $this->companyContext->requireCurrent();

        // Only posted revenue documents can be settled.
        if ($revenue->status !== 'posted') {
            throw ValidationException::withMessages([
                'revenue' => 'Only posted revenue documents can be settled with a customer receipt.',
            ]);
        }

        $payload = array_merge([
            'company_id' => $company->getKey(),
            'payable_type' => Revenue::class,
            'payable_id' => $revenue->getKey(),
            'direction' => 'inbound',
            'status' => 'posted',
            'paid_at' => now(),
            'currency' => strtoupper((string) ($revenue->currency ?? $company->base_currency)),
            'base_currency' => strtoupper((string) ($revenue->base_currency ?? $company->base_currency)),
            'exchange_rate' => isset($attributes['exchange_rate'])
                ? $attributes['exchange_rate']
                : $this->resolveExchangeRate(
                    $revenue->currency ?? $company->base_currency,
                    $revenue->base_currency ?? $company->base_currency,
                    $company,
                ),
            'amount' => '0',
            // applied_amount intentionally absent: preparePaymentAttributes
            // derives it from `amount` when the caller does not supply it.
        ], $attributes);

        $payload = $this->preparePostedPaymentPayload(
            $payload,
            $attributes,
            $company,
            Revenue::class,
            $revenue->getKey(),
            'inbound',
        );

        return DB::transaction(function () use ($revenue, $company, $payload, $attributes): Payment {
            $payment = $this->persistPostedPayment($payload, $company);

            // The cash/bank account to debit.
            $cashAccountId = $payment->financial_account_id;
            if ($cashAccountId === null) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A cash or bank account (financial_account_id) is required to post a customer receipt journal.',
                ]);
            }

            // Resolve the receivable (asset) account to credit.
            $receivableAccountId = $this->resolveAccountId(
                $company,
                isset($attributes['receivable_account_id']) ? (int) $attributes['receivable_account_id'] : null,
                ['asset'],
                'receivable_account_id',
                'Customer receivable account',
            );

            $this->postPaymentJournal(
                $payment,
                $revenue,
                $company,
                debitAccountId: $cashAccountId,         // Dr Cash/Bank
                creditAccountId: $receivableAccountId,  // Cr Customer Receivable
                description: 'Customer receipt '.$payment->payment_number.' for '.($revenue->revenue_number ?? $revenue->getKey()),
            );

            return $payment->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Public API — partner-paid purchase
    // -------------------------------------------------------------------------

    /**
     * Record that a partner paid a purchase on the company's behalf.
     *
     * Accounting effect:
     *   Dr  Supplier Payable account   (liability — clears the liability to the supplier)
     *   Cr  Due-to-Partner account     (liability — records what the company owes the partner)
     *
     * No company cash or bank account is involved.  financial_account_id must
     * NOT be set; pass partner_liability_account_id instead.
     *
     * Required attributes:
     *   amount                      — amount paid by the partner (in payment currency)
     *   currency                    — payment currency
     *   party_id                    — the partner (Party) who paid
     *   payable_account_id          — liability account to debit (supplier payable)
     *   partner_liability_account_id — liability account to credit (due to partner)
     *
     * Optional attributes (same as recordSupplierPayment):
     *   exchange_rate, base_currency, base_amount, applied_amount,
     *   applied_currency, applied_exchange_rate, applied_base_amount,
     *   project_id, reference, notes, paid_at, payment_number.
     */
    public function recordPartnerPaidPurchase(Purchase $purchase, array $attributes = []): Payment
    {
        // Reload from database to avoid stale status data, then validate.
        $purchase->refresh();

        if ($purchase->status !== 'posted') {
            throw ValidationException::withMessages([
                'purchase' => 'Only posted purchases can be settled with a partner payment.',
            ]);
        }

        $company = $purchase->company ?: $this->companyContext->requireCurrent();

        // Validate the partner party belongs to this company.
        $partyId = $attributes['party_id'] ?? null;
        if ($partyId === null) {
            throw ValidationException::withMessages([
                'party_id' => 'A partner (party_id) is required for a partner-paid purchase.',
            ]);
        }

        // Validate partner_liability_account_id before building the payload.
        $partnerLiabilityAccountId = $this->resolveAccountId(
            $company,
            isset($attributes['partner_liability_account_id']) ? (int) $attributes['partner_liability_account_id'] : null,
            ['liability'],
            'partner_liability_account_id',
            'Due-to-partner liability account',
        );

        // Partner-paid: no company cash account is credited.
        // financial_account_id must be absent; enforce that explicitly.
        if (! empty($attributes['financial_account_id'])) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'A partner-paid purchase must not reference a company cash or bank account.',
            ]);
        }

        $payload = array_merge([
            'company_id' => $company->getKey(),
            'payable_type' => Purchase::class,
            'payable_id' => $purchase->getKey(),
            'direction' => 'outbound',
            'status' => 'posted',
            'paid_at' => now(),
            'currency' => strtoupper((string) ($purchase->currency ?? $company->base_currency)),
            'base_currency' => strtoupper((string) ($purchase->base_currency ?? $company->base_currency)),
            'exchange_rate' => isset($attributes['exchange_rate'])
                ? $attributes['exchange_rate']
                : $this->resolveExchangeRate(
                    $purchase->currency ?? $company->base_currency,
                    $purchase->base_currency ?? $company->base_currency,
                    $company,
                ),
            'amount' => '0',
            // applied_amount intentionally absent: preparePaymentAttributes
            // derives it from `amount` when the caller does not supply it.
            // Deliberately no financial_account_id — partner funds, not company cash.
        ], $attributes);

        // Remove partner_liability_account_id from the payment payload — it is
        // not a payment column; we only use it for the journal.
        unset($payload['partner_liability_account_id']);

        $payload = $this->preparePostedPaymentPayload(
            $payload,
            $attributes,
            $company,
            Purchase::class,
            $purchase->getKey(),
            'outbound',
        );

        return DB::transaction(function () use ($purchase, $company, $payload, $attributes, $partnerLiabilityAccountId): Payment {
            // Refresh company to pick up any newly added forex account columns
            $company = $company->fresh();

            // Record the payment row (no financial_account_id → assertCurrencyMatchesAccount skips).
            $payment = $this->persistPostedPayment($payload, $company);

            // Resolve the supplier payable account to debit.
            $payableAccountId = $this->resolveAccountId(
                $company,
                isset($attributes['payable_account_id']) ? (int) $attributes['payable_account_id'] : null,
                ['liability'],
                'payable_account_id',
                'Supplier payable account',
            );

            // Resolve forex accounts (required for cross-currency payments).
            $exchangeGainAccountId = $this->resolveForexAccountId(
                $company,
                isset($attributes['exchange_gain_account_id']) ? (int) $attributes['exchange_gain_account_id'] : null,
                $company->exchange_gain_account_id ? (int) $company->exchange_gain_account_id : null,
                ['income'],
                'exchange_gain_account_id',
                'Exchange gain account',
            );

            $exchangeLossAccountId = $this->resolveForexAccountId(
                $company,
                isset($attributes['exchange_loss_account_id']) ? (int) $attributes['exchange_loss_account_id'] : null,
                $company->exchange_loss_account_id ? (int) $company->exchange_loss_account_id : null,
                ['expense'],
                'exchange_loss_account_id',
                'Exchange loss account',
            );

            $this->postPaymentJournal(
                $payment,
                $purchase,
                $company,
                debitAccountId: $payableAccountId,
                creditAccountId: $partnerLiabilityAccountId,
                description: 'Partner-paid purchase '.$payment->payment_number.' for '.$purchase->purchase_number,
                exchangeGainAccountId: $exchangeGainAccountId,
                exchangeLossAccountId: $exchangeLossAccountId,
            );

            return $payment->fresh();
        });
    }

    /**
     * Record the company reimbursing a partner for amounts they paid on its behalf.
     *
     * Accounting effect:
     *   Dr  Due-to-Partner account   (liability — reduces what the company owes the partner)
     *   Cr  Cash / Bank account      (asset    — company cash goes out)
     *
     * This is NOT a payment against a purchase document; it is a payment against
     * the partner liability.  The party_id identifies the partner.
     *
     * Required attributes:
     *   amount                       — reimbursement amount
     *   currency                     — reimbursement currency
     *   party_id                     — the partner being reimbursed
     *   partner_liability_account_id — liability account to debit (due to partner)
     *   financial_account_id         — asset account to credit (cash/bank)
     */
    public function recordPartnerReimbursement(Company $company, array $attributes = []): Payment
    {
        // Validate the partner liability account.
        $partnerLiabilityAccountId = $this->resolveAccountId(
            $company,
            isset($attributes['partner_liability_account_id']) ? (int) $attributes['partner_liability_account_id'] : null,
            ['liability'],
            'partner_liability_account_id',
            'Due-to-partner liability account',
        );

        $cashAccountId = $attributes['financial_account_id'] ?? null;
        if ($cashAccountId === null) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'A cash or bank account (financial_account_id) is required for partner reimbursement.',
            ]);
        }

        $partyId = $attributes['party_id'] ?? null;
        if ($partyId === null) {
            throw ValidationException::withMessages([
                'party_id' => 'A partner (party_id) is required for reimbursement.',
            ]);
        }

        // Reimbursement is not linked to a specific purchase document.
        $payload = array_merge([
            'company_id' => $company->getKey(),
            'direction' => 'outbound',
            'status' => 'posted',
            'paid_at' => now(),
            'currency' => strtoupper((string) ($company->base_currency ?? 'SYP')),
            'base_currency' => strtoupper((string) ($company->base_currency ?? 'SYP')),
            'exchange_rate' => '1',
            'amount' => '0',
            // applied_amount intentionally absent: preparePaymentAttributes
            // derives it from `amount` when the caller does not supply it.
        ], $attributes);

        // Remove the non-payment-column key.
        unset($payload['partner_liability_account_id']);

        $payload = $this->preparePostedPaymentPayload(
            $payload,
            $attributes,
            $company,
            null,
            null,
            'outbound',
        );

        return DB::transaction(function () use ($company, $payload, $partnerLiabilityAccountId, $cashAccountId): Payment {
            $payment = $this->persistPostedPayment($payload, $company);

            // postPaymentJournal requires the payment to have been persisted.
            // We pass a minimal stub for the sourceDocument (no payable link needed).
            $this->postPaymentJournal(
                $payment,
                $company, // source document — the company itself is the context
                $company,
                debitAccountId: $partnerLiabilityAccountId, // Dr Due to Partner
                creditAccountId: (int) $cashAccountId,        // Cr Cash/Bank
                description: 'Partner reimbursement '.$payment->payment_number,
            );

            return $payment->fresh();
        });
    }

    /**
     * Calculate how much the company still owes a partner.
     *
     * Returns the net credit balance of the partner's liability account
     * filtered to journal lines tagged with the given party.
     *
     * Uses journal lines (not payment rows) so the result is always
     * consistent with the posted general ledger.
     *
     * @throws ValidationException when lines for this partner span
     *                             multiple transaction currencies on the same account —
     *                             summing them into a single number would be meaningless.
     *                             Use calculatePartnerLiabilityBalanceByCurrency() for
     *                             multi-currency scenarios.
     */
    public function calculatePartnerLiabilityBalance(
        Party $partner,
        FinancialAccount $partnerLiabilityAccount,
    ): string {
        if ((int) $partner->company_id !== (int) $partnerLiabilityAccount->company_id) {
            throw ValidationException::withMessages([
                'party_id' => 'The partner and liability account must belong to the same company.',
            ]);
        }

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('financial_account_id', $partnerLiabilityAccount->getKey())
            ->where('party_id', $partner->getKey())
            ->whereHas('journal', fn ($query) => $query
                ->withoutGlobalScope(CompanyScope::class)
                ->where('status', 'posted')
                ->where('company_id', $partner->company_id))
            ->get();

        $currencies = $lines->pluck('currency')->filter()->unique();

        if ($currencies->count() > 1) {
            throw ValidationException::withMessages([
                'currency' => 'The partner liability balance spans multiple currencies ('.$currencies->implode(', ').'). Use calculatePartnerLiabilityBalanceByCurrency() for a per-currency breakdown.',
            ]);
        }

        $totalCredit = '0';
        $totalDebit = '0';

        foreach ($lines as $line) {
            $totalCredit = bcadd($totalCredit, $this->normalizeAmount((string) ($line->credit ?? '0'), 'credit'), 10);
            $totalDebit = bcadd($totalDebit, $this->normalizeAmount((string) ($line->debit ?? '0'), 'debit'), 10);
        }

        // Liability account: credit increases the balance, debit decreases it.
        // Outstanding owed to partner = credits − debits.
        $balance = bcsub($totalCredit, $totalDebit, 10);

        return bccomp($balance, '0', 10) < 0 ? '0' : $balance;
    }

    /**
     * Calculate partner liability balances broken down by currency.
     *
     * Returns an associative array: ['USD' => '1000', 'SYP' => '5000000'].
     * Each currency's balance is computed independently — never summed
     * across currencies.
     *
     * @return array<string,string>
     */
    public function calculatePartnerLiabilityBalanceByCurrency(
        Party $partner,
        FinancialAccount $partnerLiabilityAccount,
    ): array {
        if ((int) $partner->company_id !== (int) $partnerLiabilityAccount->company_id) {
            throw ValidationException::withMessages([
                'party_id' => 'The partner and liability account must belong to the same company.',
            ]);
        }

        $lines = JournalLine::withoutGlobalScope(CompanyScope::class)
            ->where('financial_account_id', $partnerLiabilityAccount->getKey())
            ->where('party_id', $partner->getKey())
            ->whereHas('journal', fn ($query) => $query
                ->withoutGlobalScope(CompanyScope::class)
                ->where('status', 'posted')
                ->where('company_id', $partner->company_id))
            ->get();

        $byCurrency = [];

        foreach ($lines as $line) {
            $currency = strtoupper((string) ($line->currency ?? 'SYP'));
            $credit = $this->normalizeAmount((string) ($line->credit ?? '0'), 'credit');
            $debit = $this->normalizeAmount((string) ($line->debit ?? '0'), 'debit');

            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = ['credit' => '0', 'debit' => '0'];
            }

            $byCurrency[$currency]['credit'] = bcadd($byCurrency[$currency]['credit'], $credit, 10);
            $byCurrency[$currency]['debit'] = bcadd($byCurrency[$currency]['debit'], $debit, 10);
        }

        $result = [];

        foreach ($byCurrency as $currency => $totals) {
            $balance = bcsub($totals['credit'], $totals['debit'], 10);
            $result[$currency] = bccomp($balance, '0', 10) < 0 ? '0' : $balance;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Public API — outstanding balance
    // -------------------------------------------------------------------------

    public function calculateOutstandingForPurchase(Purchase $purchase): string
    {
        return $this->calculateOutstandingFor($purchase);
    }

    public function calculateOutstandingForRevenue(Revenue $revenue): string
    {
        return $this->calculateOutstandingFor($revenue);
    }

    public function calculateOutstandingFor(object $payable): string
    {
        $companyId = isset($payable->company_id) ? (int) $payable->company_id : null;

        if ($companyId === null) {
            throw ValidationException::withMessages([
                'company_id' => 'The payable record must have an explicit company_id to calculate outstanding balance.',
            ]);
        }

        $total = $this->normalizeAmount(
            (string) ($payable->total ?? $payable->base_total ?? $payable->amount ?? '0'),
            'total',
        );
        $payableCurrency = strtoupper((string) ($payable->currency ?? $payable->base_currency ?? 'SYP'));

        // Bypass CompanyScope (fails closed with no session) and apply explicit
        // company_id + payable constraints so tenant isolation is still enforced.
        $payments = Payment::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->whereNull('reversal_of_payment_id')
            ->whereNull('reversed_by_payment_id')
            ->has(
                'accountingJournals',
                '=',
                1,
                'and',
                fn ($query) => $query->withoutGlobalScope(CompanyScope::class),
            )
            ->whereHas('accountingJournals', fn ($query) => $query
                ->withoutGlobalScope(CompanyScope::class)
                ->where('status', 'posted')
                ->whereColumn('accounting_journals.company_id', 'payments.company_id')
                ->whereNull('reversed_by_journal_id'))
            ->get();

        $paid = '0';

        foreach ($payments as $payment) {
            $paymentAmount = $this->normalizeAmount(
                (string) ($payment->applied_amount ?? $payment->amount ?? '0'),
                'amount',
            );
            $amountCurrency = strtoupper((string) ($payment->applied_currency ?? $payment->currency ?? $payableCurrency));

            if ($amountCurrency !== $payableCurrency) {
                $company = $payable->company ?? Company::find($companyId);
                $rate = $this->resolveConversionRate($amountCurrency, $payableCurrency, $payment, $company);

                // Rate is base-per-foreign (e.g. 12000 SYP per 1 USD).
                // $paymentAmount is in the payment currency (e.g. SYP).
                // $payableCurrency is the purchase currency (e.g. USD).
                // To convert payment-currency → payable-currency we divide by the rate:
                //   USD = SYP ÷ (SYP-per-USD)
                $paymentAmount = bcdiv($paymentAmount, $rate, 10);
            }

            $paid = bcadd($paid, $paymentAmount, 10);
        }

        $outstanding = bcsub($total, $paid, 10);

        if (bccomp($outstanding, '0', 10) < 0) {
            return '0';
        }

        return $outstanding;
    }

    // -------------------------------------------------------------------------
    // Public API — payment reversal
    // -------------------------------------------------------------------------

    /**
     * Reverse a posted payment and its corresponding accounting journal.
     *
     * Creates an equal-and-opposite reversal payment and reversal journal.
     * The original payment transitions to 'reversed' status and is linked
     * to the new reversal payment. The original journal is linked to the
     * new reversal journal via reversed_by_journal_id.
     *
     * Business rules enforced:
     * - Only posted payments can be reversed (draft payments should be deleted).
     * - A payment can only be reversed once (unique DB constraint + service guard).
     * - Company isolation is strictly enforced.
     * - No live exchange-rate lookups — historical amounts are copied exactly.
     * - No modifications to the original payment's financial fields.
     */
    public function reversePayment(
        Payment $payment,
        array $attributes = [],
        ?Company $company = null,
    ): Payment {
        // 1. Resolve company — explicit arg or active context; never silently skip isolation.
        $company ??= $this->companyContext->requireCurrent();

        // 2. Verify payment belongs to the resolved company.
        if ((int) $payment->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'payment' => 'The payment does not belong to the active company.',
            ]);
        }

        // 2a. Guard: a reversal payment (reversal_of_payment_id is set) cannot itself be reversed.
        //     Reversing a reversal would create a chain of reversals rather than the intended
        //     equal-and-opposite cancellation, and would break the one-original-one-reversal invariant.
        if ($payment->reversal_of_payment_id !== null) {
            throw ValidationException::withMessages([
                'payment' => 'A reversal payment cannot itself be reversed.',
            ]);
        }

        // 3. Only posted payments can be reversed.
        if ($payment->status !== 'posted') {
            throw ValidationException::withMessages([
                'payment' => 'Only posted payments can be reversed. Draft payments should be deleted instead.',
            ]);
        }

        // 4. Reject already-reversed payments.
        //    Application-level guard for Finding 1: even when the DB engine cannot
        //    enforce RESTRICT on the reversed_by_payment_id FK (e.g. SQLite uses
        //    SET NULL), we verify here that the reversal payment still exists. If the
        //    reversal were deleted the FK would NULL out reversed_by_payment_id and
        //    the payment would silently appear un-reversed. By re-checking this inside
        //    the service (and again inside the transaction below) we catch that case.
        if ($payment->reversed_by_payment_id !== null) {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been reversed.',
            ]);
        }

        // Secondary guard: if the DB silently NULLed reversed_by_payment_id via SET NULL
        // after the reversal payment was deleted, the status field will still be 'reversed',
        // making the inconsistency detectable here.
        if ($payment->status === 'reversed') {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been reversed.',
            ]);
        }

        // 5. Locate the single posted journal for this payment.
        $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->getKey())
            ->get();

        if ($journals->isEmpty() || $journals->first()->status !== 'posted') {
            throw ValidationException::withMessages([
                'payment' => 'No posted accounting journal was found for this payment.',
            ]);
        }

        if ($journals->count() > 1) {
            throw ValidationException::withMessages([
                'payment' => 'Multiple posted journals exist for this payment; cannot determine which to reverse.',
            ]);
        }

        $originalJournal = $journals->first();

        // 6. Reject if the journal has already been reversed.
        if ($originalJournal->reversed_by_journal_id !== null) {
            throw ValidationException::withMessages([
                'journal' => 'The accounting journal for this payment has already been reversed.',
            ]);
        }

        return DB::transaction(function () use ($payment, $company, $attributes, $originalJournal): Payment {
            // 7a. Lock the original payment row to prevent concurrent reversals.
            //     lockForUpdate() is a no-op on SQLite but serialises on MySQL/PostgreSQL.
            $lockedPayment = Payment::withoutGlobalScope(CompanyScope::class)
                ->lockForUpdate()
                ->find($payment->getKey());

            // 7b. Re-read status and reversed_by_payment_id inside the transaction (TOCTOU guard).
            if ($lockedPayment === null || $lockedPayment->status !== 'posted') {
                throw ValidationException::withMessages([
                    'payment' => 'Only posted payments can be reversed. Draft payments should be deleted instead.',
                ]);
            }

            if ($lockedPayment->reversed_by_payment_id !== null) {
                throw ValidationException::withMessages([
                    'payment' => 'This payment has already been reversed.',
                ]);
            }

            // TOCTOU guard: reject if this is a reversal payment (reversal_of_payment_id is set).
            // This re-checks inside the transaction using the authoritative locked row so a
            // race between the pre-transaction check and the lock cannot be exploited.
            if ($lockedPayment->reversal_of_payment_id !== null) {
                throw ValidationException::withMessages([
                    'payment' => 'A reversal payment cannot itself be reversed.',
                ]);
            }

            // Application-level guard for Finding 1: also block re-reversal when the DB
            // engine silently NULLed reversed_by_payment_id (SQLite SET NULL behaviour).
            // The status='reversed' field remains intact even after that NULL, so it is
            // the reliable second guard on SQLite.
            if ($lockedPayment->status === 'reversed') {
                throw ValidationException::withMessages([
                    'payment' => 'This payment has already been reversed.',
                ]);
            }

            // 7c. Create the reversal payment.
            //     Copy all monetary / identity fields but omit:
            //       - payable_type / payable_id   → reversal is not a new payment against a document.
            //       - reversed_by_payment_id       → that field belongs to the original, not the reversal.
            //     Set reversal_of_payment_id to link back to the original.
            $reversalPayment = Payment::create([
                'company_id' => $payment->company_id,
                'party_id' => $payment->party_id,
                'financial_account_id' => $payment->financial_account_id,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'exchange_rate' => $payment->exchange_rate,
                'base_currency' => $payment->base_currency,
                'base_amount' => $payment->base_amount,
                'direction' => $payment->direction,
                'paid_at' => $attributes['paid_at'] ?? now(),
                'applied_amount' => $payment->applied_amount,
                'applied_currency' => $payment->applied_currency,
                'applied_exchange_rate' => $payment->applied_exchange_rate,
                'applied_base_amount' => $payment->applied_base_amount,
                'status' => 'posted',
                'posted_at' => now(),
                'reversal_of_payment_id' => $payment->getKey(),
                'payment_number' => $this->generatePaymentNumber($company),
                // payable_type and payable_id are intentionally omitted:
                // the reversal is not a new payment against the original document.
            ]);

            // 7d. Reverse the accounting journal.
            //     reverseJournal() wraps itself in a DB::transaction — nesting is safe in Laravel.
            //     The reversal journal initially inherits sourceable from the original journal;
            //     we re-point it to the reversal payment in step 7e.
            $reversalJournal = $this->accounting->reverseJournal($originalJournal, [
                'description' => $attributes['description'] ?? 'Reversal of payment #'.$payment->getKey(),
                'entry_date' => $attributes['entry_date'] ?? now()->toDateString(),
            ]);

            // 7e. Re-point the reversal journal's sourceable to the reversal payment.
            //     We cannot use Eloquent here: AccountingJournal's updating hook only allows
            //     ['status', 'reversed_by_journal_id'] on a posted journal, and
            //     sourceable_type/sourceable_id are not in that whitelist.
            //     A direct DB update bypasses model events intentionally — the data is correct
            //     and the immutability hook exists to prevent accidental edits, not this
            //     deliberate, audited re-association performed immediately after creation inside
            //     this transaction.
            $updated = DB::table('accounting_journals')
                ->where('id', $reversalJournal->getKey())
                ->where('sourceable_type', Payment::class)      // defensive: confirm prior value
                ->where('sourceable_id', $payment->getKey())    // confirm journal still points to original payment
                ->update([
                    'sourceable_type' => Payment::class,
                    'sourceable_id' => $reversalPayment->getKey(),
                ]);

            if ($updated !== 1) {
                throw new \RuntimeException(
                    'Failed to re-associate reversal journal sourceable: expected 1 row updated, got '.$updated
                );
            }

            // 7f. Transition original payment to 'reversed' and link it to the reversal.
            //     This succeeds because the Payment updating hook whitelists
            //     ['status', 'reversed_by_payment_id'] when transitioning from 'posted',
            //     and 'reversed' is the only allowed target status.
            $lockedPayment->status = 'reversed';
            $lockedPayment->reversed_by_payment_id = $reversalPayment->getKey();
            $lockedPayment->save();

            $postedReversalJournal = $this->assertPostedPaymentHasExactlyOnePostedJournal($reversalPayment);
            $originalPayment = Payment::withoutGlobalScope(CompanyScope::class)->find($lockedPayment->getKey());
            $originalJournal = AccountingJournal::withoutGlobalScope(CompanyScope::class)->find($originalJournal->getKey());

            if (
                $originalPayment?->status !== 'reversed'
                || (int) $originalPayment->reversed_by_payment_id !== (int) $reversalPayment->getKey()
                || (int) $originalJournal?->reversed_by_journal_id !== (int) $postedReversalJournal->getKey()
                || (int) $postedReversalJournal->reversal_of_journal_id !== (int) $originalJournal->getKey()
                || (int) $reversalPayment->reversal_of_payment_id !== (int) $originalPayment->getKey()
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'The payment and journal reversal links are inconsistent.',
                ]);
            }

            // 7g. Return the fresh reversal payment.
            return $reversalPayment->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Private — journal creation shared by both payment types
    // -------------------------------------------------------------------------

    /**
     * Create and post one balanced journal entry linked to a Payment.
     *
     * The journal uses the payment's stored amount/currency/exchange_rate so
     * historical amounts are never recalculated or overwritten.
     *
     * Duplicate-posting is prevented:  if this payment already has a posted
     * journal sourced from it, we throw before creating another.
     *
     * @param  Payment  $payment  Already-persisted posted payment.
     * @param  Purchase|Revenue|object  $sourceDocument  The original payable document.
     */
    private function postPaymentJournal(
        Payment $payment,
        object $sourceDocument,
        Company $company,
        int $debitAccountId,
        int $creditAccountId,
        string $description,
        ?int $exchangeGainAccountId = null,
        ?int $exchangeLossAccountId = null,
    ): AccountingJournal {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment journals must be posted inside a payment workflow transaction.');
        }

        $lockedPayment = Payment::withoutGlobalScope(CompanyScope::class)
            ->lockForUpdate()
            ->find($payment->getKey());

        if ($lockedPayment === null || (int) $lockedPayment->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'payment' => 'The payment could not be found for the active company.',
            ]);
        }

        if ($lockedPayment->status !== 'posted' || $lockedPayment->posted_at === null) {
            throw ValidationException::withMessages([
                'payment' => 'Only posted payments can have an accounting journal posted.',
            ]);
        }

        // A payment may have only one journal, regardless of that journal's status.
        $existingCount = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->getKey())
            ->count();

        if ($existingCount > 0) {
            throw ValidationException::withMessages([
                'payment' => 'An accounting journal already exists for this payment.',
            ]);
        }

        // Use the payment's stored amounts — never recalculate.
        $amount = $this->normalizeAmount((string) ($payment->amount ?? '0'), 'amount');
        $currency = strtoupper((string) ($payment->currency ?? $company->base_currency));
        $baseCurrency = strtoupper((string) ($payment->base_currency ?? $company->base_currency));
        $exchangeRate = $this->normalizeAmount((string) ($payment->exchange_rate ?? '1'), 'exchange_rate');
        $baseAmount = $this->normalizeAmount((string) ($payment->base_amount ?? $amount), 'base_amount');

        $appliedAmount = $this->normalizeAmount((string) ($payment->applied_amount ?? $amount), 'applied_amount');
        $appliedCurrency = strtoupper((string) ($payment->applied_currency ?? $currency));
        $appliedRate = $this->normalizeAmount((string) ($payment->applied_exchange_rate ?? $exchangeRate), 'applied_exchange_rate');

        $entryDate = $payment->paid_at
            ? $payment->paid_at->toDateString()
            : now()->toDateString();

        $journal = $this->accounting->createDraftJournal([
            'company_id' => $company->getKey(),
            'project_id' => $payment->project_id,
            'created_by' => $payment->created_by,
            'sourceable_type' => Payment::class,
            'sourceable_id' => $payment->getKey(),
            'journal_number' => 'PAY-'.strtoupper($payment->payment_number),
            'entry_date' => $entryDate,
            'description' => $description,
        ], $company);

        $isCrossCurrency = $appliedCurrency !== $currency;

        if ($isCrossCurrency) {
            // Validate that forex accounts are available.
            if ($exchangeGainAccountId === null || $exchangeLossAccountId === null) {
                throw ValidationException::withMessages([
                    'exchange_gain_account_id' => 'Exchange gain and loss accounts are required for cross-currency payments.',
                ]);
            }

            // Carrying value of the liability in base currency = applied_amount × applied_exchange_rate.
            // applied_exchange_rate is the original purchase rate (R₀), i.e. the rate at which
            // the liability was first recorded.
            $carryingBase = bcmul($appliedAmount, $appliedRate, 10);

            // Actual cash paid in base currency = payment.base_amount (e.g. actual SYP disbursed).
            $cashBase = $baseAmount;

            // FX difference = cash paid − carrying value.
            // Positive diff → exchange loss (paid MORE SYP than liability was worth).
            // Negative diff → exchange gain (paid FEWER SYP than liability was worth).
            $fxDiff = bcsub($cashBase, $carryingBase, 10);

            // Line 1: Dr Supplier Payable in the payable's native currency (e.g. USD) at carrying rate.
            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $debitAccountId,
                'party_id' => $payment->party_id,
                'project_id' => $payment->project_id,
                'debit' => $appliedAmount,    // e.g. 1000 USD
                'currency' => $appliedCurrency,  // e.g. 'USD'
                'exchange_rate' => $appliedRate,       // e.g. 12000 (original purchase rate R₀)
                'base_currency' => $baseCurrency,
                'base_debit' => $carryingBase,     // e.g. 12,000,000 SYP
                'description' => $description,
            ]);

            // Line 2: Cr Cash/Bank in the payment currency (e.g. SYP).
            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $creditAccountId,
                'party_id' => $payment->party_id,
                'project_id' => $payment->project_id,
                'credit' => $amount,           // e.g. 12,500,000 SYP
                'currency' => $currency,         // e.g. 'SYP'
                'exchange_rate' => '1',               // SYP line — rate is 1
                'base_currency' => $baseCurrency,
                'base_credit' => $cashBase,         // e.g. 12,500,000 SYP
                'description' => $description,
            ]);

            // Line 3: Exchange Gain or Loss (base currency only).
            if (bccomp($fxDiff, '0', 10) > 0) {
                // Exchange Loss: paid more base currency than the liability carried.
                // Dr Exchange Loss (expense account) by |fxDiff|.
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $exchangeLossAccountId,
                    'party_id' => $payment->party_id,
                    'project_id' => $payment->project_id,
                    'debit' => $fxDiff,
                    'currency' => $baseCurrency,
                    'exchange_rate' => '1',
                    'base_currency' => $baseCurrency,
                    'base_debit' => $fxDiff,
                    'description' => $description,
                ]);
            } elseif (bccomp($fxDiff, '0', 10) < 0) {
                // Exchange Gain: paid fewer base currency units than the liability carried.
                // Cr Exchange Gain (income account) by |fxDiff|.
                $absDiff = bcmul($fxDiff, '-1', 10);
                $this->accounting->addJournalLine($journal, [
                    'financial_account_id' => $exchangeGainAccountId,
                    'party_id' => $payment->party_id,
                    'project_id' => $payment->project_id,
                    'credit' => $absDiff,
                    'currency' => $baseCurrency,
                    'exchange_rate' => '1',
                    'base_currency' => $baseCurrency,
                    'base_credit' => $absDiff,
                    'description' => $description,
                ]);
            }
            // If fxDiff == 0, rates are identical — no forex line needed; two lines balance.

        } else {
            // Same-currency path — existing two-line logic, unchanged.
            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $debitAccountId,
                'party_id' => $payment->party_id,
                'project_id' => $payment->project_id,
                'debit' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'base_currency' => $baseCurrency,
                'base_debit' => $baseAmount,
                'description' => $description,
            ]);

            $this->accounting->addJournalLine($journal, [
                'financial_account_id' => $creditAccountId,
                'party_id' => $payment->party_id,
                'project_id' => $payment->project_id,
                'credit' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'base_currency' => $baseCurrency,
                'base_credit' => $baseAmount,
                'description' => $description,
            ]);
        }

        $postedJournal = $this->accounting->postJournal($journal);
        $this->assertPostedPaymentHasExactlyOnePostedJournal($payment);

        return $postedJournal;
    }

    private function assertPostedPaymentHasExactlyOnePostedJournal(Payment $payment): AccountingJournal
    {
        $journals = AccountingJournal::withoutGlobalScope(CompanyScope::class)
            ->where('sourceable_type', Payment::class)
            ->where('sourceable_id', $payment->getKey())
            ->get();

        if (
            $payment->status !== 'posted'
            || $payment->posted_at === null
            || $journals->count() !== 1
            || (int) $journals->first()->company_id !== (int) $payment->company_id
            || $journals->first()->status !== 'posted'
        ) {
            throw ValidationException::withMessages([
                'payment' => 'A posted payment must have exactly one posted accounting journal.',
            ]);
        }

        return $journals->first();
    }

    // -------------------------------------------------------------------------
    // Private — account resolution (mirrors PurchaseService::resolveAccountId)
    // -------------------------------------------------------------------------

    private function resolveAccountId(
        Company $company,
        ?int $accountId,
        array $allowedTypes,
        string $attribute,
        string $label,
    ): int {
        if ($accountId !== null) {
            $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);

            if ($account === null) {
                throw ValidationException::withMessages([
                    $attribute => 'The selected '.$label.' does not exist.',
                ]);
            }

            if ((int) $account->company_id !== (int) $company->getKey()) {
                throw ValidationException::withMessages([
                    $attribute => 'The selected '.$label.' must belong to the same company.',
                ]);
            }

            if (! in_array(strtolower((string) $account->account_type), $allowedTypes, true)) {
                throw ValidationException::withMessages([
                    $attribute => 'The selected '.$label.' must be of type: '.implode(', ', $allowedTypes).'.',
                ]);
            }

            return $account->getKey();
        }

        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('account_type', $allowedTypes)
            ->orderBy('code')
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $attribute => 'No valid '.$label.' could be found for this company.',
            ]);
        }

        return $account->getKey();
    }

    /**
     * Resolve a forex account ID (gain or loss).
     *
     * Priority: (1) caller-supplied ID, (2) company-level default.
     * Returns null when neither is available — the caller decides whether to require it.
     *
     * @param  string[]  $allowedTypes  e.g. ['income'] or ['expense']
     */
    private function resolveForexAccountId(
        Company $company,
        ?int $callerSupplied,
        ?int $companyDefault,
        array $allowedTypes,
        string $attribute,
        string $label,
    ): ?int {
        $accountId = $callerSupplied ?? $companyDefault;

        if ($accountId === null) {
            return null;
        }

        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                $attribute => 'The selected '.$label.' does not exist.',
            ]);
        }

        if ((int) $account->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                $attribute => 'The selected '.$label.' must belong to the same company.',
            ]);
        }

        if (! in_array(strtolower((string) $account->account_type), $allowedTypes, true)) {
            throw ValidationException::withMessages([
                $attribute => 'The selected '.$label.' must be of type: '.implode(', ', $allowedTypes).'.',
            ]);
        }

        return $account->getKey();
    }

    // -------------------------------------------------------------------------
    // Private — validation helpers
    // -------------------------------------------------------------------------

    private function assertDraftPaymentAttributes(array $attributes): void
    {
        if (array_key_exists('status', $attributes) && $attributes['status'] !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Draft payment creation only accepts status=draft.',
            ]);
        }

        $this->assertNoServiceControlledPaymentAttributes($attributes);
    }

    /**
     * @param  class-string|null  $payableType
     */
    private function preparePostedPaymentPayload(
        array $payload,
        array $attributes,
        Company $company,
        ?string $payableType,
        ?int $payableId,
        string $direction,
    ): array {
        $expectedFields = [
            'company_id' => $company->getKey(),
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'direction' => $direction,
        ];

        foreach ($expectedFields as $field => $expectedValue) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $providedValue = $attributes[$field];
            if ((string) $providedValue !== (string) $expectedValue) {
                throw ValidationException::withMessages([
                    $field => 'This field is controlled by the payment workflow.',
                ]);
            }
        }

        if (array_key_exists('status', $attributes) && $attributes['status'] !== 'posted') {
            throw ValidationException::withMessages([
                'status' => 'A posted payment workflow only accepts status=posted.',
            ]);
        }

        $this->assertNoServiceControlledPaymentAttributes($attributes);

        return [
            ...$payload,
            ...$expectedFields,
            'status' => 'posted',
            'posted_at' => now(),
        ];
    }

    private function assertNoServiceControlledPaymentAttributes(array $attributes): void
    {
        foreach (self::SERVICE_CONTROLLED_PAYMENT_ATTRIBUTES as $field) {
            if (array_key_exists($field, $attributes)) {
                throw ValidationException::withMessages([
                    $field => 'This field is controlled by the payment service.',
                ]);
            }
        }
    }

    private function persistDraftPayment(array $attributes, Company $company): Payment
    {
        $payload = $this->preparePaymentAttributes($attributes, $company);
        $payload['status'] = 'draft';
        $payload['posted_at'] = null;

        return Payment::create($payload);
    }

    private function persistPostedPayment(array $attributes, Company $company): Payment
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Posted payments must be created inside a payment workflow transaction.');
        }

        $payload = $this->preparePaymentAttributes($attributes, $company);
        $payload['status'] = 'posted';
        $payload['posted_at'] = now();

        $payableType = $payload['payable_type'] ?? null;
        $payableId = $payload['payable_id'] ?? null;

        if ($payableType !== null && $payableId !== null) {
            $payable = $this->resolvePayableWithLock($payableType, (int) $payableId, $company);
            $this->guardPaymentAgainstOverpayment($payable, $payload);
        }

        $this->assertCurrencyMatchesAccount($payload, $company);
        $this->ensurePaymentIsPositive($payload);

        $payment = Payment::create($payload);

        return $payment->refresh();
    }

    private function preparePaymentAttributes(array $attributes, Company $company): array
    {
        $direction = strtoupper((string) ($attributes['direction'] ?? 'outbound'));
        $currency = strtoupper((string) ($attributes['currency'] ?? $company->base_currency));
        $baseCurrency = strtoupper((string) ($attributes['base_currency'] ?? $company->base_currency));
        $amount = $this->normalizeAmount((string) ($attributes['amount'] ?? '0'), 'amount');
        $exchangeRate = $this->normalizeAmount((string) ($attributes['exchange_rate'] ?? ($currency !== $baseCurrency ? $this->resolveExchangeRate($currency, $baseCurrency, $company) : '1')), 'exchange_rate');
        $baseAmount = $this->normalizeAmount((string) ($attributes['base_amount'] ?? $this->convertAmount($amount, $exchangeRate, $baseCurrency, $currency)), 'base_amount');

        // Resolve payable currency when a purchase/revenue document is linked.
        $payableNativeCurrency = null;
        $payableOriginalRate = null;
        if (! empty($attributes['payable_type']) && ! empty($attributes['payable_id'])) {
            $payableClass = $attributes['payable_type'];
            if (class_exists($payableClass)) {
                $payableRecord = $payableClass::withoutGlobalScope(CompanyScope::class)
                    ->find($attributes['payable_id']);
                if ($payableRecord !== null) {
                    $payableNativeCurrency = strtoupper((string) ($payableRecord->currency ?? $payableRecord->base_currency ?? null));

                    // Capture the payable's own exchange_rate (R₀) for use as the default
                    // applied_exchange_rate in cross-currency settlements.
                    if (isset($payableRecord->exchange_rate)) {
                        $normalizedPayableRate = $this->normalizeAmount((string) $payableRecord->exchange_rate, 'applied_exchange_rate');
                        if (bccomp($normalizedPayableRate, '0', 10) > 0) {
                            $payableOriginalRate = $normalizedPayableRate;
                        }
                    }
                }
            }
        }

        $appliedCurrency = strtoupper((string) ($attributes['applied_currency'] ?? $currency));

        // When the payment currency differs from the payable's native currency and the
        // caller has not explicitly set applied_currency, normalise it to the payable's
        // currency so that calculateOutstandingFor never needs to cross-convert.
        if (
            $payableNativeCurrency !== null
            && $payableNativeCurrency !== $currency
            && ! isset($attributes['applied_currency'])
        ) {
            $appliedCurrency = $payableNativeCurrency;
        }

        // applied_exchange_rate is the rate that converts the payment currency to the
        // payable currency — default to the payable's original rate (R₀) when the
        // currencies differ, so the carrying value uses the historical purchase rate.
        //
        // Fallback resolution order:
        //   1. Explicit applied_exchange_rate from caller (always trusted).
        //   2. Payable's own rate (R₀) when the applied currency differs from payment currency.
        //   3. Same-currency path (appliedCurrency === currency) → use payment's exchange_rate.
        //   4. Cross-currency with no explicit or payable-derived rate → live DB lookup
        //      (throws on miss rather than silently accepting exchange_rate as a sentinel).
        if (isset($attributes['applied_exchange_rate'])) {
            // Caller provided the rate explicitly — always use it (P1 guard fires later if ≤ 0).
            $rawAppliedRate = (string) $attributes['applied_exchange_rate'];
        } elseif (
            $payableOriginalRate !== null
            && $payableNativeCurrency !== null
            && $payableNativeCurrency !== $currency
        ) {
            // Use the payable's historical rate as the default applied rate.
            $rawAppliedRate = $payableOriginalRate;
        } elseif ($appliedCurrency !== $currency) {
            // Cross-currency applied amount with no rate supplied — require a live DB rate.
            // Never silently fall back to exchange_rate (which is often '1' for same-base
            // payments) as this produces a financially incorrect applied_amount.
            $rawAppliedRate = $this->resolveExchangeRate($appliedCurrency, $currency, $company);
        } else {
            // Same currency: reuse the payment's own exchange_rate.
            $rawAppliedRate = $exchangeRate;
        }

        $appliedExchangeRate = $this->normalizeAmount((string) $rawAppliedRate, 'applied_exchange_rate');

        // When applied_currency ≠ payment currency and no explicit applied_amount was
        // supplied, derive it: payable-currency-amount = payment-amount ÷ rate.
        if (! isset($attributes['applied_amount']) && $appliedCurrency !== $currency) {
            $derivedApplied = bccomp($appliedExchangeRate, '0', 10) > 0
                ? bcdiv($amount, $appliedExchangeRate, 10)
                : '0';
            $appliedAmount = $this->normalizeAmount($derivedApplied, 'applied_amount');
        } else {
            $appliedAmount = $this->normalizeAmount((string) ($attributes['applied_amount'] ?? $amount), 'applied_amount');
        }

        $appliedBaseAmount = $this->normalizeAmount(
            (string) ($attributes['applied_base_amount'] ?? $this->convertAmount($appliedAmount, $appliedExchangeRate, $appliedCurrency, $baseCurrency)),
            'applied_base_amount',
        );

        return [
            'company_id' => $company->getKey(),
            'party_id' => $attributes['party_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'financial_account_id' => $attributes['financial_account_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? Auth::id(),
            'payable_type' => $attributes['payable_type'] ?? null,
            'payable_id' => $attributes['payable_id'] ?? null,
            'payment_number' => $attributes['payment_number'] ?? $this->generatePaymentNumber($company),
            'direction' => $direction,
            'method' => $attributes['method'] ?? null,
            'paid_at' => $attributes['paid_at'] ?? now(),
            'amount' => $amount,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'base_currency' => $baseCurrency,
            'base_amount' => $baseAmount,
            'applied_amount' => $appliedAmount,
            'applied_currency' => $appliedCurrency,
            'applied_exchange_rate' => $appliedExchangeRate,
            'applied_base_amount' => $appliedBaseAmount,
            'reference' => $attributes['reference'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'status' => $attributes['status'] ?? 'posted',
            'posted_at' => $attributes['posted_at'] ?? now(),
        ];
    }

    private function resolvePayableWithLock(string $payableType, int $payableId, Company $company): Purchase|Revenue
    {
        $model = $this->resolveMorphedClass($payableType);

        if (! is_a($model, Purchase::class, true) && ! is_a($model, Revenue::class, true)) {
            throw ValidationException::withMessages([
                'payable_type' => 'Only purchases and revenues can be settled through a payment.',
            ]);
        }

        // lockForUpdate() serialises concurrent payment attempts on MySQL/PostgreSQL.
        // On SQLite it is silently ignored; SQLite's own exclusive-write-lock on the
        // first INSERT inside this transaction provides the equivalent guarantee.
        $record = $model::withoutGlobalScope(CompanyScope::class)
            ->lockForUpdate()
            ->whereKey($payableId)
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'payable_id' => 'The selected payable record does not exist.',
            ]);
        }

        if ((int) $record->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'payable_id' => 'The selected payable record must belong to the active company.',
            ]);
        }

        return $record;
    }

    private function resolveMorphedClass(string $type): string
    {
        $resolved = class_exists($type) ? $type : null;

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'payable_type' => 'The selected payable type is invalid.',
            ]);
        }

        return $resolved;
    }

    private function guardPaymentAgainstOverpayment(object $payable, array $payload): void
    {
        $outstanding = $this->calculateOutstandingFor($payable);
        $payableCurrency = strtoupper((string) ($payable->currency ?? $payable->base_currency ?? 'SYP'));
        $appliedAmount = $this->normalizeAmount((string) ($payload['applied_amount'] ?? $payload['amount'] ?? '0'), 'applied_amount');
        $appliedCurrency = strtoupper((string) ($payload['applied_currency'] ?? $payload['currency'] ?? $payableCurrency));

        if ($appliedCurrency !== $payableCurrency) {
            $companyId = isset($payable->company_id) ? (int) $payable->company_id : null;
            $company = ($companyId !== null) ? ($payable->company ?? Company::find($companyId)) : null;

            if ($company === null) {
                throw ValidationException::withMessages([
                    'exchange_rate' => 'A valid exchange rate is required to validate this cross-currency payment.',
                ]);
            }

            $rate = $this->resolveConversionRate($appliedCurrency, $payableCurrency, $payload, $company);

            // Convert: appliedAmountInPayableCurrency = appliedAmount ÷ rate.
            //
            // $appliedCurrency is the payment currency (e.g. SYP).
            // $payableCurrency  is the purchase currency (e.g. USD).
            // $rate             is SYP-per-USD (base-per-foreign).
            //
            // To go from SYP → USD we divide by the rate — identical to the
            // direction used in calculateOutstandingFor().  Multiplying would
            // produce an absurdly large value (SYP × 12,000) and must never
            // be used here.
            $appliedAmount = bcdiv($appliedAmount, $rate, 10);
        }

        if (bccomp($appliedAmount, $outstanding, 10) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'The payment exceeds the outstanding balance for this document.',
            ]);
        }
    }

    private function assertCurrencyMatchesAccount(array $payload, Company $company): void
    {
        $accountId = $payload['financial_account_id'] ?? null;

        if ($accountId === null) {
            return;
        }

        $account = FinancialAccount::withoutGlobalScope(CompanyScope::class)->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The selected financial account does not exist.',
            ]);
        }

        if ((int) $account->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The selected financial account must belong to the same company.',
            ]);
        }

        if (! in_array(strtolower((string) $account->account_type), ['asset'], true)) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'The selected financial account must be an asset (cash or bank) account.',
            ]);
        }

        if ($account->currency !== null && strtoupper((string) $account->currency) !== strtoupper((string) $payload['currency'])) {
            throw ValidationException::withMessages([
                'currency' => 'The payment currency must match the selected financial account currency.',
            ]);
        }
    }

    private function ensurePaymentIsPositive(array $payload): void
    {
        if (bccomp($payload['amount'] ?? '0', '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'The payment amount must be greater than zero.',
            ]);
        }

        if (bccomp($payload['exchange_rate'] ?? '0', '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'The exchange rate must be greater than zero.',
            ]);
        }

        // P1: For cross-currency payments the applied_exchange_rate must be present and positive.
        $appliedCurrency = strtoupper((string) ($payload['applied_currency'] ?? $payload['currency'] ?? ''));
        $paymentCurrency = strtoupper((string) ($payload['currency'] ?? ''));
        if ($appliedCurrency !== $paymentCurrency && $appliedCurrency !== '' && $paymentCurrency !== '') {
            $appliedRate = $this->normalizeAmount((string) ($payload['applied_exchange_rate'] ?? '0'), 'applied_exchange_rate');
            if (bccomp($appliedRate, '0', 10) <= 0) {
                throw ValidationException::withMessages([
                    'applied_exchange_rate' => 'A positive applied_exchange_rate is required for cross-currency payments.',
                ]);
            }
        }

        // P2: When a payable is linked, applied_amount must also be positive.
        if (! empty($payload['payable_type']) && ! empty($payload['payable_id'])) {
            $appliedAmount = $this->normalizeAmount((string) ($payload['applied_amount'] ?? '0'), 'applied_amount');
            if (bccomp($appliedAmount, '0', 10) <= 0) {
                throw ValidationException::withMessages([
                    'applied_amount' => 'The applied amount must be greater than zero.',
                ]);
            }
        }
    }

    /**
     * P3: Shared rate-fallback chain used by both guardPaymentAgainstOverpayment()
     * and calculateOutstandingFor().
     *
     * Resolution order:
     *   1. Same currency → return '1' immediately.
     *   2. applied_exchange_rate from payload/payment (> 0 → return it).
     *   3. exchange_rate from payload/payment (> 0 → return it).
     *   4. Live lookup via resolveExchangeRate() (> 0 → return it).
     *   5. All failed → throw ValidationException (never silently return '1').
     *
     * Preserves SYP-per-USD convention (base-per-foreign) and company isolation.
     *
     * @param  array|object  $paymentOrPayload  The payment model or the raw payload array.
     */
    private function resolveConversionRate(
        string $paymentCurrency,
        string $payableCurrency,
        array|object $paymentOrPayload,
        Company $company,
    ): string {
        $paymentCurrency = strtoupper($paymentCurrency);
        $payableCurrency = strtoupper($payableCurrency);

        if ($paymentCurrency === $payableCurrency) {
            return '1';
        }

        // Helper to read a field from either an array or an object.
        $get = static function (string $key) use ($paymentOrPayload): mixed {
            if (is_array($paymentOrPayload)) {
                return $paymentOrPayload[$key] ?? null;
            }

            return $paymentOrPayload->{$key} ?? null;
        };

        // 1. Try applied_exchange_rate
        $appliedRate = $get('applied_exchange_rate');
        if ($appliedRate !== null) {
            $normalized = $this->normalizeAmount((string) $appliedRate, 'applied_exchange_rate');
            if (bccomp($normalized, '0', 10) > 0) {
                return $normalized;
            }
        }

        // 2. Try exchange_rate
        $exchangeRate = $get('exchange_rate');
        if ($exchangeRate !== null) {
            $normalized = $this->normalizeAmount((string) $exchangeRate, 'exchange_rate');
            if (bccomp($normalized, '0', 10) > 0) {
                return $normalized;
            }
        }

        // 3. Live lookup — throws if not found, which is the correct behavior
        //    (never silently return '1' for different currencies).
        return $this->resolveExchangeRate($paymentCurrency, $payableCurrency, $company);
    }

    private function resolveExchangeRate(string $fromCurrency, string $toCurrency, Company $company): string
    {
        $fromCurrency = strtoupper((string) $fromCurrency);
        $toCurrency = strtoupper((string) $toCurrency);

        if ($fromCurrency === $toCurrency || $fromCurrency === '' || $toCurrency === '') {
            return '1';
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $company->getKey())
            ->where('currency', $fromCurrency)
            ->where('base_currency', $toCurrency)
            ->orderByDesc('effective_at')
            ->value('rate');

        if ($rate === null) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'No exchange rate is available for '.$fromCurrency.' to '.$toCurrency.'.',
            ]);
        }

        $normalized = $this->normalizeAmount((string) $rate, 'exchange_rate');

        if (bccomp($normalized, '0', 10) <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'The exchange rate for '.$fromCurrency.' to '.$toCurrency.' must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function convertAmount(string $amount, string $exchangeRate, string $fromCurrency, string $toCurrency): string
    {
        $amount = $this->normalizeAmount($amount, 'amount');

        if (strtoupper((string) $fromCurrency) === strtoupper((string) $toCurrency)) {
            return $amount;
        }

        return bcmul($amount, $exchangeRate, 10);
    }

    private function normalizeAmount(string $value, string $field): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '0';
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $field => 'The financial amount must be numeric.',
            ]);
        }

        $value = (string) $value;

        // P2: explicitly reject negative values — the regex /^\d+\.\d+$/ does not
        // match a leading minus sign, but integers like '-100' fall through the
        // regex to the bare `return $value` path and would silently pass through.
        if (bccomp($value, '0', 10) < 0) {
            throw ValidationException::withMessages([
                $field => 'Financial amounts must be zero or positive.',
            ]);
        }

        return preg_match('/^\d+\.\d+$/', $value) === 1
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }

    private function generatePaymentNumber(Company $company): string
    {
        $prefix = strtoupper(substr($company->name ?? 'CMP', 0, 3));

        return $prefix.'-PAY-'.now()->format('YmdHis').'-'.substr(uniqid(), -6);
    }
}
