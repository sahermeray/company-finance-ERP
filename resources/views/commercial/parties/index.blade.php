@extends('layouts.inventory')

@section('title', __('ui.parties.'.$kind.'s').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.operations') }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('ui.parties.'.$kind.'s') }}</h1>
    </div>
    <a href="{{ route($kind.'s.create') }}" class="button button-primary">{{ __('ui.parties.create_'.$kind) }}</a>
</div>

<section class="panel">
    <form method="GET" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
        <div>
            <label for="search" class="form-label">{{ __('ui.parties.search') }}</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" type="search">
        </div>
        <button type="submit" class="button button-primary self-end">{{ __('ui.actions.apply') }}</button>
        <a href="{{ route($kind.'s.index') }}" class="button button-secondary self-end">{{ __('ui.commercial.clear_filters') }}</a>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[42rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.parties.name') }}</th>
                    <th class="px-4 py-3">{{ __('ui.parties.code') }}</th>
                    <th class="px-4 py-3">{{ __('ui.parties.phone') }}</th>
                    <th class="px-4 py-3">{{ __('ui.parties.identifier') }}</th>
                    <th class="px-4 py-3">{{ __('ui.parties.documents') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('ui.parties.details') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($parties as $party)
                    <tr class="text-slate-700">
                        <td class="px-4 py-3 font-medium"><a class="text-teal-800 hover:underline" href="{{ route($kind.'s.show', $party) }}">{{ $party->name }}</a></td>
                        <td class="px-4 py-3"><bdi dir="auto">{{ $party->code ?: '—' }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="auto">{{ $party->phone ?: '—' }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="auto">{{ $party->tax_identifier ?: '—' }}</bdi></td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $party->{$kind === 'customer' ? 'revenues_count' : 'purchases_count'} }}</bdi></td>
                        <td class="px-4 py-3"><a class="button button-subtle" href="{{ route($kind.'s.show', $party) }}">{{ __('ui.parties.open') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('ui.parties.no_parties') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $parties->links() }}</div>
</section>
@endsection