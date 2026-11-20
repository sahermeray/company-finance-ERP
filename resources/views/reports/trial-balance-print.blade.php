@extends('layouts.app')

@section('title', __('ui.reports.trial_balance').' · '.__('ui.app_name'))

@section('content')
<main class="mx-auto max-w-5xl p-4 sm:p-8">
    <div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3">
        <a href="{{ route('reports.trial-balance', request()->query()) }}" class="button button-secondary">{{ __('ui.reports.back_to_reports') }}</a>
        <button type="button" class="button button-primary" data-print-document>{{ __('ui.reports.print') }}</button>
    </div>
    <article class="document-paper border border-slate-200 bg-white p-5 sm:p-10">
        <header class="flex flex-wrap justify-between gap-5 border-b border-slate-200 pb-5">
            <div>
                <p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p>
                <h1 class="mt-2 text-xl font-bold">{{ $activeCompany->name }}</h1>
            </div>
            <div class="text-start sm:text-end">
                <h2 class="text-xl font-semibold">{{ __('ui.reports.trial_balance') }}</h2>
                <p class="mt-2 text-sm"><bdi dir="ltr">{{ $filters['date_from'] }}</bdi> — <bdi dir="ltr">{{ $filters['date_to'] }}</bdi></p>
            </div>
        </header>

        @if($report['status'] !== 'complete')
            <div class="my-4 rounded bg-amber-50 p-3 text-sm text-amber-800">
                {{ __('ui.reports.incomplete_data_warning') }}
            </div>
        @endif

        <div class="overflow-x-auto mt-4">
            <table class="w-full min-w-[48rem] text-start text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs font-semibold">
                    <tr>
                        <th class="px-3 py-2">{{ __('ui.reports.col_account_code') }}</th>
                        <th class="px-3 py-2">{{ __('ui.reports.col_account_name') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_opening_debit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_opening_credit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_period_debit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_period_credit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_closing_debit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('ui.reports.col_closing_credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($report['accounts'] as $account)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs"><bdi dir="ltr">{{ $account['code'] }}</bdi></td>
                            <td class="px-3 py-2">{{ $account['name'] }}</td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['opening']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['opening']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['period']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['period']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['closing']['debit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                            <td class="px-3 py-2 text-end font-mono text-xs">
                                <bdi dir="ltr">{{ number_format((float) $account['closing']['credit'], 4) }} {{ $report['base_currency'] }}</bdi>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-sm text-slate-500">
                                {{ __('ui.reports.no_accounts') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-semibold text-xs">
                    <tr>
                        <td class="px-3 py-2" colspan="2">{{ __('ui.reports.totals') }}</td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['opening']['debit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['opening']['credit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['period']['debit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['period']['credit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['closing']['debit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                        <td class="px-3 py-2 text-end font-mono"><bdi dir="ltr">{{ number_format((float) $report['totals']['closing']['credit'], 4) }} {{ $report['base_currency'] }}</bdi></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </article>
</main>
@endsection
