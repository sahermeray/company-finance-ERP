<div class="commercial-line grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" data-commercial-row>
    <div>
        <label class="form-label" for="sale-product-{{ $index }}">{{ __('ui.commercial.product') }}</label>
        <select id="sale-product-{{ $index }}" name="lines[{{ $index }}][product_id]" class="form-control" required data-product-select>
            <option value="">{{ __('ui.commercial.select_product') }}</option>
            @foreach ($products as $product)
                <option value="{{ $product->getKey() }}" data-tracks-inventory="{{ $product->tracks_inventory ? '1' : '0' }}" data-unit="{{ $product->unitOfMeasure?->name }}" @selected((string) ($line['product_id'] ?? '') === (string) $product->getKey())>{{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}{{ $product->tracks_inventory ? ' · '.__('ui.inventory.tracked') : ' · '.__('ui.inventory.untracked') }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500" data-product-unit>{{ __('ui.commercial.unit') }}: —</p>
    </div>
    <div>
        <label class="form-label" for="sale-warehouse-{{ $index }}">{{ __('ui.commercial.warehouse') }}</label>
        <select id="sale-warehouse-{{ $index }}" name="lines[{{ $index }}][warehouse_id]" class="form-control" data-warehouse-select>
            <option value="">{{ __('ui.commercial.select_warehouse') }}</option>
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->getKey() }}" @selected((string) ($line['warehouse_id'] ?? '') === (string) $warehouse->getKey())>{{ $warehouse->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="sale-quantity-{{ $index }}">{{ __('ui.commercial.quantity') }}</label>
        <input id="sale-quantity-{{ $index }}" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control" type="number" min="0.0001" step="0.0001" required data-quantity>
    </div>
    <div>
        <label class="form-label" for="sale-price-{{ $index }}">{{ __('ui.commercial.unit_price') }}</label>
        <input id="sale-price-{{ $index }}" name="lines[{{ $index }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}" class="form-control" type="number" min="0" step="0.0001" required data-unit-price>
    </div>
    <div class="flex items-end justify-between gap-3 sm:col-span-2 lg:col-span-4">
        <p class="text-xs text-slate-500" data-stock-availability>{{ __('ui.commercial.stock_not_checked') }}</p>
        <button type="button" class="button button-subtle" data-remove-commercial-row>{{ __('ui.commercial.remove_line') }}</button>
    </div>
</div>
