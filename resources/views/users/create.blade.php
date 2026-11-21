@extends('layouts.inventory')

@section('title', __('ui.users.create_heading').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">
        <a href="{{ route('users.index') }}" class="hover:underline">{{ __('ui.users.title') }}</a>
    </p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.users.create_heading') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.users.create_intro') }}</p>
</div>

<section class="panel max-w-2xl">
    <form method="POST" action="{{ route('users.store') }}" class="grid gap-5 p-5">
        @csrf

        <div>
            <label for="name" class="form-label">{{ __('ui.users.name') }} <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" required maxlength="255"
                value="{{ old('name') }}"
                class="form-control @error('name') border-red-400 @enderror">
            @error('name')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="form-label">{{ __('ui.users.email') }} <span class="text-red-600">*</span></label>
            <input id="email" name="email" type="email" required maxlength="255"
                value="{{ old('email') }}"
                class="form-control @error('email') border-red-400 @enderror" dir="ltr">
            @error('email')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="form-label">{{ __('ui.users.password') }} <span class="text-red-600">*</span></label>
            <input id="password" name="password" type="password" required
                class="form-control @error('password') border-red-400 @enderror" dir="ltr"
                autocomplete="new-password">
            @error('password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="form-label">{{ __('ui.users.password_confirmation') }} <span class="text-red-600">*</span></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                class="form-control" dir="ltr" autocomplete="new-password">
        </div>

        <div class="flex items-center gap-2">
            <input id="add_to_company" name="add_to_company" type="checkbox" value="1"
                @checked(old('add_to_company', '1') === '1')
                class="h-4 w-4 rounded border-slate-300 text-teal-600">
            <label for="add_to_company" class="text-sm text-slate-700">{{ __('ui.users.add_to_company') }} ({{ $company->name }})</label>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.users.create') }}</button>
            <a href="{{ route('users.index') }}" class="button button-secondary">{{ __('ui.users.cancel') }}</a>
        </div>
    </form>
</section>
@endsection
