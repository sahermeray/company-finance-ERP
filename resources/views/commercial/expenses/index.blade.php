@extends('layouts.inventory')

@section('title', __('ui.expenses.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.finance') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.expenses.title') }}</h1>
    </div>
    <a href="{{ route('expenses.create') }}" class="button button-primary">{{ __('ui.expenses.create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="form-label">{{ __('ui.expenses.search') }}</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" type="search">
        </div>
        <div>
            <label for="party_id" class="form-label">{{ __('ui.parties.party') }}</label>
            <select id="party_id" name="party_id" class="form-control">
                <option value="">{{ __('ui.commercial.all_parties') }}</option>
                @foreach ($parties as $party)
                    <option value="{{ $party->getKey() }}" @selected((string) ($filters['party_id'] ?? '') === (string) $party->getKey())>{{ $party->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="category" class="form-label">{{ __('ui.expenses.category') }}</label>
            <select id="category" name="category" class="form-control">
                <option value="">{{ __('ui.expenses.all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="account_id" class="form-label">{{ __('ui.commercial.expense_account') }}</label>
            <select id="account_id" name="account_id" class="form-control">
                <option value="">{{ __('ui.expenses.all') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->getKey() }}" @selected((string) ($filters['account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="form-label">{{ __('ui.commercial.status') }}</label>
            <select id="status" name="status" class="form-control">
                <option value="">{{ __('ui.commercial.all_statuses') }}</option>
                @foreach (['draft', 'approved', 'posted', 'reversed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('ui.expenses.statuses.'.$status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="currency" class="form-label">{{ __('ui.commercial.currency') }}</label>
            <select id="currency" name="currency" class="form-control">
                <option value="">{{ __('ui.commercial.all_currencies') }}</option>
                @foreach ($currencies as $currency)
                    <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
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
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
            <button type="submit" class="button button-primary">{{ __('ui.expenses.filter') }}</button>
            <a href="{{ route('expenses.index') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.expenses.number') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.date') }}</th>
                    <th class="px-4 py-3">{{ __('ui.parties.party') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.status') }}</th>
                    <th class="px-4 py-3">{{ __('ui.expenses.settlement') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.commercial.amount') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.commercial.base_amount') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.expenses.outstanding') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($expenses as $expense)
                    @php($id = (int) $expense->getKey())
                    @php($isPaid = $expense->payment_financial_account_id !== null)
                    @php($out = $expense->status === 'posted' && ! $isPaid ? ($outstanding[$id] ?? null) : null)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('expenses.show', $expense) }}" class="font-medium text-teal-800 hover:underline"><bdi dir="ltr">{{ $expense->expense_number }}</bdi></a>
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $expense->expense_date->format('Y-m-d') }}</bdi></td>
                        <td class="px-4 py-3">{{ $expense->party?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="status-badge {{ in_array($expense->status, ['posted'], true) ? 'status-available' : 'status-unavailable' }}">{{ __('ui.expenses.statuses.'.$expense->status) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($isPaid)
                                <span class="status-badge status-available">{{ __('ui.expenses.paid_now') }}</span>
                            @elseif ($expense->payable_account_id !== null)
                                <span class="status-badge status-unavailable">{{ __('ui.expenses.unpaid_later') }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end"><bdi dir="ltr">{{ $expense->amount }} {{ $expense->currency }}</bdi></td>
                        <td class="px-4 py-3 text-end text-xs text-slate-500"><bdi dir="ltr">{{ $expense->base_amount }} {{ $expense->base_currency }}</bdi></td>
                        <td class="px-4 py-3 text-end">
                            @if ($out !== null)
                                <bdi dir="ltr">{{ $out }} {{ $expense->currency }}</bdi>
                            @elseif ($expense->status === 'posted' && $isPaid)
                                <span class="text-xs text-slate-500">{{ __('ui.expenses.fully_settled') }}</span>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('ui.expenses.no_expenses') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 p-4">
        {{ $expenses->links() }}
    </div>
</section>
@endsection
