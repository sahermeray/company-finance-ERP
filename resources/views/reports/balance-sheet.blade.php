@extends('layouts.inventory')

@section('title', __('ui.reports.balance_sheet').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
            {{ __('ui.reports.balance_sheet') }}
            @if($report['status'] === 'balanced')
                <span class="ms-2 inline-block align-middle status-badge status-available text-xs">{{ __('ui.reports.status_balanced') }}</span>
            @else
                <span class="ms-2 inline-block align-middle status-badge status-unavailable text-xs">{{ __('ui.reports.'.$report['status'] === 'discrepant' ? 'status_discrepant' : 'status_incomplete') }}</span>
            @endif
        </h1>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <a href="{{ route('reports.balance-sheet.print', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.print') }}</a>
    </nav>
</div>

<section class="panel mb-4">
    <form method="GET" action="" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="as_of_date" class="form-label">{{ __('ui.reports.as_of_date') }}</label>
            <input id="as_of_date" name="as_of_date" value="{{ $filters['as_of_date'] ?? '' }}" class="form-control" type="date">
        </div>
        <div>
            <label for="period_start" class="form-label">{{ __('ui.reports.date_from') }}</label>
            <input id="period_start" name="period_start" value="{{ $filters['period_start'] ?? '' }}" class="form-control" type="date">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.reports.apply_filters') }}</button>
            <a href="{{ route('reports.balance-sheet') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    @if($report['status'] !== 'balanced')
        <div class="border-b border-slate-100 bg-amber-50 p-4 text-sm text-amber-800">
            {{ __('ui.reports.incomplete_data_warning') }}
        </div>
    @endif
</section>

<section class="panel mb-4">
    <div class="border-b border-slate-100 p-4">
        <h2 class="font-semibold text-slate-900">{{ __('ui.reports.section_assets') }}</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_name') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report['assets']['accounts'] as $account)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                        <td class="px-4 py-3">{{ $account['name'] }}</td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                    <td class="px-4 py-3" colspan="2">{{ __('ui.reports.section_assets') }} {{ __('ui.reports.totals') }}</td>
                    <td class="px-4 py-3 text-end font-mono text-xs">
                        <bdi dir="ltr">{{ number_format((float) $report['assets']['total'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <div class="border-b border-slate-100 p-4">
        <h2 class="font-semibold text-slate-900">{{ __('ui.reports.section_liabilities') }}</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_name') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report['liabilities']['accounts'] as $account)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                        <td class="px-4 py-3">{{ $account['name'] }}</td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                    <td class="px-4 py-3" colspan="2">{{ __('ui.reports.section_liabilities') }} {{ __('ui.reports.totals') }}</td>
                    <td class="px-4 py-3 text-end font-mono text-xs">
                        <bdi dir="ltr">{{ number_format((float) $report['liabilities']['total'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel mb-4">
    <div class="border-b border-slate-100 p-4">
        <h2 class="font-semibold text-slate-900">{{ __('ui.reports.section_equity') }}</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.reports.col_account_name') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.col_balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report['equity']['accounts'] as $account)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                        <td class="px-4 py-3">{{ $account['name'] }}</td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @endforeach
                <tr class="border-t border-slate-100">
                    <td class="px-4 py-2 text-xs text-slate-500" colspan="2">{{ __('ui.reports.section_equity') }} ({{ __('ui.reports.col_account_name') }})</td>
                    <td class="px-4 py-2 text-end font-mono text-xs text-slate-500">
                        <bdi dir="ltr">{{ number_format((float) $report['equity']['account_balances_total'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2 text-xs text-slate-500" colspan="2">{{ __('ui.reports.section_net_profit') }}</td>
                    <td class="px-4 py-2 text-end font-mono text-xs text-slate-500">
                        <bdi dir="ltr">{{ $report['equity']['current_period_earnings'] !== null ? number_format((float) $report['equity']['current_period_earnings'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2 text-xs text-slate-500" colspan="2">{{ __('ui.reports.print_period') }}</td>
                    <td class="px-4 py-2 text-end font-mono text-xs text-slate-500">
                        <bdi dir="ltr">{{ $report['equity']['prior_unclosed_earnings'] !== null ? number_format((float) $report['equity']['prior_unclosed_earnings'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                    </td>
                </tr>
                <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                    <td class="px-4 py-3" colspan="2">{{ __('ui.reports.section_equity') }} {{ __('ui.reports.totals') }}</td>
                    <td class="px-4 py-3 text-end font-mono text-xs">
                        <bdi dir="ltr">{{ number_format((float) $report['equity']['total'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
                <tr class="bg-slate-100 font-bold">
                    <td class="px-4 py-3" colspan="2">{{ __('ui.reports.section_liabilities') }} + {{ __('ui.reports.section_equity') }}</td>
                    <td class="px-4 py-3 text-end font-mono text-xs">
                        <bdi dir="ltr">{{ number_format((float) $report['total_liabilities_and_equity'], 4) }} {{ $report['base_currency'] }}</bdi>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
@endsection
