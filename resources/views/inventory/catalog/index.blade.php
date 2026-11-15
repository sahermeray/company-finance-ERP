@extends('layouts.inventory')

@section('title', __('ui.inventory.catalog').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.catalog') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.catalog_intro') }}</p>
</div>

<div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
    <section class="panel">
        <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.categories') }}</h2></div>
        <form method="POST" action="{{ route('inventory.catalog.categories.store') }}" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-[1fr_1fr_auto]">
            @csrf
            <div>
                <label for="category-name" class="form-label">{{ __('ui.inventory.name') }}</label>
                <input id="category-name" name="name" required maxlength="255" class="form-control">
            </div>
            <div>
                <label for="category-code" class="form-label">{{ __('ui.inventory.code') }}</label>
                <input id="category-code" name="code" maxlength="255" class="form-control" dir="auto">
            </div>
            <button type="submit" class="button button-primary self-end">{{ __('ui.inventory.create') }}</button>
        </form>
        <div class="divide-y divide-slate-100">
            @forelse ($categories as $category)
                <form method="POST" action="{{ route('inventory.catalog.categories.update', $category) }}" class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    @method('PUT')
                    <label class="sr-only" for="category-name-{{ $category->getKey() }}">{{ __('ui.inventory.name') }}</label>
                    <input id="category-name-{{ $category->getKey() }}" name="name" required maxlength="255" value="{{ $category->name }}" class="form-control">
                    <label class="sr-only" for="category-code-{{ $category->getKey() }}">{{ __('ui.inventory.code') }}</label>
                    <input id="category-code-{{ $category->getKey() }}" name="code" maxlength="255" value="{{ $category->code }}" class="form-control" dir="auto">
                    <div class="flex items-center justify-between gap-2 sm:justify-end">
                        <span class="text-xs text-slate-500">{{ __('ui.inventory.products_count', ['count' => $category->products_count]) }}</span>
                        <button type="submit" class="button button-secondary">{{ __('ui.inventory.save') }}</button>
                    </div>
                </form>
            @empty
                <p class="p-5 text-sm text-slate-500">{{ __('ui.inventory.no_categories') }}</p>
            @endforelse
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.units') }}</h2></div>
        <form method="POST" action="{{ route('inventory.catalog.units.store') }}" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2">
            @csrf
            <div>
                <label for="unit-code" class="form-label">{{ __('ui.inventory.code') }}</label>
                <input id="unit-code" name="code" required maxlength="255" class="form-control" dir="auto">
            </div>
            <div>
                <label for="unit-name" class="form-label">{{ __('ui.inventory.name') }}</label>
                <input id="unit-name" name="name" required maxlength="255" class="form-control">
            </div>
            <div>
                <label for="unit-symbol" class="form-label">{{ __('ui.inventory.symbol') }}</label>
                <input id="unit-symbol" name="symbol" maxlength="20" class="form-control">
            </div>
            <div>
                <label for="unit-decimals" class="form-label">{{ __('ui.inventory.decimal_places') }}</label>
                <input id="unit-decimals" name="decimal_places" type="number" min="0" max="6" value="2" required class="form-control">
            </div>
            <button type="submit" class="button button-primary sm:col-span-2">{{ __('ui.inventory.create') }}</button>
        </form>
        <div class="divide-y divide-slate-100">
            @forelse ($units as $unit)
                <form method="POST" action="{{ route('inventory.catalog.units.update', $unit) }}" class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <label class="sr-only" for="unit-code-{{ $unit->getKey() }}">{{ __('ui.inventory.code') }}</label>
                    <input id="unit-code-{{ $unit->getKey() }}" name="code" required maxlength="255" value="{{ $unit->code }}" class="form-control" dir="auto">
                    <label class="sr-only" for="unit-name-{{ $unit->getKey() }}">{{ __('ui.inventory.name') }}</label>
                    <input id="unit-name-{{ $unit->getKey() }}" name="name" required maxlength="255" value="{{ $unit->name }}" class="form-control">
                    <label class="sr-only" for="unit-symbol-{{ $unit->getKey() }}">{{ __('ui.inventory.symbol') }}</label>
                    <input id="unit-symbol-{{ $unit->getKey() }}" name="symbol" maxlength="20" value="{{ $unit->symbol }}" class="form-control">
                    <label class="sr-only" for="unit-decimals-{{ $unit->getKey() }}">{{ __('ui.inventory.decimal_places') }}</label>
                    <input id="unit-decimals-{{ $unit->getKey() }}" name="decimal_places" type="number" min="0" max="6" value="{{ $unit->decimal_places }}" required class="form-control">
                    <div class="flex items-center justify-between gap-2 sm:col-span-2">
                        <span class="text-xs text-slate-500">{{ __('ui.inventory.products_count', ['count' => $unit->products_count]) }}</span>
                        <button type="submit" class="button button-secondary">{{ __('ui.inventory.save') }}</button>
                    </div>
                </form>
            @empty
                <p class="p-5 text-sm text-slate-500">{{ __('ui.inventory.no_units') }}</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
