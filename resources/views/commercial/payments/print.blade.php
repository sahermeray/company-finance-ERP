@extends('layouts.app')

@section('title', __('ui.payments.print_document').' · '.$payment->payment_number)

@section('content')
<main class="mx-auto max-w-4xl p-4 sm:p-8">
    <div class="document-print-controls mb-5 flex flex-wrap justify-between gap-3"><a href="{{ route('payments.show', $payment) }}" class="button button-secondary">{{ __('ui.parties.back') }}</a><button type="button" class="button button-primary" data-print-document>{{ __('ui.payments.print_document') }}</button></div>
    <article class="document-paper border border-slate-200 bg-white p-6 sm:p-10">
        <header class="flex flex-wrap justify-between gap-5 border-b border-slate-200 pb-5"><div><p class="text-sm font-semibold text-teal-700">{{ __('ui.app_name') }}</p><h1 class="mt-2 text-xl font-bold">{{ $company->name }}</h1></div><div class="text-start sm:text-end"><h2 class="text-xl font-semibold">{{ __('ui.payments.types.'.strtolower($payment->direction)) }}</h2><p class="mt-2"><bdi dir="ltr">{{ $payment->payment_number }}</bdi></p><p class="text-sm"><bdi dir="ltr">{{ $payment->paid_at?->format('Y-m-d') }}</bdi></p></div></header>
        <dl class="grid gap-5 py-6 sm:grid-cols-2"><div><dt class="form-label">{{ __('ui.parties.party') }}</dt><dd class="font-semibold">{{ $payment->party?->name ?? '—' }}</dd></div><div><dt class="form-label">{{ __('ui.payments.source_document') }}</dt><dd>@if ($payment->payable instanceof \App\Models\Revenue)<bdi dir="ltr">{{ $payment->payable->revenue_number }}</bdi>@elseif ($payment->payable instanceof \App\Models\Purchase)<bdi dir="ltr">{{ $payment->payable->purchase_number }}</bdi>@else—@endif</dd></div><div><dt class="form-label">{{ __('ui.payments.financial_account') }}</dt><dd>{{ $payment->financialAccount?->name ?? '—' }}</dd></div><div><dt class="form-label">{{ __('ui.payments.reference') }}</dt><dd><bdi dir="auto">{{ $payment->reference ?: '—' }}</bdi></dd></div><div><dt class="form-label">{{ __('ui.commercial.status') }}</dt><dd>{{ __('ui.payments.statuses.'.$payment->status) }}</dd></div></dl>
        <p class="border-y border-slate-200 py-5 text-lg font-semibold">{{ __('ui.commercial.amount') }}: <bdi dir="ltr">{{ number_format((float) $payment->amount, 4) }} {{ $payment->currency }}</bdi></p>
        @if ($payment->reversal_of_payment_id || $payment->reversed_by_payment_id)<p class="mt-4 text-sm">{{ __('ui.payments.reversal_of') }}: <bdi dir="ltr">{{ $payment->reversalOf?->payment_number ?? $payment->reversedBy?->payment_number }}</bdi></p>@endif
    </article>
</main>
@endsection