@extends('layouts.app')

@section('title', __('ui.companies.select_title').' · '.__('ui.app_name'))

@section('content')
<main class="guest-page flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
    <section class="guest-card w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="company-select-title">
        <div class="mb-8 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="brand-lockup inline-flex items-center gap-3 text-slate-950">
                <span class="brand-mark inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-700 text-sm font-bold text-white">N</span>
                <span class="font-semibold">{{ __('ui.app_name') }}</span>
            </a>
            <form method="POST" action="{{ route('language.update') }}" class="inline-flex items-center gap-2">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <label for="company-locale" class="sr-only">{{ __('ui.language.label') }}</label>
                <select id="company-locale" name="locale" class="form-control form-control-compact" aria-label="{{ __('ui.language.label') }}" data-auto-submit>
                    <option value="ar" @selected(app()->getLocale() === 'ar')>{{ __('ui.language.arabic') }}</option>
                    <option value="en" @selected(app()->getLocale() === 'en')>{{ __('ui.language.english') }}</option>
                </select>
                <button type="submit" class="sr-only">{{ __('ui.actions.apply') }}</button>
            </form>
        </div>

        <p class="mb-2 text-sm font-medium text-teal-700">{{ __('ui.app_tagline') }}</p>
        <h1 id="company-select-title" class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.companies.select_title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('ui.companies.choose_intro') }}</p>

        @if ($companies->isEmpty())
            <div class="empty-state mt-6 rounded-xl border border-slate-200 bg-slate-50 p-5" role="status">
                <p class="font-medium text-slate-800">{{ __('ui.companies.no_companies') }}</p>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('ui.companies.no_access') }}</p>
            </div>
        @else
            <form method="POST" action="{{ route('company-context.update') }}" class="mt-6 space-y-3">
                @csrf
                <input type="hidden" name="return_to" value="/dashboard">
                <label for="company_id" class="form-label">{{ __('ui.companies.choose_company') }}</label>
                <select id="company_id" name="company_id" required class="form-control @error('company_id') form-control-error @enderror" aria-describedby="@error('company_id') company-error @enderror">
                    @foreach ($companies as $availableCompany)
                        <option value="{{ $availableCompany->getKey() }}" @selected((int) $activeCompanyId === (int) $availableCompany->getKey())>
                            {{ $availableCompany->name }} · {{ $availableCompany->base_currency }}
                        </option>
                    @endforeach
                </select>
                @error('company_id')
                    <p id="company-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
                <button type="submit" class="button button-primary mt-3">{{ __('ui.actions.select_company') }}</button>
            </form>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-6 border-t border-slate-100 pt-5">
            @csrf
            <button type="submit" class="button button-secondary">{{ __('ui.actions.logout') }}</button>
        </form>
    </section>
</main>
@endsection
