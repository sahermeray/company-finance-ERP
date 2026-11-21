@extends('layouts.inventory')

@section('title', __('ui.roles.edit_heading').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route('roles.show', $role) }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.roles.back_to_role') }}</a>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.roles.edit_heading') }}: {{ $role->name_en }}</h1>
</div>

@if ($role->is_protected)
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        {{ __('ui.roles.protected_note') }}
    </div>

    {{-- Read-only view for protected roles --}}
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
                            <input type="checkbox" class="rounded border-slate-300" @checked($has) disabled>
                            {{ $perm['name_en'] }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    <a href="{{ route('roles.show', $role) }}" class="button button-secondary">{{ __('ui.roles.back_to_role') }}</a>
@else
    <form method="POST" action="{{ route('roles.update', $role) }}">
        @csrf
        @method('PUT')

        <section class="panel mb-6">
            <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.roles.detail_heading') }}</h2></div>
            <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2">
                <div>
                    <label for="name_en" class="form-label">{{ __('ui.roles.name_en') }} <span class="text-red-600">*</span></label>
                    <input id="name_en" name="name_en" type="text" class="form-control"
                        value="{{ old('name_en', $role->name_en) }}" required>
                    @error('name_en') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="name_ar" class="form-label">{{ __('ui.roles.name_ar') }} <span class="text-red-600">*</span></label>
                    <input id="name_ar" name="name_ar" type="text" class="form-control"
                        value="{{ old('name_ar', $role->name_ar) }}" required>
                    @error('name_ar') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="description" class="form-label">{{ __('ui.roles.description') }}</label>
                    <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $role->description) }}</textarea>
                    @error('description') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="panel mb-6">
            <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.roles.permissions_heading') }}</h2></div>
            <p class="px-5 pb-2 pt-1 text-sm text-slate-500">{{ __('ui.roles.permissions_intro') }}</p>

            @error('permissions') <p class="px-5 pb-3 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('permissions.*') <p class="px-5 pb-3 text-sm text-red-700">{{ $message }}</p> @enderror

            @foreach ($grouped as $module => $permissions)
                <div class="border-t border-slate-100 px-5 py-4">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {{ __('ui.roles.modules.'.$module, [], app()->getLocale()) ?? $module }}
                    </p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($permissions as $perm)
                            @php
                                $checked = old('permissions') !== null
                                    ? in_array($perm['key'], (array) old('permissions', []))
                                    : $role->permissions->contains('key', $perm['key']);
                            @endphp
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value="{{ $perm['key'] }}"
                                    class="rounded border-slate-300"
                                    @checked($checked)
                                >
                                {{ $perm['name_en'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        <div class="flex gap-3">
            <button type="submit" class="button button-primary">{{ __('ui.roles.save') }}</button>
            <a href="{{ route('roles.show', $role) }}" class="button button-secondary">{{ __('ui.inventory.cancel') }}</a>
        </div>
    </form>
@endif
@endsection
