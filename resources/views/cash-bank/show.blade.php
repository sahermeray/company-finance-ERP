@extends('layouts.inventory')

@section('title', $account->code.' · '.__('ui.cash_bank.details').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('cash-bank.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.cash_bank.back_to_list') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">
                <bdi dir="ltr">{{ $account->code }}</bdi> · {{ $account->name }}
            </h1>
            <span class="status-badge {{ $account->is_active ? 'status-available' : 'status-unavailable' }}">
                {{ $account->is_active ? __('ui.cash_bank.status_active') : __('ui.cash_bank.status_inactive') }}
            </span>
        </div>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('cash-bank.print', array_merge(['cashBank' => $account->getKey()], $filters)) }}" class="button button-secondary">{{ __('ui.cash_bank.print_statement') }}</a>
        <a href="{{ route('cash-bank.edit', $account) }}" class="button button-primary">{{ __('ui.cash_bank.edit') }}</a>
    </div>
</div>

<div class="grid gap-5">
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="form-label">{{ __('ui.cash_bank.account_code') }}</p>
            <p class="font-mono font-medium"><bdi dir="ltr">{{ $account->code }}</bdi></p>
        </div>
        <div>
            <p class="form-label">{{ __('ui.cash_bank.account_type') }}</p>
            <p>{{ __('ui.cash_bank.account_types.'.$account->account_type) }}</p>
        </div>
        <div>
            <p class="form-label">{{ __('ui.cash_bank.currency') }}</p>
            <p><bdi dir="ltr">{{ $account->currency }}</bdi></p>
        </div>
        <div>
            <p class="form-label">{{ __('ui.cash_bank.balance') }}</p>
            <p class="font-semibold"><bdi dir="ltr">{{ $balance }} {{ $account->currency }}</bdi></p>
        </div>
        @if ($account->statement_classification)
            <div>
                <p class="form-label">{{ __('ui.cash_bank.statement_classification') }}</p>
                <p>{{ __('ui.cash_bank.statement_classifications.'.$account->statement_classification) }}</p>
            </div>
        @endif
        @if ($account->parentAccount)
            <div>
                <p class="form-label">{{ __('ui.cash_bank.parent_account') }}</p>
                <p><a href="{{ route('cash-bank.show', $account->parentAccount) }}" class="text-teal-800 hover:underline">{{ $account->parentAccount->code }} · {{ $account->parentAccount->name }}</a></p>
            </div>
        @endif
        @if ($account->description)
            <div class="sm:col-span-2 lg:col-span-4">
                <p class="form-label">{{ __('ui.cash_bank.description') }}</p>
                <p class="whitespace-pre-line">{{ $account->description }}</p>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <h2 class="font-semibold text-slate-900">{{ __('ui.cash_bank.transaction_history') }}</h2>
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="cashBank" value="{{ $account->getKey() }}">
                <div>
                    <label for="date_from" class="form-label">{{ __('ui.commercial.date_from') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="form-control">
                </div>
                <div>
                    <label for="date_to" class="form-label">{{ __('ui.commercial.date_to') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="form-control">
                </div>
                <button type="submit" class="button button-secondary">{{ __('ui.actions.apply') }}</button>
                <a href="{{ route('cash-bank.show', $account) }}" class="button button-subtle">{{ __('ui.commercial.clear_filters') }}</a>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('ui.commercial.date') }}</th>
                        <th class="px-4 py-3">{{ __('ui.cash_bank.journal_reference') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.description') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('ui.cash_bank.debit') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('ui.cash_bank.credit') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('ui.cash_bank.currency') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($lines as $line)
                        <tr>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $line->journal?->entry_date?->format('Y-m-d') ?? '—' }}</bdi></td>
                            <td class="px-4 py-3 font-mono text-xs"><bdi dir="ltr">{{ $line->journal?->journal_number ?? '—' }}</bdi></td>
                            <td class="px-4 py-3">{{ $line->description ?? $line->journal?->description ?? '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                @if (bccomp((string) ($line->debit ?? '0'), '0', 4) > 0)
                                    <bdi dir="ltr">{{ $line->debit }}</bdi>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-end">
                                @if (bccomp((string) ($line->credit ?? '0'), '0', 4) > 0)
                                    <bdi dir="ltr">{{ $line->credit }}</bdi>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $line->currency ?? $account->currency }}</bdi></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">{{ __('ui.cash_bank.no_transactions') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($lines->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">
                {{ $lines->links() }}
            </div>
        @endif
    </section>
</div>
@endsection
