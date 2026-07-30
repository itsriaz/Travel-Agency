(function () {
    const form = document.querySelector('[data-auto-submit="reports"]');
    if (!form) {
        return;
    }

    const immediateFilters = form.querySelectorAll('[data-report-filter="immediate"]');
    const debouncedFilters = form.querySelectorAll('[data-report-filter="debounced"]');
    const amountFilters = form.querySelectorAll('[data-report-filter="debounced-amount"]');
    const exportLink = document.getElementById('reports-export-link');
    const status = document.getElementById('reports-auto-status');
    const runButton = document.getElementById('reports-run-button');
    const exportBaseUrl = form.getAttribute('data-export-url') || '';

    let debounceTimer = null;
    let isSubmitting = false;

    const setLoadingState = (loading) => {
        if (status) {
            status.hidden = !loading;
            status.textContent = loading ? 'Loading report...' : '';
        }

        if (runButton) {
            runButton.disabled = loading;
            runButton.textContent = loading ? 'Loading...' : 'Run Report';
        }
    };

    const isCompleteDateValue = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || '').trim());

    const syncExportUrl = () => {
        if (!exportLink || !exportBaseUrl) {
            return;
        }

        const params = new URLSearchParams();
        const formData = new FormData(form);

        for (const [key, rawValue] of formData.entries()) {
            const value = String(rawValue ?? '').trim();
            if (value === '') {
                continue;
            }
            params.set(key, value);
        }

        const query = params.toString();
        exportLink.href = query ? `${exportBaseUrl}?${query}` : exportBaseUrl;
    };

    const submitReport = () => {
        if (isSubmitting) {
            return;
        }

        isSubmitting = true;
        syncExportUrl();
        setLoadingState(true);

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    };

    const scheduleSubmit = (delay = 650) => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            submitReport();
        }, delay);
    };

    immediateFilters.forEach((field) => {
        field.addEventListener('change', () => {
            syncExportUrl();
            submitReport();
        });
    });

    debouncedFilters.forEach((field) => {
        const handlePotentialUpdate = () => {
            syncExportUrl();

            const value = field.value;
            if (value !== '' && !isCompleteDateValue(value)) {
                return;
            }

            scheduleSubmit();
        };

        field.addEventListener('input', handlePotentialUpdate);
        field.addEventListener('change', handlePotentialUpdate);
    });

    amountFilters.forEach((field) => {
        const handleAmountUpdate = () => {
            syncExportUrl();

            const value = String(field.value || '').trim();
            if (value !== '' && (!field.validity.valid || Number(value) <= 0)) {
                return;
            }

            scheduleSubmit(350);
        };

        field.addEventListener('input', handleAmountUpdate);
        field.addEventListener('change', handleAmountUpdate);
    });

    form.addEventListener('submit', () => {
        syncExportUrl();
        setLoadingState(true);
        isSubmitting = true;
    });

    window.addEventListener('pageshow', () => {
        isSubmitting = false;
        setLoadingState(false);
        syncExportUrl();
    });

    syncExportUrl();
})();
