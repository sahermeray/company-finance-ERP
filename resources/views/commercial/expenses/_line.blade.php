<div class="commercial-line grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" data-commercial-row>
    <div class="sm:col-span-2">
        <label class="form-label" for="expense-account-{{ $index }}">{{ __('ui.commercial.expense_account') }}</label>
        <select id="expense-account-{{ $index }}" name="lines[{{ $index }}][expense_account_id]" class="form-control" required data-currency-account>
            <option value="">{{ __('ui.commercial.select_account') }}</option>
            @foreach ($accounts['expense'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($line['expense_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="expense-category-{{ $index }}">{{ __('ui.expenses.category') }}</label>
        <input id="expense-category-{{ $index }}" name="lines[{{ $index }}][category]" value="{{ $line['category'] ?? '' }}" class="form-control" type="text" maxlength="120" list="expense-categories" required>
    </div>
    <div>
        <label class="form-label" for="expense-description-{{ $index }}">{{ __('ui.commercial.description') }}</label>
        <input id="expense-description-{{ $index }}" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" class="form-control" type="text" maxlength="255" required>
    </div>
    <div>
        <label class="form-label" for="expense-quantity-{{ $index }}">{{ __('ui.commercial.quantity') }}</label>
        <input id="expense-quantity-{{ $index }}" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control" type="number" min="0.0001" step="0.0001" required data-quantity>
    </div>
    <div>
        <label class="form-label" for="expense-price-{{ $index }}">{{ __('ui.commercial.unit_price') }}</label>
        <input id="expense-price-{{ $index }}" name="lines[{{ $index }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}" class="form-control" type="number" min="0.0001" step="0.0001" required data-unit-price>
    </div>
    <div>
        <label class="form-label" for="expense-tax-{{ $index }}">{{ __('ui.commercial.tax_amount') }}</label>
        <input id="expense-tax-{{ $index }}" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? '' }}" class="form-control" type="number" min="0" step="0.0001">
    </div>
    <div>
        <label class="form-label" for="expense-tax-treatment-{{ $index }}">{{ __('ui.commercial.tax_treatment') }}</label>
        <select id="expense-tax-treatment-{{ $index }}" name="lines[{{ $index }}][tax_treatment]" class="form-control">
            <option value="">{{ __('ui.commercial.not_applicable') }}</option>
            <option value="recoverable" @selected(($line['tax_treatment'] ?? '') === 'recoverable')>{{ __('ui.commercial.recoverable') }}</option>
            <option value="non_recoverable" @selected(($line['tax_treatment'] ?? '') === 'non_recoverable')>{{ __('ui.commercial.non_recoverable') }}</option>
        </select>
    </div>
    <div>
        <label class="form-label" for="expense-tax-account-{{ $index }}">{{ __('ui.commercial.tax_asset_account') }}</label>
        <select id="expense-tax-account-{{ $index }}" name="lines[{{ $index }}][tax_asset_account_id]" class="form-control" data-currency-account>
            <option value="">{{ __('ui.commercial.select_account') }}</option>
            @foreach ($accounts['asset'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($line['tax_asset_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-end justify-end sm:col-span-2 lg:col-span-4">
        <button type="button" class="button button-subtle" data-remove-commercial-row>{{ __('ui.commercial.remove_line') }}</button>
    </div>
</div>
