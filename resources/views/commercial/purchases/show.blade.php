@extends('layouts.inventory')

@section('title', $purchase->purchase_number.' · '.__('ui.app_name'))

@section('page-content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('purchases.index') }}" class="text-sm font-medium text-teal-800 hover:underline">{{ __('ui.commercial.back_to_purchases') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950"><bdi dir="ltr">{{ $purchase->purchase_number }}</bdi></h1>
            <span class="status-badge {{ $purchase->status === 'posted' ? 'status-available' : 'status-unavailable' }}">{{ __('ui.commercial.statuses.'.$purchase->status) }}</span>
        </div>
    </div>
    <a href="{{ route('purchases.print', $purchase) }}" class="button button-secondary">{{ __('ui.commercial.print_invoice') }}</a>
</div>

<div class="grid gap-5">
    <section class="panel grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="form-label">{{ __('ui.commercial.supplier') }}</p><p class="font-medium">{{ $purchase->party->name }}</p></div>
        <div><p class="form-label">{{ __('ui.commercial.date') }}</p><p><bdi dir="ltr">{{ $purchase->purchase_date->format('Y-m-d') }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.due_date') }}</p><p><bdi dir="ltr">{{ $purchase->due_date?->format('Y-m-d') ?? '—' }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.currency') }}</p><p><bdi dir="ltr">{{ $purchase->currency }}</bdi></p></div>
        <div><p class="form-label">{{ __('ui.commercial.exchange_rate') }}</p><p><bdi dir="ltr">{{ $purchase->exchange_rate }} {{ $purchase->base_currency }}/{{ $purchase->currency }}</bdi></p></div>
        @if ($purchase->notes)
            <div class="sm:col-span-2 lg:col-span-4"><p class="form-label">{{ __('ui.commercial.notes') }}</p><p class="whitespace-pre-line">{{ $purchase->notes }}</p></div>
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
                    @foreach ($purchase->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ $item->description ?: $item->product->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $item->product->tracks_inventory ? __('ui.inventory.tracked') : __('ui.inventory.untracked') }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $item->warehouse?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->quantity }} {{ $item->unitOfMeasure?->symbol ?? $item->unitOfMeasure?->code }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->unit_price }} {{ $purchase->currency }}</bdi></td>
                            <td class="px-4 py-3"><bdi dir="ltr">{{ $item->line_total }} {{ $purchase->currency }}</bdi></td>
                        </tr>
                        @foreach ($item->taxLines as $taxLine)
                            <tr class="bg-slate-50/70 text-xs text-slate-600">
                                <td colspan="3" class="px-4 py-2">{{ __('ui.commercial.tax') }} · {{ __('ui.commercial.'.$taxLine->treatment) }}</td>
                                <td colspan="2" class="px-4 py-2"><bdi dir="ltr">{{ $taxLine->amount }} {{ $purchase->currency }}</bdi></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-2 border-t border-slate-100 p-4 text-sm sm:ms-auto sm:w-80">
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.subtotal') }}</span><bdi dir="ltr">{{ $purchase->subtotal }} {{ $purchase->currency }}</bdi></p>
            <p class="flex justify-between gap-4"><span>{{ __('ui.commercial.tax_total') }}</span><bdi dir="ltr">{{ $purchase->tax_total }} {{ $purchase->currency }}</bdi></p>
            <p class="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base font-semibold"><span>{{ __('ui.commercial.total') }}</span><bdi dir="ltr">{{ $purchase->total }} {{ $purchase->currency }}</bdi></p>
            <p class="flex justify-between gap-4 text-xs text-slate-500"><span>{{ __('ui.commercial.base_total') }}</span><bdi dir="ltr">{{ $purchase->base_total }} {{ $purchase->base_currency }}</bdi></p>
        </div>
    </section>

    @if ($purchase->landedCosts->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold">{{ __('ui.commercial.landed_costs') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-start text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                        <tr><th class="px-4 py-3">{{ __('ui.commercial.description') }}</th><th class="px-4 py-3">{{ __('ui.commercial.landed_cost_type') }}</th><th class="px-4 py-3">{{ __('ui.commercial.amount') }}</th><th class="px-4 py-3">{{ __('ui.commercial.base_amount') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($purchase->landedCosts as $cost)
                            <tr>
                                <td class="px-4 py-3">{{ $cost->description ?: __('ui.commercial.landed_cost_types.'.$cost->cost_type) }}</td>
                                <td class="px-4 py-3">{{ __('ui.commercial.landed_cost_types.'.$cost->cost_type) }}</td>
                                <td class="px-4 py-3"><bdi dir="ltr">{{ $cost->amount }} {{ $cost->currency }}</bdi></td>
                                <td class="px-4 py-3"><bdi dir="ltr">{{ $cost->base_amount }} {{ $cost->base_currency }}</bdi></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">{{ __('ui.commercial.landed_cost_currency_note') }}</p>
        </section>
    @endif

    @if ($purchase->stockMovements->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold">{{ __('ui.commercial.inventory_effects') }}</h2>
            <div class="grid gap-2">
                @foreach ($purchase->stockMovements as $movement)
                    <div class="flex flex-wrap justify-between gap-3 border-b border-slate-100 py-2 text-sm last:border-0">
                        <span>{{ $movement->product?->name }} · {{ $movement->warehouse?->name }}</span>
                        <bdi dir="ltr">{{ $movement->quantity }} {{ __('ui.inventory.incoming') }} · {{ $movement->base_cost_amount }} {{ $purchase->base_currency }}</bdi>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($purchase->accountingJournals->isNotEmpty())
        <section class="panel p-4">
            <h2 class="mb-3 text-lg font-semibold">{{ __('ui.commercial.accounting_effects') }}</h2>
            @foreach ($purchase->accountingJournals as $journal)
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

    @if ($purchase->status === 'draft')
        <section class="panel p-4">
            <h2 class="mb-1 text-lg font-semibold">{{ __('ui.commercial.post_purchase') }}</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('ui.commercial.post_purchase_help') }}</p>
            <form method="POST" action="{{ route('purchases.post', $purchase) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @csrf
                <div>
                    <label for="payable_account_id" class="form-label">{{ __('ui.commercial.payable_account') }}</label>
                    <select id="payable_account_id" name="payable_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.automatic_account') }}</option>
                        @foreach ($accountsByCurrency['liability']->where('currency', $purchase->currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="inventory_account_id" class="form-label">{{ __('ui.commercial.inventory_account') }}</label>
                    <select id="inventory_account_id" name="inventory_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.automatic_account') }}</option>
                        @foreach ($accountsByCurrency['asset']->where('currency', $purchase->base_currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="expense_account_id" class="form-label">{{ __('ui.commercial.expense_account') }}</label>
                    <select id="expense_account_id" name="expense_account_id" class="form-control">
                        <option value="">{{ __('ui.commercial.automatic_account') }}</option>
                        @foreach ($accountsByCurrency['expense']->where('currency', $purchase->currency) as $account)
                            <option value="{{ $account->getKey() }}">{{ $account->code }} · {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="warehouse_id" class="form-label">{{ __('ui.commercial.default_warehouse') }}</label>
                    <select id="warehouse_id" name="warehouse_id" class="form-control">
                        <option value="">{{ __('ui.commercial.no_default_warehouse') }}</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->getKey() }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="button button-primary">{{ __('ui.commercial.post') }}</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
