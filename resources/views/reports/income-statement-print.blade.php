@extends('layouts.app')

@section('title', __('ui.reports.income_statement').' · '.__('ui.app_name'))

@section('content')
<main class="mx-auto max-w-5xl p-4 sm:p-8">
    <div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ route('reports.income-statement', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <button type="button" class="button button-primary" data-print-document>{{ __('ui.reports.print') }}</button>
    </div>
    <article class="document-paper border border-slate-200 bg-white p-5 sm:p-10">
        <header class="flex flex-wrap justify-between gap-5 border-b border-slate-200 pb-5">
            <div>
                <p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p>
                <h1 class="mt-2 text-xl font-bold">{{ $activeCompany->name }}</h1>
            </div>
            <div class="text-start sm:text-end">
                <h2 class="text-xl font-semibold">{{ __('ui.reports.income_statement') }}</h2>
                <p class="mt-2 text-sm"><bdi dir="ltr">{{ $filters['date_from'] }}</bdi> — <bdi dir="ltr">{{ $filters['date_to'] }}</bdi></p>
            </div>
        </header>

        @if($report['status'] !== 'complete')
            <div class="my-4 rounded bg-amber-50 p-3 text-sm text-amber-800">
                {{ __('ui.reports.incomplete_data_warning') }}
            </div>
        @endif

        @php
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

        <dl class="mt-4 divide-y divide-slate-100">
            @foreach ($metrics as $metricKey => $translationKey)
                @php $metric = $report['metrics'][$metricKey]; @endphp
                <div class="flex items-center justify-between py-3">
                    <dt class="text-sm font-medium text-slate-700">{{ __('ui.reports.'.$translationKey) }}</dt>
                    <dd class="font-mono text-sm font-semibold">
                        @if($metric['availability'] !== 'available')
                            <span class="text-xs text-slate-500">{{ __('ui.reports.metric_unavailable') }}</span>
                        @elseif($metricKey === 'gross_margin_percentage')
                            <bdi dir="ltr">{{ $metric['value'] !== null ? number_format((float) $metric['value'], 2).'%' : '—' }}</bdi>
                        @else
                            <bdi dir="ltr">{{ $metric['value'] !== null ? number_format((float) $metric['value'], 4).' '.$report['base_currency'] : '—' }}</bdi>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </article>
</main>
@endsection
