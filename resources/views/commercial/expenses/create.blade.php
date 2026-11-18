@extends('layouts.inventory')

@section('title', __('ui.expenses.create').' · '.__('ui.app_name'))

@section('page-content')
@php($defaultCurrency = old('currency', $baseCurrency))
<div class="mb-6">
    <a href="{{ route('expenses.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.expenses.back_to_list') }}</a>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.expenses.create') }}</h1>
    <p class="mt-1 text-sm text-slate-600">{{ __('ui.expenses.create_intro') }}</p>
</div>

@if ($categories->isNotEmpty())
    <datalist id="expense-categories">
        @foreach ($categories as $category)
            <option value="{{ $category }}">
        @endforeach
    </datalist>
@endif

<form method="POST" action="{{ route('expenses.store') }}" class="grid gap-5" data-commercial-form data-expense-form data-currency="{{ $defaultCurrency }}">
    @csrf
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="party_id" class="form-label">{{ __('ui.parties.party') }}</label>
            <select id="party_id" name="party_id" class="form-control">
                <option value="">{{ __('ui.commercial.select_party') }}</option>
                @foreach ($parties as $party)
                    <option value="{{ $party->getKey() }}" @selected((string) old('party_id') === (string) $party->getKey())>{{ $party->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="expense_date" class="form-label">{{ __('ui.commercial.date') }}</label>
            <input id="expense_date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" class="form-control" type="date" required>
        </div>
        <div>
            <label for="currency" class="form-label">{{ __('ui.commercial.currency') }}</label>
            <select id="currency" name="currency" class="form-control" required data-document-currency>
                @foreach ($currencies as $currency)
                    <option value="{{ $currency }}" @selected($defaultCurrency === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="exchange_rate" class="form-label">{{ __('ui.commercial.exchange_rate') }}</label>
            <input id="exchange_rate" name="exchange_rate" value="{{ old('exchange_rate') }}" class="form-control" type="number" min="0.0000000001" step="0.0000000001">
            <p class="mt-1 text-xs text-slate-500">{{ __('ui.commercial.exchange_rate_help', ['currency' => $baseCurrency]) }}</p>
        </div>
        <div class="sm:col-span-2">
            <label for="description" class="form-label">{{ __('ui.commercial.description') }}</label>
            <input id="description" name="description" value="{{ old('description') }}" class="form-control" type="text" maxlength="255" required>
        </div>
        <div class="sm:col-span-2 lg:col-span-4">
            <label for="notes" class="form-label">{{ __('ui.expenses.reference_notes') }}</label>
            <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
        </div>
    </section>

    <section class="panel p-4">
        <h2 class="mb-2 text-lg font-semibold text-slate-950">{{ __('ui.expenses.payment_classification') }}</h2>
        <p class="mb-4 text-sm text-slate-600">{{ __('ui.expenses.payment_classification_help') }}</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2 lg:col-span-4">
                <fieldset class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                        <input type="radio" name="payment_type" value="paid" class="text-teal-600" data-payment-type @checked(old('payment_type', 'paid') === 'paid')>
                        {{ __('ui.expenses.paid_now') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                        <input type="radio" name="payment_type" value="unpaid" class="text-teal-600" data-payment-type @checked(old('payment_type', 'paid') === 'unpaid')>
                        {{ __('ui.expenses.unpaid_later') }}
                    </label>
                </fieldset>
            </div>
            <div data-paid-account>
                <label class="form-label" for="payment_financial_account_id">{{ __('ui.expenses.paid_account') }}</label>
                <select id="payment_financial_account_id" name="payment_financial_account_id" class="form-control" data-currency-account data-paid-select>
                    <option value="">{{ __('ui.commercial.select_account') }}</option>
                    @foreach ($accounts['asset'] as $account)
                        <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) old('payment_financial_account_id') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">{{ __('ui.expenses.paid_account_help') }}</p>
            </div>
            <div data-unpaid-account hidden>
                <label class="form-label" for="payable_account_id">{{ __('ui.expenses.unpaid_account') }}</label>
                <select id="payable_account_id" name="payable_account_id" class="form-control" data-currency-account data-unpaid-select>
                    <option value="">{{ __('ui.commercial.select_account') }}</option>
                    @foreach ($accounts['liability'] as $account)
                        <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) old('payable_account_id') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">{{ __('ui.expenses.unpaid_account_help') }}</p>
            </div>
        </div>
    </section>

    <section class="panel p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('ui.commercial.items') }}</h2>
                <p class="text-sm text-slate-500">{{ __('ui.expenses.line_help') }}</p>
            </div>
            <button type="button" class="button button-secondary" data-add-commercial-row>{{ __('ui.commercial.add_line') }}</button>
        </div>
        <div class="grid gap-3" data-repeatable-list="lines">
            @foreach (old('lines', [[]]) as $index => $line)
                @include('commercial.expenses._line', ['index' => $index, 'line' => $line])
            @endforeach
        </div>
        <template data-repeatable-template="lines">
            @include('commercial.expenses._line', ['index' => '__INDEX__', 'line' => []])
        </template>
        <div class="mt-4 flex flex-wrap justify-end gap-6 border-t border-slate-100 pt-4 text-sm">
            <p>{{ __('ui.commercial.subtotal') }}: <bdi dir="ltr"><strong data-subtotal-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
            <p>{{ __('ui.commercial.tax_total') }}: <bdi dir="ltr"><strong data-tax-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
            <p class="text-base font-semibold">{{ __('ui.commercial.total') }}: <bdi dir="ltr"><strong data-total-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
        </div>
        <p class="mt-2 text-end text-xs text-slate-500">{{ __('ui.commercial.preview_note') }}</p>
    </section>

    <div class="flex flex-wrap justify-end gap-3">
        <a href="{{ route('expenses.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        <button type="submit" class="button button-primary">{{ __('ui.commercial.save_draft') }}</button>
    </div>
</form>
@endsection
