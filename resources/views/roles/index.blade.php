@extends('layouts.inventory')

@section('title', __('ui.roles.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.roles.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.roles.list_intro') }}</p>
    </div>
    <a href="{{ route('roles.create') }}" class="button button-primary">{{ __('ui.roles.create') }}</a>
</div>

<section class="panel">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[42rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.roles.name_en') }}</th>
                    <th class="px-4 py-3">{{ __('ui.roles.name_ar') }}</th>
                    <th class="px-4 py-3">{{ __('ui.roles.permissions_heading') }}</th>
                    <th class="px-4 py-3">{{ __('ui.roles.is_system') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('ui.roles.edit') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($roles as $role)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <a href="{{ route('roles.show', $role) }}" class="text-teal-800 hover:underline">{{ $role->name_en }}</a>
                            @if ($role->is_protected)
                                <span class="ms-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('ui.roles.owner_badge') }}</span>
                            @elseif ($role->is_system)
                                <span class="ms-2 inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('ui.roles.system_badge') }}</span>
                            @else
                                <span class="ms-2 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ __('ui.roles.custom_badge') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $role->name_ar }}</td>
                        <td class="px-4 py-3">{{ $role->permissions_count }}</td>
                        <td class="px-4 py-3">
                            @if ($role->is_system)
                                <span class="text-teal-700">✓</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('roles.show', $role) }}" class="button button-subtle">{{ __('ui.roles.edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('ui.roles.no_roles') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $roles->links() }}</div>
</section>
@endsection
