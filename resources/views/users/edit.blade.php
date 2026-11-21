@extends('layouts.inventory')

@section('title', __('ui.users.edit_heading').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">
        <a href="{{ route('users.show', $user) }}" class="hover:underline">{{ $user->name }}</a>
    </p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.users.edit_heading') }}</h1>
</div>

<section class="panel max-w-2xl">
    <form method="POST" action="{{ route('users.update', $user) }}" class="grid gap-5 p-5">
        @csrf
        @method('PUT')

        <div>
            <label for="name" class="form-label">{{ __('ui.users.name') }} <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" required maxlength="255"
                value="{{ old('name', $user->name) }}"
                class="form-control @error('name') border-red-400 @enderror">
            @error('name')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="form-label">{{ __('ui.users.email') }} <span class="text-red-600">*</span></label>
            <input id="email" name="email" type="email" required maxlength="255"
                value="{{ old('email', $user->email) }}"
                class="form-control @error('email') border-red-400 @enderror" dir="ltr">
            @error('email')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <hr class="border-slate-100">

        <div>
            <p class="form-label font-semibold">{{ __('ui.users.new_password') }}</p>
            <p class="mb-2 text-xs text-slate-500">{{ __('ui.users.password_change_intro') }}</p>
        </div>

        <div>
            <label for="new_password" class="form-label">{{ __('ui.users.new_password') }}</label>
            <input id="new_password" name="new_password" type="password"
                class="form-control @error('new_password') border-red-400 @enderror" dir="ltr"
                autocomplete="new-password">
            @error('new_password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="new_password_confirmation" class="form-label">{{ __('ui.users.new_password_confirmation') }}</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password"
                class="form-control" dir="ltr" autocomplete="new-password">
        </div>

        <div class="flex gap-2">
            <button type="submit" class="button button-primary">{{ __('ui.users.save') }}</button>
            <a href="{{ route('users.show', $user) }}" class="button button-secondary">{{ __('ui.users.cancel') }}</a>
        </div>
    </form>
</section>
@endsection
