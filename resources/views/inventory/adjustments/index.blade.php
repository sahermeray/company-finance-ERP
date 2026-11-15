@extends('layouts.inventory')

@section('title', __('ui.inventory.adjustments').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.adjustments') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.adjustment_intro') }}</p>
    </div>
    <a href="{{ route('inventory.adjustments.create') }}" class="button button-primary">{{ __('ui.inventory.adjustment_create') }}</a>
</div>
<section class="panel overflow-x-auto">
    <table class="w-full min-w-[50rem] text-start text-sm">
        <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
            <tr>
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">{{ __('ui.inventory.date') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.product') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.warehouse') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.adjustment_type') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.quantity') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3"><a href="{{ route('inventory.adjustments.show', $adjustment) }}" class="font-medium text-teal-800 hover:underline"><bdi dir="ltr">#{{ $adjustment->getKey() }}</bdi></a></td>
                    <td class="px-4 py-3"><bdi dir="ltr">{{ $adjustment->occurred_at->format('Y-m-d') }}</bdi></td>
                    <td class="px-4 py-3">{{ $adjustment->product->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->warehouse->name }}</td>
                    <td class="px-4 py-3">{{ __('ui.inventory.'.$adjustment->adjustment_type) }}</td>
                    <td class="px-4 py-3"><bdi dir="ltr">{{ $adjustment->quantity }} {{ $adjustment->product->unitOfMeasure?->symbol }}</bdi></td>
                    <td class="px-4 py-3">{{ __('ui.inventory.'.($adjustment->status === 'draft' ? 'draft' : $adjustment->status.'_status')) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_adjustments') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $adjustments->links() }}</div>
</section>
@endsection
