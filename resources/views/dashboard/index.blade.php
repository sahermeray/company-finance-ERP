@extends('layouts.app')

@section('title', __('ui.dashboard.title').' · '.__('ui.app_name'))

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
                            <select id="company_id" name="company_id" class="company-select">
                                @foreach ($companies as $availableCompany)
                                    <option value="{{ $availableCompany->getKey() }}" @selected((int) $company->getKey() === (int) $availableCompany->getKey())>
                                        {{ $availableCompany->name }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="button button-subtle">{{ __('ui.actions.switch_company') }}</button>
                        </form>
                    @else
                        <span class="company-name" title="{{ $company->name }}">{{ $company->name }}</span>
                        <span class="company-currency">{{ strtoupper((string) $company->base_currency) }}</span>
                    @endif
                </div>
            </div>

            <div class="topbar-actions">
                <form method="POST" action="{{ route('language.update') }}" class="language-switcher">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                    <label for="app-locale" class="sr-only">{{ __('ui.language.label') }}</label>
                    <select id="app-locale" name="locale" class="form-control form-control-compact" aria-label="{{ __('ui.language.label') }}" data-auto-submit>
                        <option value="ar" @selected(app()->getLocale() === 'ar')>{{ __('ui.language.arabic') }}</option>
                        <option value="en" @selected(app()->getLocale() === 'en')>{{ __('ui.language.english') }}</option>
                    </select>
                    <button type="submit" class="sr-only">{{ __('ui.actions.apply') }}</button>
                </form>

                <button type="button" class="icon-button notification-placeholder" aria-label="{{ __('ui.notifications.label') }}" title="{{ __('ui.notifications.placeholder') }}" aria-disabled="true">
                    <x-icon name="bell" />
                </button>

                <details class="user-menu">
                    <summary class="user-menu-trigger" aria-label="{{ __('ui.profile.signed_in_as') }} {{ auth()->user()->name }}">
                        <span class="user-avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                        <span class="user-name">{{ auth()->user()->name }}</span>
                        <x-icon name="chevron" :size="16" />
                    </summary>
                    <div class="user-menu-panel">
                        <p class="text-xs text-slate-500">{{ __('ui.profile.signed_in_as') }}</p>
                        <p class="mt-1 font-medium text-slate-900">{{ auth()->user()->name }}</p>
                        <p class="mt-0.5 break-all text-sm text-slate-600">{{ auth()->user()->email }}</p>
                        <form method="POST" action="{{ route('logout') }}" class="mt-4 border-t border-slate-100 pt-3">
                            @csrf
                            <button type="submit" class="menu-action">
                                <x-icon name="logout" :size="17" />
                                <span>{{ __('ui.actions.logout') }}</span>
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <div class="dashboard-content mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
            <section class="mb-6 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-medium text-teal-700">{{ __('ui.dashboard.greeting') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{{ __('ui.dashboard.title') }}</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ __('ui.dashboard.period_description') }}</p>
                </div>

                <form method="GET" action="{{ route('dashboard.index') }}" class="period-form rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-36">
                            <label for="period" class="form-label">{{ __('ui.dashboard.period_title') }}</label>
                            <select id="period" name="period" class="form-control">
                                <option value="today" @selected($period === 'today')>{{ __('ui.periods.today') }}</option>
                                <option value="week" @selected($period === 'week')>{{ __('ui.periods.week') }}</option>
                                <option value="month" @selected($period === 'month')>{{ __('ui.periods.month') }}</option>
                                <option value="year" @selected($period === 'year')>{{ __('ui.periods.year') }}</option>
                                <option value="custom" @selected($period === 'custom')>{{ __('ui.periods.custom') }}</option>
                            </select>
                        </div>
                        <div class="date-field">
                            <label for="start_date" class="form-label">{{ __('ui.periods.start_date') }}</label>
                            <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $fromDate) }}" class="form-control @error('start_date') form-control-error @enderror">
                        </div>
                        <div class="date-field">
                            <label for="end_date" class="form-label">{{ __('ui.periods.end_date') }}</label>
                            <input id="end_date" name="end_date" type="date" value="{{ old('end_date', $toDate) }}" class="form-control @error('end_date') form-control-error @enderror">
                        </div>
                        <button type="submit" class="button button-primary">{{ __('ui.actions.apply') }}</button>
                    </div>
                    @if ($errors->hasAny(['period', 'start_date', 'end_date']))
                        <div class="mt-3 space-y-1" role="alert">
                            @foreach ($errors->get('period') as $message)
                                <p class="form-error">{{ $message }}</p>
                            @endforeach
                            @foreach ($errors->get('start_date') as $message)
                                <p class="form-error">{{ $message }}</p>
                            @endforeach
                            @foreach ($errors->get('end_date') as $message)
                                <p class="form-error">{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-3 text-xs text-slate-500"><bdi dir="ltr">{{ $fromDate }} — {{ $toDate }}</bdi></p>
                </form>
            </section>

            <section aria-label="{{ __('ui.dashboard.title') }}">
                <div class="metric-grid grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-dashboard.metric-card :title="__('ui.metrics.sales_revenue')" :metric="$metrics['sales_revenue']" :currency="$company->base_currency" icon="trend" />
                    <x-dashboard.metric-card :title="__('ui.metrics.purchases')" :metric="$metrics['purchases']" :currency="$company->base_currency" icon="purchases" />
                    <x-dashboard.metric-card :title="__('ui.metrics.gross_profit')" :metric="$metrics['gross_profit']" :currency="$company->base_currency" icon="reports" />
                    <x-dashboard.metric-card :title="__('ui.metrics.operating_expenses')" :metric="$metrics['operating_expenses']" :currency="$company->base_currency" icon="expenses" />
                    <x-dashboard.metric-card :title="__('ui.metrics.net_profit')" :metric="$metrics['net_profit']" :currency="$company->base_currency" icon="accounting" />
                    <x-dashboard.metric-card :title="__('ui.metrics.inventory')" :metric="$metrics['inventory']" :currency="$company->base_currency" icon="inventory" />
                    <x-dashboard.metric-card :title="__('ui.metrics.receivables')" :metric="$metrics['receivables']" :currency="$company->base_currency" icon="customers" />
                    <x-dashboard.metric-card :title="__('ui.metrics.payables')" :metric="$metrics['payables']" :currency="$company->base_currency" icon="suppliers" />
                </div>
            </section>

            <div class="mt-6 grid grid-cols-1 gap-5 xl:grid-cols-3">
                <section class="panel xl:col-span-2" aria-labelledby="trend-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="trend-title" class="text-base font-semibold text-slate-950">{{ __('ui.dashboard.trend_title') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('ui.dashboard.period_title') }}: <bdi dir="ltr">{{ $fromDate }} — {{ $toDate }}</bdi></p>
                        </div>
                    </div>

                    @if ($chartAvailable)
                        <div class="trend-legend" aria-hidden="true">
                            <span><i class="legend-dot legend-revenue"></i>{{ __('ui.trend.revenue') }}</span>
                            <span><i class="legend-dot legend-expenses"></i>{{ __('ui.trend.expenses') }}</span>
                            <span><i class="legend-dot legend-profit"></i>{{ __('ui.trend.net_profit') }}</span>
                        </div>
                        <div class="trend-list">
                            @foreach ($chartMonths as $month)
                                <div class="trend-month">
                                    <bdi class="trend-month-label" dir="ltr">{{ $month['month'] }}</bdi>
                                    <div class="trend-month-lines">
                                        @foreach ([
                                            ['key' => 'revenue', 'label' => __('ui.trend.revenue'), 'color' => 'revenue'],
                                            ['key' => 'expenses', 'label' => __('ui.trend.expenses'), 'color' => 'expenses'],
                                            ['key' => 'profit', 'label' => __('ui.trend.net_profit'), 'color' => 'profit'],
                                        ] as $trendMetric)
                                            <div class="trend-line">
                                                <span class="trend-line-label">{{ $trendMetric['label'] }}</span>
                                                <span class="trend-track">
                                                    <span class="trend-bar trend-bar-{{ $trendMetric['color'] }}" style="width: {{ $month[$trendMetric['key'].'_width'] }}%"></span>
                                                </span>
                                                <bdi class="trend-value" dir="ltr">{{ $month[$trendMetric['key']].' '.$company->base_currency }}</bdi>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="empty-state" role="status">
                            <x-icon name="reports" :size="24" />
                            <p class="mt-3 font-medium text-slate-800">{{ __('ui.dashboard.trend_incomplete') }}</p>
                        </div>
                    @endif
                </section>

                <section class="panel" aria-labelledby="warnings-title">
                    <div class="panel-heading">
                        <h2 id="warnings-title" class="text-base font-semibold text-slate-950">{{ __('ui.dashboard.warnings_title') }}</h2>
                    </div>
                    @forelse ($warnings as $warning)
                        <article class="warning-item {{ $warning['type'] === 'discrepancy' ? 'warning-item-discrepancy' : 'warning-item-incomplete' }}">
                            <span class="mt-0.5 shrink-0">
                                <x-icon name="warning" :size="18" />
                            </span>
                            <div>
                                <p class="text-sm font-medium">{{ $warning['category'] }}</p>
                                <p class="mt-1 text-sm leading-5">{{ $warning['message'] }}</p>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state empty-state-compact" role="status">
                            <p class="text-sm text-slate-600">{{ __('ui.dashboard.warnings_empty') }}</p>
                        </div>
                    @endforelse
                </section>
            </div>

            <section class="panel mt-5" aria-labelledby="activity-title">
                <div class="panel-heading">
                    <div>
                        <h2 id="activity-title" class="text-base font-semibold text-slate-950">{{ __('ui.dashboard.activity_title') }}</h2>
                    </div>
                </div>
                <div class="empty-state empty-state-compact" role="status">
                    <p class="text-sm text-slate-600">{{ __('ui.dashboard.activity_empty') }}</p>
                </div>
            </section>
        </div>
    </main>
</div>
@endsection
