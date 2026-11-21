@extends('layouts.inventory')

@section('title', $role->name_en.' · '.__('ui.roles.detail_heading'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('roles.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.roles.back_to_list') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ $role->name_en }}</h1>
        @if ($role->description)
            <p class="mt-1 text-sm text-slate-500">{{ $role->description }}</p>
        @endif
    </div>
    <div class="flex gap-2">
        @if (! $role->is_protected)
            <a href="{{ route('roles.edit', $role) }}" class="button button-secondary">{{ __('ui.roles.edit') }}</a>
            <form method="POST" action="{{ route('roles.destroy', $role) }}"
                onsubmit="return confirm('{{ __('ui.roles.confirm_delete') }}')">
                @csrf
                @method('DELETE')
                <button type="submit" class="button button-secondary text-red-700">{{ __('ui.roles.delete') }}</button>
            </form>
        @endif
    </div>
</div>

@if ($role->is_protected)
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        {{ __('ui.roles.protected_note') }}
    </div>
@endif

<section class="panel mb-6">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.roles.detail_heading') }}</h2></div>
    <dl class="divide-y divide-slate-100 text-sm">
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.roles.name_en') }}</dt>
            <dd class="font-medium text-slate-900">{{ $role->name_en }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.roles.name_ar') }}</dt>
            <dd class="font-medium text-slate-900">{{ $role->name_ar }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.roles.slug') }}</dt>
            <dd class="font-mono text-slate-700">{{ $role->slug }}</dd>
        </div>
        @if ($role->description)
            <div class="grid grid-cols-2 gap-4 px-5 py-3">
                <dt class="text-slate-500">{{ __('ui.roles.description') }}</dt>
                <dd class="text-slate-700">{{ $role->description }}</dd>
            </div>
        @endif
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.roles.is_system') }}</dt>
            <dd>{{ $role->is_system ? '✓' : '—' }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.roles.is_protected') }}</dt>
            <dd>{{ $role->is_protected ? '✓' : '—' }}</dd>
        </div>
    </dl>
</section>

<section class="panel mb-6">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.roles.permissions_heading') }}</h2></div>

    @foreach ($grouped as $module => $permissions)
        <div class="border-t border-slate-100 px-5 py-4">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                {{ __('ui.roles.modules.'.$module, [], app()->getLocale()) ?? $module }}
            </p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($permissions as $perm)
                    @php $has = $role->permissions->contains('key', $perm['key']); @endphp
                    <label class="flex items-center gap-2 text-sm {{ $has ? 'text-slate-700' : 'text-slate-400' }}">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300"
                            @checked($has)
                            disabled
                        >
                        {{ $perm['name_en'] }}
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
@endsection
