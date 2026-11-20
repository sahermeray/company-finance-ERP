@extends('layouts.app')

@section('title', __('ui.reports.balance_sheet').' · '.__('ui.app_name'))

@section('content')
<main class="mx-auto max-w-5xl p-4 sm:p-8">
    <div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ route('reports.balance-sheet', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <button type="button" class="button button-primary" data-print-document>{{ __('ui.reports.print') }}</button>
    </div>
    <article class="document-paper border border-slate-200 bg-white p-5 sm:p-10">
        <header class="flex flex-wrap justify-between gap-5 border-b border-slate-200 pb-5">
            <div>
                <p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p>
                <h1 class="mt-2 text-xl font-bold">{{ $activeCompany->name }}</h1>
            </div>
            <div class="text-start sm:text-end">
                <h2 class="text-xl font-semibold">{{ __('ui.reports.balance_sheet') }}</h2>
                <p class="mt-2 text-sm">{{ __('ui.reports.print_as_of') }}: <bdi dir="ltr">{{ $filters['as_of_date'] }}</bdi></p>
            </div>
        </header>

        @if($report['status'] !== 'balanced')
            <div class="my-4 rounded bg-amber-50 p-3 text-sm text-amber-800">
                {{ __('ui.reports.incomplete_data_warning') }}
            </div>
        @endif

        <div class="mt-4">
            <h3 class="mb-2 font-semibold text-slate-700">{{ __('ui.reports.section_assets') }}</h3>
            <table class="w-full text-sm mb-6">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report['assets']['accounts'] as $account)
                        <tr>
                            <td class="py-2 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                            <td class="py-2">{{ $account['name'] }}</td>
                            <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-slate-200 font-semibold">
                        <td class="py-2" colspan="2">{{ __('ui.reports.totals') }}</td>
                        <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $report['assets']['total'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="mb-2 font-semibold text-slate-700">{{ __('ui.reports.section_liabilities') }}</h3>
            <table class="w-full text-sm mb-6">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report['liabilities']['accounts'] as $account)
                        <tr>
                            <td class="py-2 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                            <td class="py-2">{{ $account['name'] }}</td>
                            <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-slate-200 font-semibold">
                        <td class="py-2" colspan="2">{{ __('ui.reports.totals') }}</td>
                        <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $report['liabilities']['total'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                    </tr>
                </tbody>
            </table>

            <h3 class="mb-2 font-semibold text-slate-700">{{ __('ui.reports.section_equity') }}</h3>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    @foreach ($report['equity']['accounts'] as $account)
                        <tr>
                            <td class="py-2 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                            <td class="py-2">{{ $account['name'] }}</td>
                            <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $account['balance'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold border-t-2 border-slate-200">
                        <td class="py-2" colspan="2">{{ __('ui.reports.totals') }}</td>
                        <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $report['equity']['total'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                    </tr>
                    <tr class="font-bold border-t-2 border-slate-300 bg-slate-50">
                        <td class="py-2" colspan="2">{{ __('ui.reports.section_liabilities') }} + {{ __('ui.reports.section_equity') }}</td>
                        <td class="py-2 text-end font-mono text-xs"><bdi dir="ltr">{{ number_format((float) $report['total_liabilities_and_equity'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </article>
</main>
@endsection
