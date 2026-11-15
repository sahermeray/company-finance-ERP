@extends('layouts.inventory')

@section('title', __('ui.inventory.products_title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.inventory.overview') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.products_title') }}</h1>
    </div>
    <a href="{{ route('inventory.products.create') }}" class="button button-primary">{{ __('ui.inventory.product_create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="form-label">{{ __('ui.inventory.search') }}</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" type="search">
        </div>
        <div>
            <label for="category" class="form-label">{{ __('ui.inventory.category') }}</label>
            <select id="category" name="category" class="form-control">
                <option value="">{{ __('ui.inventory.filter_category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->getKey() }}" @selected((string) ($filters['category'] ?? '') === (string) $category->getKey())>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tracking" class="form-label">{{ __('ui.inventory.tracking') }}</label>
            <select id="tracking" name="tracking" class="form-control">
                <option value="">{{ __('ui.inventory.filter_tracking') }}</option>
                <option value="tracked" @selected(($filters['tracking'] ?? '') === 'tracked')>{{ __('ui.inventory.tracked') }}</option>
                <option value="untracked" @selected(($filters['tracking'] ?? '') === 'untracked')>{{ __('ui.inventory.untracked') }}</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
            <a href="{{ route('inventory.products.index') }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.product') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.category') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.unit') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.tracking') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.status') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('ui.inventory.edit') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr class="text-slate-700">
                        <td class="px-4 py-3 font-medium">
                            <a class="text-teal-800 hover:underline" href="{{ route('inventory.products.show', $product) }}">{{ $product->name }}</a>
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $product->sku ?: '—' }}</bdi></td>
                        <td class="px-4 py-3">{{ $product->category?->name ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $product->unitOfMeasure?->name }}</td>
                        <td class="px-4 py-3">
                            <span class="status-badge {{ $product->tracks_inventory ? 'status-available' : 'status-unavailable' }}">
                                {{ $product->tracks_inventory ? __('ui.inventory.tracked') : __('ui.inventory.untracked') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ $product->is_active ? __('ui.inventory.active') : __('ui.inventory.inactive') }}</td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('inventory.products.edit', $product) }}" class="button button-subtle">{{ __('ui.inventory.edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_products') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $products->links() }}</div>
</section>
@endsection
