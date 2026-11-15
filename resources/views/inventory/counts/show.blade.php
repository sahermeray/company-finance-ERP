@extends('layouts.inventory')

@section('title', __('ui.inventory.count_details').' · #'.$count->getKey())

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('inventory.counts.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.counts') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.count_details') }} <bdi dir="ltr">#{{ $count->getKey() }}</bdi></h1>
        <p class="mt-1 text-sm text-slate-500"><bdi dir="ltr">{{ $count->counted_at->format('Y-m-d H:i') }}</bdi> · {{ $count->description }}</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="status-badge {{ $count->status === 'posted' ? 'status-available' : 'status-incomplete' }}">{{ __('ui.inventory.'.($count->status === 'draft' ? 'draft' : $count->status.'_status')) }}</span>
        @if ($count->status === 'draft')
            <form method="POST" action="{{ route('inventory.counts.approve', $count) }}">@csrf<button class="button button-secondary" type="submit">{{ __('ui.inventory.approve') }}</button></form>
        @elseif ($count->status === 'approved')
            <form method="POST" action="{{ route('inventory.counts.post', $count) }}">@csrf<button class="button button-primary" type="submit">{{ __('ui.inventory.post') }}</button></form>
        @endif
    </div>
</div>
<section class="panel mb-5">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.inventory.count_lines') }}</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.product') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.warehouse') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.system_quantity') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.counted_quantity') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.variance') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.approved_unit_cost', ['currency' => $activeCompany->base_currency]) }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @foreach ($count->lines as $line)
                    <tr>
                        <td class="px-4 py-3">{{ $line->product->name }}</td>
                        <td class="px-4 py-3">{{ $line->warehouse->name }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $line->system_quantity }} {{ $line->product->unitOfMeasure?->symbol }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $line->counted_quantity }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $line->variance_quantity }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $line->approved_unit_cost ?? '—' }}</bdi></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
<section class="panel p-5 text-sm text-slate-700">
    <p>{{ __('ui.inventory.inventory_asset_account') }}: {{ $count->inventoryAssetAccount->code }} · {{ $count->inventoryAssetAccount->name }}</p>
    <p class="mt-2">{{ __('ui.inventory.positive_variance_account') }}: {{ $count->positiveVarianceAccount->code }} · {{ $count->positiveVarianceAccount->name }}</p>
    <p class="mt-2">{{ __('ui.inventory.negative_variance_account') }}: {{ $count->negativeVarianceAccount->code }} · {{ $count->negativeVarianceAccount->name }}</p>
</section>
@endsection
