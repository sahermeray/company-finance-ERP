@extends('layouts.inventory')

@section('title', __('ui.inventory.adjustment_create').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('inventory.adjustments.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.adjustments') }}</a>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.adjustment_create') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.adjustment_account_help') }}</p>
</div>
<form method="POST" action="{{ route('inventory.adjustments.store') }}" class="panel grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
    @csrf
    <div>
        <label for="product_id" class="form-label">{{ __('ui.inventory.product') }}</label>
        <select id="product_id" name="product_id" required class="form-control">
            <option value="">{{ __('ui.inventory.select_option') }}</option>
            @foreach ($products as $product)
                <option value="{{ $product->getKey() }}" @selected(old('product_id') == $product->getKey())>{{ $product->name }} ({{ $product->sku ?: '—' }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="warehouse_id" class="form-label">{{ __('ui.inventory.warehouse') }}</label>
        <select id="warehouse_id" name="warehouse_id" required class="form-control">
            <option value="">{{ __('ui.inventory.select_option') }}</option>
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->getKey() }}" @selected(old('warehouse_id') == $warehouse->getKey())>{{ $warehouse->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="adjustment_type" class="form-label">{{ __('ui.inventory.adjustment_type') }}</label>
        <select id="adjustment_type" name="adjustment_type" required class="form-control" data-adjustment-type>
            @foreach (['positive', 'negative', 'damaged', 'lost', 'unusable'] as $type)
                <option value="{{ $type }}" @selected(old('adjustment_type', 'positive') === $type)>{{ __('ui.inventory.'.$type) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="quantity" class="form-label">{{ __('ui.inventory.quantity') }}</label>
        <input id="quantity" name="quantity" type="number" step="0.0001" min="0.0001" max="99999999999999.9999" required value="{{ old('quantity') }}" class="form-control">
    </div>
    <div data-positive-cost>
        <label for="base_unit_cost" class="form-label">{{ __('ui.inventory.base_unit_cost') }} ({{ $baseCurrency }})</label>
        <input id="base_unit_cost" name="base_unit_cost" type="number" step="0.0000000001" min="0" max="99999999999999.9999999999" value="{{ old('base_unit_cost') }}" class="form-control" data-cost-input>
        <p class="mt-1 text-xs text-slate-500">{{ __('ui.inventory.unit_cost_help') }}</p>
    </div>
    <div>
        <label for="inventory_asset_account_id" class="form-label">{{ __('ui.inventory.inventory_asset_account') }}</label>
        <select id="inventory_asset_account_id" name="inventory_asset_account_id" required class="form-control">
            <option value="">{{ __('ui.inventory.select_option') }}</option>
            @foreach ($assetAccounts as $account)
                <option value="{{ $account->getKey() }}" @selected(old('inventory_asset_account_id') == $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="offset_account_id" class="form-label">{{ __('ui.inventory.offset_account') }}</label>
        <select id="offset_account_id" name="offset_account_id" required class="form-control" data-offset-account>
            <option value="">{{ __('ui.inventory.select_option') }}</option>
            @foreach ($positiveAccounts as $account)
                <option value="{{ $account->getKey() }}" data-account-type="positive" @selected(old('offset_account_id') == $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
            @endforeach
            @foreach ($negativeAccounts as $account)
                <option value="{{ $account->getKey() }}" data-account-type="negative" @selected(old('offset_account_id') == $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
            @endforeach
        </select>
    </div>
    @if ($assetAccounts->isEmpty() || ($positiveAccounts->isEmpty() && $negativeAccounts->isEmpty()))
        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2">{{ __('ui.inventory.no_accounts') }}</p>
    @endif
    <div>
        <label for="occurred_at" class="form-label">{{ __('ui.inventory.occurred_at') }}</label>
        <input id="occurred_at" name="occurred_at" type="datetime-local" required value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" class="form-control">
    </div>
    <div>
        <label for="reason" class="form-label">{{ __('ui.inventory.reason') }}</label>
        <input id="reason" name="reason" required maxlength="2000" value="{{ old('reason') }}" class="form-control">
    </div>
    <div class="flex gap-2 sm:col-span-2">
        <button type="submit" class="button button-primary">{{ __('ui.inventory.save') }}</button>
        <a href="{{ route('inventory.adjustments.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
    </div>
</form>
@endsection
