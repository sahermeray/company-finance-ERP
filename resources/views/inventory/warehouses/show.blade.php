@extends('layouts.inventory')

@section('title', $warehouse->name.' · '.__('ui.inventory.warehouse_details'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('inventory.warehouses.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.warehouses') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ $warehouse->name }}</h1>
        <p class="mt-1 text-sm text-slate-500"><bdi dir="ltr">{{ $warehouse->code ?: '—' }}</bdi> · {{ $warehouse->address ?: '—' }}</p>
    </div>
    <a href="{{ route('inventory.warehouses.edit', $warehouse) }}" class="button button-secondary">{{ __('ui.inventory.edit') }}</a>
</div>
<section class="panel">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.stock_summary') }}</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[36rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.product') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.quantity') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @php($hasStock = false)
                @foreach ($stockRows as $row)
                    @if (bccomp($row['quantity'], '0', 4) !== 0)
                        @php($hasStock = true)
                        <tr>
                            <td class="px-4 py-3 font-medium"><a href="{{ route('inventory.products.show', $row['product']) }}" class="text-teal-800 hover:underline">{{ $row['product']->name }}</a></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $row['product']->sku ?: '—' }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $row['quantity'] }} {{ $row['product']->unitOfMeasure?->symbol }}</bdi></td>
                        </tr>
                    @endif
                @endforeach
                @unless ($hasStock)
                    <tr><td colspan="3" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_stock') }}</td></tr>
                @endunless
            </tbody>
        </table>
    </div>
</section>
@endsection
