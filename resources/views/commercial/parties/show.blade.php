@extends('layouts.inventory')

@section('title', $party->name.' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route($kind.'s.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.parties.back') }}</a>
        <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ $party->name }}</h1>
        @if ($party->legal_name)<p class="mt-1 text-sm text-slate-500">{{ $party->legal_name }}</p>@endif
    </div>
    <a href="{{ route($kind.'s.edit', $party) }}" class="button button-secondary">{{ __('ui.parties.edit') }}</a>
</div>

<div class="grid gap-5">
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="form-label">{{ __('ui.parties.phone') }}</p><p><bdi dir="auto">{{ $party->phone ?: '—' }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.parties.email') }}</p><p><bdi dir="ltr">{{ $party->email ?: '—' }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.parties.code') }}</p><p><bdi dir="auto">{{ $party->code ?: '—' }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.parties.identifier') }}</p><p><bdi dir="auto">{{ $party->tax_identifier ?: '—' }}</bdi></p></div>
        @if ($party->address)<div class="sm:col-span-2 lg:col-span-4"><p class="form-label">{{ __('ui.parties.address') }}</p><p class="whitespace-pre-line">{{ $party->address }}</p></div>@endif
    </section>

    <section class="panel p-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">{{ __('ui.parties.operational_balance') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('ui.parties.operational_note') }}</p>
            </div>
            <a href="{{ route($kind.'s.statement', $party) }}" class="button button-secondary">{{ __('ui.parties.view_statement') }}</a>
        </div>
        @if ($statement['balances'] === [])
            <p class="text-sm text-slate-500">{{ __('ui.parties.no_balance') }}</p>
        @else
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($statement['balances'] as $currency => $balance)
                    <div class="border-s-2 border-teal-600 ps-3">
                        <dt class="text-xs font-semibold text-slate-500"><bdi dir="ltr">{{ $currency }}</bdi></dt>
                        <dd class="mt-1 text-lg font-semibold text-slate-900"><bdi dir="ltr">{{ number_format((float) $balance, 4) }} {{ $currency }}</bdi></dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    <section class="panel">
        <div class="panel-heading"><h2 class="text-lg font-semibold">{{ __('ui.parties.'.$kind.'_history') }}</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[38rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-4 py-3">{{ __('ui.commercial.invoice_number') }}</th><th class="px-4 py-3">{{ __('ui.commercial.date') }}</th><th class="px-4 py-3">{{ __('ui.commercial.total') }}</th><th class="px-4 py-3">{{ __('ui.commercial.status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($history as $document)
                        @php($number = $kind === 'customer' ? $document->revenue_number : $document->purchase_number)
                        @php($date = $kind === 'customer' ? $document->revenue_date : $document->purchase_date)
                        <tr><td class="px-4 py-3 font-medium"><a class="text-teal-800 hover:underline" href="{{ route($kind === 'customer' ? 'sales.show' : 'purchases.show', $document) }}"><bdi dir="ltr">{{ $number }}</bdi></a></td><td class="px-4 py-3"><bdi dir="ltr">{{ $date->format('Y-m-d') }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ number_format((float) $document->total, 4) }} {{ $document->currency }}</bdi></td><td class="px-4 py-3">{{ __('ui.commercial.statuses.'.$document->status) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('ui.parties.no_documents') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading"><h2 class="text-lg font-semibold">{{ __('ui.parties.returns_history') }}</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-4 py-3">{{ __('ui.parties.return_number') }}</th><th class="px-4 py-3">{{ __('ui.commercial.date') }}</th><th class="px-4 py-3">{{ __('ui.commercial.total') }}</th><th class="px-4 py-3">{{ __('ui.commercial.status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($returns as $return)
                        <tr><td class="px-4 py-3"><bdi dir="ltr">{{ $return->return_number }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ $return->return_date->format('Y-m-d') }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ number_format((float) $return->total, 4) }} {{ $return->currency }}</bdi></td><td class="px-4 py-3">{{ __('ui.commercial.statuses.'.$return->status) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('ui.parties.no_returns') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection