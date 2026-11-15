@extends('layouts.app')

@section('title', __('ui.commercial.'.$type.'_invoice').' · '.$number)

@section('content')
<main class="mx-auto max-w-4xl p-4 sm:p-8">
    <div class="invoice-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ $type === 'purchase' ? route('purchases.show', $document) : route('sales.show', $document) }}" class="button button-secondary">{{ __('ui.commercial.back_to_document') }}</a>
        <button type="button" class="button button-primary" data-print-invoice>{{ __('ui.commercial.print_invoice') }}</button>
    </div>
    <article class="invoice-paper rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        <header class="flex flex-wrap justify-between gap-6 border-b border-slate-200 pb-6">
            <div>
                <p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ $company->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $company->base_currency }}</p>
            </div>
            <div class="text-start sm:text-end">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('ui.commercial.'.$type.'_invoice') }}</h2>
                <p class="mt-2 text-sm"><span class="text-slate-500">{{ __('ui.commercial.invoice_number') }}:</span> <bdi dir="ltr">{{ $number }}</bdi></p>
                <p class="mt-1 text-sm"><span class="text-slate-500">{{ __('ui.commercial.date') }}:</span> <bdi dir="ltr">{{ $date->format('Y-m-d') }}</bdi></p>
            </div>
        </header>

        <section class="grid gap-4 py-6 sm:grid-cols-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $type === 'purchase' ? __('ui.commercial.supplier') : __('ui.commercial.customer') }}</p>
                <p class="mt-1 font-semibold text-slate-950">{{ $document->party->name }}</p>
                @if ($document->party->address)
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $document->party->address }}</p>
                @endif
            </div>
            <div class="sm:text-end">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.commercial.status') }}</p>
                <p class="mt-1 font-semibold">{{ __('ui.commercial.statuses.'.$document->status) }}</p>
            </div>
        </section>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-start text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600">
                    <tr>
                        <th class="px-3 py-3">{{ __('ui.commercial.product') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.quantity') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.unit_price') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($items as $item)
                        <tr>
                            <td class="px-3 py-3">
                                <span class="font-medium">{{ $item->description ?: $item->product->name }}</span>
                            </td>
                            <td class="px-3 py-3"><bdi dir="ltr">{{ $item->quantity }} {{ $item->unitOfMeasure?->symbol ?? $item->unitOfMeasure?->code }}</bdi></td>
                            <td class="px-3 py-3"><bdi dir="ltr">{{ $item->unit_price }} {{ $document->currency }}</bdi></td>
                            <td class="px-3 py-3"><bdi dir="ltr">{{ $item->line_total }} {{ $document->currency }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <section class="ms-auto mt-6 grid max-w-sm gap-2 text-sm">
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.subtotal') }}</span><bdi dir="ltr">{{ $document->subtotal }} {{ $document->currency }}</bdi></p>
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.tax_total') }}</span><bdi dir="ltr">{{ $document->tax_total }} {{ $document->currency }}</bdi></p>
            <p class="flex justify-between gap-4 border-t border-slate-200 pt-3 text-base font-semibold"><span>{{ __('ui.commercial.total') }}</span><bdi dir="ltr">{{ $document->total }} {{ $document->currency }}</bdi></p>
            <p class="flex justify-between gap-4 text-xs text-slate-500"><span>{{ __('ui.commercial.base_total') }}</span><bdi dir="ltr">{{ $document->base_total }} {{ $document->base_currency }}</bdi></p>
        </section>

        @if ($type === 'purchase' && $document->landedCosts->isNotEmpty())
            <section class="mt-6 border-t border-slate-200 pt-4">
                <h2 class="mb-2 text-sm font-semibold">{{ __('ui.commercial.landed_costs') }}</h2>
                @foreach ($document->landedCosts as $cost)
                    <p class="flex justify-between gap-4 py-1 text-sm"><span>{{ $cost->description ?: __('ui.commercial.landed_cost_types.'.$cost->cost_type) }}</span><bdi dir="ltr">{{ $cost->amount }} {{ $cost->currency }}</bdi></p>
                @endforeach
                <p class="mt-2 text-xs text-slate-500">{{ __('ui.commercial.landed_cost_currency_note') }}</p>
            </section>
        @endif

        @if ($document->notes)
            <section class="mt-6 border-t border-slate-200 pt-4">
                <h2 class="text-sm font-semibold">{{ __('ui.commercial.notes') }}</h2>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $document->notes }}</p>
            </section>
        @endif
    </article>
</main>
@endsection
