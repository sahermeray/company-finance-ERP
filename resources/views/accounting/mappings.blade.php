@extends('layouts.inventory')

@section('title', __('ui.accounting.mappings_title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.accounting') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.accounting.mappings_title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.accounting.mappings_intro') }}</p>
</div>

<nav class="mb-6 flex flex-wrap gap-2">
    <a href="{{ route('accounting.journals.index') }}" class="button button-secondary">{{ __('ui.accounting.nav_journals') }}</a>
    <a href="{{ route('cash-bank.index') }}" class="button button-secondary">{{ __('ui.accounting.nav_accounts') }}</a>
    <a href="{{ route('accounting.mappings') }}" @class(['button', 'button-primary' => request()->routeIs('accounting.mappings'), 'button-secondary' => ! request()->routeIs('accounting.mappings')])>{{ __('ui.accounting.nav_mappings') }}</a>
</nav>

<div class="grid gap-5">
    @foreach ($purposes as $purpose)
        @php($mapping = $mappings->get($purpose))
        @php($isLocked = $mapping !== null && $mapping->financialAccount !== null)
        @php($purposeLabel = __('ui.accounting.mapping_purposes.'.$purpose))

        <section class="panel p-4">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-900">{{ $purposeLabel }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500">{{ __('ui.accounting.mapping_purpose_help.'.$purpose) }}</p>
                </div>
                @if($mapping && $mapping->financialAccount)
                    <div class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm">
                        <p class="text-xs font-semibold text-teal-700">{{ __('ui.accounting.current_account') }}</p>
                        <p class="font-medium"><bdi dir="ltr">{{ $mapping->financialAccount->code }}</bdi> · {{ $mapping->financialAccount->name }}</p>
                        <p class="text-xs text-slate-500"><bdi dir="ltr">{{ $mapping->financialAccount->currency }}</bdi> · {{ __('ui.cash_bank.account_types.'.$mapping->financialAccount->account_type) }}</p>
                    </div>
                @else
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('ui.accounting.mapping_not_set') }}</span>
                @endif
            </div>

            @php($requiredType = $purpose === \App\Models\CompanyAccountMapping::SUPPLIER_PAYABLES ? 'liability' : 'asset')
            @php($eligible = $eligibleAccounts->where('account_type', $requiredType))

            @if($eligible->isEmpty())
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ __('ui.accounting.mapping_no_eligible', ['type' => __('ui.cash_bank.account_types.'.$requiredType), 'currency' => $baseCurrency]) }}
                </p>
            @else
                <form method="POST" action="{{ route('accounting.mappings.update', $purpose) }}">
                    @csrf
                    @method('PUT')
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1">
                            <label for="account_{{ $purpose }}" class="form-label">
                                {{ __('ui.accounting.select_account_for', ['purpose' => $purposeLabel]) }}
                                <span class="ms-1 text-xs text-slate-500">({{ __('ui.cash_bank.account_types.'.$requiredType) }} · {{ $baseCurrency }})</span>
                            </label>
                            <select id="account_{{ $purpose }}" name="financial_account_id" class="form-control" required>
                                <option value="">{{ __('ui.commercial.select_account') }}</option>
                                @foreach($eligible as $account)
                                    <option value="{{ $account->getKey() }}" @selected($mapping?->financial_account_id == $account->getKey())>
                                        {{ $account->code }} · {{ $account->name }} ({{ $account->currency }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="button button-primary">{{ __('ui.accounting.save_mapping') }}</button>
                    </div>
                </form>
                <p class="mt-3 text-xs text-slate-500">
                    <x-icon name="warning" size="14" class="inline-block align-middle text-amber-500" />
                    {{ __('ui.accounting.mapping_lock_note') }}
                </p>
            @endif
        </section>
    @endforeach
</div>
@endsection
