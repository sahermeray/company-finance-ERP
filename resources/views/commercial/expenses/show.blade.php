@extends('layouts.inventory')

@section('title', $expense->expense_number.' · '.__('ui.app_name'))

@section('page-content')
@php($subtotal = bcsub((string) $expense->amount, (string) $expense->tax_total, 4))
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('expenses.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.expenses.back_to_list') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950"><bdi dir="ltr">{{ $expense->expense_number }}</bdi></h1>
            <span class="status-badge {{ $expense->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.expenses.statuses.'.$expense->status) }}</span>
            @if ($expense->payment_financial_account_id !== null)
                <span class="status-badge status-available">{{ __('ui.expenses.paid_now') }}</span>
            @elseif ($expense->payable_account_id !== null)
                <span class="status-badge status-unavailable">{{ __('ui.expenses.unpaid_later') }}</span>
            @endif
        </div>
    </div>
    <a href="{{ route('expenses.print', $expense) }}" class="button button-secondary">{{ __('ui.expenses.print_voucher') }}</a>
</div>

@if ($settlement ?? null)
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
        <p class="font-semibold">{{ __('ui.expenses.settlement_saved') }}</p>
        <p class="mt-1">{{ __('ui.payments.outstanding_before') }}: <bdi dir="ltr">{{ $settlement['before'] }} {{ $settlement['currency'] }}</bdi></p>
        <p>{{ __('ui.payments.outstanding_after') }}: <bdi dir="ltr">{{ $settlement['after'] }} {{ $settlement['currency'] }}</bdi></p>
    </div>
@endif

<div class="grid gap-5">
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="form-label">{{ __('ui.parties.party') }}</p><p class="font-medium">{{ $expense->party?->name ?? '—' }}</p></div>
        <div><p class="form-label">{{ __('ui.commercial.date') }}</p><p><bdi dir="ltr">{{ $expense->expense_date->format('Y-m-d') }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.currency') }}</p><p><bdi dir="ltr">{{ $expense->currency }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.exchange_rate') }}</p><p><bdi dir="ltr">{{ $expense->exchange_rate ?? '—' }} {{ $expense->base_currency }}/{{ $expense->currency }}</bdi></p></div>
        <div class="sm:col-span-2"><p class="form-label">{{ __('ui.commercial.description') }}</p><p class="font-medium">{{ $expense->description }}</p></div>
        @if ($expense->project)
            <div><p class="form-label">{{ __('ui.expenses.project') }}</p><p>{{ $expense->project->name }}</p></div>
        @endif
        @if ($expense->approver)
            <div><p class="form-label">{{ __('ui.expenses.approved_by') }}</p><p>{{ $expense->approver->name }} · <bdi dir="ltr">{{ $expense->approved_at?->format('Y-m-d H:i') }}</bdi></p></div>
        @endif
        @if ($expense->poster)
            <div><p class="form-label">{{ __('ui.expenses.posted_by') }}</p><p>{{ $expense->poster->name }} · <bdi dir="ltr">{{ $expense->posted_at?->format('Y-m-d H:i') }}</bdi></p></div>
        @endif
        @if ($expense->reverser)
            <div><p class="form-label">{{ __('ui.expenses.reversed_by') }}</p><p>{{ $expense->reverser->name }} · <bdi dir="ltr">{{ $expense->reversed_at?->format('Y-m-d H:i') }}</bdi></p></div>
        @endif
        @if ($expense->notes)
            <div class="sm:col-span-2 lg:col-span-4"><p class="form-label">{{ __('ui.expenses.reference_notes') }}</p><p class="whitespace-pre-line">{{ $expense->notes }}</p></div>
        @endif
    </section>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[42rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('ui.commercial.expense_account') }}</th>
                        <th class="px-4 py-3">{{ __('ui.expenses.category') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.description') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.quantity') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.unit_price') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('ui.commercial.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($expense->lines as $line)
                        <tr>
                            <td class="px-4 py-3">{{ $line->expenseAccount?->code }} · {{ $line->expenseAccount?->name }}</td>
                            <td class="px-4 py-3">{{ $line->category }}</td>
                            <td class="px-4 py-3">{{ $line->description }}</td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $line->quantity }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $line->unit_price }} {{ $expense->currency }}</bdi></td>
                            <td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->line_total }} {{ $expense->currency }}</bdi></td>
                        </tr>
                        @foreach ($line->taxLines as $taxLine)
                            <tr class="bg-slate-50/70 text-xs text-slate-600">
                                <td colspan="3" class="px-4 py-2">{{ __('ui.commercial.tax') }} · {{ __('ui.commercial.'.$taxLine->treatment) }}@if($taxLine->taxAssetAccount) · {{ $taxLine->taxAssetAccount->code }} @endif</td>
                                <td colspan="2" class="px-4 py-2"><bdi dir="ltr">{{ $taxLine->amount }} {{ $expense->currency }}</bdi></td>
                                <td class="px-4 py-2 text-end"><bdi dir="ltr">{{ $taxLine->base_amount ?? '—' }} {{ $expense->base_currency }}</bdi></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-2 border-t border-slate-100 p-4 text-sm sm:ms-auto sm:w-80">
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.subtotal') }}</span><bdi dir="ltr">{{ $subtotal }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.tax_total') }}</span><bdi dir="ltr">{{ $expense->tax_total }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base font-semibold"><span>{{ __('ui.commercial.total') }}</span><bdi dir="ltr">{{ $expense->amount }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4 text-xs text-slate-500"><span>{{ __('ui.commercial.base_total') }}</span><bdi dir="ltr">{{ $expense->base_amount }} {{ $expense->base_currency }}</bdi></p>
        </div>
    </section>

    @if ($expense->payment_financial_account_id !== null)
        <section class="panel p-4">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('ui.expenses.settlement') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('ui.expenses.paid_now_note') }}</p>
        </section>
    @elseif ($expense->status === 'posted' && $outstanding !== null)
        <section class="panel p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('ui.expenses.outstanding') }}</h2>
                    <p class="mt-1 text-sm text-slate-600"><bdi dir="ltr">{{ $outstanding }} {{ $expense->currency }}</bdi></p>
                </div>
            </div>
        </section>
    @endif

    @if ($isSettleable)
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold text-slate-950">{{ __('ui.expenses.settle_title') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.expenses.settle_help', ['outstanding' => $outstanding, 'currency' => $expense->currency]) }}</p>
            <form method="POST" action="{{ route('expenses.settle', $expense) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-settle-form data-settle-outstanding="{{ $outstanding }}">
                @csrf
                <div>
                    <label for="settle-amount" class="form-label">{{ __('ui.commercial.amount') }}</label>
                    <div class="flex gap-2">
                        <input id="settle-amount" name="amount" class="form-control" type="number" min="0.0001" step="0.0001" required inputmode="decimal" data-settle-amount>
                        <button type="button" class="button button-subtle" data-settle-full>{{ __('ui.expenses.full') }}</button>
                    </div>
                </div>
                <div>
                    <label for="settle-currency" class="form-label">{{ __('ui.commercial.currency') }}</label>
                    <select id="settle-currency" name="currency" class="form-control" required data-settle-currency>
                        @foreach ($settleCurrencies as $currency)
                            <option value="{{ $currency }}" @selected($currency === strtoupper((string) $expense->currency))>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="financial_account_id" class="form-label">{{ __('ui.payments.financial_account') }}</label>
                    <select id="financial_account_id" name="financial_account_id" class="form-control" required data-settle-account>
                        <option value="">{{ __('ui.commercial.select_account') }}</option>
                        @foreach ($assetAccounts as $account)
                            <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}">{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="paid_at" class="form-label">{{ __('ui.commercial.date') }}</label>
                    <input id="paid_at" name="paid_at" type="date" required value="{{ old('paid_at', now()->toDateString()) }}" class="form-control">
                </div>
                <div>
                    <label for="method" class="form-label">{{ __('ui.expenses.method') }}</label>
                    <input id="method" name="method" maxlength="120" class="form-control" value="{{ old('method') }}" dir="auto">
                </div>
                <div>
                    <label for="reference" class="form-label">{{ __('ui.payments.reference') }}</label>
                    <input id="reference" name="reference" maxlength="255" class="form-control" value="{{ old('reference') }}" dir="auto">
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="button button-primary">{{ __('ui.expenses.settle_save') }}</button>
                </div>
            </form>
        </section>
    @endif

    @if ($paymentHistory->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold text-slate-950">{{ __('ui.expenses.payment_history') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-start text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('ui.payments.number') }}</th>
                            <th class="px-4 py-3">{{ __('ui.commercial.date') }}</th>
                            <th class="px-4 py-3">{{ __('ui.payments.financial_account') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('ui.commercial.amount') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('ui.expenses.applied') }}</th>
                            <th class="px-4 py-3">{{ __('ui.commercial.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($paymentHistory as $payment)
                            <tr>
                                <td class="px-4 py-3"><bdi dir="ltr">{{ $payment->payment_number ?? '—' }}</bdi></td>
                                <td class="px-4 py-3"><bdi dir="ltr">{{ $payment->paid_at?->format('Y-m-d') }}</bdi></td>
                                <td class="px-4 py-3">{{ $payment->financialAccount?->code }} · {{ $payment->financialAccount?->name }}</td>
                                <td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $payment->amount }} {{ $payment->currency }}</bdi></td>
                                <td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $payment->applied_amount }} {{ $payment->applied_currency }}</bdi></td>
                                <td class="px-4 py-3">
                                    <span class="status-badge {{ $payment->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.payments.statuses.'.$payment->status) }}</span>
                                    @if ($payment->reversed_by_payment_id !== null)
                                        <span class="block text-xs text-slate-500">{{ __('ui.payments.reversed_by') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($expense->postedJournal)
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold text-slate-950">{{ __('ui.commercial.accounting_effects') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-start text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr><th class="px-4 py-3">{{ __('ui.commercial.account') }}</th><th class="px-4 py-3 text-end">{{ __('ui.commercial.debit') }}</th><th class="px-4 py-3 text-end">{{ __('ui.commercial.credit') }}</th><th class="px-4 py-3">{{ __('ui.commercial.currency') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($expense->postedJournal->lines as $line)
                            <tr><td class="px-4 py-3">{{ $line->financialAccount?->name }}</td><td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->debit ?: '—' }}</bdi></td><td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->credit ?: '—' }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ $line->currency }}</bdi></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($expense->reversalJournal)
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold text-slate-950">{{ __('ui.expenses.reversal_history') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] text-start text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr><th class="px-4 py-3">{{ __('ui.commercial.account') }}</th><th class="px-4 py-3 text-end">{{ __('ui.commercial.debit') }}</th><th class="px-4 py-3 text-end">{{ __('ui.commercial.credit') }}</th><th class="px-4 py-3">{{ __('ui.commercial.currency') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($expense->reversalJournal->lines as $line)
                            <tr><td class="px-4 py-3">{{ $line->financialAccount?->name }}</td><td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->debit ?: '—' }}</bdi></td><td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->credit ?: '—' }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ $line->currency }}</bdi></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($expense->status === 'draft')
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold text-slate-950">{{ __('ui.expenses.approve_title') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.expenses.approve_help') }}</p>
            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('expenses.approve', $expense) }}">
                    @csrf
                    <button type="submit" class="button button-primary">{{ __('ui.expenses.approve') }}</button>
                </form>
                <form method="POST" action="{{ route('expenses.destroy', $expense) }}" onsubmit="return confirm('{{ __('ui.expenses.confirm_delete') }}');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-secondary">{{ __('ui.expenses.delete_draft') }}</button>
                </form>
            </div>
        </section>
    @endif

    @if ($expense->status === 'approved')
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold text-slate-950">{{ __('ui.expenses.post_title') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.expenses.post_help') }}</p>
            <form method="POST" action="{{ route('expenses.post', $expense) }}">
                @csrf
                <button type="submit" class="button button-primary">{{ __('ui.expenses.post') }}</button>
            </form>
        </section>
    @endif

    @if ($expense->status === 'posted' && $expense->reversal_journal_id === null)
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold text-slate-950">{{ __('ui.expenses.reverse_title') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.expenses.reverse_help') }}</p>
            <form method="POST" action="{{ route('expenses.reverse', $expense) }}" onsubmit="return confirm('{{ __('ui.expenses.confirm_reverse') }}');">
                @csrf
                <button type="submit" class="button button-secondary">{{ __('ui.expenses.reverse') }}</button>
            </form>
        </section>
    @endif
</div>
@endsection
