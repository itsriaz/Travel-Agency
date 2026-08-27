(function () {
    const form = document.querySelector('[data-auto-submit="reports"]');
    if (!form) {
        return;
    }

    const immediateFilters = form.querySelectorAll('[data-report-filter="immediate"]');
    const dateFilters = form.querySelectorAll('[data-report-filter="debounced-date"]');
    const textFilters = form.querySelectorAll('[data-report-filter="debounced-text"], [data-report-filter="debounced"]');
    const referenceFilters = form.querySelectorAll('[data-report-filter="debounced-reference"]');
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

    dateFilters.forEach((field) => {
        const handlePotentialUpdate = () => {
            syncExportUrl();

            const value = field.value;
            if (value !== '' && !isCompleteDateValue(value)) {
                return;
            }

            scheduleSubmit(1200);
        };

        field.addEventListener('focus', () => {
            window.clearTimeout(debounceTimer);
        });
        field.addEventListener('input', () => {
            syncExportUrl();
        });
        field.addEventListener('change', () => {
            syncExportUrl();
        });
        field.addEventListener('blur', handlePotentialUpdate);
        field.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            window.clearTimeout(debounceTimer);
            handlePotentialUpdate();
        });
    });

    textFilters.forEach((field) => {
        const handleTextUpdate = () => {
            syncExportUrl();
            scheduleSubmit(500);
        };

        field.addEventListener('input', handleTextUpdate);
        field.addEventListener('change', handleTextUpdate);
    });

    referenceFilters.forEach((field) => {
        field.addEventListener('input', () => {
            syncExportUrl();
        });

        field.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            window.clearTimeout(debounceTimer);
            syncExportUrl();
            submitReport();
        });
    });

    amountFilters.forEach((field) => {
        const handleAmountUpdate = () => {
            syncExportUrl();

            const value = String(field.value || '').trim();
            if (value !== '' && (!field.validity.valid || Number(value) <= 0)) {
                return;
            }

            scheduleSubmit(0);
        };

        field.addEventListener('input', () => {
            syncExportUrl();
        });
        field.addEventListener('blur', handleAmountUpdate);
        field.addEventListener('change', handleAmountUpdate);
        field.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            window.clearTimeout(debounceTimer);
            handleAmountUpdate();
        });
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
