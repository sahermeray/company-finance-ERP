@extends('layouts.app')

@section('title', __('ui.parties.statement').' · '.$party->name)

@section('content')
<main class="mx-auto max-w-5xl p-4 sm:p-8">
    <div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ route($kind.'s.statement', [$party, ...request()->query()]) }}" class="button button-secondary">{{ __('ui.parties.back') }}</a>
        <button type="button" class="button button-primary" data-print-document>{{ __('ui.parties.print') }}</button>
    </div>
    <article class="document-paper border border-slate-200 bg-white p-5 sm:p-10">
        <header class="flex flex-wrap justify-between gap-5 border-b border-slate-200 pb-5">
            <div><p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p><h1 class="mt-2 text-xl font-bold">{{ $activeCompany->name }}</h1></div>
            <div class="text-start sm:text-end"><h2 class="text-xl font-semibold">{{ __('ui.parties.statement') }}</h2><p class="mt-2">{{ $party->name }}</p>@if ($filters['date_from'] ?? null)<p class="text-sm"><bdi dir="ltr">{{ $filters['date_from'] }}</bdi> — <bdi dir="ltr">{{ $filters['date_to'] ?? now()->toDateString() }}</bdi></p>@endif</div>
        </header>
        <p class="border-b border-slate-200 py-3 text-sm text-slate-600">{{ __('ui.parties.opening_balance_unavailable') }} {{ __('ui.parties.operational_note') }}</p>
        <div class="overflow-x-auto">
            <table class="mt-4 w-full min-w-[48rem] text-start text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs font-semibold"><tr><th class="px-3 py-2">{{ __('ui.commercial.date') }}</th><th class="px-3 py-2">{{ __('ui.parties.entry_type') }}</th><th class="px-3 py-2">{{ __('ui.parties.reference') }}</th><th class="px-3 py-2">{{ __('ui.parties.debit') }}</th><th class="px-3 py-2">{{ __('ui.parties.credit') }}</th><th class="px-3 py-2">{{ __('ui.parties.running_balance') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($statement['entries'] as $entry)
                        <tr><td class="px-3 py-2"><bdi dir="ltr">{{ $entry['date'] }}</bdi></td><td class="px-3 py-2">{{ __('ui.parties.entries.'.$entry['type']) }}</td><td class="px-3 py-2"><bdi dir="ltr">{{ $entry['number'] }}</bdi></td><td class="px-3 py-2"><bdi dir="ltr">{{ bccomp($entry['debit'], '0', 4) === 0 ? '—' : number_format((float) $entry['debit'], 4).' '.$entry['currency'] }}</bdi></td><td class="px-3 py-2"><bdi dir="ltr">{{ bccomp($entry['credit'], '0', 4) === 0 ? '—' : number_format((float) $entry['credit'], 4).' '.$entry['currency'] }}</bdi></td><td class="px-3 py-2 font-medium"><bdi dir="ltr">{{ number_format((float) $entry['balance'], 4) }} {{ $entry['currency'] }}</bdi></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <dl class="mt-5 grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-3">
            @foreach ($statement['balances'] as $currency => $balance)
                <div><dt class="text-xs text-slate-500">{{ __('ui.parties.balance') }} · <bdi dir="ltr">{{ $currency }}</bdi></dt><dd class="mt-1 font-semibold"><bdi dir="ltr">{{ number_format((float) $balance, 4) }} {{ $currency }}</bdi></dd></div>
            @endforeach
        </dl>
    </article>
</main>
@endsection