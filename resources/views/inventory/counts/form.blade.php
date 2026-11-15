@extends('layouts.inventory')

@section('title', __('ui.inventory.count_create').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('inventory.counts.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.counts') }}</a>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.count_create') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.count_account_help') }}</p>
</div>
<form method="POST" action="{{ route('inventory.counts.store') }}" class="space-y-5">
    @csrf
    <section class="panel grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
        <div>
            <label for="counted_at" class="form-label">{{ __('ui.inventory.counted_at') }}</label>
            <input id="counted_at" name="counted_at" type="datetime-local" required value="{{ old('counted_at', now()->format('Y-m-d\TH:i')) }}" class="form-control">
        </div>
        <div>
            <label for="description" class="form-label">{{ __('ui.inventory.reason') }}</label>
            <input id="description" name="description" required maxlength="1950" value="{{ old('description') }}" class="form-control">
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
            <label for="positive_variance_account_id" class="form-label">{{ __('ui.inventory.positive_variance_account') }}</label>
            <select id="positive_variance_account_id" name="positive_variance_account_id" required class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($positiveAccounts as $account)
                    <option value="{{ $account->getKey() }}" @selected(old('positive_variance_account_id') == $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="negative_variance_account_id" class="form-label">{{ __('ui.inventory.negative_variance_account') }}</label>
            <select id="negative_variance_account_id" name="negative_variance_account_id" required class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($negativeAccounts as $account)
                    <option value="{{ $account->getKey() }}" @selected(old('negative_variance_account_id') == $account->getKey())>{{ $account->code }} · {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        @if ($assetAccounts->isEmpty() || $positiveAccounts->isEmpty() || $negativeAccounts->isEmpty())
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2">{{ __('ui.inventory.no_accounts') }}</p>
        @endif
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2 class="font-semibold text-slate-950">{{ __('ui.inventory.count_lines') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('ui.inventory.count_cost_help') }}</p>
            </div>
            <button type="button" class="button button-secondary" data-add-count-line>{{ __('ui.inventory.add_line') }}</button>
        </div>
        <div class="space-y-3 p-4" data-count-lines>
            @php($oldLines = old('lines', [[]]))
            @foreach ($oldLines as $index => $line)
                <div class="grid grid-cols-1 items-end gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-2 xl:grid-cols-5" data-count-line>
                    <div>
                        <label for="product-{{ $index }}" class="form-label">{{ __('ui.inventory.product') }}</label>
                        <select id="product-{{ $index }}" name="lines[{{ $index }}][product_id]" required class="form-control">
                            <option value="">{{ __('ui.inventory.select_option') }}</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->getKey() }}" @selected(($line['product_id'] ?? '') == $product->getKey())>{{ $product->name }} ({{ $product->sku ?: '—' }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="warehouse-{{ $index }}" class="form-label">{{ __('ui.inventory.warehouse') }}</label>
                        <select id="warehouse-{{ $index }}" name="lines[{{ $index }}][warehouse_id]" required class="form-control">
                            <option value="">{{ __('ui.inventory.select_option') }}</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->getKey() }}" @selected(($line['warehouse_id'] ?? '') == $warehouse->getKey())>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="counted-{{ $index }}" class="form-label">{{ __('ui.inventory.counted_quantity') }}</label>
                        <input id="counted-{{ $index }}" name="lines[{{ $index }}][counted_quantity]" type="number" step="0.0001" min="0" required value="{{ $line['counted_quantity'] ?? '' }}" class="form-control">
                    </div>
                    <div>
                        <label for="cost-{{ $index }}" class="form-label">{{ __('ui.inventory.approved_unit_cost', ['currency' => $baseCurrency]) }}</label>
                        <input id="cost-{{ $index }}" name="lines[{{ $index }}][approved_unit_cost]" type="number" step="0.0000000001" min="0" value="{{ $line['approved_unit_cost'] ?? '' }}" class="form-control">
                    </div>
                    <button type="button" class="button button-secondary" data-remove-count-line>{{ __('ui.inventory.remove_line') }}</button>
                </div>
            @endforeach
        </div>
        <template data-count-line-template>
            <div class="grid grid-cols-1 items-end gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-2 xl:grid-cols-5" data-count-line>
                <div>
                    <label class="form-label" data-label="product">{{ __('ui.inventory.product') }}</label>
                    <select data-name="product_id" data-id="product" required class="form-control">
                        <option value="">{{ __('ui.inventory.select_option') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->getKey() }}">{{ $product->name }} ({{ $product->sku ?: '—' }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" data-label="warehouse">{{ __('ui.inventory.warehouse') }}</label>
                    <select data-name="warehouse_id" data-id="warehouse" required class="form-control">
                        <option value="">{{ __('ui.inventory.select_option') }}</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->getKey() }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" data-label="counted">{{ __('ui.inventory.counted_quantity') }}</label>
                    <input data-name="counted_quantity" data-id="counted" type="number" step="0.0001" min="0" required class="form-control">
                </div>
                <div>
                    <label class="form-label" data-label="cost">{{ __('ui.inventory.approved_unit_cost', ['currency' => $baseCurrency]) }}</label>
                    <input data-name="approved_unit_cost" data-id="cost" type="number" step="0.0000000001" min="0" class="form-control">
                </div>
                <button type="button" class="button button-secondary" data-remove-count-line>{{ __('ui.inventory.remove_line') }}</button>
            </div>
        </template>
    </section>
    <div class="flex gap-2">
        <button type="submit" class="button button-primary">{{ __('ui.inventory.record_count') }}</button>
        <a href="{{ route('inventory.counts.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
    </div>
</form>
@endsection
