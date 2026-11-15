@extends('layouts.inventory')

@section('title', __('ui.inventory.counts').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.inventory.counts') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.inventory.count_intro') }}</p>
    </div>
    <a href="{{ route('inventory.counts.create') }}" class="button button-primary">{{ __('ui.inventory.count_create') }}</a>
</div>
<section class="panel overflow-x-auto">
    <table class="w-full min-w-[38rem] text-start text-sm">
        <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
            <tr>
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">{{ __('ui.inventory.counted_at') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.description') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.count_lines') }}</th>
                <th class="px-4 py-3">{{ __('ui.inventory.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">
            @forelse ($counts as $count)
                <tr>
                    <td class="px-4 py-3"><a href="{{ route('inventory.counts.show', $count) }}" class="font-medium text-teal-800 hover:underline"><bdi dir="ltr">#{{ $count->getKey() }}</bdi></a></td>
                    <td class="px-4 py-3"><bdi dir="ltr">{{ $count->counted_at->format('Y-m-d') }}</bdi></td>
                    <td class="px-4 py-3">{{ $count->description }}</td>
                    <td class="px-4 py-3">{{ $count->lines_count }}</td>
                    <td class="px-4 py-3">{{ __('ui.inventory.'.($count->status === 'draft' ? 'draft' : $count->status.'_status')) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('ui.inventory.no_counts') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $counts->links() }}</div>
</section>
@endsection
