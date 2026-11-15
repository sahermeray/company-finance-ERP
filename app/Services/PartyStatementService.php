<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\CustomerReturn;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\SupplierReturn;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

class PartyStatementService
{
    /**
     * @return array{entries: array<int, array<string, mixed>>, balances: array<string, string>, opening_balance_available: false}
     */
    public function statement(Party $party, string $kind, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $customer = $kind === 'customer';
        $documentClass = $customer ? Revenue::class : Purchase::class;
        $returnClass = $customer ? CustomerReturn::class : SupplierReturn::class;
        $documentDate = $customer ? 'revenue_date' : 'purchase_date';
        $documentNumber = $customer ? 'revenue_number' : 'purchase_number';

        $documents = $documentClass::query()
            ->where('company_id', $party->company_id)
            ->where('party_id', $party->getKey())
            ->where('status', 'posted')
            ->with([
                'payments' => fn (MorphMany $query) => $query
                    ->where('company_id', $party->company_id)
                    ->whereIn('status', ['posted', 'reversed']),
                'payments.accountingJournals',
                'payments.reversedBy.accountingJournals',
            ])
            ->orderBy($documentDate)
            ->orderBy('id')
            ->get();

        $entries = [];

        foreach ($documents as $document) {
            $documentCurrency = strtoupper((string) $document->currency);
            $entries[] = $this->entry(
                date: $document->{$documentDate}->toDateString(),
                type: 'invoice',
                number: (string) $document->{$documentNumber},
                currency: $documentCurrency,
                amount: (string) $document->total,
                increasesBalance: true,
                customer: $customer,
                sequence: 10,
                source: $document,
            );

            foreach ($document->payments->sortBy([['paid_at', 'asc'], ['id', 'asc']]) as $payment) {
                $this->appendPaymentEntries($entries, $payment, $documentCurrency, $party, $customer);
            }
        }

        $returns = $returnClass::query()
            ->where('company_id', $party->company_id)
            ->where('party_id', $party->getKey())
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->with($customer ? ['journal', 'originalRevenue'] : ['journal', 'originalPurchase'])
            ->orderBy('return_date')
            ->orderBy('id')
            ->get();

        foreach ($returns as $return) {
            $journal = $return->journal;
            $originalDocument = $customer ? $return->originalRevenue : $return->originalPurchase;
            if (
                $originalDocument === null
                || (int) $originalDocument->company_id !== (int) $party->company_id
                || (int) $originalDocument->party_id !== (int) $party->getKey()
                || $originalDocument->status !== 'posted'
                || ! $this->isPostedJournal($journal, (int) $party->company_id)
                || $journal->reversed_by_journal_id !== null
            ) {
                continue;
            }

            $entries[] = $this->entry(
                date: $return->return_date->toDateString(),
                type: 'return',
                number: (string) $return->return_number,
                currency: strtoupper((string) $return->currency),
                amount: (string) $return->total,
                increasesBalance: false,
                customer: $customer,
                sequence: 20,
                source: $return,
            );
        }

        usort($entries, static fn (array $left, array $right): int => [
            $left['date'], $left['sequence'], $left['number'],
        ] <=> [
            $right['date'], $right['sequence'], $right['number'],
        ]);

        $balances = [];
        $visibleEntries = [];
        $cutoff = $dateTo ?? now()->toDateString();

        foreach ($entries as $entry) {
            $entryCurrency = $entry['currency'];
            $balances[$entryCurrency] ??= '0.0000';

            if ($entry['date'] <= $cutoff) {
                $increase = $entry['debit'];
                $decrease = $entry['credit'];
                $balances[$entryCurrency] = $customer
                    ? bcadd(bcsub($balances[$entryCurrency], $decrease, 10), $increase, 10)
                    : bcadd(bcsub($balances[$entryCurrency], $increase, 10), $decrease, 10);
                $entry['balance'] = $balances[$entryCurrency];

                if (($dateFrom === null || $entry['date'] >= $dateFrom) && $entry['date'] <= $cutoff) {
                    $visibleEntries[] = $entry;
                }
            }
        }

        return [
            'entries' => $visibleEntries,
            'balances' => $balances,
            'opening_balance_available' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function appendPaymentEntries(
        array &$entries,
        Payment $payment,
        string $documentCurrency,
        Party $party,
        bool $customer,
    ): void {
        $expectedDirection = $customer ? 'inbound' : 'outbound';
        $amount = (string) ($payment->applied_amount ?? $payment->amount ?? '0');
        $appliedCurrency = strtoupper((string) ($payment->applied_currency ?? $payment->currency));

        if (
            (int) $payment->company_id !== (int) $party->company_id
            || strtoupper((string) $payment->direction) !== strtoupper($expectedDirection)
            || $appliedCurrency !== $documentCurrency
            || bccomp($amount, '0', 10) <= 0
            || ! $this->hasExactlyOneJournal($payment->accountingJournals, (int) $party->company_id)
        ) {
            return;
        }

        $originalJournal = $payment->accountingJournals->first();
        $isReversed = $payment->status === 'reversed';

        if (
            ! in_array($payment->status, ['posted', 'reversed'], true)
            || $payment->posted_at === null
            || $payment->paid_at === null
            || $payment->reversal_of_payment_id !== null
            || ($isReversed !== ($payment->reversed_by_payment_id !== null))
            || ($isReversed
                ? $originalJournal->reversed_by_journal_id === null
                : $originalJournal->reversed_by_journal_id !== null)
        ) {
            return;
        }

        $entries[] = $this->entry(
            date: $payment->paid_at->toDateString(),
            type: 'payment',
            number: (string) $payment->payment_number,
            currency: $documentCurrency,
            amount: $amount,
            increasesBalance: false,
            customer: $customer,
            sequence: 30,
            source: $payment,
        );

        if (! $isReversed) {
            return;
        }

        $reversal = $payment->reversedBy;
        if (
            $reversal === null
            || (int) $reversal->company_id !== (int) $party->company_id
            || (int) $reversal->reversal_of_payment_id !== (int) $payment->getKey()
            || $reversal->status !== 'posted'
            || $reversal->posted_at === null
            || $reversal->paid_at === null
            || strtoupper((string) $reversal->direction) !== strtoupper($expectedDirection)
            || strtoupper((string) $reversal->currency) !== strtoupper((string) $payment->currency)
            || bccomp((string) $reversal->amount, (string) $payment->amount, 4) !== 0
            || ! $this->hasExactlyOneJournal($reversal->accountingJournals, (int) $party->company_id)
        ) {
            return;
        }

        $entries[] = $this->entry(
            date: $reversal->paid_at->toDateString(),
            type: 'reversal',
            number: (string) $reversal->payment_number,
            currency: $documentCurrency,
            amount: $amount,
            increasesBalance: true,
            customer: $customer,
            sequence: 40,
            source: $reversal,
        );
    }

    private function hasExactlyOneJournal(Collection $journals, int $companyId): bool
    {
        return $journals->count() === 1
            && $this->isPostedJournal($journals->first(), $companyId);
    }

    private function isPostedJournal(?AccountingJournal $journal, int $companyId): bool
    {
        return $journal !== null
            && (int) $journal->company_id === $companyId
            && $journal->status === 'posted'
            && $journal->posted_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(
        string $date,
        string $type,
        string $number,
        string $currency,
        string $amount,
        bool $increasesBalance,
        bool $customer,
        int $sequence,
        object $source,
    ): array {
        $isDebit = $increasesBalance === $customer;

        return [
            'date' => $date,
            'type' => $type,
            'number' => $number,
            'currency' => $currency,
            'debit' => $isDebit ? $amount : '0.0000',
            'credit' => $isDebit ? '0.0000' : $amount,
            'balance' => '0.0000',
            'sequence' => $sequence,
            'source' => $source,
        ];
    }
}
