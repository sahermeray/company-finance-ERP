@extends('layouts.inventory')

@section('title', __('ui.parties.'.$kind.'_'.($party->exists ? 'edit' : 'create')).' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <a href="{{ route($kind.'s.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.parties.back') }}</a>
    <h1 class="mt-3 text-2xl font-semibold text-slate-950">{{ __('ui.parties.'.$kind.'_'.($party->exists ? 'edit' : 'create')) }}</h1>
</div>

<section class="panel max-w-4xl">
    <form method="POST" action="{{ $party->exists ? route($kind.'s.update', $party) : route($kind.'s.store') }}" class="grid gap-5 p-5 sm:grid-cols-2">
        @csrf
        @if ($party->exists)
            @method('PUT')
        @endif
        <div>
            <label for="name" class="form-label">{{ __('ui.parties.name') }}</label>
            <input id="name" name="name" required maxlength="255" value="{{ old('name', $party->name) }}" class="form-control">
        </div>
        <div>
            <label for="code" class="form-label">{{ __('ui.parties.code') }}</label>
            <input id="code" name="code" maxlength="255" value="{{ old('code', $party->code) }}" class="form-control" dir="auto">
        </div>
        <div>
            <label for="legal_name" class="form-label">{{ __('ui.parties.legal_name') }}</label>
            <input id="legal_name" name="legal_name" maxlength="255" value="{{ old('legal_name', $party->legal_name) }}" class="form-control">
        </div>
        <div>
            <label for="phone" class="form-label">{{ __('ui.parties.phone') }}</label>
            <input id="phone" name="phone" maxlength="255" value="{{ old('phone', $party->phone) }}" class="form-control" dir="auto" inputmode="tel">
        </div>
        <div>
            <label for="email" class="form-label">{{ __('ui.parties.email') }}</label>
            <input id="email" name="email" type="email" maxlength="255" value="{{ old('email', $party->email) }}" class="form-control" dir="ltr">
        </div>
        <div>
            <label for="tax_identifier" class="form-label">{{ __('ui.parties.identifier') }}</label>
            <input id="tax_identifier" name="tax_identifier" maxlength="255" value="{{ old('tax_identifier', $party->tax_identifier) }}" class="form-control" dir="auto">
        </div>
        <div class="sm:col-span-2">
            <label for="address" class="form-label">{{ __('ui.parties.address') }}</label>
            <textarea id="address" name="address" rows="2" maxlength="10000" class="form-control">{{ old('address', $party->address) }}</textarea>
        </div>
        <div class="sm:col-span-2">
            <label for="notes" class="form-label">{{ __('ui.parties.notes') }}</label>
            <textarea id="notes" name="notes" rows="3" maxlength="10000" class="form-control">{{ old('notes', $party->notes) }}</textarea>
        </div>
        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="button button-primary">{{ __('ui.parties.save') }}</button>
            <a href="{{ $party->exists ? route($kind.'s.show', $party) : route($kind.'s.index') }}" class="button button-secondary">{{ __('ui.parties.cancel') }}</a>
        </div>
    </form>
</section>
@endsection