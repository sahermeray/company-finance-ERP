<nav class="mb-6 flex flex-wrap gap-2">
    <a href="{{ route('settings.general') }}" @class(['button', 'button-primary' => request()->routeIs('settings.general'), 'button-secondary' => ! request()->routeIs('settings.general')])>
        {{ __('ui.settings.nav_general') }}
    </a>
    <a href="{{ route('settings.financial') }}" @class(['button', 'button-primary' => request()->routeIs('settings.financial'), 'button-secondary' => ! request()->routeIs('settings.financial')])>
        {{ __('ui.settings.nav_financial') }}
    </a>
    <a href="{{ route('settings.exchange-rates') }}" @class(['button', 'button-primary' => request()->routeIs('settings.exchange-rates'), 'button-secondary' => ! request()->routeIs('settings.exchange-rates')])>
        {{ __('ui.settings.nav_exchange_rates') }}
    </a>
    <a href="{{ route('settings.forex-accounts') }}" @class(['button', 'button-primary' => request()->routeIs('settings.forex-accounts'), 'button-secondary' => ! request()->routeIs('settings.forex-accounts')])>
        {{ __('ui.settings.nav_forex_accounts') }}
    </a>
    <a href="{{ route('settings.fiscal-year') }}" @class(['button', 'button-primary' => request()->routeIs('settings.fiscal-year'), 'button-secondary' => ! request()->routeIs('settings.fiscal-year')])>
        {{ __('ui.settings.nav_fiscal_year') }}
    </a>
</nav>
