@props(['title', 'metric', 'currency', 'icon'])

@php
    $availability = $metric['availability'] ?? 'unavailable';
    $value = $metric['value'] ?? null;
    $hasValue = $value !== null && in_array($availability, ['available', 'incomplete'], true);
    $formatAmount = static function (string $amount): string {
        $negative = str_starts_with($amount, '-');
        $parts = explode('.', ltrim($amount, '-'), 2);
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]) ?? $parts[0];
        $fraction = rtrim($parts[1] ?? '', '0');

        return ($negative ? '-' : '').$integer.($fraction === '' ? '' : '.'.$fraction);
    };
    $status = match ($availability) {
        'available' => 'available',
        'incomplete' => 'incomplete',
        'unsupported' => 'unsupported',
        default => 'unavailable',
    };
    $comparisonValue = $metric['comparison']['value'] ?? null;
@endphp

<article class="metric-card rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <div class="flex items-start justify-between gap-3">
        <h2 class="text-sm font-medium leading-5 text-slate-600">{{ $title }}</h2>
        <span class="metric-icon inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700">
            <x-icon :name="$icon" :size="18" />
        </span>
    </div>

    @if ($hasValue)
        <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-950">
            <bdi dir="ltr">{{ $formatAmount((string) $value) }}</bdi>
            <span class="ms-1 text-xs font-medium text-slate-500">{{ $metric['currency'] ?? $currency }}</span>
        </p>
        <div class="mt-3 flex min-h-5 flex-wrap items-center gap-x-2 gap-y-1">
            <span class="status-badge status-{{ $status }}">{{ __('ui.status.'.$status) }}</span>
            @if ($availability === 'incomplete')
                <span class="text-xs text-amber-700">{{ __('ui.status.partial') }}</span>
            @endif
            @if ($comparisonValue !== null)
                <span class="text-xs text-slate-500">
                    {{ __('ui.dashboard.vs_previous') }}
                    <bdi dir="ltr">{{ $formatAmount((string) $comparisonValue) }} {{ $metric['currency'] ?? $currency }}</bdi>
                </span>
            @endif
        </div>
    @else
        <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-400">—</p>
        <p class="mt-3 text-xs leading-5 text-slate-500">
            <span class="status-badge status-{{ $status }}">{{ __('ui.status.'.$status) }}</span>
            <span class="ms-1">{{ $metric['unavailable_description'] ?? __('ui.status.'.$status.'_description') }}</span>
        </p>
    @endif
</article>
