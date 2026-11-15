@extends('layouts.app')

@section('content')
<div class="app-shell">
    <button class="sidebar-overlay" type="button" aria-label="{{ __('ui.actions.close') }}" data-sidebar-overlay></button>

    <x-app-sidebar />

    <main class="app-main min-w-0">
        <header class="app-topbar">
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    class="icon-button"
                    aria-label="{{ __('ui.navigation.open') }}"
                    aria-controls="app-sidebar"
                    aria-expanded="false"
                    data-sidebar-toggle
                    data-open-label="{{ __('ui.navigation.open') }}"
                    data-close-label="{{ __('ui.actions.close') }}"
                    data-collapse-label="{{ __('ui.navigation.collapse') }}"
                    data-expand-label="{{ __('ui.navigation.expand') }}"
                    title="{{ __('ui.navigation.open') }}"
                >
                    <x-icon name="menu" />
                </button>

                <div class="company-switcher min-w-0">
                    @if ($companies->count() > 1)
                        <form method="POST" action="{{ route('company-context.update') }}" class="company-switcher-form">
                            @csrf
                            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                            <label for="company_id" class="sr-only">{{ __('ui.companies.active_company') }}</label>
                            <select id="company_id" name="company_id" class="company-select" data-auto-submit>
                                @foreach ($companies as $availableCompany)
                                    <option value="{{ $availableCompany->getKey() }}" @selected((int) $activeCompany->getKey() === (int) $availableCompany->getKey())>
                                        {{ $availableCompany->name }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    @else
                        <span class="company-name">{{ $activeCompany->name }}</span>
                    @endif
                    <span class="company-currency">{{ $activeCompany->base_currency }}</span>
                </div>
            </div>

            <div class="topbar-actions">
                <form method="POST" action="{{ route('language.update') }}">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                    <label for="inventory-locale" class="sr-only">{{ __('ui.language.label') }}</label>
                    <select id="inventory-locale" name="locale" class="form-control form-control-compact" data-auto-submit>
                        <option value="ar" @selected(app()->getLocale() === 'ar')>{{ __('ui.language.arabic') }}</option>
                        <option value="en" @selected(app()->getLocale() === 'en')>{{ __('ui.language.english') }}</option>
                    </select>
                </form>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="button button-secondary">{{ __('ui.actions.logout') }}</button>
                </form>
            </div>
        </header>

        <div class="inventory-content mx-auto w-full max-w-7xl p-4 sm:p-6 lg:p-8">
            @if (session('status'))
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    <p class="font-semibold">{{ __('ui.inventory.validation_heading') }}</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield('page-content')
        </div>
    </main>
</div>
@endsection
