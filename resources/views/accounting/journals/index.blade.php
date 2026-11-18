@extends('layouts.inventory')

@section('title', __('ui.accounting.journals_title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.accounting') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.accounting.journals_title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.accounting.journals_intro') }}</p>
    </div>
    <nav class="flex flex-wrap gap-2">
        <a href="{{ route('accounting.journals.index') }}" @class(['button', 'button-primary' => request()->routeIs('accounting.journals.*'), 'button-secondary' => ! request()->routeIs('accounting.journals.*')])>{{ __('ui.accounting.nav_journals') }}</a>
        <a href="{{ route('cash-bank.index') }}" class="button button-secondary">{{ __('ui.accounting.nav_accounts') }}</a>
        <a href="{{ route('accounting.mappings') }}" class="button button-secondary">{{ __('ui.accounting.nav_mappings') }}</a>
    </nav>
</div>

<section class="panel">
    <form method="GET" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="form-label">{{ __('ui.accounting.search') }}</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" type="search" placeholder="{{ __('ui.accounting.search_placeholder') }}">
        </div>
        <div>
            <label for="status" class="form-label">{{ __('ui.commercial.status') }}</label>
            <select id="status" name="status" class="form-control">
                <option value="">{{ __('ui.commercial.all_statuses') }}</option>
                <option value="posted" @selected(($filters['status'] ?? '') === 'posted')>{{ __('ui.accounting.status_posted') }}</option>
                <option value="draft" @selected(($filters['status'] ?? '') === 'draft')>{{ __('ui.accounting.status_draft') }}</option>
            </select>
        </div>
        <div>
            <label for="source_type" class="form-label">{{ __('ui.accounting.source_type') }}</label>
            <select id="source_type" name="source_type" class="form-control">
                <option value="">{{ __('ui.expenses.all') }}</option>
                @foreach ($sourceTypes as $fqcn => $label)
                    <option value="{{ $fqcn }}" @selected(($filters['source_type'] ?? '') === $fqcn)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="date_from" class="form-label">{{ __('ui.commercial.date_from') }}</label>
            <input id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" type="date">
        </div>
        <div>
            <label for="date_to" class="form-label">{{ __('ui.commercial.date_to') }}</label>
            <input id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" type="date">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3">
            <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
            <a href="{{ route('accounting.journals.index') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[52rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.accounting.journal_number') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.date') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.description') }}</th>
                    <th class="px-4 py-3">{{ __('ui.accounting.source') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.status') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.accounting.debit') }} ({{ $baseCurrency }})</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.accounting.credit') }} ({{ $baseCurrency }})</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($journals as $journal)
                    @php($labelKey = \App\Http\Controllers\AccountingJournalController::resolveSourceLabel($journal->sourceable_type))
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('accounting.journals.show', $journal) }}" class="font-medium text-teal-800 hover:underline">
                                <bdi dir="ltr">{{ $journal->journal_number }}</bdi>
                            </a>
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $journal->entry_date->format('Y-m-d') }}</bdi></td>
                        <td class="px-4 py-3 max-w-xs truncate" title="{{ $journal->description }}">{{ $journal->description ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if($labelKey)
                                <span class="text-xs text-slate-600">{{ __('ui.accounting.source_types.'.$labelKey) }}</span>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="status-badge {{ $journal->status === 'posted' ? 'status-available' : 'status-unavailable' }}">
                                {{ __('ui.accounting.status_'.$journal->status) }}
                            </span>
                            @if($journal->reversal_of_journal_id !== null)
                                <span class="ms-1 text-xs text-slate-500">({{ __('ui.accounting.reversal') }})</span>
                            @endif
                            @if($journal->reversed_by_journal_id !== null)
                                <span class="ms-1 text-xs text-amber-600">({{ __('ui.accounting.reversed') }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            {{-- base_debit_total comes from withSum which uses SQL SUM on BCMath-stored decimals.
                                 Displayed for orientation only; the precise per-journal check is on the show page. --}}
                            <bdi dir="ltr">{{ bcadd((string) ($journal->base_debit_total ?? '0'), '0', 4) }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-end font-mono text-xs">
                            <bdi dir="ltr">{{ bcadd((string) ($journal->base_credit_total ?? '0'), '0', 4) }}</bdi>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">
                            {{ __('ui.accounting.no_journals') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($journals->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $journals->links() }}
        </div>
    @endif
</section>
@endsection
