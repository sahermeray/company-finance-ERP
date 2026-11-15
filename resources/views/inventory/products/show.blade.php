@extends('layouts.inventory')

@section('title', $product->name.' · '.__('ui.inventory.product_details'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('inventory.products.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.products') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ $product->name }}</h1>
        <p class="mt-1 text-sm text-slate-500"><bdi dir="ltr">{{ $product->sku ?: '—' }}</bdi> · {{ $product->category?->name ?: '—' }} · {{ $product->unitOfMeasure?->name }}</p>
    </div>
    <a href="{{ route('inventory.products.edit', $product) }}" class="button button-secondary">{{ __('ui.inventory.edit') }}</a>
</div>

@if (! $product->tracks_inventory)
    <div class="mb-5 rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm text-slate-700">{{ __('ui.inventory.not_tracked_notice') }}</div>
@else
    @if (! $valuation)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('ui.inventory.valuation_unavailable') }}</div>
    @endif
    <section class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="{{ __('ui.inventory.stock_summary') }}">
        <article class="panel p-5">
            <p class="text-sm text-slate-500">{{ __('ui.inventory.quantity') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-950"><bdi dir="ltr">{{ $warehouseTotal }}</bdi> {{ $product->unitOfMeasure?->symbol }}</p>
            @if (bccomp($warehouseTotal, '0', 4) === 0)
                <p class="mt-1 text-xs text-slate-500">{{ __('ui.inventory.zero_stock') }}</p>
            @endif
        </article>
        <article class="panel p-5">
            <p class="text-sm text-slate-500">{{ __('ui.inventory.average_cost') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-950">
                <bdi dir="ltr">{{ $valuation['average_unit_cost'] ?? '—' }} {{ $company->base_currency }}</bdi>
            </p>
        </article>
        <article class="panel p-5">
            <p class="text-sm text-slate-500">{{ __('ui.inventory.inventory_value') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-950"><bdi dir="ltr">{{ $valuation['inventory_value'] ?? '—' }} {{ $company->base_currency }}</bdi></p>
        </article>
    </section>

    <section class="panel mb-5">
        <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.warehouse_quantities') }}</h2></div>
        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($warehouses as $warehouse)
                <a href="{{ route('inventory.warehouses.show', $warehouse) }}" class="rounded-xl border border-slate-200 p-4 text-slate-800 hover:border-teal-500">
                    <span class="block font-medium">{{ $warehouse->name }}</span>
                    <span class="mt-2 block text-sm text-slate-500"><bdi dir="ltr">{{ $warehouseQuantities[$warehouse->getKey()] }}</bdi> {{ $product->unitOfMeasure?->symbol }}</span>
                </a>
            @empty
                <p class="text-sm text-slate-500">{{ __('ui.inventory.no_warehouses') }}</p>
            @endforelse
        </div>
    </section>
@endif

<section class="panel">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.movement_history') }}</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[56rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.date') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.movement_type') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.warehouse') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.direction') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.quantity') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.source_document') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.reference') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($movements as $movement)
                    @php
                        $source = $movement->sourceable;
                        $sourceName = data_get($source, 'purchase_number')
                            ?? data_get($source, 'revenue_number')
                            ?? data_get($source, 'reference')
                            ?? ($source ? class_basename($source).' #'.$source->getKey() : '—');
                    @endphp
                    <tr>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $movement->occurred_at?->format('Y-m-d H:i') }}</bdi></td>
                        <td class="px-4 py-3">{{ __('ui.inventory.movement_types.'.$movement->movement_type) }}</td>
                        <td class="px-4 py-3">{{ $movement->warehouse?->name }}</td>
                        <td class="px-4 py-3">{{ $movement->direction === 'in' ? __('ui.inventory.incoming') : __('ui.inventory.outgoing') }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $movement->quantity }} {{ $product->unitOfMeasure?->symbol }}</bdi></td>
                        <td class="px-4 py-3">{{ $sourceName }}</td>
                        <td class="px-4 py-3">{{ $movement->reference ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_movements') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $movements->links() }}</div>
</section>
@endsection
