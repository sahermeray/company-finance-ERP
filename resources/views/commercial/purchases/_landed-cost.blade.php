<div class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" data-commercial-row>
    <div>
        <label class="form-label" for="landed-cost-type-{{ $index }}">{{ __('ui.commercial.landed_cost_type') }}</label>
        <select id="landed-cost-type-{{ $index }}" name="landed_costs[{{ $index }}][cost_type]" class="form-control">
            <option value="">{{ __('ui.commercial.select_option') }}</option>
            @foreach (['direct_freight', 'direct_handling', 'administrative'] as $costType)
                <option value="{{ $costType }}" @selected(($cost['cost_type'] ?? '') === $costType)>{{ __('ui.commercial.landed_cost_types.'.$costType) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="landed-cost-amount-{{ $index }}">{{ __('ui.commercial.amount') }}</label>
        <input id="landed-cost-amount-{{ $index }}" name="landed_costs[{{ $index }}][amount]" value="{{ $cost['amount'] ?? '' }}" class="form-control" type="number" min="0" step="0.0001">
    </div>
    <div>
        <label class="form-label" for="landed-cost-currency-{{ $index }}">{{ __('ui.commercial.currency') }}</label>
        <select id="landed-cost-currency-{{ $index }}" name="landed_costs[{{ $index }}][currency]" class="form-control" data-landed-currency>
            @foreach ($currencies as $currency)
                <option value="{{ $currency }}" @selected(($cost['currency'] ?? $defaultCurrency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="landed-cost-rate-{{ $index }}">{{ __('ui.commercial.exchange_rate') }}</label>
        <input id="landed-cost-rate-{{ $index }}" name="landed_costs[{{ $index }}][exchange_rate]" value="{{ $cost['exchange_rate'] ?? '' }}" class="form-control" type="number" min="0.0000000001" step="0.0000000001">
    </div>
    <div>
        <label class="form-label" for="landed-cost-payable-{{ $index }}">{{ __('ui.commercial.payable_account') }}</label>
        <select id="landed-cost-payable-{{ $index }}" name="landed_costs[{{ $index }}][payable_account_id]" class="form-control" data-currency-account>
            <option value="">{{ __('ui.commercial.automatic_account') }}</option>
            @foreach ($accountsByCurrency['liability'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($cost['payable_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="landed-cost-expense-{{ $index }}">{{ __('ui.commercial.expense_account') }}</label>
        <select id="landed-cost-expense-{{ $index }}" name="landed_costs[{{ $index }}][expense_account_id]" class="form-control" data-currency-account>
            <option value="">{{ __('ui.commercial.automatic_account') }}</option>
            @foreach ($accountsByCurrency['expense'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($cost['expense_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="form-label" for="landed-cost-description-{{ $index }}">{{ __('ui.commercial.description') }}</label>
        <input id="landed-cost-description-{{ $index }}" name="landed_costs[{{ $index }}][description]" value="{{ $cost['description'] ?? '' }}" class="form-control" type="text">
    </div>
    <div class="flex items-end justify-end">
        <button type="button" class="button button-subtle" data-remove-commercial-row>{{ __('ui.commercial.remove_line') }}</button>
    </div>
</div>
