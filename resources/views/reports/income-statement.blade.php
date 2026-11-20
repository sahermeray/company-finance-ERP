@extends('layouts.inventory')

@section('title', __('ui.reports.income_statement').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.income_statement') }}</h1>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <a href="{{ route('reports.income-statement.print', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.print') }}</a>
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
            <label for="comparison_from" class="form-label">{{ __('ui.reports.comparison_from') }}</label>
            <input id="comparison_from" name="comparison_from" value="{{ $filters['comparison_from'] ?? '' }}" class="form-control" type="date">
        </div>
        <div>
            <label for="comparison_to" class="form-label">{{ __('ui.reports.comparison_to') }}</label>
            <input id="comparison_to" name="comparison_to" value="{{ $filters['comparison_to'] ?? '' }}" class="form-control" type="date">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
            <button type="submit" class="button button-primary">{{ __('ui.reports.apply_filters') }}</button>
            <a href="{{ route('reports.income-statement') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    @if($report['status'] !== 'complete')
        <div class="border-b border-slate-100 bg-amber-50 p-4 text-sm text-amber-800">
            {{ __('ui.reports.incomplete_data_warning') }}
        </div>
    @endif

    <div class="p-4">
        @php
            $hasComparison = ! empty($filters['comparison_from']) && ! empty($filters['comparison_to']);
            $metrics = [
                'revenue' => 'metric_revenue',
                'cost_of_goods_sold' => 'metric_cogs',
                'gross_profit' => 'metric_gross_profit',
                'gross_margin_percentage' => 'metric_gross_margin',
                'operating_expenses' => 'metric_operating_expenses',
                'other_income' => 'metric_other_income',
                'other_expenses' => 'metric_other_expenses',
                'net_profit' => 'metric_net_profit',
            ];
        @endphp
        <dl class="divide-y divide-slate-100">
            @foreach ($metrics as $metricKey => $translationKey)
                @php $metric = $report['metrics'][$metricKey]; @endphp
                <div class="flex flex-wrap items-center justify-between gap-4 py-3">
                    <dt class="text-sm font-medium text-slate-700">{{ __('ui.reports.'.$translationKey) }}</dt>
                    <dd class="flex flex-wrap items-center gap-6 text-sm">
                        <span class="font-mono font-semibold">
                            @if($metric['availability'] !== 'available')
                                <span class="status-badge status-unavailable">{{ __('ui.reports.metric_unavailable') }}</span>
                            @elseif($metricKey === 'gross_margin_percentage')
                                <bdi dir="ltr">{{ $metric['value'] !== null ? number_format((float) $metric['value'], 2).'%' : '—' }}</bdi>
                            @else
                                <bdi dir="ltr">{{ $metric['value'] !== null ? number_format((float) $metric['value'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                            @endif
                        </span>
                        @if($hasComparison)
                            @isset($metric['comparison'])
                                <span class="text-slate-500">
                                    {{ __('ui.reports.comparison_period') }}:
                                    @if($metricKey === 'gross_margin_percentage')
                                        <bdi dir="ltr">{{ $metric['comparison']['value'] !== null ? number_format((float) $metric['comparison']['value'], 2).'%' : '—' }}</bdi>
                                    @else
                                        <bdi dir="ltr">{{ $metric['comparison']['value'] !== null ? number_format((float) $metric['comparison']['value'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                                    @endif
                                </span>
                                <span class="text-slate-500">
                                    {{ __('ui.reports.comparison_change') }}:
                                    @if($metricKey === 'gross_margin_percentage')
                                        <bdi dir="ltr">{{ $metric['comparison']['difference'] !== null ? number_format((float) $metric['comparison']['difference'], 2).'%' : '—' }}</bdi>
                                    @else
                                        <bdi dir="ltr">{{ $metric['comparison']['difference'] !== null ? number_format((float) $metric['comparison']['difference'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                                    @endif
                                </span>
                                <span class="text-slate-500">
                                    {{ __('ui.reports.comparison_pct') }}:
                                    <bdi dir="ltr">{{ $metric['comparison']['percentage_change'] !== null ? number_format((float) $metric['comparison']['percentage_change'], 2).'%' : '—' }}</bdi>
                                </span>
                            @endisset
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
@endsection
