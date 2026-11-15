@extends('layouts.inventory')

@section('title', __('ui.commercial.create_purchase').' · '.__('ui.app_name'))

@section('page-content')
@php($defaultCurrency = old('currency', $baseCurrency))
<div class="mb-6">
    <a href="{{ route('purchases.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.commercial.back_to_purchases') }}</a>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.commercial.create_purchase') }}</h1>
    <p class="mt-1 text-sm text-slate-600">{{ __('ui.commercial.purchase_intro') }}</p>
</div>

<form method="POST" action="{{ route('purchases.store') }}" class="grid gap-5" data-commercial-form data-currency="{{ $defaultCurrency }}" data-currency-label="{{ __('ui.commercial.currency') }}">
    @csrf
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="party_id" class="form-label">{{ __('ui.commercial.supplier') }}</label>
            <select id="party_id" name="party_id" class="form-control" required>
                <option value="">{{ __('ui.commercial.select_party') }}</option>
                @foreach ($parties as $party)
                    <option value="{{ $party->getKey() }}" @selected((string) old('party_id') === (string) $party->getKey())>{{ $party->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="purchase_date" class="form-label">{{ __('ui.commercial.date') }}</label>
            <input id="purchase_date" name="purchase_date" value="{{ old('purchase_date', now()->toDateString()) }}" class="form-control" type="date" required>
        </div>
        <div>
            <label for="due_date" class="form-label">{{ __('ui.commercial.due_date') }}</label>
            <input id="due_date" name="due_date" value="{{ old('due_date') }}" class="form-control" type="date">
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
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="notes" class="form-label">{{ __('ui.commercial.notes') }}</label>
            <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
        </div>
    </section>

    <section class="panel p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('ui.commercial.items') }}</h2>
                <p class="text-sm text-slate-500">{{ __('ui.commercial.line_help') }}</p>
            </div>
            <button type="button" class="button button-secondary" data-add-commercial-row>{{ __('ui.commercial.add_line') }}</button>
        </div>
        <div class="grid gap-3" data-repeatable-list="lines">
            @foreach (old('lines', [[]]) as $index => $line)
                @include('commercial.purchases._line', ['index' => $index, 'line' => $line])
            @endforeach
        </div>
        <template data-repeatable-template="lines">
            @include('commercial.purchases._line', ['index' => '__INDEX__', 'line' => []])
        </template>
        <div class="mt-4 flex flex-wrap justify-end gap-6 border-t border-slate-100 pt-4 text-sm">
            <p>{{ __('ui.commercial.subtotal') }}: <bdi dir="ltr"><strong data-subtotal-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
            <p>{{ __('ui.commercial.tax_total') }}: <bdi dir="ltr"><strong data-tax-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
            <p class="text-base font-semibold">{{ __('ui.commercial.total') }}: <bdi dir="ltr"><strong data-total-preview>0.0000</strong> <span data-total-currency>{{ old('currency', $baseCurrency) }}</span></bdi></p>
        </div>
        <p class="mt-2 text-end text-xs text-slate-500">{{ __('ui.commercial.preview_note') }}</p>
    </section>

    <section class="panel p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('ui.commercial.landed_costs') }}</h2>
                <p class="text-sm text-slate-500">{{ __('ui.commercial.landed_cost_help') }}</p>
            </div>
            <button type="button" class="button button-secondary" data-add-commercial-row>{{ __('ui.commercial.add_landed_cost') }}</button>
        </div>
        <div class="grid gap-3" data-repeatable-list="landed_costs">
            @foreach (old('landed_costs', [[]]) as $index => $cost)
                @include('commercial.purchases._landed-cost', ['index' => $index, 'cost' => $cost, 'defaultCurrency' => $defaultCurrency])
            @endforeach
        </div>
        <template data-repeatable-template="landed_costs">
            @include('commercial.purchases._landed-cost', ['index' => '__INDEX__', 'cost' => [], 'defaultCurrency' => $defaultCurrency])
        </template>
    </section>

    <div class="flex flex-wrap justify-end gap-3">
        <a href="{{ route('purchases.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        <button type="submit" class="button button-primary">{{ __('ui.commercial.save_draft') }}</button>
    </div>
</form>
@endsection
