@extends('layouts.inventory')

@section('title', __('ui.inventory.adjustment_details').' · #'.$adjustment->getKey())

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('inventory.adjustments.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.inventory.adjustments') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.adjustment_details') }} <bdi dir="ltr">#{{ $adjustment->getKey() }}</bdi></h1>
        <p class="mt-1 text-sm text-slate-500">{{ $adjustment->product->name }} · {{ $adjustment->warehouse->name }} · {{ __('ui.inventory.'.$adjustment->adjustment_type) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="status-badge {{ $adjustment->status === 'posted' ? 'status-available' : 'status-incomplete' }}">{{ __('ui.inventory.'.($adjustment->status === 'draft' ? 'draft' : $adjustment->status.'_status')) }}</span>
        @if ($adjustment->status === 'draft')
            <form method="POST" action="{{ route('inventory.adjustments.approve', $adjustment) }}">@csrf<button class="button button-secondary" type="submit">{{ __('ui.inventory.approve') }}</button></form>
        @elseif ($adjustment->status === 'approved')
            <form method="POST" action="{{ route('inventory.adjustments.post', $adjustment) }}">@csrf<button class="button button-primary" type="submit">{{ __('ui.inventory.post') }}</button></form>
        @endif
    </div>
</div>
<section class="panel grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.date') }}</p><p class="mt-1 font-medium"><bdi dir="ltr">{{ $adjustment->occurred_at->format('Y-m-d H:i') }}</bdi></p></div>
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.quantity') }}</p><p class="mt-1 font-medium"><bdi dir="ltr">{{ $adjustment->quantity }} {{ $adjustment->product->unitOfMeasure?->symbol }}</bdi></p></div>
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.base_unit_cost') }}</p><p class="mt-1 font-medium"><bdi dir="ltr">{{ $adjustment->base_unit_cost ?? '—' }} {{ $activeCompany->base_currency }}</bdi></p></div>
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.inventory_value') }}</p><p class="mt-1 font-medium"><bdi dir="ltr">{{ $adjustment->base_cost_amount ?? '—' }} {{ $activeCompany->base_currency }}</bdi></p></div>
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.inventory_asset_account') }}</p><p class="mt-1 font-medium">{{ $adjustment->inventoryAssetAccount->code }} · {{ $adjustment->inventoryAssetAccount->name }}</p></div>
    <div><p class="text-sm text-slate-500">{{ __('ui.inventory.offset_account') }}</p><p class="mt-1 font-medium">{{ $adjustment->offsetAccount->code }} · {{ $adjustment->offsetAccount->name }}</p></div>
    <div class="sm:col-span-2"><p class="text-sm text-slate-500">{{ __('ui.inventory.reason') }}</p><p class="mt-1 font-medium">{{ $adjustment->reason }}</p></div>
    @if ($adjustment->movement)
        <div><p class="text-sm text-slate-500">{{ __('ui.inventory.movement_type') }}</p><p class="mt-1 font-medium">{{ __('ui.inventory.movement_types.'.$adjustment->movement->movement_type) }}</p></div>
        <div><p class="text-sm text-slate-500">{{ __('ui.inventory.source_document') }}</p><p class="mt-1 font-medium">{{ __('ui.inventory.adjustment') }} #{{ $adjustment->getKey() }}</p></div>
    @endif
</section>
@endsection
