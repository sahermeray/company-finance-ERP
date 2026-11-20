@extends('layouts.inventory')

@section('title', __('ui.reports.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.reports') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.reports.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.intro') }}</p>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('reports.index') }}" @class(['button', 'button-primary' => request()->routeIs('reports.index'), 'button-secondary' => ! request()->routeIs('reports.index')])>{{ __('ui.reports.title') }}</a>
        <a href="{{ route('reports.trial-balance') }}" @class(['button', 'button-primary' => request()->routeIs('reports.trial-balance'), 'button-secondary' => ! request()->routeIs('reports.trial-balance')])>{{ __('ui.reports.trial_balance') }}</a>
        <a href="{{ route('reports.general-ledger') }}" @class(['button', 'button-primary' => request()->routeIs('reports.general-ledger'), 'button-secondary' => ! request()->routeIs('reports.general-ledger')])>{{ __('ui.reports.general_ledger') }}</a>
        <a href="{{ route('reports.income-statement') }}" @class(['button', 'button-primary' => request()->routeIs('reports.income-statement'), 'button-secondary' => ! request()->routeIs('reports.income-statement')])>{{ __('ui.reports.income_statement') }}</a>
        <a href="{{ route('reports.balance-sheet') }}" @class(['button', 'button-primary' => request()->routeIs('reports.balance-sheet'), 'button-secondary' => ! request()->routeIs('reports.balance-sheet')])>{{ __('ui.reports.balance_sheet') }}</a>
        <a href="{{ route('reports.performance-trends') }}" @class(['button', 'button-primary' => request()->routeIs('reports.performance-trends'), 'button-secondary' => ! request()->routeIs('reports.performance-trends')])>{{ __('ui.reports.performance_trends') }}</a>
    </nav>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <a href="{{ route('reports.trial-balance') }}" class="rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm">
        <p class="font-semibold text-slate-900">{{ __('ui.reports.trial_balance') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.trial_balance_description') }}</p>
    </a>
    <a href="{{ route('reports.general-ledger') }}" class="rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm">
        <p class="font-semibold text-slate-900">{{ __('ui.reports.general_ledger') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.general_ledger_description') }}</p>
    </a>
    <a href="{{ route('reports.income-statement') }}" class="rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm">
        <p class="font-semibold text-slate-900">{{ __('ui.reports.income_statement') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.income_statement_description') }}</p>
    </a>
    <a href="{{ route('reports.balance-sheet') }}" class="rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm">
        <p class="font-semibold text-slate-900">{{ __('ui.reports.balance_sheet') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.balance_sheet_description') }}</p>
    </a>
    <a href="{{ route('reports.performance-trends') }}" class="rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300 hover:shadow-sm">
        <p class="font-semibold text-slate-900">{{ __('ui.reports.performance_trends') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.reports.performance_trends_description') }}</p>
    </a>
</div>
@endsection
