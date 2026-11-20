@extends('layouts.inventory')

@section('title', __('ui.settings.nav_forex_accounts').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
</div>

@include('settings._subnav')

<div class="grid max-w-5xl gap-6 lg:grid-cols-2">

    {{-- Exchange Gain Account --}}
    <section class="panel p-5">
        <div class="mb-4">
            <h2 class="font-semibold text-slate-900">{{ __('ui.settings.forex_accounts.gain_heading') }}</h2>
            <p class="mt-0.5 text-sm text-slate-500">{{ __('ui.settings.forex_accounts.gain_intro') }}</p>
        </div>

        @if ($company->exchangeGainAccount)
            <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-sm">
                <p class="text-xs font-semibold text-teal-700">{{ __('ui.settings.forex_accounts.current_account') }}</p>
                <p class="font-medium"><bdi dir="ltr">{{ $company->exchangeGainAccount->code }}</bdi> · {{ $company->exchangeGainAccount->name }}</p>
                <p class="text-xs text-slate-500">
                    <bdi dir="ltr">{{ $company->exchangeGainAccount->currency }}</bdi> ·
                    {{ __('ui.cash_bank.account_types.'.$company->exchangeGainAccount->account_type) }}
                </p>
            </div>
        @else
            <span class="mb-4 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                {{ __('ui.settings.forex_accounts.not_configured') }}
            </span>
        @endif

        @if ($eligibleGain->isEmpty())
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('ui.settings.forex_accounts.no_eligible_gain', ['currency' => $company->base_currency]) }}
            </p>
        @else
            <form method="POST" action="{{ route('settings.forex-accounts.gain') }}">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1">
                        <label for="gain_account" class="form-label">{{ __('ui.settings.forex_accounts.select_account') }}</label>
                        <select id="gain_account" name="financial_account_id" class="form-control" required>
                            <option value="">{{ __('ui.inventory.select_option') }}</option>
                            @foreach ($eligibleGain as $account)
                                <option value="{{ $account->getKey() }}" @selected($company->exchange_gain_account_id == $account->getKey())>
                                    {{ $account->code }} · {{ $account->name }} ({{ $account->currency }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="button button-primary">{{ __('ui.settings.forex_accounts.save_gain') }}</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Exchange Loss Account --}}
    <section class="panel p-5">
        <div class="mb-4">
            <h2 class="font-semibold text-slate-900">{{ __('ui.settings.forex_accounts.loss_heading') }}</h2>
            <p class="mt-0.5 text-sm text-slate-500">{{ __('ui.settings.forex_accounts.loss_intro') }}</p>
        </div>

        @if ($company->exchangeLossAccount)
            <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-sm">
                <p class="text-xs font-semibold text-teal-700">{{ __('ui.settings.forex_accounts.current_account') }}</p>
                <p class="font-medium"><bdi dir="ltr">{{ $company->exchangeLossAccount->code }}</bdi> · {{ $company->exchangeLossAccount->name }}</p>
                <p class="text-xs text-slate-500">
                    <bdi dir="ltr">{{ $company->exchangeLossAccount->currency }}</bdi> ·
                    {{ __('ui.cash_bank.account_types.'.$company->exchangeLossAccount->account_type) }}
                </p>
            </div>
        @else
            <span class="mb-4 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                {{ __('ui.settings.forex_accounts.not_configured') }}
            </span>
        @endif

        @if ($eligibleLoss->isEmpty())
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('ui.settings.forex_accounts.no_eligible_loss', ['currency' => $company->base_currency]) }}
            </p>
        @else
            <form method="POST" action="{{ route('settings.forex-accounts.loss') }}">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1">
                        <label for="loss_account" class="form-label">{{ __('ui.settings.forex_accounts.select_account') }}</label>
                        <select id="loss_account" name="financial_account_id" class="form-control" required>
                            <option value="">{{ __('ui.inventory.select_option') }}</option>
                            @foreach ($eligibleLoss as $account)
                                <option value="{{ $account->getKey() }}" @selected($company->exchange_loss_account_id == $account->getKey())>
                                    {{ $account->code }} · {{ $account->name }} ({{ $account->currency }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="button button-primary">{{ __('ui.settings.forex_accounts.save_loss') }}</button>
                </div>
            </form>
        @endif
    </section>

</div>
@endsection
