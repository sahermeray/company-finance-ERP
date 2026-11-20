@extends('layouts.inventory')

@section('title', __('ui.reports.performance_trends').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.performance_trends') }}</h1>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
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
            <a href="{{ route('reports.performance-trends') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    @if($report['status'] !== 'complete')
        <div class="border-b border-slate-100 bg-amber-50 p-4 text-sm text-amber-800">
            {{ __('ui.reports.incomplete_data_warning') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.reports.col_month') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_revenue') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_cogs') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_gross_profit') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_operating_expenses') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_other_income') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_other_expenses') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.reports.metric_net_profit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($report['months'] as $monthKey => $months)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $monthKey }}</bdi></td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['revenue'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['cost_of_goods_sold'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['gross_profit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['operating_expense'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['other_income'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['other_expense'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ number_format((float) $months['net_profit'], 4) }} {{ $report['base_currency'] }}</bdi>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-500">
                            {{ __('ui.reports.no_data') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
