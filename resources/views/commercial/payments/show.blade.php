@extends('layouts.inventory')

@section('title', $payment->payment_number.' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><a href="{{ route('payments.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.parties.back') }}</a><div class="mt-2 flex flex-wrap items-center gap-3"><h1 class="text-2xl font-semibold text-slate-950"><bdi dir="ltr">{{ $payment->payment_number }}</bdi></h1><span class="status-badge {{ $payment->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.payments.statuses.'.$payment->status) }}</span></div></div>
    <div class="flex flex-wrap gap-2"><a href="{{ route('payments.print', $payment) }}" class="button button-secondary">{{ __('ui.payments.print_document') }}</a>@if ($payment->status === 'posted' && $payment->reversal_of_payment_id === null && $payment->reversed_by_payment_id === null)<form method="POST" action="{{ route('payments.reverse', $payment) }}" onsubmit="return confirm(@js(__('ui.payments.confirm_reversal')))">@csrf<button type="submit" class="button button-secondary">{{ __('ui.payments.reverse') }}</button></form>@endif</div>
</div>

@if (session('settlement'))
    <section class="panel mb-5 grid gap-3 p-4 sm:grid-cols-2"><div><p class="form-label">{{ __('ui.payments.outstanding_before') }}</p><p class="font-semibold"><bdi dir="ltr">{{ number_format((float) session('settlement.before'), 4) }} {{ session('settlement.currency') }}</bdi></p></div><div><p class="form-label">{{ __('ui.payments.outstanding_after') }}</p><p class="font-semibold"><bdi dir="ltr">{{ number_format((float) session('settlement.after'), 4) }} {{ session('settlement.currency') }}</bdi></p></div></section>
@endif

<section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
    <div><p class="form-label">{{ __('ui.payments.kind') }}</p><p>{{ __('ui.payments.types.'.strtolower($payment->direction)) }}</p></div>
    <div><p class="form-label">{{ __('ui.parties.party') }}</p><p>{{ $payment->party?->name ?? '—' }}</p></div>
    <div><p class="form-label">{{ __('ui.commercial.date') }}</p><p><bdi dir="ltr">{{ $payment->paid_at?->format('Y-m-d') ?? '—' }}</bdi></p></div>
    <div><p class="form-label">{{ __('ui.commercial.amount') }}</p><p class="font-semibold"><bdi dir="ltr">{{ number_format((float) $payment->amount, 4) }} {{ $payment->currency }}</bdi></p></div>
    <div><p class="form-label">{{ __('ui.payments.financial_account') }}</p><p>{{ $payment->financialAccount?->name ?? '—' }}</p></div>
    <div><p class="form-label">{{ __('ui.payments.reference') }}</p><p><bdi dir="auto">{{ $payment->reference ?: '—' }}</bdi></p></div>
    <div><p class="form-label">{{ __('ui.payments.source_document') }}</p><p>@if ($payment->payable instanceof \App\Models\Revenue)<a class="text-teal-800 hover:underline" href="{{ route('sales.show', $payment->payable) }}"><bdi dir="ltr">{{ $payment->payable->revenue_number }}</bdi></a>@elseif ($payment->payable instanceof \App\Models\Purchase)<a class="text-teal-800 hover:underline" href="{{ route('purchases.show', $payment->payable) }}"><bdi dir="ltr">{{ $payment->payable->purchase_number }}</bdi></a>@else—@endif</p></div>
    @if ($payment->reversal_of_payment_id)<div><p class="form-label">{{ __('ui.payments.reversal_of') }}</p><a class="text-teal-800 hover:underline" href="{{ route('payments.show', $payment->reversalOf) }}"><bdi dir="ltr">{{ $payment->reversalOf?->payment_number }}</bdi></a></div>@endif
    @if ($payment->reversed_by_payment_id)<div><p class="form-label">{{ __('ui.payments.reversed_by') }}</p><a class="text-teal-800 hover:underline" href="{{ route('payments.show', $payment->reversedBy) }}"><bdi dir="ltr">{{ $payment->reversedBy?->payment_number }}</bdi></a></div>@endif
</section>
@endsection