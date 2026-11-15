@extends('layouts.inventory')

@section('title', __('ui.inventory.overview').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ $activeCompany->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.overview') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.base_currency_note', ['currency' => $activeCompany->base_currency]) }}</p>
    </div>
    <a href="{{ route('inventory.products.create') }}" class="button button-primary">{{ __('ui.inventory.product_create') }}</a>
</div>
<section class="panel">
    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
        <label for="search" class="sr-only">{{ __('ui.inventory.search') }}</label>
        <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" type="search" class="form-control sm:max-w-sm" placeholder="{{ __('ui.inventory.search') }}">
        <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[58rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.product') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.quantity') }}</th>
                    @foreach ($warehouses as $warehouse)
                        <th class="px-4 py-3">{{ $warehouse->name }}</th>
                    @endforeach
                    <th class="px-4 py-3">{{ __('ui.inventory.average_cost') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.inventory_value') }} ({{ $activeCompany->base_currency }})</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($products as $row)
                    @php($valuation = $row['valuation'])
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('inventory.products.show', $row['product']) }}" class="font-medium text-teal-800 hover:underline">{{ $row['product']->name }}</a>
                            <span class="mt-1 block text-xs text-slate-500"><bdi dir="ltr">{{ $row['product']->sku ?: '—' }}</bdi> · {{ $row['product']->unitOfMeasure?->symbol }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <bdi dir="ltr">{{ $row['quantity'] }}</bdi>
                            @if (bccomp($row['quantity'], '0', 4) === 0)
                                <span class="mt-1 block text-xs text-slate-500">{{ __('ui.inventory.zero_stock') }}</span>
                            @endif
                        </td>
                        @foreach ($warehouses as $warehouse)
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $row['warehouse_quantities'][$warehouse->getKey()] }}</bdi></td>
                        @endforeach
                        <td class="px-4 py-3">
                            @if ($valuation)
                                <bdi dir="ltr">{{ $valuation['average_unit_cost'] ?? '—' }}</bdi>
                            @else
                                <span class="text-xs text-amber-800">{{ __('ui.inventory.valuation_unavailable') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($valuation)
                                <bdi dir="ltr">{{ $valuation['inventory_value'] }}</bdi>
                            @else
                                <span class="text-xs text-amber-800">{{ __('ui.inventory.valuation_unavailable') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 4 + $warehouses->count() }}" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_products') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $products->links() }}</div>
</section>
@endsection
