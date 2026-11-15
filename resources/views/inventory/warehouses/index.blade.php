@extends('layouts.inventory')

@section('title', __('ui.inventory.warehouses').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.warehouses') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.warehouse_intro') }}</p>
    </div>
    <a href="{{ route('inventory.warehouses.create') }}" class="button button-primary">{{ __('ui.inventory.warehouse_create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
        <label for="search" class="sr-only">{{ __('ui.inventory.search') }}</label>
        <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" type="search" class="form-control sm:max-w-sm" placeholder="{{ __('ui.inventory.search') }}">
        <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[36rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.inventory.name') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.address') }}</th>
                    <th class="px-4 py-3">{{ __('ui.inventory.status') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('ui.inventory.edit') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($warehouses as $warehouse)
                    <tr>
                        <td class="px-4 py-3 font-medium"><a href="{{ route('inventory.warehouses.show', $warehouse) }}" class="text-teal-800 hover:underline">{{ $warehouse->name }}</a></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $warehouse->code ?: '—' }}</bdi></td>
                        <td class="px-4 py-3">{{ $warehouse->address ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $warehouse->is_active ? __('ui.inventory.active') : __('ui.inventory.inactive') }}</td>
                        <td class="px-4 py-3 text-end"><a href="{{ route('inventory.warehouses.edit', $warehouse) }}" class="button button-subtle">{{ __('ui.inventory.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_warehouses') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $warehouses->links() }}</div>
</section>
@endsection
