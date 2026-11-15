@extends('layouts.inventory')

@section('title', __('ui.commercial.purchases').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.operations') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.commercial.purchases') }}</h1>
    </div>
    <a href="{{ route('purchases.create') }}" class="button button-primary">{{ __('ui.commercial.create_purchase') }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="form-label">{{ __('ui.commercial.search_purchase') }}</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" type="search">
        </div>
        <div>
            <label for="party_id" class="form-label">{{ __('ui.commercial.supplier') }}</label>
            <select id="party_id" name="party_id" class="form-control">
                <option value="">{{ __('ui.commercial.all_parties') }}</option>
                @foreach ($parties as $party)
                    <option value="{{ $party->getKey() }}" @selected((string) ($filters['party_id'] ?? '') === (string) $party->getKey())>{{ $party->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="form-label">{{ __('ui.commercial.status') }}</label>
            <select id="status" name="status" class="form-control">
                <option value="">{{ __('ui.commercial.all_statuses') }}</option>
                @foreach (['draft', 'posted'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('ui.commercial.statuses.'.$status) }}</option>
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
        <div class="flex items-end gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
            <a href="{{ route('purchases.index') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[52rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.commercial.invoice_number') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.date') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.supplier') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.total') }}</th>
                    <th class="px-4 py-3">{{ __('ui.commercial.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($purchases as $purchase)
                    <tr class="text-slate-700">
                        <td class="px-4 py-3 font-medium"><a class="text-teal-800 hover:underline" href="{{ route('purchases.show', $purchase) }}"><bdi dir="ltr">{{ $purchase->purchase_number }}</bdi></a></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></td>
                        <td class="px-4 py-3">{{ $purchase->party->name }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $purchase->total }} {{ $purchase->currency }}</bdi></td>
                        <td class="px-4 py-3"><span class="status-badge {{ $purchase->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.commercial.statuses.'.$purchase->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('ui.commercial.no_purchases') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $purchases->links() }}</div>
</section>
@endsection
