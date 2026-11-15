@extends('layouts.inventory')

@section('title', $revenue->revenue_number.' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('sales.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.commercial.back_to_sales') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950"><bdi dir="ltr">{{ $revenue->revenue_number }}</bdi></h1>
            <span class="status-badge {{ $revenue->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.commercial.statuses.'.$revenue->status) }}</span>
        </div>
    </div>
    <a href="{{ route('sales.print', $revenue) }}" class="button button-secondary">{{ __('ui.commercial.print_invoice') }}</a>
</div>

<div class="grid gap-5">
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="form-label">{{ __('ui.commercial.customer') }}</p><p class="font-medium">{{ $revenue->party->name }}</p></div>
        <div><p class="form-label">{{ __('ui.commercial.date') }}</p><p><bdi dir="ltr">{{ $revenue->revenue_date->format('Y-m-d') }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.due_date') }}</p><p><bdi dir="ltr">{{ $revenue->due_date?->format('Y-m-d') ?? '—' }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.currency') }}</p><p><bdi dir="ltr">{{ $revenue->currency }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.exchange_rate') }}</p><p><bdi dir="ltr">{{ $revenue->exchange_rate }} {{ $revenue->base_currency }}/{{ $revenue->currency }}</bdi></p></div>
        @if ($revenue->notes)
            <div class="sm:col-span-2 lg:col-span-4"><p class="form-label">{{ __('ui.commercial.notes') }}</p><p class="whitespace-pre-line">{{ $revenue->notes }}</p></div>
        @endif
    </section>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[42rem] text-start text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('ui.commercial.product') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.warehouse') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.quantity') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.unit_price') }}</th>
                        <th class="px-4 py-3">{{ __('ui.commercial.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($revenue->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ $item->description ?: $item->product->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $item->product->tracks_inventory ? __('ui.inventory.tracked') : __('ui.inventory.untracked') }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $item->warehouse?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->quantity }} {{ $item->unitOfMeasure?->symbol ?? $item->unitOfMeasure?->code }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->unit_price }} {{ $revenue->currency }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->line_total }} {{ $revenue->currency }}</bdi></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-2 border-t border-slate-100 p-4 text-sm sm:ms-auto sm:w-80">
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.subtotal') }}</span><bdi dir="ltr">{{ $revenue->subtotal }} {{ $revenue->currency }}</bdi></p>
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.tax_total') }}</span><bdi dir="ltr">{{ $revenue->tax_total }} {{ $revenue->currency }}</bdi></p>
            <p class="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base font-semibold"><span>{{ __('ui.commercial.total') }}</span><bdi dir="ltr">{{ $revenue->total }} {{ $revenue->currency }}</bdi></p>
            <p class="flex justify-between gap-4 text-xs text-slate-500"><span>{{ __('ui.commercial.base_total') }}</span><bdi dir="ltr">{{ $revenue->base_total }} {{ $revenue->base_currency }}</bdi></p>
        </div>
    </section>

    @if ($stockMovements->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold">{{ __('ui.commercial.inventory_effects') }}</h2>
            <p class="mb-3 text-sm text-slate-500">{{ __('ui.commercial.cogs_note', ['currency' => $revenue->base_currency]) }}</p>
            <div class="grid gap-2">
                @foreach ($stockMovements as $movement)
                    <div class="flex flex-wrap justify-between gap-3 border-b border-slate-100 py-2 text-sm last:border-0">
                        <span>{{ $movement->product?->name }} · {{ $movement->warehouse?->name }}</span>
                        <bdi dir="ltr">{{ $movement->quantity }} {{ __('ui.inventory.outgoing') }} · {{ $movement->base_cost_amount }} {{ $revenue->base_currency }}</bdi>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($revenue->accountingJournals->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold">{{ __('ui.commercial.accounting_effects') }}</h2>
            @foreach ($revenue->accountingJournals as $journal)
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[42rem] text-start text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                            <tr><th class="px-4 py-3">{{ __('ui.commercial.account') }}</th><th class="px-4 py-3">{{ __('ui.commercial.debit') }}</th><th class="px-4 py-3">{{ __('ui.commercial.credit') }}</th><th class="px-4 py-3">{{ __('ui.commercial.currency') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($journal->lines as $line)
                                <tr><td class="px-4 py-3">{{ $line->financialAccount?->name }}</td><td class="px-4 py-3"><bdi dir="ltr">{{ $line->debit }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ $line->credit }}</bdi></td><td class="px-4 py-3"><bdi dir="ltr">{{ $line->currency }}</bdi></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </section>
    @endif

    @if ($revenue->status === 'draft')
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold">{{ __('ui.commercial.post_sale') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.commercial.post_sale_help') }}</p>
            <form method="POST" action="{{ route('sales.post', $revenue) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @csrf
                <div>
                    <label for="settlement_type" class="form-label">{{ __('ui.commercial.payment_type') }}</label>
                    <select id="settlement_type" name="settlement_type" class="form-control" required>
                        <option value="receivable">{{ __('ui.commercial.credit_sale') }}</option>
                        <option value="cash">{{ __('ui.commercial.cash_sale') }}</option>
                    </select>
                </div>
                <div>
                    <label for="settlement_account_id" class="form-label">{{ __('ui.commercial.payment_account') }}</label>
                    <select id="settlement_account_id" name="settlement_account_id" class="form-control" required>
                        <option value="">{{ __('ui.commercial.select_account') }}</option>
                        @foreach ($accountsByCurrency['asset']->where('currency', $revenue->currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="revenue_account_id" class="form-label">{{ __('ui.commercial.revenue_account') }}</label>
                    <select id="revenue_account_id" name="revenue_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.automatic_account') }}</option>
                        @foreach ($accountsByCurrency['revenue']->where('currency', $revenue->currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="inventory_account_id" class="form-label">{{ __('ui.commercial.inventory_account') }}</label>
                    <select id="inventory_account_id" name="inventory_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.select_account') }}</option>
                        @foreach ($accountsByCurrency['asset']->where('currency', $revenue->base_currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="cogs_account_id" class="form-label">{{ __('ui.commercial.cogs_account') }}</label>
                    <select id="cogs_account_id" name="cogs_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.select_account') }}</option>
                        @foreach ($accountsByCurrency['expense']->where('currency', $revenue->base_currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <button type="submit" class="button button-primary">{{ __('ui.commercial.post') }}</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
