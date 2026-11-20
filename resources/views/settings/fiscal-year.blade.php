@extends('layouts.inventory')

@section('title', __('ui.settings.nav_fiscal_year').' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6">
    <p class="text-sm font-semibold text-teal-700">{{ __('ui.navigation.administration') }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.settings.title') }}</h1>
</div>

@include('settings._subnav')

<section class="panel max-w-2xl p-6">
    <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ __('ui.settings.fiscal_year.heading') }}</h2>
    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
        <div class="flex gap-3">
            <x-icon name="info" size="20" class="mt-0.5 shrink-0 text-slate-400" />
            <p>{{ __('ui.settings.fiscal_year.notice') }}</p>
        </div>
    </div>
</section>
@endsection
