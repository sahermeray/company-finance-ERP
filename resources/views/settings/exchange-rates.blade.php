@extends('layouts.inventory')

@section('title', __('ui.settings.nav_exchange_rates').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
</div>

@include('settings._subnav')

<div class="grid gap-6 max-w-5xl">

    {{-- Latest rates summary --}}
    <section class="panel p-5">
        <h2 class="mb-3 font-semibold text-slate-900">{{ __('ui.settings.exchange_rates.latest_heading') }}</h2>
        <p class="mb-4 text-sm text-slate-500">{{ __('ui.settings.exchange_rates.intro') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($foreignCurrencies as $fc)
                @php($latest = $latestRates[$fc] ?? null)
                <div class="rounded-xl border {{ $latest ? 'border-teal-200 bg-teal-50' : 'border-amber-200 bg-amber-50' }} px-4 py-3">
                    <p class="text-xs font-semibold {{ $latest ? 'text-teal-700' : 'text-amber-700' }}">
                        <bdi dir="ltr">1 {{ $fc }}</bdi> =
                    </p>
                    @if ($latest)
                        <p class="mt-0.5 text-lg font-bold {{ 'text-teal-900' }}">
                            <bdi dir="ltr">{{ rtrim(rtrim((string) $latest->rate, '0'), '.') }} {{ $company->base_currency }}</bdi>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ __('ui.settings.exchange_rates.effective_at') }}:
                            <bdi dir="ltr">{{ $latest->effective_at->format('Y-m-d H:i') }}</bdi>
                        </p>
                    @else
                        <p class="mt-0.5 text-sm font-medium text-amber-800">{{ __('ui.settings.exchange_rates.not_configured') }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Add new rate --}}
    <section class="panel p-5">
        <h2 class="mb-1 font-semibold text-slate-900">{{ __('ui.settings.exchange_rates.add_heading') }}</h2>
        <p class="mb-4 text-sm text-slate-500">{{ __('ui.settings.exchange_rates.add_intro') }}</p>

        <form method="POST" action="{{ route('settings.exchange-rates.store') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf

            <div>
                <label for="currency" class="form-label">{{ __('ui.settings.exchange_rates.currency') }}</label>
                <select id="currency" name="currency" class="form-control" dir="ltr" required>
                    <option value="">{{ __('ui.inventory.select_option') }}</option>
                    @foreach ($foreignCurrencies as $fc)
                        <option value="{{ $fc }}" @selected(old('currency') === $fc)>{{ $fc }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="rate" class="form-label">
                    {{ __('ui.settings.exchange_rates.rate') }}
                    <span class="ms-1 text-xs text-slate-500">({{ __('ui.settings.exchange_rates.rate_help', ['base' => $company->base_currency]) }})</span>
                </label>
                <input id="rate" name="rate" type="text" inputmode="decimal"
                    value="{{ old('rate') }}"
                    class="form-control" dir="ltr"
                    placeholder="1500.0000000000" required>
            </div>

            <div>
                <label for="effective_at" class="form-label">{{ __('ui.settings.exchange_rates.effective_at') }}</label>
                <input id="effective_at" name="effective_at" type="datetime-local"
                    value="{{ old('effective_at', now()->format('Y-m-d\TH:i')) }}"
                    class="form-control" dir="ltr" required>
            </div>

            <div class="flex items-end sm:col-span-3">
                <button type="submit" class="button button-primary">{{ __('ui.settings.exchange_rates.save') }}</button>
            </div>
        </form>
    </section>

    {{-- Filter + history --}}
    <section class="panel">
        <div class="border-b border-slate-100 p-4">
            <h2 class="mb-3 font-semibold text-slate-900">{{ __('ui.settings.exchange_rates.history_heading') }}</h2>
            <form method="GET" class="grid gap-3 sm:grid-cols-4">
                <div>
                    <label for="filter_currency" class="form-label">{{ __('ui.settings.exchange_rates.currency') }}</label>
                    <select id="filter_currency" name="currency" class="form-control" dir="ltr">
                        <option value="">{{ __('ui.settings.exchange_rates.filter_currency') }}</option>
                        @foreach ($foreignCurrencies as $fc)
                            <option value="{{ $fc }}" @selected(($filters['currency'] ?? '') === $fc)>{{ $fc }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from" class="form-label">{{ __('ui.settings.exchange_rates.filter_from') }}</label>
                    <input id="date_from" name="date_from" type="date"
                        value="{{ $filters['date_from'] ?? '' }}" class="form-control" dir="ltr">
                </div>
                <div>
                    <label for="date_to" class="form-label">{{ __('ui.settings.exchange_rates.filter_to') }}</label>
                    <input id="date_to" name="date_to" type="date"
                        value="{{ $filters['date_to'] ?? '' }}" class="form-control" dir="ltr">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="button button-primary">{{ __('ui.settings.exchange_rates.apply_filters') }}</button>
                    <a href="{{ route('settings.exchange-rates') }}" class="button button-secondary">{{ __('ui.settings.exchange_rates.clear_filters') }}</a>
                </div>
            </form>
        </div>

        @if ($rates->isEmpty())
            <p class="px-4 py-6 text-sm text-slate-500">{{ __('ui.settings.exchange_rates.no_rates') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-start text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('ui.settings.exchange_rates.currency') }}</th>
                            <th class="px-4 py-3">{{ __('ui.settings.exchange_rates.base_currency') }}</th>
                            <th class="px-4 py-3 text-end">{{ __('ui.settings.exchange_rates.rate') }}</th>
                            <th class="px-4 py-3">{{ __('ui.settings.exchange_rates.effective_at') }}</th>
                            <th class="px-4 py-3">{{ __('ui.settings.exchange_rates.source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rates as $rate)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium"><bdi dir="ltr">{{ $rate->currency }}</bdi></td>
                                <td class="px-4 py-3 text-slate-600"><bdi dir="ltr">{{ $rate->base_currency }}</bdi></td>
                                <td class="px-4 py-3 text-end font-mono"><bdi dir="ltr">{{ rtrim(rtrim((string) $rate->rate, '0'), '.') }}</bdi></td>
                                <td class="px-4 py-3 text-slate-600"><bdi dir="ltr">{{ $rate->effective_at->format('Y-m-d H:i') }}</bdi></td>
                                <td class="px-4 py-3 text-slate-500">
                                    {{ $rate->source === 'manual' ? __('ui.settings.exchange_rates.source_manual') : $rate->source }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rates->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $rates->links() }}
                </div>
            @endif
        @endif
    </section>

</div>
@endsection
