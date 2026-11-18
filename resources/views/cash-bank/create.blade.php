@extends('layouts.inventory')

@section('title', __('ui.cash_bank.create').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('cash-bank.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.cash_bank.back_to_list') }}</a>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.cash_bank.create') }}</h1>
</div>

<form method="POST" action="{{ route('cash-bank.store') }}" class="grid gap-5 max-w-2xl">
    @csrf

    <section class="panel p-4 grid gap-4 sm:grid-cols-2">
        <div>
            <label for="code" class="form-label">{{ __('ui.cash_bank.account_code') }} <span class="text-red-600">*</span></label>
            <input id="code" name="code" type="text" value="{{ old('code') }}" class="form-control" required>
        </div>

        <div>
            <label for="name" class="form-label">{{ __('ui.cash_bank.account_name') }} <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" class="form-control" required>
        </div>

        <div>
            <label for="account_type" class="form-label">{{ __('ui.cash_bank.account_type') }} <span class="text-red-600">*</span></label>
            <select id="account_type" name="account_type" class="form-control" required>
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($accountTypes as $type)
                    <option value="{{ $type }}" @selected(old('account_type') === $type)>{{ __('ui.cash_bank.account_types.'.$type) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="currency" class="form-label">{{ __('ui.cash_bank.currency') }} <span class="text-red-600">*</span></label>
            <select id="currency" name="currency" class="form-control" required>
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($currencies as $currency)
                    <option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
        </div>

        <div class="sm:col-span-2">
            <label for="description" class="form-label">{{ __('ui.cash_bank.description') }}</label>
            <textarea id="description" name="description" rows="2" class="form-control">{{ old('description') }}</textarea>
        </div>

        <div>
            <label for="parent_account_id" class="form-label">{{ __('ui.cash_bank.parent_account') }}</label>
            <select id="parent_account_id" name="parent_account_id" class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($parentAccounts as $parent)
                    <option value="{{ $parent->getKey() }}" @selected((string) old('parent_account_id') === (string) $parent->getKey())>
                        {{ $parent->code }} · {{ $parent->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="statement_classification" class="form-label">{{ __('ui.cash_bank.statement_classification') }}</label>
            <select id="statement_classification" name="statement_classification" class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($statementClassifications as $classification)
                    <option value="{{ $classification }}" @selected(old('statement_classification') === $classification)>
                        {{ __('ui.cash_bank.statement_classifications.'.$classification) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="sm:col-span-2 flex items-center gap-2">
            <input id="is_active" name="is_active" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-300" @checked(old('is_active', '1') === '1')>
            <label for="is_active" class="text-sm font-medium text-slate-700">{{ __('ui.cash_bank.is_active') }}</label>
        </div>
    </section>

    <div class="flex gap-3">
        <button type="submit" class="button button-primary">{{ __('ui.inventory.save') }}</button>
        <a href="{{ route('cash-bank.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
    </div>
</form>
@endsection
