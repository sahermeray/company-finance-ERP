@extends('layouts.app')

@section('title', __('ui.cash_bank.print_statement').' · '.__('ui.app_name'))

@section('content')
<div class="mx-auto max-w-4xl p-8">
    <div class="mb-6 flex items-start justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">{{ $company->name }}</p>
            <h1 class="mt-1 text-xl font-bold text-slate-950">{{ __('ui.cash_bank.print_statement') }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                <bdi dir="ltr">{{ $account->code }}</bdi> · {{ $account->name }}
            </p>
        </div>
        <div class="text-end text-xs text-slate-500">
            @if ($filters['date_from'] ?? null)
                <p>{{ __('ui.commercial.date_from') }}: <bdi dir="ltr">{{ $filters['date_from'] }}</bdi></p>
            @endif
            @if ($filters['date_to'] ?? null)
                <p>{{ __('ui.commercial.date_to') }}: <bdi dir="ltr">{{ $filters['date_to'] }}</bdi></p>
            @endif
        </div>
    </div>

    <div class="mb-5 grid grid-cols-3 gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <div>
            <p class="text-xs text-slate-500">{{ __('ui.cash_bank.account_type') }}</p>
            <p class="font-medium">{{ __('ui.cash_bank.account_types.'.$account->account_type) }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">{{ __('ui.cash_bank.currency') }}</p>
            <p class="font-medium"><bdi dir="ltr">{{ $account->currency }}</bdi></p>
        </div>
        <div>
            <p class="text-xs text-slate-500">{{ __('ui.cash_bank.balance') }}</p>
            <p class="font-semibold"><bdi dir="ltr">{{ $balance }} {{ $account->currency }}</bdi></p>
        </div>
    </div>

    <table class="w-full text-start text-sm">
        <thead class="border-b border-slate-300 text-xs font-semibold text-slate-500">
            <tr>
                <th class="pb-2 text-start">{{ __('ui.commercial.date') }}</th>
                <th class="pb-2 text-start">{{ __('ui.cash_bank.journal_reference') }}</th>
                <th class="pb-2 text-start">{{ __('ui.commercial.description') }}</th>
                <th class="pb-2 text-end">{{ __('ui.cash_bank.debit') }}</th>
                <th class="pb-2 text-end">{{ __('ui.cash_bank.credit') }}</th>
                <th class="pb-2 text-end">{{ __('ui.cash_bank.currency') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($lines as $line)
                <tr>
                    <td class="py-2"><bdi dir="ltr">{{ $line->journal?->entry_date?->format('Y-m-d') ?? '—' }}</bdi></td>
                    <td class="py-2 font-mono text-xs"><bdi dir="ltr">{{ $line->journal?->journal_number ?? '—' }}</bdi></td>
                    <td class="py-2">{{ $line->description ?? $line->journal?->description ?? '—' }}</td>
                    <td class="py-2 text-end">
                        @if (bccomp((string) ($line->debit ?? '0'), '0', 4) > 0)
                            <bdi dir="ltr">{{ $line->debit }}</bdi>
                        @else
                            —
                        @endif
                    </td>
                    <td class="py-2 text-end">
                        @if (bccomp((string) ($line->credit ?? '0'), '0', 4) > 0)
                            <bdi dir="ltr">{{ $line->credit }}</bdi>
                        @else
                            —
                        @endif
                    </td>
                    <td class="py-2 text-end"><bdi dir="ltr">{{ $line->currency ?? $account->currency }}</bdi></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="py-6 text-center text-slate-500">{{ __('ui.cash_bank.no_transactions') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-6 flex justify-end gap-6 border-t border-slate-300 pt-4 text-sm font-semibold">
        <span>{{ __('ui.cash_bank.balance') }}:</span>
        <bdi dir="ltr">{{ $balance }} {{ $account->currency }}</bdi>
    </div>
</div>
@endsection
