@extends('layouts.app')

@section('title', __('ui.auth.login_title').' · '.__('ui.app_name'))

@section('content')
<main class="guest-page flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
    <section class="guest-card w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="login-title">
        <div class="mb-8 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="brand-lockup inline-flex items-center gap-3 text-slate-950">
                <span class="brand-mark inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-700 text-sm font-bold text-white">N</span>
                <span class="font-semibold">{{ __('ui.app_name') }}</span>
            </a>
            <form method="POST" action="{{ route('language.update') }}" class="inline-flex items-center gap-2">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <label for="guest-locale" class="sr-only">{{ __('ui.language.label') }}</label>
                <select id="guest-locale" name="locale" class="form-control form-control-compact" aria-label="{{ __('ui.language.label') }}" data-auto-submit>
                    <option value="ar" @selected(app()->getLocale() === 'ar')>{{ __('ui.language.arabic') }}</option>
                    <option value="en" @selected(app()->getLocale() === 'en')>{{ __('ui.language.english') }}</option>
                </select>
                <button type="submit" class="sr-only">{{ __('ui.actions.apply') }}</button>
            </form>
        </div>

        <p class="mb-2 text-sm font-medium text-teal-700">{{ __('ui.app_tagline') }}</p>
        <h1 id="login-title" class="text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.auth.login_title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('ui.auth.login_intro') }}</p>

        <form method="POST" action="{{ route('login.store') }}" class="mt-7 space-y-5">
            @csrf
            <div>
                <label for="email" class="form-label">{{ __('ui.auth.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="form-control @error('email') form-control-error @enderror" aria-describedby="@error('email') email-error @enderror">
                @error('email')
                    <p id="email-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="form-label">{{ __('ui.auth.password') }}</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required class="form-control @error('password') form-control-error @enderror" aria-describedby="@error('password') password-error @enderror">
                @error('password')
                    <p id="password-error" class="form-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="button button-primary w-full">{{ __('ui.actions.login') }}</button>
        </form>
    </section>
</main>
@endsection
