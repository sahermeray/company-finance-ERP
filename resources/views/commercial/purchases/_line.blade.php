<div class="commercial-line grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" data-commercial-row>
    <div>
        <label class="form-label" for="purchase-product-{{ $index }}">{{ __('ui.commercial.product') }}</label>
        <select id="purchase-product-{{ $index }}" name="lines[{{ $index }}][product_id]" class="form-control" required data-product-select>
            <option value="">{{ __('ui.commercial.select_product') }}</option>
            @foreach ($products as $product)
                <option value="{{ $product->getKey() }}" data-tracks-inventory="{{ $product->tracks_inventory ? '1' : '0' }}" data-unit="{{ $product->unitOfMeasure?->name }}" @selected((string) ($line['product_id'] ?? '') === (string) $product->getKey())>{{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500" data-product-unit>{{ __('ui.commercial.unit') }}: —</p>
    </div>
    <div>
        <label class="form-label" for="purchase-warehouse-{{ $index }}">{{ __('ui.commercial.warehouse') }}</label>
        <select id="purchase-warehouse-{{ $index }}" name="lines[{{ $index }}][warehouse_id]" class="form-control" data-warehouse-select>
            <option value="">{{ __('ui.commercial.select_warehouse') }}</option>
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->getKey() }}" @selected((string) ($line['warehouse_id'] ?? '') === (string) $warehouse->getKey())>{{ $warehouse->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="purchase-quantity-{{ $index }}">{{ __('ui.commercial.quantity') }}</label>
        <input id="purchase-quantity-{{ $index }}" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control" type="number" min="0.0001" step="0.0001" required data-quantity>
    </div>
    <div>
        <label class="form-label" for="purchase-price-{{ $index }}">{{ __('ui.commercial.unit_price') }}</label>
        <input id="purchase-price-{{ $index }}" name="lines[{{ $index }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}" class="form-control" type="number" min="0" step="0.0001" required data-unit-price>
    </div>
    <div>
        <label class="form-label" for="purchase-tax-{{ $index }}">{{ __('ui.commercial.tax_amount') }}</label>
        <input id="purchase-tax-{{ $index }}" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? '' }}" class="form-control" type="number" min="0" step="0.0001">
    </div>
    <div>
        <label class="form-label" for="purchase-tax-treatment-{{ $index }}">{{ __('ui.commercial.tax_treatment') }}</label>
        <select id="purchase-tax-treatment-{{ $index }}" name="lines[{{ $index }}][tax_treatment]" class="form-control">
            <option value="">{{ __('ui.commercial.not_applicable') }}</option>
            <option value="recoverable" @selected(($line['tax_treatment'] ?? '') === 'recoverable')>{{ __('ui.commercial.recoverable') }}</option>
            <option value="non_recoverable" @selected(($line['tax_treatment'] ?? '') === 'non_recoverable')>{{ __('ui.commercial.non_recoverable') }}</option>
        </select>
    </div>
    <div>
        <label class="form-label" for="purchase-tax-account-{{ $index }}">{{ __('ui.commercial.tax_asset_account') }}</label>
        <select id="purchase-tax-account-{{ $index }}" name="lines[{{ $index }}][tax_asset_account_id]" class="form-control" data-currency-account>
            <option value="">{{ __('ui.commercial.select_account') }}</option>
            @foreach ($accountsByCurrency['asset'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($line['tax_asset_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="purchase-expense-account-{{ $index }}">{{ __('ui.commercial.expense_account') }}</label>
        <select id="purchase-expense-account-{{ $index }}" name="lines[{{ $index }}][expense_account_id]" class="form-control" data-currency-account>
            <option value="">{{ __('ui.commercial.automatic_account') }}</option>
            @foreach ($accountsByCurrency['expense'] as $account)
                <option value="{{ $account->getKey() }}" data-currency="{{ $account->currency }}" @selected((string) ($line['expense_account_id'] ?? '') === (string) $account->getKey())>{{ $account->code }} · {{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-end justify-between gap-3 sm:col-span-2 lg:col-span-4">
        <p class="text-xs text-slate-500" data-stock-availability>{{ __('ui.commercial.stock_not_checked') }}</p>
        <button type="button" class="button button-subtle" data-remove-commercial-row>{{ __('ui.commercial.remove_line') }}</button>
    </div>
</div>
