@extends('layouts.inventory')

@section('title', $journal->journal_number.' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('accounting.journals.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.accounting.back_to_journals') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">
                <bdi dir="ltr">{{ $journal->journal_number }}</bdi>
            </h1>
            <span class="status-badge {{ $journal->status === 'posted' ? 'status-available' : 'status-unavailable' }}">
                {{ __('ui.accounting.status_'.$journal->status) }}
            </span>
            @if($journal->reversal_of_journal_id !== null)
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ __('ui.accounting.is_reversal') }}</span>
            @endif
            @if($journal->reversed_by_journal_id !== null)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('ui.accounting.is_reversed') }}</span>
            @endif
        </div>
    </div>
</div>

<div class="grid gap-5">
    {{-- Journal Header --}}
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="form-label">{{ __('ui.accounting.journal_number') }}</p>
            <p class="font-medium"><bdi dir="ltr">{{ $journal->journal_number }}</bdi></p>
        </div>
        <div>
            <p class="form-label">{{ __('ui.commercial.date') }}</p>
            <p><bdi dir="ltr">{{ $journal->entry_date->format('Y-m-d') }}</bdi></p>
        </div>
        <div>
            <p class="form-label">{{ __('ui.commercial.status') }}</p>
            <p>{{ __('ui.accounting.status_'.$journal->status) }}</p>
        </div>
        @if($journal->posted_at)
            <div>
                <p class="form-label">{{ __('ui.accounting.posted_at') }}</p>
                <p><bdi dir="ltr">{{ $journal->posted_at->format('Y-m-d H:i') }}</bdi></p>
            </div>
        @endif
        @if($journal->creator)
            <div>
                <p class="form-label">{{ __('ui.accounting.created_by') }}</p>
                <p>{{ $journal->creator->name }}</p>
            </div>
        @endif
        @if($journal->description)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="form-label">{{ __('ui.commercial.description') }}</p>
                <p>{{ $journal->description }}</p>
            </div>
        @endif

        {{-- Source document --}}
        <div class="sm:col-span-2 lg:col-span-4">
            <p class="form-label">{{ __('ui.accounting.source_document') }}</p>
            @if($sourceInfo['label'])
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ $sourceInfo['label'] }}</span>
                    @if($sourceInfo['reference'])
                        @if($sourceInfo['route'])
                            <a href="{{ $sourceInfo['route'] }}" class="font-medium text-teal-800 hover:underline">
                                <bdi dir="ltr">{{ $sourceInfo['reference'] }}</bdi>
                            </a>
                        @else
                            <bdi dir="ltr" class="font-medium">{{ $sourceInfo['reference'] }}</bdi>
                        @endif
                    @else
                        <span class="text-slate-500">{{ __('ui.accounting.source_no_reference') }}</span>
                    @endif
                </p>
            @else
                <p class="text-sm text-slate-500">{{ __('ui.accounting.no_source') }}</p>
            @endif
        </div>
    </section>

    {{-- Journal Lines --}}
    <section class="panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <h2 class="font-semibold text-slate-900">{{ __('ui.accounting.journal_lines') }}</h2>
            @if($journal->lines->isNotEmpty())
                @if($isBalanced)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        {{ __('ui.accounting.balanced') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        {{ __('ui.accounting.unbalanced') }}
                    </span>
                @endif
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[60rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-3 py-3">{{ __('ui.accounting.line_no') }}</th>
                        <th class="px-3 py-3">{{ __('ui.accounting.account') }}</th>
                        <th class="px-3 py-3">{{ __('ui.commercial.description') }}</th>
                        <th class="px-3 py-3">{{ __('ui.parties.party') }}</th>
                        <th class="px-3 py-3">{{ __('ui.accounting.currency') }}</th>
                        <th class="px-3 py-3 text-end">{{ __('ui.accounting.debit') }}</th>
                        <th class="px-3 py-3 text-end">{{ __('ui.accounting.credit') }}</th>
                        <th class="px-3 py-3 text-end">{{ __('ui.accounting.base_debit') }} ({{ $baseCurrency }})</th>
                        <th class="px-3 py-3 text-end">{{ __('ui.accounting.base_credit') }} ({{ $baseCurrency }})</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($journal->lines as $line)
                        @php($hasDebit = bccomp((string)($line->debit ?? '0'), '0', 4) > 0)
                        <tr @class(['bg-teal-50/40' => $hasDebit])>
                            <td class="px-3 py-3 text-xs text-slate-500"><bdi dir="ltr">{{ $line->line_number }}</bdi></td>
                            <td class="px-3 py-3">
                                @if($line->financialAccount)
                                    <a href="{{ route('cash-bank.show', $line->financialAccount) }}" class="font-medium text-teal-800 hover:underline">
                                        <bdi dir="ltr">{{ $line->financialAccount->code }}</bdi>
                                    </a>
                                    <span class="block text-xs text-slate-500">{{ $line->financialAccount->name }}</span>
                                @else
                                    <span class="text-xs text-slate-400">{{ __('ui.accounting.account_missing') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 max-w-[14rem] text-xs text-slate-700">{{ $line->description ?: '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ $line->party?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs"><bdi dir="ltr">{{ $line->currency ?? '—' }}</bdi></td>
                            <td class="px-3 py-3 text-end font-mono text-xs">
                                @if($hasDebit)
                                    <bdi dir="ltr" class="font-semibold text-slate-900">{{ $line->debit }}</bdi>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-end font-mono text-xs">
                                @if(bccomp((string)($line->credit ?? '0'), '0', 4) > 0)
                                    <bdi dir="ltr" class="font-semibold text-slate-900">{{ $line->credit }}</bdi>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-end font-mono text-xs text-slate-600">
                                @if(bccomp((string)($line->base_debit ?? '0'), '0', 4) > 0)
                                    <bdi dir="ltr">{{ $line->base_debit }}</bdi>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-end font-mono text-xs text-slate-600">
                                @if(bccomp((string)($line->base_credit ?? '0'), '0', 4) > 0)
                                    <bdi dir="ltr">{{ $line->base_credit }}</bdi>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-6 text-center text-sm text-slate-500">{{ __('ui.accounting.no_lines') }}</td></tr>
                    @endforelse
                </tbody>
                @if($journal->lines->isNotEmpty())
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50 text-xs font-semibold text-slate-700">
                        <tr>
                            <td colspan="7" class="px-3 py-3 text-end">{{ __('ui.accounting.totals') }}</td>
                            <td class="px-3 py-3 text-end font-mono"><bdi dir="ltr">{{ $totalBaseDebit }}</bdi></td>
                            <td class="px-3 py-3 text-end font-mono"><bdi dir="ltr">{{ $totalBaseCredit }}</bdi></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- Reversal relationships --}}
    @if($journal->reversalOf || $journal->reversedBy)
        <section class="panel p-4">
            <h2 class="mb-3 font-semibold text-slate-900">{{ __('ui.accounting.reversal_relationships') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @if($journal->reversalOf)
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.accounting.reversal_of') }}</p>
                        <a href="{{ route('accounting.journals.show', $journal->reversalOf) }}" class="mt-1 block font-medium text-teal-800 hover:underline">
                            <bdi dir="ltr">{{ $journal->reversalOf->journal_number }}</bdi>
                        </a>
                        <p class="text-xs text-slate-500"><bdi dir="ltr">{{ $journal->reversalOf->entry_date->format('Y-m-d') }}</bdi></p>
                    </div>
                @endif
                @if($journal->reversedBy)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ __('ui.accounting.reversed_by') }}</p>
                        <a href="{{ route('accounting.journals.show', $journal->reversedBy) }}" class="mt-1 block font-medium text-teal-800 hover:underline">
                            <bdi dir="ltr">{{ $journal->reversedBy->journal_number }}</bdi>
                        </a>
                        <p class="text-xs text-slate-500"><bdi dir="ltr">{{ $journal->reversedBy->entry_date->format('Y-m-d') }}</bdi></p>
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
@endsection
