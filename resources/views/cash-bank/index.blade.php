@extends('layouts.inventory')

@section('title', __('ui.cash_bank.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.finance') }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('ui.cash_bank.title') }}</h1>
    </div>
    <a href="{{ route('cash-bank.create') }}" class="button button-primary">{{ __('ui.cash_bank.create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="form-label">{{ __('ui.inventory.search') }}</label>
            <input id="search" name="search" type="text" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="{{ __('ui.cash_bank.account_code') }} / {{ __('ui.cash_bank.account_name') }}">
        </div>
        <div>
            <label for="account_type" class="form-label">{{ __('ui.cash_bank.account_type') }}</label>
            <select id="account_type" name="account_type" class="form-control">
                <option value="">{{ __('ui.cash_bank.filter_all_types') }}</option>
                @foreach ($accountTypes as $type)
                    <option value="{{ $type }}" @selected(($filters['account_type'] ?? '') === $type)>{{ __('ui.cash_bank.account_types.'.$type) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="currency" class="form-label">{{ __('ui.cash_bank.currency') }}</label>
            <select id="currency" name="currency" class="form-control">
                <option value="">{{ __('ui.commercial.all_currencies') }}</option>
                @foreach ($currencies as $currency)
                    <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="form-label">{{ __('ui.inventory.status') }}</label>
            <select id="status" name="status" class="form-control">
                <option value="">{{ __('ui.cash_bank.filter_all_statuses') }}</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('ui.cash_bank.status_active') }}</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('ui.cash_bank.status_inactive') }}</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
            <a href="{{ route('cash-bank.index') }}" class="button button-secondary">{{ __('ui.commercial.clear_filters') }}</a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.cash_bank.account_code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.cash_bank.account_name') }}</th>
                    <th class="px-4 py-3">{{ __('ui.cash_bank.account_type') }}</th>
                    <th class="px-4 py-3">{{ __('ui.cash_bank.currency') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('ui.cash_bank.balance') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($accounts as $account)
                    @php($depth = $depths[$account->getKey()] ?? 0)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">
                            @if($depth > 0)
                                <span style="margin-inline-start: {{ $depth * 1.25 }}rem; display: inline-block;" aria-hidden="true">
                                    @str_repeat('&#x2514;&#x2500; ', $depth)
                                </span>
                            @endif
                            <a class="font-medium text-teal-800 hover:underline" href="{{ route('cash-bank.show', $account) }}">
                                <bdi dir="ltr">{{ $account->code }}</bdi>
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            @if($depth > 0)
                                <span style="margin-inline-start: {{ $depth * 1.25 }}rem; display: inline-block;"></span>
                            @endif
                            <a class="font-medium text-slate-900 hover:text-teal-800 hover:underline" href="{{ route('cash-bank.show', $account) }}">
                                {{ $account->name }}
                            </a>
                            @if($account->parentAccount && $depth === 0)
                                <p class="text-xs text-slate-500">{{ $account->parentAccount->code }} · {{ $account->parentAccount->name }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ __('ui.cash_bank.account_types.'.$account->account_type) }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $account->currency }}</bdi></td>
                        <td class="px-4 py-3 text-end">
                            <bdi dir="ltr">{{ $account->nativeCurrencyBalance((string) $account->currency) }} {{ $account->currency }}</bdi>
                        </td>
                        <td class="px-4 py-3">
                            <span class="status-badge {{ $account->is_active ? 'status-available' : 'status-unavailable' }}">
                                {{ $account->is_active ? __('ui.cash_bank.status_active') : __('ui.cash_bank.status_inactive') }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">{{ __('ui.cash_bank.no_accounts') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($accounts->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $accounts->links() }}
        </div>
    @endif
</section>
@endsection
