<aside id="app-sidebar" class="app-sidebar" data-sidebar data-open="false" aria-label="{{ __('ui.navigation.menu') }}">
    <div class="sidebar-heading">
        <a href="{{ route('dashboard.index') }}" class="brand-lockup inline-flex min-w-0 items-center gap-3 text-white">
            <span class="brand-mark inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-500 text-sm font-bold text-white">N</span>
            <span class="sidebar-label truncate font-semibold">{{ __('ui.app_name') }}</span>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="{{ __('ui.navigation.menu') }}">
        @foreach (config('navigation') as $group)
            <details class="sidebar-navigation-group flex flex-col gap-1" data-navigation-group="{{ $group['key'] }}" open>
                <summary class="sidebar-group-label">{{ __($group['label']) }}</summary>

                @foreach ($group['items'] as $item)
                    @php
                        $isAvailable = isset($item['route'])
                            && \Illuminate\Support\Facades\Route::has($item['route']);
                        $isActive = $isAvailable
                            && request()->routeIs(...($item['active'] ?? [$item['route']]));
                    @endphp

                    @if ($isAvailable)
                        <a
                            data-navigation-item="{{ $item['key'] }}"
                            data-navigation-status="available"
                            href="{{ route($item['route']) }}"
                            @class(['sidebar-link', 'is-active' => $isActive])
                            @if ($isActive) aria-current="page" @endif
                            title="{{ __($item['label']) }}"
                        >
                            @isset ($item['icon'])
                                <x-icon :name="$item['icon']" />
                            @endisset
                            <span class="sidebar-label">{{ __($item['label']) }}</span>
                        </a>
                    @else
                        <span
                            data-navigation-item="{{ $item['key'] }}"
                            data-navigation-status="coming-soon"
                            class="sidebar-link is-unavailable"
                            aria-disabled="true"
                            aria-label="{{ __($item['label']) }} - {{ __('ui.navigation.soon') }}"
                            title="{{ __($item['label']) }} - {{ __('ui.navigation.soon') }}"
                        >
                            @isset ($item['icon'])
                                <x-icon :name="$item['icon']" />
                            @endisset
                            <span class="sidebar-label">{{ __($item['label']) }}</span>
                            <span class="sidebar-label sidebar-soon">{{ __('ui.navigation.soon') }}</span>
                        </span>
                    @endif
                @endforeach
            </details>
        @endforeach
    </nav>
</aside>
