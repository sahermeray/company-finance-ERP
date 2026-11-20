@extends('layouts.inventory')

@section('title', __('ui.reports.trial_balance').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.trial_balance') }}</h1>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <a href="{{ route('reports.trial-balance.print', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.print') }}</a>
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
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.reports.apply_filters') }}</button>
            <a href="{{ route('reports.trial-balance') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    @if($report['status'] !== 'complete')
        <div class="border-b border-slate-100 bg-amber-50 p-4 text-sm text-amber-800">
            {{ __('ui.reports.incomplete_data_warning') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full min-w-[60rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_name') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_opening_debit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_opening_credit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_period_debit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_period_credit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_closing_debit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_closing_credit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($report['accounts'] as $account)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                        <td class="px-4 py-3">{{ $account['name'] }}</td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['opening']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['opening']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['period']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['period']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['closing']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['closing']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">
                            {{ __('ui.reports.no_accounts') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-semibold text-xs">
                <tr>
                    <td class="px-4 py-3" colspan="2">{{ __('ui.reports.totals') }}</td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['opening']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['opening']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['period']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['period']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['closing']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                    <td class="px-4 py-3 text-end font-mono">
                        <bdi dir="ltr">{{ number_format((float) $report['totals']['closing']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if(! empty($report['native_currency_totals']))
        <details class="border-t border-slate-100">
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-700">{{ __('ui.reports.base_currency_note', ['currency' => $report['base_currency']]) }}</summary>
            <div class="overflow-x-auto p-4">
                @foreach ($report['native_currency_totals'] as $currency => $totals)
                    <div class="mb-4">
                        <p class="mb-2 text-xs font-semibold text-slate-600"><bdi dir="ltr">{{ $currency }}</bdi></p>
                        <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_opening_debit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['opening']['debit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_opening_credit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['opening']['credit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_period_debit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['period']['debit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_period_credit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['period']['credit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_closing_debit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['closing']['debit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('ui.reports.col_closing_credit') }}</dt>
                                <dd class="font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $totals['closing']['credit'], 4) }} {{ $currency }}</bdi></dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</section>
@endsection
