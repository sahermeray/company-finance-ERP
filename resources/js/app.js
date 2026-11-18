const sidebar = document.querySelector('[data-sidebar]');
const sidebarOverlay = document.querySelector('[data-sidebar-overlay]');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const desktopSidebar = window.matchMedia('(min-width: 1024px)');

if (sidebar && sidebarToggle) {
    const setToggleState = () => {
        const expanded = desktopSidebar.matches
            ? document.documentElement.dataset.sidebarCollapsed !== 'true'
            : sidebar.dataset.open === 'true';
        const label = desktopSidebar.matches
            ? expanded
                ? sidebarToggle.dataset.collapseLabel
                : sidebarToggle.dataset.expandLabel
            : expanded
                ? sidebarToggle.dataset.closeLabel
                : sidebarToggle.dataset.openLabel;

        sidebarToggle.setAttribute('aria-expanded', String(expanded));
        sidebarToggle.setAttribute('aria-label', label);
        sidebarToggle.title = label;
    };

    const closeMobileSidebar = () => {
        sidebar.dataset.open = 'false';
        sidebarOverlay?.setAttribute('data-open', 'false');
        setToggleState();
    };

    sidebarToggle.addEventListener('click', () => {
        if (desktopSidebar.matches) {
            document.documentElement.dataset.sidebarCollapsed =
                document.documentElement.dataset.sidebarCollapsed === 'true' ? 'false' : 'true';
        } else {
            sidebar.dataset.open = sidebar.dataset.open === 'true' ? 'false' : 'true';
            sidebarOverlay?.setAttribute('data-open', sidebar.dataset.open);
        }

        setToggleState();
    });

    sidebarOverlay?.addEventListener('click', closeMobileSidebar);
    sidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => {
            if (!desktopSidebar.matches) {
                closeMobileSidebar();
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.dataset.open === 'true') {
            closeMobileSidebar();
            sidebarToggle.focus();
        }
    });

    desktopSidebar.addEventListener('change', () => {
        closeMobileSidebar();
        setToggleState();
    });

    setToggleState();
}

document.querySelectorAll('[data-auto-submit]').forEach((input) => {
    input.addEventListener('change', () => input.form?.requestSubmit());
});

const countLines = document.querySelector('[data-count-lines]');
const countLineTemplate = document.querySelector('[data-count-line-template]');
const addCountLineButton = document.querySelector('[data-add-count-line]');

if (countLines && countLineTemplate && addCountLineButton) {
    let nextCountLineIndex = [...countLines.querySelectorAll('[name]')].reduce((nextIndex, field) => {
        const index = field.name.match(/^lines\[(\d+)\]/)?.[1];
        return index === undefined ? nextIndex : Math.max(nextIndex, Number(index) + 1);
    }, 0);

    addCountLineButton.addEventListener('click', () => {
        const row = countLineTemplate.content.firstElementChild.cloneNode(true);

        row.querySelectorAll('[data-name]').forEach((field) => {
            const fieldName = field.dataset.name;
            const fieldId = `${field.dataset.id}-${nextCountLineIndex}`;
            field.name = `lines[${nextCountLineIndex}][${fieldName}]`;
            field.id = fieldId;
            field.closest('div')?.querySelector(`[data-label="${field.dataset.id}"]`)?.setAttribute('for', fieldId);
        });

        row.querySelector('[data-remove-count-line]')?.addEventListener('click', () => row.remove());
        countLines.append(row);
        nextCountLineIndex++;
    });

    countLines.querySelectorAll('[data-remove-count-line]').forEach((button) => {
        button.addEventListener('click', () => button.closest('[data-count-line]')?.remove());
    });
}

const adjustmentType = document.querySelector('[data-adjustment-type]');
const positiveCostGroup = document.querySelector('[data-positive-cost]');
const positiveCostInput = document.querySelector('[data-cost-input]');
const offsetAccount = document.querySelector('[data-offset-account]');

if (adjustmentType && positiveCostGroup && positiveCostInput && offsetAccount) {
    const updateAdjustmentFields = () => {
        const isPositive = adjustmentType.value === 'positive';
        positiveCostGroup.hidden = !isPositive;
        positiveCostInput.disabled = !isPositive;
        positiveCostInput.required = isPositive;

        offsetAccount.querySelectorAll('option[data-account-type]').forEach((option) => {
            const isEligible = option.dataset.accountType === (isPositive ? 'positive' : 'negative');
            option.hidden = !isEligible;
            option.disabled = !isEligible;

            if (!isEligible && option.selected) {
                offsetAccount.value = '';
            }
        });
    };

    adjustmentType.addEventListener('change', updateAdjustmentFields);
    updateAdjustmentFields();
}

document.querySelectorAll('[data-commercial-form]').forEach((form) => {
    const updateFormTotals = () => {
        let subtotal = 0;
        let taxTotal = 0;

        form.querySelectorAll('[data-repeatable-list="lines"] [data-commercial-row]').forEach((row) => {
            const quantity = Number(row.querySelector('[data-quantity]')?.value || 0);
            const unitPrice = Number(row.querySelector('[data-unit-price]')?.value || 0);
            subtotal += quantity * unitPrice;
            taxTotal += Number(row.querySelector('[name$="[tax_amount]"]')?.value || 0);
        });

        const subtotalPreview = form.querySelector('[data-subtotal-preview]');
        const taxPreview = form.querySelector('[data-tax-preview]');
        const totalPreview = form.querySelector('[data-total-preview]');
        const formattedSubtotal = subtotal.toFixed(4);
        const formattedTax = taxTotal.toFixed(4);

        if (subtotalPreview) subtotalPreview.textContent = formattedSubtotal;
        if (taxPreview) taxPreview.textContent = formattedTax;
        if (totalPreview) totalPreview.textContent = (subtotal + taxTotal).toFixed(4);

        const currency = form.querySelector('[data-document-currency]')?.value || form.dataset.currency;
        form.querySelectorAll('[data-total-currency]').forEach((label) => {
            label.textContent = currency;
        });
    };

    const updateAccountCurrencies = (row = form) => {
        row.querySelectorAll('[data-currency-account]').forEach((select) => {
            const landedCurrency = select.closest('[data-commercial-row]')?.querySelector('[data-landed-currency]')?.value;
            const currency = landedCurrency || form.querySelector('[data-document-currency]')?.value || form.dataset.currency;

            select.querySelectorAll('option[data-currency]').forEach((option) => {
                const eligible = option.dataset.currency === currency;
                option.hidden = !eligible;
                option.disabled = !eligible;

                if (!eligible && option.selected) {
                    select.value = '';
                }
            });
        });
    };

    const updateProductRow = async (row) => {
        const productSelect = row.querySelector('[data-product-select]');
        const warehouseSelect = row.querySelector('[data-warehouse-select]');
        const productOption = productSelect?.selectedOptions[0];
        const unit = row.querySelector('[data-product-unit]');
        const stockLabel = row.querySelector('[data-stock-availability]');

        if (unit && productOption?.dataset.unit) {
            unit.textContent = `${unit.textContent.split(':')[0]}: ${productOption.dataset.unit}`;
        }

        const tracksInventory = productOption?.dataset.tracksInventory === '1';
        if (warehouseSelect) {
            warehouseSelect.required = tracksInventory;
        }

        if (!stockLabel || !form.dataset.availabilityUrl) {
            return;
        }

        if (!productOption?.value || !tracksInventory) {
            stockLabel.textContent = productOption?.value
                ? form.dataset.stockNotTracked
                : form.dataset.stockChoose;
            return;
        }

        if (!warehouseSelect?.value) {
            stockLabel.textContent = form.dataset.stockChoose;
            return;
        }

        stockLabel.textContent = form.dataset.stockLoading;
        const url = new URL(form.dataset.availabilityUrl, window.location.origin);
        url.searchParams.set('product_id', productOption.value);
        url.searchParams.set('warehouse_id', warehouseSelect.value);

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`Stock request failed with status ${response.status}`);
            }

            const data = await response.json();
            stockLabel.textContent = `${form.dataset.stockUnit}: ${data.quantity}`;
        } catch (error) {
            stockLabel.textContent = form.dataset.stockError;
        }
    };

    form.querySelectorAll('[data-add-commercial-row]').forEach((button) => {
        button.addEventListener('click', () => {
            const section = button.closest('.panel');
            const list = section?.querySelector('[data-repeatable-list]');
            const listName = list?.dataset.repeatableList;
            const template = listName ? form.querySelector(`[data-repeatable-template="${listName}"]`) : null;

            if (!list || !template) {
                return;
            }

            const pattern = new RegExp(`^${listName}\\[(\\d+)\\]`);
            const nextIndex = [...list.querySelectorAll('[name]')].reduce((next, field) => {
                const match = field.name.match(pattern);
                return match ? Math.max(next, Number(match[1]) + 1) : next;
            }, 0);
            const row = template.content.firstElementChild.cloneNode(true);

            row.querySelectorAll('[name], [id]').forEach((field) => {
                field.name = field.name?.replaceAll('__INDEX__', String(nextIndex));
                field.id = field.id?.replaceAll('__INDEX__', String(nextIndex));
            });
            list.append(row);
            updateAccountCurrencies(row);
            updateFormTotals();
            updateProductRow(row);
        });
    });

    form.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-commercial-row]');
        if (removeButton) {
            removeButton.closest('[data-commercial-row]')?.remove();
            updateFormTotals();
        }
    });

    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-quantity], [data-unit-price], [name$="[tax_amount]"]')) {
            updateFormTotals();
        }
    });

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-document-currency]')) {
            updateAccountCurrencies();
            updateFormTotals();
        }

        if (event.target.matches('[data-landed-currency]')) {
            updateAccountCurrencies(event.target.closest('[data-commercial-row]'));
        }

        if (event.target.matches('[data-product-select], [data-warehouse-select]')) {
            updateProductRow(event.target.closest('[data-commercial-row]'));
        }
    });

    form.querySelectorAll('[data-repeatable-list] [data-commercial-row]').forEach((row) => {
        updateAccountCurrencies(row);
        updateProductRow(row);
    });
    updateFormTotals();
});

document.querySelectorAll('[data-print-invoice]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});

document.querySelectorAll('[data-print-document]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});

document.querySelectorAll('[data-payment-form]').forEach((form) => {
    const direction = form.querySelector('[data-payment-direction]');
    const invoiceGroups = form.querySelectorAll('[data-payment-invoices]');
    const invoices = form.querySelectorAll('[data-payment-invoice]');
    const partyId = form.querySelector('[data-payment-party-id]');
    const partyName = form.querySelector('[data-payment-party-name]');
    const currency = form.querySelector('[data-payment-currency]');
    const account = form.querySelector('[data-payment-account]');
    const outstanding = form.querySelector('[data-payment-outstanding]');

    const syncPaymentForm = () => {
        invoiceGroups.forEach((group) => {
            const active = group.dataset.paymentInvoices === direction.value;
            group.hidden = !active;
            const select = group.querySelector('[data-payment-invoice]');
            select.disabled = !active;
            select.required = active;
        });

        const selectedInvoice = [...invoices].find((invoice) => !invoice.disabled && invoice.value);
        const option = selectedInvoice?.selectedOptions[0];
        partyId.value = option?.dataset.partyId ?? '';
        partyName.value = option?.dataset.partyName ?? '';
        outstanding.textContent = option
            ? `${option.dataset.outstanding} ${option.dataset.currency}`
            : outstanding.dataset.emptyLabel;

        if (option) {
            currency.value = option.dataset.currency;
        }

        [...account.options].forEach((accountOption) => {
            const eligible = accountOption.value === '' || !accountOption.dataset.currency || accountOption.dataset.currency === currency.value;
            accountOption.disabled = !eligible;
            accountOption.hidden = !eligible;
        });

        if (account.selectedOptions[0]?.disabled) {
            account.value = '';
        }
    };

    direction.addEventListener('change', syncPaymentForm);
    invoices.forEach((invoice) => invoice.addEventListener('change', syncPaymentForm));
    currency.addEventListener('change', syncPaymentForm);
    syncPaymentForm();
});

document.querySelectorAll('[data-expense-form]').forEach((form) => {
    const toggle = form.querySelector('[data-payment-type]');
    const paidGroup = form.querySelector('[data-paid-account]');
    const unpaidGroup = form.querySelector('[data-unpaid-account]');
    const paidSelect = form.querySelector('[data-paid-select]');
    const unpaidSelect = form.querySelector('[data-unpaid-select]');
    const sync = () => {
        const paid = toggle?.value === 'paid';
        if (paidGroup) paidGroup.hidden = !paid;
        if (unpaidGroup) unpaidGroup.hidden = paid;
        if (paidSelect) paidSelect.required = paid;
        if (unpaidSelect) unpaidSelect.required = !paid;
    };
    toggle?.addEventListener('change', sync);
    sync();
});

document.querySelectorAll('[data-settle-form]').forEach((form) => {
    const currency = form.querySelector('[data-settle-currency]');
    const account = form.querySelector('[data-settle-account]');
    const amount = form.querySelector('[data-settle-amount]');
    const fullButton = form.querySelector('[data-settle-full]');
    const outstanding = form.dataset.settleOutstanding || '0';
    const filterAccounts = () => {
        [...account.options].forEach((opt) => {
            if (!opt.value) {
                return;
            }
            const ok = opt.dataset.currency === currency.value;
            opt.hidden = !ok;
            opt.disabled = !ok;
        });
        if (account.selectedOptions[0]?.disabled) {
            account.value = '';
        }
    };
    currency?.addEventListener('change', filterAccounts);
    filterAccounts();
    fullButton?.addEventListener('click', () => {
        if (currency.options.length > 0) {
            currency.value = currency.options[0].value;
            filterAccounts();
        }
        if (amount) {
            amount.value = outstanding;
        }
    });
});
