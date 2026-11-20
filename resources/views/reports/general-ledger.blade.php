@extends('layouts.inventory')

@section('title', __('ui.reports.general_ledger').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.general_ledger') }}</h1>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <a href="{{ route('reports.general-ledger.print', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.print') }}</a>
    </nav>
</div>

<section class="panel">
    <form method="GET" action="" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="date_from" class="form-label">{{ __('ui.reports.date_from') }}</label>
            <input id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" type="date">
        </div>
        <div>
            <label for="date_to" class="form-label">{{ __('ui.reports.date_to') }}</label>
            <input id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" type="date">
        </div>
        <div>
            <label for="account_id" class="form-label">{{ __('ui.reports.account') }}</label>
            <select id="account_id" name="account_id" class="form-control">
                <option value="">{{ __('ui.reports.all_accounts') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->getKey() }}" @selected(($filters['account_id'] ?? null) == $account->getKey())>
                        <bdi dir="ltr">{{ $account->code }}</bdi> – {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.reports.apply_filters') }}</button>
            <a href="{{ route('reports.general-ledger') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    @if($report['status'] !== 'complete')
        <div class="border-b border-slate-100 bg-amber-50 p-4 text-sm text-amber-800">
            {{ __('ui.reports.incomplete_data_warning') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full min-w-[70rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_date') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_journal') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_description') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_name') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_debit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_credit') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.account') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_base_debit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_base_credit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_running_balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($report['transactions'] as $transaction)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $transaction['accounting_date'] }}</bdi></td>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $transaction['journal_reference'] }}</bdi></td>
                        <td class="px-4 py-3 max-w-xs truncate" title="{{ $transaction['description'] }}">{{ $transaction['description'] ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $transaction['account_name'] }}</td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            @if($transaction['debit'] === null)
                                —
                            @else
                                <bdi dir="ltr">{{ number_format((float) $transaction['debit'], 4) }} {{ $transaction['currency'] }}</bdi>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            @if($transaction['credit'] === null)
                                —
                            @else
                                <bdi dir="ltr">{{ number_format((float) $transaction['credit'], 4) }} {{ $transaction['currency'] }}</bdi>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs"><bdi dir="ltr">{{ $transaction['currency'] ?? $report['base_currency'] }}</bdi></td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $transaction['base_debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $transaction['base_credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $transaction['running_balance_base'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-10 text-center text-sm text-slate-500">
                            {{ __('ui.reports.no_transactions') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
