@extends('layouts.inventory')

@section('title', __('ui.settings.nav_financial').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
</div>

@include('settings._subnav')

<div class="grid max-w-4xl gap-6">
    {{-- Base Currency --}}
    <section class="panel p-5">
        <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ __('ui.settings.financial.currency_heading') }}</h2>
        <p class="mb-4 text-sm text-slate-500">{{ __('ui.settings.financial.currency_intro') }}</p>

        @if ($currencyLocked)
            {{-- Locked state: show current currency and explanation --}}
            <div class="mb-4 flex items-center gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3">
                <div>
                    <p class="text-xs font-semibold text-teal-700">{{ __('ui.settings.financial.current_currency') }}</p>
                    <p class="text-lg font-bold text-teal-900"><bdi dir="ltr">{{ $company->base_currency }}</bdi></p>
                </div>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-semibold">{{ __('ui.settings.financial.currency_locked') }}</p>
                <p class="mt-1">{{ __('ui.settings.financial.currency_locked_reason') }}</p>
            </div>
        @else
            {{-- Unlocked state: allow change with confirmation --}}
            <div class="mb-4 flex items-center gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3">
                <div>
                    <p class="text-xs font-semibold text-teal-700">{{ __('ui.settings.financial.current_currency') }}</p>
                    <p class="text-lg font-bold text-teal-900"><bdi dir="ltr">{{ $company->base_currency }}</bdi></p>
                </div>
            </div>

            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('ui.settings.financial.currency_warning') }}
            </div>

            <form method="POST" action="{{ route('settings.financial.update') }}" id="currency-form">
                @csrf
                @method('PUT')

                <div class="mb-4 max-w-xs">
                    <label for="base_currency" class="form-label">{{ __('ui.settings.financial.currency_heading') }}</label>
                    <select id="base_currency" name="base_currency" class="form-control" dir="ltr">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('base_currency', $company->base_currency) === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-4 flex items-start gap-2">
                    <input type="checkbox" id="confirm_change" name="confirm_change" value="1"
                        class="mt-1 h-4 w-4 rounded border-slate-300 text-teal-600"
                        @checked(old('confirm_change'))
                        data-enables="currency-submit">
                    <label for="confirm_change" class="text-sm text-slate-700 cursor-pointer">
                        {{ __('ui.settings.financial.confirm_label') }}
                    </label>
                </div>

                <button type="submit" id="currency-submit" class="button button-primary" disabled>
                    {{ __('ui.settings.financial.save_currency') }}
                </button>
            </form>

            <script>
                (function () {
                    var checkbox = document.getElementById('confirm_change');
                    var submit = document.getElementById('currency-submit');
                    if (checkbox && submit) {
                        function sync() { submit.disabled = !checkbox.checked; }
                        sync();
                        checkbox.addEventListener('change', sync);
                    }
                }());
            </script>
        @endif
    </section>
</div>
@endsection
