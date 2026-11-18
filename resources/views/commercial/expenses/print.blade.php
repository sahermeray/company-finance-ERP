@extends('layouts.app')

@section('title', __('ui.expenses.voucher').' · '.$expense->expense_number)

@section('content')
@php($subtotal = bcsub((string) $expense->amount, (string) $expense->tax_total, 4))
<main class="mx-auto max-w-4xl p-4 sm:p-8">
    <div class="invoice-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ route('expenses.show', $expense) }}" class="button button-secondary">{{ __('ui.commercial.back_to_document') }}</a>
        <button type="button" class="button button-primary" data-print-invoice>{{ __('ui.expenses.print_voucher') }}</button>
    </div>
    <article class="invoice-paper rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        <header class="flex flex-wrap justify-between gap-6 border-b border-slate-200 pb-6">
            <div>
                <p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ $company->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $company->base_currency }}</p>
            </div>
            <div class="text-start sm:text-end">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('ui.expenses.voucher') }}</h2>
                <p class="mt-2 text-sm"><span class="text-slate-500">{{ __('ui.expenses.number') }}:</span> <bdi dir="ltr">{{ $expense->expense_number }}</bdi></p>
                <p class="mt-1 text-sm"><span class="text-slate-500">{{ __('ui.commercial.date') }}:</span> <bdi dir="ltr">{{ $expense->expense_date->format('Y-m-d') }}</bdi></p>
                <p class="mt-1 text-sm"><span class="text-slate-500">{{ __('ui.commercial.status') }}:</span> {{ __('ui.expenses.statuses.'.$expense->status) }}</p>
            </div>
        </header>

        <section class="grid gap-4 py-6 sm:grid-cols-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.parties.party') }}</p>
                <p class="mt-1 font-semibold text-slate-950">{{ $expense->party?->name ?? '—' }}</p>
                @if ($expense->project)
                    <p class="mt-1 text-sm text-slate-600">{{ __('ui.expenses.project') }}: {{ $expense->project->name }}</p>
                @endif
            </div>
            <div class="sm:text-end">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.commercial.exchange_rate') }}</p>
                <p class="mt-1 font-semibold"><bdi dir="ltr">{{ $expense->exchange_rate ?? '—' }} {{ $expense->base_currency }}/{{ $expense->currency }}</bdi></p>
            </div>
        </section>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-start text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600">
                    <tr>
                        <th class="px-3 py-3">{{ __('ui.commercial.expense_account') }}</th>
                        <th class="px-3 py-3">{{ __('ui.expenses.category') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.quantity') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.unit_price') }}</th>
                        <th class="px-3 py-3 text-end">{{ __('ui.commercial.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($expense->lines as $line)
                        <tr>
                            <td class="px-3 py-3"><span class="font-medium">{{ $line->expenseAccount?->code }}</span><span class="block text-xs text-slate-500">{{ $line->expenseAccount?->name }}</span></td>
                            <td class="px-3 py-3">{{ $line->category }}<span class="block text-xs text-slate-500">{{ $line->description }}</span></td>
                            <td class="px-3 py-3"><bdi dir="ltr">{{ $line->quantity }}</bdi></td>
                            <td class="px-3 py-3"><bdi dir="ltr">{{ $line->unit_price }} {{ $expense->currency }}</bdi></td>
                            <td class="px-3 py-3 text-end"><bdi dir="ltr">{{ $line->line_total }} {{ $expense->currency }}</bdi></td>
                        </tr>
                        @foreach ($line->taxLines as $taxLine)
                            <tr class="bg-slate-50/70 text-xs text-slate-600">
                                <td colspan="3" class="px-3 py-2">{{ __('ui.commercial.tax') }} · {{ __('ui.commercial.'.$taxLine->treatment) }}</td>
                                <td colspan="2" class="px-3 py-2 text-end"><bdi dir="ltr">{{ $taxLine->amount }} {{ $expense->currency }}</bdi></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <section class="ms-auto mt-6 grid max-w-sm gap-2 text-sm">
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.subtotal') }}</span><bdi dir="ltr">{{ $subtotal }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.tax_total') }}</span><bdi dir="ltr">{{ $expense->tax_total }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4 border-t border-slate-200 pt-3 text-base font-semibold"><span>{{ __('ui.commercial.total') }}</span><bdi dir="ltr">{{ $expense->amount }} {{ $expense->currency }}</bdi></p>
            <p class="flex justify-between gap-4 text-xs text-slate-500"><span>{{ __('ui.commercial.base_total') }}</span><bdi dir="ltr">{{ $expense->base_amount }} {{ $expense->base_currency }}</bdi></p>
        </section>

        @if ($expense->notes)
            <section class="mt-6 border-t border-slate-200 pt-4">
                <h2 class="text-sm font-semibold">{{ __('ui.expenses.reference_notes') }}</h2>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $expense->notes }}</p>
            </section>
        @endif
    </article>
</main>
@endsection
