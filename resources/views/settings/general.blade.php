@extends('layouts.inventory')

@section('title', __('ui.settings.title').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('ui.settings.general.intro') }}</p>
</div>

@include('settings._subnav')

<section class="panel max-w-4xl">
    <form method="POST" action="{{ route('settings.general.update') }}" class="grid gap-5 p-5 sm:grid-cols-2">
        @csrf
        @method('PUT')

        <div class="sm:col-span-2">
            <label for="name" class="form-label">{{ __('ui.settings.general.name') }} <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" required maxlength="255"
                value="{{ old('name', $company->name) }}"
                class="form-control">
        </div>

        <div>
            <label for="legal_name" class="form-label">{{ __('ui.settings.general.legal_name') }}</label>
            <input id="legal_name" name="legal_name" type="text" maxlength="255"
                value="{{ old('legal_name', $company->legal_name) }}"
                class="form-control">
        </div>

        <div>
            <label for="tax_identifier" class="form-label">{{ __('ui.settings.general.tax_identifier') }}</label>
            <input id="tax_identifier" name="tax_identifier" type="text" maxlength="255"
                value="{{ old('tax_identifier', $company->tax_identifier) }}"
                class="form-control" dir="auto">
        </div>

        <div>
            <label for="email" class="form-label">{{ __('ui.settings.general.email') }}</label>
            <input id="email" name="email" type="email" maxlength="255"
                value="{{ old('email', $company->email) }}"
                class="form-control" dir="ltr">
        </div>

        <div>
            <label for="phone" class="form-label">{{ __('ui.settings.general.phone') }}</label>
            <input id="phone" name="phone" type="text" maxlength="50"
                value="{{ old('phone', $company->phone) }}"
                class="form-control" dir="auto" inputmode="tel">
        </div>

        <div class="sm:col-span-2">
            <label for="address" class="form-label">{{ __('ui.settings.general.address') }}</label>
            <textarea id="address" name="address" rows="3" maxlength="10000"
                class="form-control">{{ old('address', $company->address) }}</textarea>
        </div>

        <div>
            <label for="country_code" class="form-label">{{ __('ui.settings.general.country_code') }}</label>
            <input id="country_code" name="country_code" type="text" maxlength="2"
                value="{{ old('country_code', $company->country_code) }}"
                class="form-control" dir="ltr" placeholder="SA">
        </div>

        <div>
            <label for="timezone" class="form-label">{{ __('ui.settings.general.timezone') }}</label>
            <select id="timezone" name="timezone" class="form-control" dir="ltr">
                <option value="">{{ __('ui.inventory.select_option') }}</option>
                @foreach ($timezones as $region => $tzList)
                    <optgroup label="{{ $region }}">
                        @foreach ($tzList as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $company->timezone) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.settings.general.save') }}</button>
        </div>
    </form>
</section>
@endsection
