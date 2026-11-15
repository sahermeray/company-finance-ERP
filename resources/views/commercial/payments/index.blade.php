@extends('layouts.inventory')

@section('title', __('ui.payments.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.operations') }}</p><h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('ui.payments.title') }}</h1></div>
    <a href="{{ route('payments.create') }}" class="button button-primary">{{ __('ui.payments.create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><label for="date_from" class="form-label">{{ __('ui.commercial.date_from') }}</label><input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="form-control"></div>
        <div><label for="date_to" class="form-label">{{ __('ui.commercial.date_to') }}</label><input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="form-control"></div>
        <div><label for="party_id" class="form-label">{{ __('ui.parties.party') }}</label><select id="party_id" name="party_id" class="form-control"><option value="">{{ __('ui.parties.all_parties') }}</option>@foreach ($parties as $party)<option value="{{ $party->getKey() }}" @selected((string) ($filters['party_id'] ?? '') === (string) $party->getKey())>{{ $party->name }}</option>@endforeach</select></div>
        <div><label for="direction" class="form-label">{{ __('ui.payments.kind') }}</label><select id="direction" name="direction" class="form-control"><option value="">{{ __('ui.payments.all_types') }}</option>@foreach (['inbound', 'outbound'] as $direction)<option value="{{ $direction }}" @selected(($filters['direction'] ?? '') === $direction)>{{ __('ui.payments.types.'.$direction) }}</option>@endforeach</select></div>
        <div><label for="currency" class="form-label">{{ __('ui.commercial.currency') }}</label><select id="currency" name="currency" class="form-control"><option value="">{{ __('ui.commercial.all_currencies') }}</option>@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select></div>
        <div><label for="status" class="form-label">{{ __('ui.commercial.status') }}</label><select id="status" name="status" class="form-control"><option value="">{{ __('ui.payments.all_statuses') }}</option>@foreach (['draft', 'posted', 'reversed'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('ui.payments.statuses.'.$status) }}</option>@endforeach</select></div>
        <div class="flex items-end gap-2"><button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button><a href="{{ route('payments.index') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a></div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[60rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-4 py-3">{{ __('ui.payments.number') }}</th><th class="px-4 py-3">{{ __('ui.commercial.date') }}</th><th class="px-4 py-3">{{ __('ui.payments.kind') }}</th><th class="px-4 py-3">{{ __('ui.parties.party') }}</th><th class="px-4 py-3">{{ __('ui.payments.source_document') }}</th><th class="px-4 py-3">{{ __('ui.commercial.amount') }}</th><th class="px-4 py-3">{{ __('ui.commercial.status') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $payment)
                    <tr>
                        <td class="px-4 py-3 font-medium"><a class="text-teal-800 hover:underline" href="{{ route('payments.show', $payment) }}"><bdi dir="ltr">{{ $payment->payment_number ?: '#'.$payment->getKey() }}</bdi></a></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $payment->paid_at?->format('Y-m-d') ?? '—' }}</bdi></td>
                        <td class="px-4 py-3">{{ __('ui.payments.types.'.strtolower($payment->direction)) }}</td>
                        <td class="px-4 py-3">{{ $payment->party?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($payment->payable instanceof \App\Models\Revenue)
                                <a class="text-teal-800 hover:underline" href="{{ route('sales.show', $payment->payable) }}"><bdi dir="ltr">{{ $payment->payable->revenue_number }}</bdi></a>
                            @elseif ($payment->payable instanceof \App\Models\Purchase)
                                <a class="text-teal-800 hover:underline" href="{{ route('purchases.show', $payment->payable) }}"><bdi dir="ltr">{{ $payment->payable->purchase_number }}</bdi></a>
                            @elseif ($payment->reversalOf)
                                <a class="text-teal-800 hover:underline" href="{{ route('payments.show', $payment->reversalOf) }}"><bdi dir="ltr">{{ $payment->reversalOf->payment_number }}</bdi></a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ number_format((float) $payment->amount, 4) }} {{ $payment->currency }}</bdi></td>
                        <td class="px-4 py-3"><span class="status-badge {{ $payment->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.payments.statuses.'.$payment->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">{{ __('ui.payments.no_payments') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $payments->links() }}</div>
</section>
@endsection