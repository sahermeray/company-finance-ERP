@extends('layouts.inventory')

@section('title', __('ui.users.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.users.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('ui.users.list_intro') }}</p>
    </div>
    <a href="{{ route('users.create') }}" class="button button-primary">{{ __('ui.users.create') }}</a>
</div>

<section class="panel">
    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
        <label for="search" class="sr-only">{{ __('ui.users.search') }}</label>
        <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" type="search"
            class="form-control sm:max-w-sm" placeholder="{{ __('ui.users.search') }}">

        <select name="is_active" class="form-control sm:max-w-[12rem]">
            <option value="">{{ __('ui.users.filter_status') }}</option>
            <option value="1" @selected(isset($filters['is_active']) && $filters['is_active'] === '1')>{{ __('ui.users.active') }}</option>
            <option value="0" @selected(isset($filters['is_active']) && $filters['is_active'] === '0')>{{ __('ui.users.inactive') }}</option>
        </select>

        <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[42rem] text-start text-sm">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ __('ui.users.name') }}</th>
                    <th class="px-4 py-3">{{ __('ui.users.email') }}</th>
                    <th class="px-4 py-3">{{ __('ui.users.is_active') }}</th>
                    <th class="px-4 py-3">{{ __('ui.users.company_count') }}</th>
                    <th class="px-4 py-3">{{ __('ui.users.created_at') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('ui.users.edit') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <a href="{{ route('users.show', $user) }}" class="text-teal-800 hover:underline">{{ $user->name }}</a>
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $user->email }}</bdi></td>
                        <td class="px-4 py-3">
                            @if ($user->is_active)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('ui.users.active') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('ui.users.inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $user->companies_count }}</td>
                        <td class="px-4 py-3"><bdi dir="ltr">{{ $user->created_at?->toDateString() }}</bdi></td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('users.show', $user) }}" class="button button-subtle">{{ __('ui.users.edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('ui.users.no_users') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $users->links() }}</div>
</section>
@endsection
