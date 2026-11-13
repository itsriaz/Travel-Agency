(() => {
    const forms = document.querySelectorAll('form[data-supplier-duplicate-check-url]');
    const money = (amount) => Math.round(Number(amount) * 100) / 100;

    const plannedPayments = (form) => {
        const amount = money(form.elements.namedItem('supplier_paid_amount')?.value || 0);
        const date = String(form.elements.namedItem('supplier_payment_date')?.value || '');
        const currency = String(form.elements.namedItem('supplier_payment_currency')?.value || '').toUpperCase();
        const status = String(form.elements.namedItem('supplier_payment_status')?.value || 'paid').toLowerCase();
        if (amount <= 0 || !date || !currency || status === 'void') {
            return [];
        }

        const common = {
            currency,
            payment_date: date,
            treasury_account_id: String(form.elements.namedItem('supplier_treasury_account_id')?.value || ''),
            reference_number: String(form.elements.namedItem('supplier_reference_number')?.value || '').trim(),
        };
        if (!form.matches('[data-simple-postpaid-form]')) {
            const supplierId = Number(form.elements.namedItem('supplier_id')?.value || 0);
            return supplierId > 0 ? [{ ...common, supplier_id: supplierId, amount }] : [];
        }

        let remaining = amount;
        const supplierAmounts = new Map();
        const selected = form.querySelectorAll('[data-simple-postpaid-select]:checked');
        for (const row of selected) {
            if (String(row.dataset.currency || '').toUpperCase() !== currency) {
                return [];
            }
            const supplierId = Number(row.dataset.supplierId || 0);
            const applied = money(Math.min(remaining, Number(row.dataset.balance || 0)));
            if (supplierId > 0 && applied > 0) {
                supplierAmounts.set(supplierId, money((supplierAmounts.get(supplierId) || 0) + applied));
                remaining = money(remaining - applied);
            }
        }
        if (supplierAmounts.size === 1 && remaining > 0) {
            const supplierId = supplierAmounts.keys().next().value;
            supplierAmounts.set(supplierId, money(supplierAmounts.get(supplierId) + remaining));
        }
        return Array.from(supplierAmounts, ([supplierId, supplierAmount]) => ({
            ...common,
            supplier_id: supplierId,
            amount: supplierAmount,
        }));
    };

    const displayDate = (value) => {
        const parts = String(value || '').split('-');
        return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : value;
    };

    const showWarning = (form, message) => {
        let warning = form.querySelector('[data-supplier-duplicate-warning]');
        if (!warning) {
            warning = document.createElement('div');
            warning.className = 'workspace-feedback workspace-feedback--inline top-gap';
            warning.dataset.supplierDuplicateWarning = '';
            warning.setAttribute('role', 'alert');
            warning.style.whiteSpace = 'pre-line';
            const actions = form.querySelector('.station-command-buttons');
            (actions?.parentNode || form).insertBefore(warning, actions || null);
        }
        warning.textContent = message;
    };

    for (const form of forms) {
        let checking = false;
        let continuing = false;
        const clearWarning = () => form.querySelector('[data-supplier-duplicate-warning]')?.remove();
        form.addEventListener('input', clearWarning);
        form.addEventListener('change', clearWarning);
        form.addEventListener('submit', async (event) => {
            if (continuing) {
                continuing = false;
                return;
            }
            if (event.defaultPrevented || !form.checkValidity()) {
                return;
            }
            const plans = plannedPayments(form);
            if (plans.length === 0) {
                return;
            }
            event.preventDefault();
            if (checking) {
                return;
            }
            checking = true;
            const originalPlans = JSON.stringify(plans);
            const submitter = event.submitter instanceof HTMLElement ? event.submitter : undefined;
            let proceed = false;
            try {
                const results = await Promise.all(plans.map(async (plan) => {
                    const url = new URL(form.dataset.supplierDuplicateCheckUrl, window.location.href);
                    url.search = new URLSearchParams(plan).toString();
                    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (!response.ok) {
                        throw new Error('Similarity check failed');
                    }
                    const result = await response.json();
                    if (!Array.isArray(result.matches)) {
                        throw new Error('Invalid similarity response');
                    }
                    return result.matches;
                }));
                const matches = results.flat();
                if (matches.length === 0) {
                    clearWarning();
                    proceed = true;
                } else {
                    const details = matches.slice(0, 5).map((match) => {
                        const source = match.treasury_account_name ? `, ${match.treasury_account_name}` : '';
                        return `${match.payment_no}: ${match.supplier_name}, ${match.currency} ${Number(match.paid_amount).toLocaleString()}, dated ${displayDate(match.payment_date)}, entered ${displayDate(String(match.created_at).slice(0, 10))}${source}`;
                    });
                    const message = `Possible similar supplier payment already exists:\n${details.join('\n')}\n\nThis may be a separate real payment. Check the cash or bank evidence before saving. Continue only if this is another real payment.`;
                    showWarning(form, message);
                    proceed = window.confirm(message);
                }
            } catch (error) {
                const message = 'Similar-payment check is unavailable. Review the cash or bank record before continuing. Save this payment anyway?';
                showWarning(form, message);
                proceed = window.confirm(message);
            } finally {
                checking = false;
            }
            if (!proceed) {
                return;
            }
            if (JSON.stringify(plannedPayments(form)) !== originalPlans) {
                form.requestSubmit(submitter);
                return;
            }
            continuing = true;
            form.requestSubmit(submitter);
        });
    }
})();
