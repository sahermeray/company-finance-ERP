@extends('layouts.inventory')

@section('title', __('ui.parties.statement').' · '.$party->name)

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route($kind.'s.show', $party) }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.parties.back') }}</a>
        <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ __('ui.parties.statement') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $party->name }}</p>
    </div>
    <a href="{{ route($kind.'s.statement.print', [$party, ...request()->query()]) }}" class="button button-secondary" data-print-document>{{ __('ui.parties.print') }}</a>
</div>

<section class="panel mb-5">
    <form method="GET" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-[1fr_1fr_auto_auto]">
        <div><label for="date_from" class="form-label">{{ __('ui.commercial.date_from') }}</label><input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="form-control"></div>
        <div><label for="date_to" class="form-label">{{ __('ui.commercial.date_to') }}</label><input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="form-control"></div>
        <button type="submit" class="button button-primary self-end">{{ __('ui.actions.apply') }}</button>
        <a href="{{ route($kind.'s.statement', $party) }}" class="button button-secondary self-end">{{ __('ui.commercial.clear_filters') }}</a>
    </form>
    <div class="p-4 text-sm text-slate-600">
        <p>{{ __('ui.parties.opening_balance_unavailable') }}</p>
        <p class="mt-1">{{ __('ui.parties.operational_note') }}</p>
    </div>
</section>

<section class="panel">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[54rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-4 py-3">{{ __('ui.commercial.date') }}</th><th class="px-4 py-3">{{ __('ui.parties.entry_type') }}</th><th class="px-4 py-3">{{ __('ui.parties.reference') }}</th><th class="px-4 py-3">{{ __('ui.parties.debit') }}</th><th class="px-4 py-3">{{ __('ui.parties.credit') }}</th><th class="px-4 py-3">{{ __('ui.parties.running_balance') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($statement['entries'] as $entry)
                    <tr>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $entry['date'] }}</bdi></td>
                        <td class="px-4 py-3">{{ __('ui.parties.entries.'.$entry['type']) }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $entry['number'] }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ bccomp($entry['debit'], '0', 4) === 0 ? '—' : number_format((float) $entry['debit'], 4).' '.$entry['currency'] }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ bccomp($entry['credit'], '0', 4) === 0 ? '—' : number_format((float) $entry['credit'], 4).' '.$entry['currency'] }}</bdi></td>
                        <td class="px-4 py-3 font-medium"><bdi dir="ltr">{{ number_format((float) $entry['balance'], 4) }} {{ $entry['currency'] }}</bdi></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('ui.parties.no_statement_entries') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($statement['balances'] !== [])
        <dl class="grid gap-3 border-t border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($statement['balances'] as $currency => $balance)
                <div><dt class="text-xs font-semibold text-slate-500">{{ __('ui.parties.balance') }} · <bdi dir="ltr">{{ $currency }}</bdi></dt><dd class="mt-1 font-semibold"><bdi dir="ltr">{{ number_format((float) $balance, 4) }} {{ $currency }}</bdi></dd></div>
            @endforeach
        </dl>
    @endif
</section>
@endsection