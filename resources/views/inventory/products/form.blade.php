@extends('layouts.inventory')

@section('title', ($product->exists ? __('ui.inventory.product_edit') : __('ui.inventory.product_create')).' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('inventory.products.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.back') }}</a>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $product->exists ? __('ui.inventory.product_edit') : __('ui.inventory.product_create') }}</h1>
</div>

<section class="panel max-w-3xl">
    <form method="POST" action="{{ $product->exists ? route('inventory.products.update', $product) : route('inventory.products.store') }}" class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
        @csrf
        @if ($product->exists)
            @method('PUT')
        @endif
        <div>
            <label for="name" class="form-label">{{ __('ui.inventory.name') }}</label>
            <input id="name" name="name" required maxlength="255" value="{{ old('name', $product->name) }}" class="form-control">
        </div>
        <div>
            <label for="sku" class="form-label">{{ __('ui.inventory.code') }}</label>
            <input id="sku" name="sku" maxlength="255" value="{{ old('sku', $product->sku) }}" class="form-control" dir="auto">
        </div>
        <div>
            <label for="product_category_id" class="form-label">{{ __('ui.inventory.category') }}</label>
            <select id="product_category_id" name="product_category_id" class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->getKey() }}" @selected((string) old('product_category_id', $product->product_category_id) === (string) $category->getKey())>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="unit_of_measure_id" class="form-label">{{ __('ui.inventory.unit') }}</label>
            <select id="unit_of_measure_id" name="unit_of_measure_id" required class="form-control">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($units as $unit)
                    <option value="{{ $unit->getKey() }}" @selected((string) old('unit_of_measure_id', $product->unit_of_measure_id) === (string) $unit->getKey())>{{ $unit->name }}{{ $unit->symbol ? ' · '.$unit->symbol : '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="description" class="form-label">{{ __('ui.inventory.description') }}</label>
            <textarea id="description" name="description" rows="3" maxlength="10000" class="form-control">{{ old('description', $product->description) }}</textarea>
        </div>
        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="hidden" name="tracks_inventory" value="0">
                <input type="checkbox" name="tracks_inventory" value="1" @checked((bool) old('tracks_inventory', $product->exists ? $product->tracks_inventory : true))>
                {{ __('ui.inventory.tracked') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $product->exists ? $product->is_active : true))>
                {{ __('ui.inventory.active') }}
            </label>
        </div>
        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.inventory.save') }}</button>
            <a href="{{ route('inventory.products.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        </div>
    </form>
</section>
@endsection
