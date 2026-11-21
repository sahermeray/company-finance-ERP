@extends('layouts.inventory')

@section('title', $user->name.' · '.__('ui.users.detail_heading'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('users.index') }}" class="text-sm font-medium text-teal-800 hover:underline">‹ {{ __('ui.users.back_to_list') }}</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-slate-500"><bdi dir="ltr">{{ $user->email }}</bdi></p>
    </div>
    <a href="{{ route('users.edit', $user) }}" class="button button-secondary">{{ __('ui.users.edit') }}</a>
</div>

@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <ul class="list-inside list-disc">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- User info panel --}}
<section class="panel mb-6">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.users.detail_heading') }}</h2></div>
    <dl class="divide-y divide-slate-100 text-sm">
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.users.name') }}</dt>
            <dd class="font-medium text-slate-900">{{ $user->name }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.users.email') }}</dt>
            <dd><bdi dir="ltr">{{ $user->email }}</bdi></dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.users.is_active') }}</dt>
            <dd>
                @if ($user->is_active)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('ui.users.active') }}</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('ui.users.inactive') }}</span>
                @endif
            </dd>
        </div>
        <div class="grid grid-cols-2 gap-4 px-5 py-3">
            <dt class="text-slate-500">{{ __('ui.users.created_at') }}</dt>
            <dd><bdi dir="ltr">{{ $user->created_at?->toDateString() }}</bdi></dd>
        </div>
    </dl>

    <div class="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-4">
        @if ($user->is_active)
            @if ((int) auth()->id() !== (int) $user->getKey())
                <form method="POST" action="{{ route('users.deactivate', $user) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button button-secondary"
                        onclick="return confirm('{{ __('ui.users.confirm_deactivate') }}')">
                        {{ __('ui.users.deactivate') }}
                    </button>
                </form>
            @endif
        @else
            <form method="POST" action="{{ route('users.activate', $user) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button button-primary">{{ __('ui.users.activate') }}</button>
            </form>
        @endif
    </div>
</section>

{{-- Memberships panel --}}
<section class="panel mb-6">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.users.memberships') }}</h2></div>

    @if ($memberships->isEmpty())
        <p class="px-5 py-6 text-sm text-slate-500">{{ __('ui.users.no_memberships') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('ui.settings.general.name') }}</th>
                        <th class="px-4 py-3">{{ __('ui.users.joined_at') }}</th>
                        <th class="px-4 py-3"><span class="sr-only">{{ __('ui.users.remove_membership') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @foreach ($memberships as $membership)
                        <tr>
                            <td class="px-4 py-3 font-medium">
                                {{ $membership->name }}
                                @if ((int) $membership->getKey() === (int) $company->getKey())
                                    <span class="ms-2 text-xs text-teal-700">({{ __('ui.companies.active_company') }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <bdi dir="ltr">{{ $membership->pivot->created_at?->toDateString() ?? '—' }}</bdi>
                            </td>
                            <td class="px-4 py-3 text-end">
                                @if ((int) $membership->getKey() === (int) $company->getKey())
                                    <form method="POST"
                                        action="{{ route('users.memberships.remove', [$user, $membership]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button button-subtle text-red-700">
                                            {{ __('ui.users.remove_membership') }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! $isMember)
        <div class="border-t border-slate-100 px-5 py-4">
            <form method="POST" action="{{ route('users.memberships.add', $user) }}" class="flex items-center gap-3">
                @csrf
                <p class="text-sm text-slate-600">{{ __('ui.users.add_to_active_company') }}: <strong>{{ $company->name }}</strong></p>
                <button type="submit" class="button button-primary">{{ __('ui.users.add_membership') }}</button>
            </form>
        </div>
    @endif
</section>

{{-- Role assignment panel (only shown when user is a member of the active company) --}}
@if ($isMember)
<section class="panel mb-6">
    <div class="panel-heading"><h2 class="font-semibold text-slate-950">{{ __('ui.roles.assign_role') }}</h2></div>
    <div class="px-5 py-4">
        <p class="mb-3 text-sm text-slate-500">
            {{ __('ui.roles.current_role') }}:
            @if ($currentIsOwner)
                <strong>{{ __('ui.roles.owner_badge') }}</strong>
            @elseif ($currentRole)
                <strong>{{ $currentRole->name_en }}</strong>
            @else
                <em>{{ __('ui.roles.no_role_assigned') }}</em>
            @endif
        </p>

        @if ($availableRoles->isNotEmpty())
            <form method="POST" action="{{ route('users.role.assign', $user) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @method('PATCH')
                <div>
                    <label for="role_id" class="form-label">{{ __('ui.roles.assign_role') }}</label>
                    <select id="role_id" name="role_id" class="form-control">
                        <option value="">{{ __('ui.roles.no_role_assigned') }}</option>
                        @foreach ($availableRoles as $availableRole)
                            <option value="{{ $availableRole->getKey() }}"
                                @selected($currentRole && (int) $currentRole->getKey() === (int) $availableRole->getKey())>
                                {{ $availableRole->name_en }}
                                @if ($availableRole->is_protected)
                                    ({{ __('ui.roles.owner_badge') }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="button button-primary">{{ __('ui.roles.save') }}</button>
            </form>
        @endif
    </div>
</section>
@endif
@endsection
