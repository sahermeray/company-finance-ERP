@extends('layouts.inventory')

@section('title', ($warehouse->exists ? __('ui.inventory.warehouse_edit') : __('ui.inventory.warehouse_create')).' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('inventory.warehouses.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.warehouses') }}</a>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $warehouse->exists ? __('ui.inventory.warehouse_edit') : __('ui.inventory.warehouse_create') }}</h1>
</div>
<section class="panel max-w-2xl">
    <form method="POST" action="{{ $warehouse->exists ? route('inventory.warehouses.update', $warehouse) : route('inventory.warehouses.store') }}" class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
        @csrf
        @if ($warehouse->exists)
            @method('PUT')
        @endif
        <div>
            <label for="name" class="form-label">{{ __('ui.inventory.name') }}</label>
            <input id="name" name="name" required maxlength="255" value="{{ old('name', $warehouse->name) }}" class="form-control">
        </div>
        <div>
            <label for="code" class="form-label">{{ __('ui.inventory.code') }}</label>
            <input id="code" name="code" maxlength="255" value="{{ old('code', $warehouse->code) }}" class="form-control" dir="auto">
        </div>
        <div class="sm:col-span-2">
            <label for="address" class="form-label">{{ __('ui.inventory.address') }}</label>
            <textarea id="address" name="address" rows="3" maxlength="5000" class="form-control">{{ old('address', $warehouse->address) }}</textarea>
        </div>
        <label class="inline-flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $warehouse->exists ? $warehouse->is_active : true))>
            {{ __('ui.inventory.active') }}
        </label>
        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.inventory.save') }}</button>
            <a href="{{ route('inventory.warehouses.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        </div>
    </form>
</section>
@endsection
