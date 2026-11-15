@extends('layouts.inventory')

@section('title', __('ui.payments.create').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6"><a href="{{ route('payments.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.parties.back') }}</a><h1 class="mt-3 text-2xl font-semibold text-slate-950">{{ __('ui.payments.create') }}</h1><p class="mt-1 text-sm text-slate-500">{{ __('ui.payments.invoice_only_note') }}</p></div>

<section class="panel max-w-4xl">
    <form method="POST" action="{{ route('payments.store') }}" class="grid gap-5 p-5 sm:grid-cols-2" data-payment-form>
        @csrf
        <div>
            <label for="payment-direction" class="form-label">{{ __('ui.payments.kind') }}</label>
            <select id="payment-direction" name="direction" class="form-control" required data-payment-direction>
                <option value="inbound">{{ __('ui.payments.types.inbound') }}</option>
                <option value="outbound">{{ __('ui.payments.types.outbound') }}</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="payment-party">{{ __('ui.parties.party') }}</label>
            <input id="payment-party" class="form-control" value="" readonly data-payment-party-name>
            <input type="hidden" name="party_id" value="" data-payment-party-id>
        </div>
        <div class="sm:col-span-2" data-payment-invoices="inbound">
            <label for="customer-invoice" class="form-label">{{ __('ui.payments.customer_invoice') }}</label>
            <select id="customer-invoice" name="payable_id" class="form-control" required data-payment-invoice>
                <option value="">{{ __('ui.payments.select_invoice') }}</option>
                @foreach ($customers as $row)
                    <option value="{{ $row['invoice']->getKey() }}" data-party-id="{{ $row['invoice']->party_id }}" data-party-name="{{ $row['invoice']->party->name }}" data-currency="{{ $row['invoice']->currency }}" data-outstanding="{{ $row['outstanding'] }}">
                        {{ $row['invoice']->party->name }} · {{ $row['invoice']->revenue_number }} · {{ number_format((float) $row['outstanding'], 4) }} {{ $row['invoice']->currency }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2" data-payment-invoices="outbound" hidden>
            <label for="supplier-invoice" class="form-label">{{ __('ui.payments.supplier_invoice') }}</label>
            <select id="supplier-invoice" name="payable_id" class="form-control" required disabled data-payment-invoice>
                <option value="">{{ __('ui.payments.select_invoice') }}</option>
                @foreach ($suppliers as $row)
                    <option value="{{ $row['invoice']->getKey() }}" data-party-id="{{ $row['invoice']->party_id }}" data-party-name="{{ $row['invoice']->party->name }}" data-currency="{{ $row['invoice']->currency }}" data-outstanding="{{ $row['outstanding'] }}">
                        {{ $row['invoice']->party->name }} · {{ $row['invoice']->purchase_number }} · {{ number_format((float) $row['outstanding'], 4) }} {{ $row['invoice']->currency }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="amount" class="form-label">{{ __('ui.commercial.amount') }}</label>
            <input id="amount" name="amount" type="number" min="0.0001" step="0.0001" required class="form-control" inputmode="decimal">
        </div>
        <div>
            <label for="payment-currency" class="form-label">{{ __('ui.commercial.currency') }}</label>
            <select id="payment-currency" name="currency" class="form-control" required data-payment-currency>
                @foreach ($currencies as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="financial_account_id" class="form-label">{{ __('ui.payments.financial_account') }}</label>
            <select id="financial_account_id" name="financial_account_id" class="form-control" required data-payment-account>
                <option value="">{{ __('ui.commercial.select_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}">{{ $account->code }} · {{ $account->name }} · {{ $account->currency }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="paid_at" class="form-label">{{ __('ui.commercial.date') }}</label>
            <input id="paid_at" name="paid_at" type="date" required value="{{ now()->toDateString() }}" class="form-control">
        </div>
        <div>
            <label for="reference" class="form-label">{{ __('ui.payments.reference') }}</label>
            <input id="reference" name="reference" maxlength="255" class="form-control" dir="auto">
        </div>
        <div class="sm:col-span-2">
            <p class="form-label">{{ __('ui.payments.outstanding_before') }}</p>
            <p class="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800" data-payment-outstanding data-empty-label="{{ __('ui.payments.choose_invoice') }}">{{ __('ui.payments.choose_invoice') }}</p>
        </div>
        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.payments.save') }}</button>
            <a href="{{ route('payments.index') }}" class="button button-secondary">{{ __('ui.parties.cancel') }}</a>
        </div>
    </form>
</section>
@endsection