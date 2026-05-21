document.addEventListener('DOMContentLoaded', () => {
    const station = document.querySelector('[data-workspace-station]');

    if (!station) {
        return;
    }

    const feedback = station.querySelector('[data-workspace-feedback]');
    const quickSearch = station.querySelector('#workspace-search');
    const quickSearchForm = station.querySelector('#workspace-search-form');
    const quickSearchSubmit = station.querySelector('[data-workspace-search-submit]');
    const actionButtons = Array.from(station.querySelectorAll('[data-workspace-action]'));
    const invoiceForm = station.querySelector('.legacy-invoice-header');
    const serviceForm = station.querySelector('#legacy-service-form');
    const dockTabs = Array.from(station.querySelectorAll('[data-dock-tab]'));
    const dockPanels = Array.from(station.querySelectorAll('[data-dock-panel]'));
    const newCustomerModal = station.querySelector('[data-new-customer-modal]');
    const newCustomerCloseButtons = Array.from(station.querySelectorAll('[data-new-customer-close]'));
    const newCustomerForm = station.querySelector('[data-new-customer-form]');
    const newCustomerTravelerId = station.querySelector('[data-new-customer-traveler-id]');
    const newCustomerTitle = station.querySelector('[data-new-customer-title]');
    const newCustomerSubmit = station.querySelector('[data-new-customer-submit]');
    const customerPicker = station.querySelector('[data-customer-picker]');
    const customerPickerInput = station.querySelector('[data-customer-picker-input]');
    const customerPickerResults = station.querySelector('[data-customer-picker-results]');
    const customerPickerCloseButtons = Array.from(station.querySelectorAll('[data-customer-picker-close]'));
    const customerAutocompleteInput = station.querySelector('[data-customer-autocomplete-input]');
    const customerAutocompletePanel = station.querySelector('[data-customer-autocomplete-panel]');
    const customerAutocompleteResults = station.querySelector('[data-customer-autocomplete-results]');
    const customerAutocompleteAnchor = station.querySelector('[data-customer-inline-search]');
    const paymentHistoryModal = station.querySelector('[data-payment-history-modal]');
    const paymentHistoryCloseButtons = Array.from(station.querySelectorAll('[data-payment-history-close]'));
    const paymentHistorySummary = station.querySelector('[data-payment-history-summary]');
    const paymentHistoryReceiptsBody = station.querySelector('[data-payment-history-receipts-body]');
    const paymentHistoryAllocationsBody = station.querySelector('[data-payment-history-allocations-body]');
    const customerDuesModal = station.querySelector('[data-customer-dues-modal]');
    const customerDuesCloseButtons = Array.from(station.querySelectorAll('[data-customer-dues-close]'));
    const customerDuesSearchInput = station.querySelector('[data-customer-dues-search]');
    const customerDuesCurrencyFilter = station.querySelector('[data-customer-dues-currency-filter]');
    const customerDuesFeedback = station.querySelector('[data-customer-dues-feedback]');
    const customerDuesCustomersBody = station.querySelector('[data-customer-dues-customers-body]');
    const customerDuesInvoicesBody = station.querySelector('[data-customer-dues-invoices-body]');
    const customerDuesSelectedSummary = station.querySelector('[data-customer-dues-selected-summary]');
    const supplierHistoryModal = station.querySelector('[data-supplier-history-modal]');
    const supplierHistoryCloseButtons = Array.from(station.querySelectorAll('[data-supplier-history-close]'));
    const supplierHistorySearchInput = station.querySelector('[data-supplier-history-search]');
    const supplierHistoryFeedback = station.querySelector('[data-supplier-history-feedback]');
    const supplierHistoryResultsBody = station.querySelector('[data-supplier-history-results-body]');
    const supplierSettlementModal = station.querySelector('[data-supplier-settlement-modal]');
    const supplierSettlementCloseButtons = Array.from(station.querySelectorAll('[data-supplier-settlement-close]'));
    const simplePostpaidForm = station.querySelector('[data-simple-postpaid-form]');
    const simplePostpaidSelectors = Array.from(station.querySelectorAll('[data-simple-postpaid-select]'));
    const simplePostpaidSupplierDisplay = station.querySelector('[data-simple-postpaid-supplier-display]');
    const simplePostpaidCurrencyDisplay = station.querySelector('[data-simple-postpaid-currency-display]');
    const simplePostpaidCurrencyInput = station.querySelector('[data-simple-postpaid-currency-input]');
    const simplePostpaidAmountInput = station.querySelector('[data-simple-postpaid-amount]');
    const simplePostpaidTotal = station.querySelector('[data-simple-postpaid-total]');
    const simplePostpaidFeedback = station.querySelector('[data-simple-postpaid-feedback]');
    const simplePostpaidSubmit = station.querySelector('[data-simple-postpaid-submit]');
    const globalPrepaidSupplierModal = station.querySelector('[data-global-prepaid-supplier-modal]');
    const globalPrepaidSupplierOpenButtons = Array.from(station.querySelectorAll('[data-global-prepaid-supplier-open]'));
    const globalPrepaidSupplierCloseButtons = Array.from(station.querySelectorAll('[data-global-prepaid-supplier-close]'));
    const supplierAdvanceLookupUrl = station.dataset.supplierAdvanceLookupUrl || '';
    const supplierAdvanceNote = station.querySelector('[data-supplier-advance-note]');
    const supplierAdvanceSummary = station.querySelector('[data-supplier-advance-summary]');
    const supplierAdvanceMessage = station.querySelector('[data-supplier-advance-message]');
    const paymentForm = station.querySelector('.legacy-payment-strip');
    const debugToolsEnabled = station.dataset.debugToolsEnabled === '1';
    const canVoidFinancials = station.dataset.canVoidFinancials === '1';
    const commercialEditor = station.querySelector('[data-commercial-editor="active"]');
    const commercialLookup = (id, fallbackSelector = null) => {
        if (id) {
            const byId = station.querySelector(`#${id}`);
            if (byId) {
                return byId;
            }
        }

        if (fallbackSelector && commercialEditor) {
            const scoped = commercialEditor.querySelector(fallbackSelector);
            if (scoped) {
                return scoped;
            }
        }

        return fallbackSelector ? station.querySelector(fallbackSelector) : null;
    };
    const commercialDebug = station.querySelector('[data-commercial-debug]');
    const commercialTrace = {
        bootStatus: 'booting',
        editorFound: Boolean(commercialEditor),
        mktFareFieldFound: false,
        servAmountFieldFound: false,
        frtxFieldFound: false,
        finalSaleFieldFound: false,
        listenerAttached: false,
        lastEventFired: 'boot',
        lastMktFareRead: 'n/a',
        lastServAmountRead: 'n/a',
        lastFrTxComputed: 'n/a',
        lastFinalSaleComputed: 'n/a',
        lastFieldWritten: 'n/a',
        lastOverwriteSource: 'n/a',
        firstFailurePoint: 'pending',
        lastDomTarget: 'n/a',
        selectorCounts: 'pending',
        lastDocumentTarget: 'n/a',
        lastDocumentInsideStation: 'n/a',
        activeElement: 'n/a',
    };
    const renderCommercialDebug = () => {
        if (!commercialDebug) {
            return;
        }

        commercialDebug.textContent = [
            'Commercial debug trace',
            `boot status: ${commercialTrace.bootStatus}`,
            `editor found: ${commercialTrace.editorFound ? 'yes' : 'no'}`,
            `mktFare field found: ${commercialTrace.mktFareFieldFound ? 'yes' : 'no'}`,
            `servAmount field found: ${commercialTrace.servAmountFieldFound ? 'yes' : 'no'}`,
            `frtx field found: ${commercialTrace.frtxFieldFound ? 'yes' : 'no'}`,
            `finalSale field found: ${commercialTrace.finalSaleFieldFound ? 'yes' : 'no'}`,
            `listener attached: ${commercialTrace.listenerAttached ? 'yes' : 'no'}`,
            `last event fired: ${commercialTrace.lastEventFired}`,
            `last Mkt.Fare read: ${commercialTrace.lastMktFareRead}`,
            `last Serv.Amount read: ${commercialTrace.lastServAmountRead}`,
            `last Fr+Tx computed: ${commercialTrace.lastFrTxComputed}`,
            `last Final Sale computed: ${commercialTrace.lastFinalSaleComputed}`,
            `last field written: ${commercialTrace.lastFieldWritten}`,
            `last reset/overwrite source: ${commercialTrace.lastOverwriteSource}`,
            `first failure point: ${commercialTrace.firstFailurePoint}`,
            `last DOM target: ${commercialTrace.lastDomTarget}`,
            `selector counts: ${commercialTrace.selectorCounts}`,
            `last document target: ${commercialTrace.lastDocumentTarget}`,
            `last document target inside station: ${commercialTrace.lastDocumentInsideStation}`,
            `active element: ${commercialTrace.activeElement}`,
        ].join('\n');
    };
    const updateCommercialTrace = (patch, options = {}) => {
        Object.assign(commercialTrace, patch);
        renderCommercialDebug();

        if (debugToolsEnabled && options.log !== false && typeof console !== 'undefined' && typeof console.debug === 'function') {
            console.debug('[commercial-debug]', patch);
        }
    };
    const traceInputValue = (label, field, eventName) => {
        updateCommercialTrace({
            lastEventFired: `${eventName}:${label}`,
            lastMktFareRead: label === 'mktFare'
                ? `${String(field?.value ?? '')} (raw) / ${toNumber(field?.value).toFixed(2)}`
                : commercialTrace.lastMktFareRead,
            lastServAmountRead: label === 'servAmount'
                ? `${String(field?.value ?? '')} (raw) / ${toNumber(field?.value).toFixed(2)}`
                : commercialTrace.lastServAmountRead,
            lastOverwriteSource: `${eventName}:${label}`,
        });
    };
    if (debugToolsEnabled) {
        window.addEventListener('error', (event) => {
            updateCommercialTrace({
                bootStatus: 'runtime-error',
                lastEventFired: 'window.error',
                firstFailurePoint: commercialTrace.firstFailurePoint === 'pending' || commercialTrace.firstFailurePoint === 'none yet'
                    ? `runtime error: ${event.message || 'unknown error'}`
                    : commercialTrace.firstFailurePoint,
                lastOverwriteSource: `window.error @ ${event.filename || 'inline'}:${event.lineno || 0}`,
            });
        });
    }
    renderCommercialDebug();
    const describeTarget = (target) => {
        if (!(target instanceof HTMLElement)) {
            return 'non-element';
        }

        const namePart = target.getAttribute('name') ? ` name=${target.getAttribute('name')}` : '';
        const idPart = target.id ? ` id=${target.id}` : '';
        const typePart = target instanceof HTMLInputElement ? ` type=${target.type}` : '';
        const valuePart = 'value' in target ? ` value=${String(target.value ?? '')}` : '';
        return `${target.tagName.toLowerCase()}${idPart}${namePart}${typePart}${valuePart}`;
    };
    const updateActiveElementTrace = () => {
        updateCommercialTrace({
            activeElement: describeTarget(document.activeElement),
        }, { log: false });
    };
    const refreshSelectorCounts = () => {
        updateCommercialTrace({
            selectorCounts: [
                `#commercial-sale-price=${station.querySelectorAll('#commercial-sale-price').length}`,
                `[name="sale_price"]=${station.querySelectorAll('[name="sale_price"]').length}`,
                `[data-service-metric="sale"]=${station.querySelectorAll('[data-service-metric="sale"]').length}`,
                `#commercial-service-charge=${station.querySelectorAll('#commercial-service-charge').length}`,
                `[name="service_charge"]=${station.querySelectorAll('[name="service_charge"]').length}`,
                `[data-service-metric="service_charge"]=${station.querySelectorAll('[data-service-metric="service_charge"]').length}`,
            ].join(' | '),
        }, { log: false });
    };
    ['focusin', 'input', 'change', 'keyup'].forEach((eventName) => {
        station.addEventListener(eventName, (event) => {
            updateCommercialTrace({
                lastEventFired: `station:${eventName}`,
                lastDomTarget: describeTarget(event.target),
            });
            updateActiveElementTrace();
        }, true);
    });
    ['focusin', 'input', 'change', 'keyup', 'click', 'mousedown'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            updateCommercialTrace({
                lastDocumentTarget: describeTarget(event.target),
                lastDocumentInsideStation: event.target instanceof Node && station.contains(event.target) ? 'yes' : 'no',
            }, { log: false });
            updateActiveElementTrace();
        }, true);
    });
    refreshSelectorCounts();
    updateActiveElementTrace();
    let serviceLines = [];
    let hasSavedServiceRows = () => false;
    const receivedNowInput = station.querySelector('[data-payment-focus="received_amount"]');
    const quickReceiveInput = station.querySelector('[data-quick-receive-input]');
    const paymentCurrentInvoiceInput = commercialLookup('commercial-payment-current-invoice', '[data-payment-current-invoice]');
    const paymentAlreadyReceivedInput = commercialLookup('commercial-payment-already-received', '[data-payment-already-received]');
    const paymentCurrentBalanceInput = commercialLookup('commercial-payment-current-balance', '[data-payment-current-balance]');
    const paymentCurrentInvoiceRow = station.querySelector('[data-payment-current-invoice-row]');
    const paymentPaidCurrentInvoiceRow = station.querySelector('[data-payment-paid-current-invoice-row]');
    const paymentCurrentBalanceRow = station.querySelector('[data-payment-current-balance-row]');
    const paymentNoCurrentInvoice = station.querySelector('[data-payment-no-current-invoice]');
    const paymentTotalOutstandingInput = commercialLookup('commercial-payment-total-outstanding', '[data-payment-total-outstanding]');
    const paymentPreviousBalanceInput = station.querySelector('[data-payment-previous-balance]');
    const paymentPreviousBalancesBlock = station.querySelector('[data-payment-previous-balances-block]');
    const paymentPreviousBalanceList = station.querySelector('[data-payment-previous-balance-list]');
    const paymentNoPreviousBalance = station.querySelector('[data-payment-no-previous-balance]');
    const paymentTotalOutstandingLabel = station.querySelector('[data-payment-total-outstanding-label]');
    const paymentCurrencySelect = paymentForm?.elements?.namedItem('receipt_currency') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('receipt_currency')
        : null;
    const paymentCurrentBalancePkrRow = station.querySelector('[data-payment-current-balance-pkr-row]');
    const paymentCurrentBalancePkrInput = commercialLookup('commercial-payment-current-balance-pkr', '[data-payment-current-balance-pkr]');
    const paymentState = station.querySelector('[data-payment-state]');
    const paymentStateLabel = station.querySelector('[data-payment-state-label]');
    const paymentDueHelper = station.querySelector('[data-payment-due-helper]');
    const paymentDueRow = station.querySelector('[data-payment-due-row]');
    const paymentDueDateInput = station.querySelector('[data-payment-due-date]');
    const paymentDueDateTrigger = station.querySelector('[data-payment-due-trigger]');
    const paymentDueDatePicker = station.querySelector('[data-payment-due-picker]');
    const paymentExchangeSettlementButton = station.querySelector('[data-payment-exchange-settlement]');
    const paymentExchangeModal = station.querySelector('[data-payment-exchange-modal]');
    const paymentExchangeCloseButtons = Array.from(station.querySelectorAll('[data-payment-exchange-close]'));
    const paymentExchangeTargetSelect = station.querySelector('[data-payment-exchange-target]');
    const paymentExchangeInvoiceNoInput = station.querySelector('[data-payment-exchange-invoice-no]');
    const paymentExchangeInvoiceCurrencyInput = station.querySelector('[data-payment-exchange-invoice-currency]');
    const paymentExchangeTargetCurrencyInput = station.querySelector('[data-payment-exchange-target-currency]');
    const paymentExchangeTargetBalanceInput = station.querySelector('[data-payment-exchange-target-balance]');
    const paymentExchangePaymentCurrencyInput = station.querySelector('[data-payment-exchange-payment-currency]');
    const paymentExchangePaymentAmountInput = station.querySelector('[data-payment-exchange-payment-amount]');
    const paymentExchangeRateDateDisplay = station.querySelector('[data-payment-exchange-rate-date-display]');
    const paymentExchangeRateRow = station.querySelector('[data-payment-exchange-rate-row]');
    const paymentExchangeRateLabel = station.querySelector('[data-payment-exchange-rate-label]');
    const paymentExchangeRateInput = station.querySelector('[data-payment-exchange-rate-input]');
    const paymentExchangeRateHelp = station.querySelector('[data-payment-exchange-rate-help]');
    const paymentExchangeFeedback = station.querySelector('[data-payment-exchange-feedback]');
    const paymentExchangeConfirmButton = station.querySelector('[data-payment-exchange-confirm]');
    const paymentExchangePreviewRequired = station.querySelector('[data-payment-exchange-preview-required]');
    const paymentExchangePreviewSettled = station.querySelector('[data-payment-exchange-preview-settled]');
    const paymentExchangePreviewConsumed = station.querySelector('[data-payment-exchange-preview-consumed]');
    const paymentExchangePreviewTargetRemaining = station.querySelector('[data-payment-exchange-preview-target-remaining]');
    const paymentExchangePreviewPaymentRemaining = station.querySelector('[data-payment-exchange-preview-payment-remaining]');
    const paymentExchangePreviewAutoApply = station.querySelector('[data-payment-exchange-preview-auto-apply]');
    const paymentExchangePreviewReturn = station.querySelector('[data-payment-exchange-preview-return]');
    const paymentPrimarySaveButton = station.querySelector('[data-payment-submit-action="save"]');
    const paymentNewEntryButton = station.querySelector('[data-payment-action="new-payment"]');
    const paymentPrintReceiptButton = station.querySelector('[data-payment-action="print-receipt"]');
    const paymentLedgerLink = station.querySelector('[data-payment-action="customer-ledger"]');
    const customerLedgerLinks = Array.from(station.querySelectorAll('[data-customer-ledger-link]'));
    const paymentReceiptDateInput = paymentForm?.elements?.namedItem('receipt_date') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('receipt_date')
        : null;
    const paymentMethodSelect = paymentForm?.elements?.namedItem('payment_method') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('payment_method')
        : null;
    const paymentSettlementModeInput = paymentForm?.elements?.namedItem('settlement_mode') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_mode')
        : null;
    const paymentSettlementTargetIdInput = paymentForm?.elements?.namedItem('settlement_target_receivable_id') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_target_receivable_id')
        : null;
    const paymentSettlementTargetCurrencyInput = paymentForm?.elements?.namedItem('settlement_target_currency') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_target_currency')
        : null;
    const paymentSettlementTargetReceivableAmountInput = paymentForm?.elements?.namedItem('settlement_target_receivable_amount') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_target_receivable_amount')
        : null;
    const paymentSettlementTargetPaymentAmountInput = paymentForm?.elements?.namedItem('settlement_target_payment_amount') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_target_payment_amount')
        : null;
    const paymentSettlementRateFromInput = paymentForm?.elements?.namedItem('settlement_rate_from_currency') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_rate_from_currency')
        : null;
    const paymentSettlementRateToInput = paymentForm?.elements?.namedItem('settlement_rate_to_currency') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_rate_to_currency')
        : null;
    const paymentSettlementRateInput = paymentForm?.elements?.namedItem('settlement_exchange_rate') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_exchange_rate')
        : null;
    const paymentSettlementRateDateInput = paymentForm?.elements?.namedItem('settlement_exchange_rate_effective_date') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('settlement_exchange_rate_effective_date')
        : null;
    const bookingDueDateField = station.querySelector('[data-booking-due-date-field]');
    const bookingDueDateDisplay = station.querySelector('[data-booking-due-date-display]');
    const autoBookingDueDateField = station.querySelector('[data-auto-booking-field="due_date"]');
    const paymentReturnRow = station.querySelector('[data-payment-return-row]');
    const paymentReturnAmountInput = commercialLookup('commercial-payment-return-amount', '[data-payment-return-amount]');
    const autosaveInvoiceUrl = station.dataset.autosaveInvoiceUrl || '';
    const autosaveServiceUrl = station.dataset.autosaveServiceUrl || '';
    const invoiceNumberDisplay = station.querySelector('[data-invoice-number-display]');
    const autosaveStatusLabel = station.querySelector('[data-autosave-status]');
    const serviceTableBody = station.querySelector('.legacy-service-table tbody');
    let suppressAutosave = false;
    let invoiceAutosaveTimerId = 0;
    let serviceAutosaveTimerId = 0;
    let invoiceAutosavePromise = Promise.resolve(null);
    let serviceAutosavePromise = Promise.resolve(null);
    let lastInvoiceAutosaveKey = '';
    let lastServiceAutosaveKey = '';
    let invoiceAutosaveInFlight = false;
    let serviceAutosaveInFlight = false;
    let pendingInvoiceAutosave = false;
    let pendingInvoiceAutosaveForce = false;
    let pendingServiceAutosave = false;
    let autosavedPaymentEligible = false;
    let autosavedPersistedServiceId = 0;
    let autosavedHasSavedService = false;
    let autosavedReceivableAmount = 0;
    let allowNativePaymentSubmit = false;
    let paymentSubmitValidationInFlight = false;
    let paymentExchangeConfirmInFlight = false;
    let paymentExchangeAutoOpenInFlight = false;
    const emptySavedPaymentState = () => ({
        saved: false,
        bookingId: 0,
        receiptId: 0,
        receiptNo: '',
        amount: 0,
        currency: '',
        paymentMethod: '',
        dueDate: '',
        settlementMode: '',
    });
    let savedPaymentState = emptySavedPaymentState();
    let savedPaymentEditNoticeShown = false;
    const paymentSubmitDebug = {
        saveButtonFound: false,
        handlerAttached: false,
        currentBookingId: 0,
        parsedAmountReceiving: 0,
        paymentCurrency: '',
        invoiceCurrency: '',
        dueDate: '',
        csrfFound: false,
        routeUrl: '',
        lastAttemptedPayload: null,
        lastBackendResponse: null,
        lastBackendError: null,
    };
    const paymentRouteBasePath = (() => {
        const action = paymentForm?.getAttribute('action') || '';

        try {
            const parsed = new URL(action, window.location.origin);
            return parsed.pathname.replace(/\/workspace\/payments\/receipts\/save$/i, '').replace(/\/$/, '');
        } catch (error) {
            return '';
        }
    })();
    const buildWorkspacePathUrl = (path) => {
        const normalizedPath = '/' + String(path || '').replace(/^\/+/, '');
        return `${paymentRouteBasePath}${normalizedPath}`;
    };

    const showFeedback = (message) => {
        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.classList.add('is-visible');
        window.clearTimeout(showFeedback.timerId);
        showFeedback.timerId = window.setTimeout(() => {
            feedback.classList.remove('is-visible');
        }, 4800);
    };

    const showExchangeFeedback = (message) => {
        if (!paymentExchangeFeedback) {
            showFeedback(message);
            return;
        }

        paymentExchangeFeedback.textContent = String(message || '').trim();
        paymentExchangeFeedback.hidden = paymentExchangeFeedback.textContent === '';
    };

    const clearExchangeFeedback = () => {
        if (!paymentExchangeFeedback) {
            return;
        }

        paymentExchangeFeedback.textContent = '';
        paymentExchangeFeedback.hidden = true;
    };

    const focusTarget = (selector) => {
        if (!selector) {
            return;
        }

        const field = station.querySelector(selector);
        if (field instanceof HTMLElement) {
            field.focus({ preventScroll: false });
            if (field instanceof HTMLInputElement && field.type === 'text') {
                field.select();
            }
        }
    };

    const submitQuickSearch = () => {
        if (!(quickSearchForm instanceof HTMLFormElement)) {
            return;
        }

        const query = String(quickSearch?.value || '').trim();
        if (quickSearch && query === '') {
            quickSearch.focus();
            showFeedback('Enter invoice number, customer, mobile, passport, PNR, or supplier first.');
            return;
        }

        if (quickSearchSubmit instanceof HTMLButtonElement) {
            quickSearchSubmit.click();
        } else {
            quickSearchForm.submit();
        }
    };

    const activateDock = (dockName, options = {}) => {
        const { message = null, focusSelector = null } = options;

        dockTabs.forEach((button) => {
            const isActive = button.dataset.dockTab === dockName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.tabIndex = isActive ? 0 : -1;
        });

        dockPanels.forEach((panel) => {
            const isActive = panel.dataset.dockPanel === dockName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        if (message) {
            showFeedback(message);
        }

        if (focusSelector) {
            window.setTimeout(() => focusTarget(focusSelector), 80);
        }
    };

    dockTabs.forEach((button, index) => {
        button.addEventListener('click', () => {
            const dockName = button.dataset.dockTab;
            if (!dockName) {
                return;
            }

            activateDock(dockName, { message: `${button.textContent.trim()} ready.` });
        });

        button.addEventListener('keydown', (event) => {
            let nextIndex = index;

            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % dockTabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + dockTabs.length) % dockTabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = dockTabs.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            dockTabs[nextIndex].focus();
        });
    });

    actionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            switch (button.dataset.workspaceAction) {
                case 'new-booking':
                    window.location.href = station.dataset.newBookingUrl || '/workspace?new=1';
                    break;
                case 'new-customer':
                    openNewCustomerModal();
                    break;
                case 'search-booking':
                    if (quickSearch && String(quickSearch.value || '').trim() !== '') {
                        submitQuickSearch();
                    } else {
                        focusTarget('#workspace-search');
                        showFeedback('Quick search is ready. Type booking no, traveler, mobile, passport, or supplier reference.');
                    }
                    break;
                case 'edit-booking':
                    if (currentBookingId() <= 0) {
                        showFeedback('Open or save an invoice before editing.');
                        break;
                    }
                    focusTarget('[data-customer-autocomplete-input]');
                    showFeedback('Current invoice is editable. Update the fields and autosave will keep it current.');
                    break;
                case 'delete-booking':
                    showFeedback('Delete is locked for production safety. Use void/cancel workflows so ledger history is preserved.');
                    break;
                case 'add-traveler':
                    openCustomerPicker();
                    break;
                case 'customer-dues-finder':
                    openCustomerDuesModal();
                    break;
                case 'supplier-history-finder':
                    openSupplierHistoryModal();
                    break;
                case 'add-service':
                    resetServiceLine();
                    showFeedback('New service row ready. Enter details in the service band.');
                    focusTarget('[data-service-field="type"]');
                    break;
                case 'add-payment':
                    activateDock('payments', {
                        message: 'Payments opened. Record the receipt first, then allocate it if needed.',
                        focusSelector: '[data-payment-focus="received_amount"]',
                    });
                    break;
                case 'add-reminder':
                    activateDock('reminders', {
                        message: 'Reminders opened. All follow-up stays linked to this invoice.',
                        focusSelector: '[data-reminder-focus="task"]',
                    });
                    break;
                case 'print':
                    activateDock('print', {
                        message: 'Print options opened. Choose the document you want to print.',
                        focusSelector: '[data-print-focus="preset"]',
                    });
                    break;
                case 'invoice-status':
                    focusTarget('select[name="booking_status"]');
                    showFeedback('Invoice status is ready. Closed is allowed only after customer and supplier balances are clear.');
                    break;
                case 'detail-remarks':
                    focusTarget('input[name="remarks"]');
                    showFeedback('Invoice remarks are ready for detailed operational notes.');
                    break;
                case 'payment-history':
                    openPaymentHistoryModal();
                    break;
                case 'supplier-settlement':
                    openSupplierSettlementModal();
                    break;
                default:
                    break;
            }
        });
    });

    if (quickSearch) {
        const handleQuickSearchEnter = (event) => {
            const isEnter = event.key === 'Enter'
                || event.code === 'Enter'
                || event.code === 'NumpadEnter'
                || event.keyCode === 13;
            if (!isEnter) {
                return;
            }

            event.preventDefault();
            submitQuickSearch();
        };

        quickSearch.addEventListener('keydown', handleQuickSearchEnter);
        quickSearch.addEventListener('keypress', handleQuickSearchEnter);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeCustomerPicker();
            closeCustomerDuesModal();
            closeSupplierHistoryModal();
            closeNewCustomerModal();
            closePaymentHistoryModal();
            closeSupplierSettlementModal();
            closeExchangeSettlementModal({ clear: false });
        }

        if (event.key !== '/') {
            return;
        }

        const activeElement = document.activeElement;
        const isTypingTarget = activeElement instanceof HTMLInputElement
            || activeElement instanceof HTMLTextAreaElement
            || activeElement instanceof HTMLSelectElement;

        if (isTypingTarget || !quickSearch) {
            return;
        }

        event.preventDefault();
        quickSearch.focus();
        quickSearch.select();
    });

    const toNumber = (value) => {
        const parsed = Number.parseFloat(String(value ?? '').replace(/[^0-9.-]/g, ''));
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const setLinkDisabled = (link, disabled) => {
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(link.dataset, 'workflowOriginalTabindex')) {
            link.dataset.workflowOriginalTabindex = link.hasAttribute('tabindex') ? String(link.tabIndex) : '';
        }

        link.classList.toggle('is-disabled', disabled);
        link.setAttribute('aria-disabled', disabled ? 'true' : 'false');

        if (disabled) {
            link.tabIndex = -1;
        } else if (link.dataset.workflowOriginalTabindex !== '') {
            link.tabIndex = Number.parseInt(link.dataset.workflowOriginalTabindex, 10);
        } else {
            link.removeAttribute('tabindex');
        }
    };

    const setControlDisabled = (control, disabled) => {
        if (!(control instanceof HTMLInputElement
            || control instanceof HTMLSelectElement
            || control instanceof HTMLTextAreaElement
            || control instanceof HTMLButtonElement)) {
            return;
        }

        if (control.type === 'hidden') {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(control.dataset, 'workflowOriginalDisabled')) {
            control.dataset.workflowOriginalDisabled = control.disabled ? '1' : '0';
        }

        const originallyDisabled = control.dataset.workflowOriginalDisabled === '1';
        control.disabled = disabled ? true : originallyDisabled;
    };

    let updateWorkflowState = () => {};

    const normalizeScaffoldValue = (value) => String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/,/g, '')
        .replace(/\s+/g, ' ');

    const isClearableScaffoldValue = (value) => {
        const normalized = normalizeScaffoldValue(value);

        if (normalized === '') {
            return false;
        }

        if (['0', '0.0', '0.00', '0.000', 'dd/mm/yyyy', 'dd/mm/yy', 'yyyy-mm-dd', 'yyyy/mm/dd'].includes(normalized)) {
            return true;
        }

        return /^(pkr|aed|usd)\s+0(?:\.0+)?$/.test(normalized);
    };

    const formatMoney = (value) => value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const formatCurrencyAmount = (currency, amount) => `${currency || 'PKR'} ${formatMoney(Math.max(amount, 0))}`;
    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const parseBalanceMap = (rawValue) => {
        if (rawValue && typeof rawValue === 'object' && !Array.isArray(rawValue)) {
            return rawValue;
        }

        if (typeof rawValue !== 'string' || rawValue.trim() === '') {
            return {};
        }

        try {
            const parsed = JSON.parse(rawValue);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const roundToTwo = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

    const preferredSettlementQuote = (targetCurrency, paymentCurrency, ratesMap, effectiveDate) => {
        const normalizeRateRow = (row) => ({
            rateFromCurrency: String(row?.fromCurrency || row?.rateFromCurrency || ''),
            rateToCurrency: String(row?.toCurrency || row?.rateToCurrency || ''),
            exchangeRate: toNumber(row?.exchangeRate || row?.rate || 0),
            effectiveDate: String(row?.effectiveDate || effectiveDate || ''),
            isDerived: Boolean(row?.isDerived),
        });

        if (targetCurrency === paymentCurrency) {
            return {
                rateFromCurrency: targetCurrency,
                rateToCurrency: paymentCurrency,
                exchangeRate: 1,
                effectiveDate,
                isDerived: false,
            };
        }

        const directKey = `${targetCurrency}->${paymentCurrency}`;
        const reverseKey = `${paymentCurrency}->${targetCurrency}`;
        const direct = ratesMap[directKey] ? normalizeRateRow(ratesMap[directKey]) : null;
        const reverse = ratesMap[reverseKey] ? normalizeRateRow(ratesMap[reverseKey]) : null;

        if (direct && reverse) {
            return (toNumber(direct.exchangeRate) >= toNumber(reverse.exchangeRate)) ? direct : reverse;
        }

        if (direct) {
            return direct;
        }

        if (reverse) {
            return reverse;
        }

        if (targetCurrency === 'PKR' && paymentCurrency !== 'PKR') {
            return {
                rateFromCurrency: paymentCurrency,
                rateToCurrency: targetCurrency,
                exchangeRate: 0,
                effectiveDate,
                isDerived: false,
            };
        }

        if (paymentCurrency === 'PKR' && targetCurrency !== 'PKR') {
            return {
                rateFromCurrency: targetCurrency,
                rateToCurrency: paymentCurrency,
                exchangeRate: 0,
                effectiveDate,
                isDerived: false,
            };
        }

        return {
            rateFromCurrency: targetCurrency,
            rateToCurrency: paymentCurrency,
            exchangeRate: 0,
            effectiveDate,
            isDerived: false,
        };
    };

    const convertPaymentAmountToTargetAmount = (paymentAmount, quote) => {
        const rate = toNumber(quote.exchangeRate || 0);
        if (rate <= 0) {
            return 0;
        }

        return quote.rateFromCurrency === quote.rateToCurrency
            ? roundToTwo(paymentAmount)
            : quote.rateFromCurrency === quote.paymentCurrency
                ? roundToTwo(paymentAmount * rate)
                : roundToTwo(paymentAmount / rate);
    };

    const convertTargetAmountToPaymentAmount = (targetAmount, quote) => {
        const rate = toNumber(quote.exchangeRate || 0);
        if (rate <= 0) {
            return 0;
        }

        return quote.rateFromCurrency === quote.rateToCurrency
            ? roundToTwo(targetAmount)
            : quote.rateFromCurrency === quote.paymentCurrency
                ? roundToTwo(targetAmount / rate)
                : roundToTwo(targetAmount * rate);
    };

    const resolveOpenBalanceMap = (currentInvoiceCurrency, currentInvoiceBalance) => {
        if (!paymentPreviousBalanceInput) {
            return {};
        }

        const previousBalanceMap = parseBalanceMap(
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap || '{}'
        );
        const fullOpenBalanceMap = parseBalanceMap(
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap || '{}'
        );
        const hasFullOpenBalanceMap = Object.keys(fullOpenBalanceMap).length > 0;
        const openBalanceMap = {
            ...(hasFullOpenBalanceMap ? fullOpenBalanceMap : previousBalanceMap),
        };
        const normalizedCurrency = currentInvoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const normalizedInvoiceBalance = Math.max(toNumber(currentInvoiceBalance), 0);

        if (normalizedInvoiceBalance > 0.005) {
            openBalanceMap[normalizedCurrency] = hasFullOpenBalanceMap
                ? roundToTwo(Math.max(toNumber(openBalanceMap[normalizedCurrency] || 0), normalizedInvoiceBalance))
                : roundToTwo(toNumber(openBalanceMap[normalizedCurrency] || 0) + normalizedInvoiceBalance);
        } else if (!Object.prototype.hasOwnProperty.call(openBalanceMap, normalizedCurrency)) {
            openBalanceMap[normalizedCurrency] = 0;
        }

        return openBalanceMap;
    };

    const syncOpenBalanceDisplay = (currentInvoiceCurrency, currentInvoiceBalance) => {
        const invoiceCurrency = currentInvoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const openBalanceMap = resolveOpenBalanceMap(invoiceCurrency, currentInvoiceBalance);
        const previousBalanceAmount = Math.max(toNumber(openBalanceMap[invoiceCurrency] || 0) - Math.max(toNumber(currentInvoiceBalance), 0), 0);
        const visibleBalances = Object.entries(openBalanceMap)
            .map(([currency, amount]) => [currency, toNumber(amount)])
            .filter(([, amount]) => Math.abs(amount) > 0.005);

        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalance = String(previousBalanceAmount);
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = JSON.stringify(openBalanceMap);
        }

        if (paymentPreviousBalanceList) {
            paymentPreviousBalanceList.innerHTML = '';
            visibleBalances.forEach(([currency, amount]) => {
                const label = document.createElement('label');
                const span = document.createElement('span');
                span.textContent = currency;
                const input = document.createElement('input');
                input.className = 'legacy-red-text';
                input.type = 'text';
                input.value = formatCurrencyAmount(currency, amount);
                input.readOnly = true;
                label.appendChild(span);
                label.appendChild(input);
                paymentPreviousBalanceList.appendChild(label);
            });
        }

        if (paymentPreviousBalancesBlock) {
            paymentPreviousBalancesBlock.hidden = visibleBalances.length === 0;
        }

        if (paymentNoPreviousBalance) {
            paymentNoPreviousBalance.hidden = visibleBalances.length !== 0;
        }

        return {
            invoiceCurrency,
            previousBalanceInInvoiceCurrency: previousBalanceAmount,
            openBalanceInInvoiceCurrency: Math.max(toNumber(openBalanceMap[invoiceCurrency] || 0), 0),
            openBalanceMap,
        };
    };

    const syncPaymentCurrencyLabels = (currency) => {
        const displayCurrency = currency || paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        if (paymentTotalOutstandingLabel) {
            paymentTotalOutstandingLabel.textContent = `Balance in Payment Currency (${displayCurrency})`;
        }
    };

    const syncCurrentBalancePkrEquivalent = (currentBalance, currency) => {
        if (!paymentCurrentBalancePkrRow || !paymentCurrentBalancePkrInput) {
            return;
        }

        const displayCurrency = currency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const candidateRate = toNumber(paymentCurrentBalancePkrInput.dataset.paymentPkrRate || 0);
        const shouldShow = displayCurrency !== 'PKR' && candidateRate > 0.005;

        paymentCurrentBalancePkrRow.hidden = !shouldShow;
        if (!shouldShow) {
            paymentCurrentBalancePkrInput.value = 'PKR 0.00';
            return;
        }

        const pkrEquivalent = Math.max(currentBalance, 0) * candidateRate;
        paymentCurrentBalancePkrInput.value = formatCurrencyAmount('PKR', pkrEquivalent);
    };

    const clearExchangeSettlementFields = () => {
        clearExchangeFeedback();
        if (paymentSettlementModeInput) {
            paymentSettlementModeInput.value = 'normal';
        }
        if (paymentSettlementTargetIdInput) {
            paymentSettlementTargetIdInput.value = '';
        }
        if (paymentSettlementTargetCurrencyInput) {
            paymentSettlementTargetCurrencyInput.value = '';
        }
        if (paymentSettlementTargetReceivableAmountInput) {
            paymentSettlementTargetReceivableAmountInput.value = '';
        }
        if (paymentSettlementTargetPaymentAmountInput) {
            paymentSettlementTargetPaymentAmountInput.value = '';
        }
        if (paymentSettlementRateFromInput) {
            paymentSettlementRateFromInput.value = '';
        }
        if (paymentSettlementRateToInput) {
            paymentSettlementRateToInput.value = '';
        }
        if (paymentSettlementRateInput) {
            paymentSettlementRateInput.value = '';
        }
        if (paymentSettlementRateDateInput) {
            paymentSettlementRateDateInput.value = normalizeLooseDate(paymentReceiptDateInput?.value || '') || paymentSettlementRateDateInput.value;
        }
        if (paymentExchangeConfirmButton) {
            paymentExchangeConfirmButton.disabled = true;
            paymentExchangeConfirmButton.textContent = 'Confirm Settlement';
        }
        paymentExchangeConfirmInFlight = false;
    };

    const updateExchangeConfirmAvailability = (preview = null) => {
        if (!paymentExchangeConfirmButton || paymentExchangeConfirmInFlight) {
            return;
        }

        const hasValidPreview = Boolean(preview)
            && preview.paymentAmount > 0.005
            && preview.targetSettled > 0.005
            && preview.paymentConsumed > 0.005
            && (
                preview.target.currency === preview.quote.paymentCurrency
                || toNumber(preview.quote.exchangeRate || 0) > 0.005
            );

        paymentExchangeConfirmButton.disabled = !hasValidPreview;
        paymentExchangeConfirmButton.textContent = 'Confirm Settlement';
    };

    const currentSettlementTargets = () => {
        const currentBookingReference = currentBookingId() > 0
            ? Array.from(customerOpenReceivables).find((row) => Number.parseInt(String(row.bookingId || 0), 10) === currentBookingId())?.bookingReference || ''
            : '';

        return Array.from(customerOpenReceivables)
            .filter((row) => toNumber(row.outstandingAmount || 0) > 0.005)
            .map((row) => ({
                ...row,
                isCurrentBooking: Boolean(row.isCurrentBooking) || (currentBookingReference !== '' && row.bookingReference === currentBookingReference),
            }))
            .sort((left, right) => {
                if (left.isCurrentBooking && !right.isCurrentBooking) {
                    return -1;
                }
                if (!left.isCurrentBooking && right.isCurrentBooking) {
                    return 1;
                }
                const leftDate = String(left.bookingDate || '');
                const rightDate = String(right.bookingDate || '');
                if (leftDate !== rightDate) {
                    return leftDate.localeCompare(rightDate);
                }
                return Number.parseInt(String(left.id || 0), 10) - Number.parseInt(String(right.id || 0), 10);
            });
    };

    const settlementTargetLabel = (target) => {
        const prefix = target.isCurrentBooking ? 'Current Invoice' : 'Open Balance';
        const bookingRef = String(target.bookingReference || '').trim();
        const serviceRef = String(target.serviceLineReference || '').trim();
        const dueDate = String(target.nextDueDate || '').trim();
        return [
            prefix,
            bookingRef !== '' ? bookingRef : 'Booking',
            serviceRef !== '' ? serviceRef : 'Receivable',
            dueDate !== '' ? dueDate : 'No due date',
            formatCurrencyAmount(String(target.currency || 'PKR'), toNumber(target.outstandingAmount || 0)),
        ].join(' / ');
    };

    const currentInvoiceExchangeTargets = () => {
        const snapshot = currentInvoiceSnapshot();
        const invoiceCurrency = String(snapshot.invoiceCurrency || 'PKR').toUpperCase();

        return currentSettlementTargets().filter((row) => {
            const rowCurrency = String(row.currency || 'PKR').toUpperCase();
            return row.isCurrentBooking && rowCurrency === invoiceCurrency && toNumber(row.outstandingAmount || 0) > 0.005;
        });
    };

    const currentInvoiceExchangeTarget = () => currentInvoiceExchangeTargets()[0] || null;

    const replaceSettlementData = (nextReceivables = [], nextRates = {}) => {
        customerOpenReceivables = Array.isArray(nextReceivables) ? nextReceivables : [];
        dailySettlementRates = nextRates && typeof nextRates === 'object' ? nextRates : {};

        if (customerOpenReceivablesDataNode) {
            customerOpenReceivablesDataNode.textContent = JSON.stringify(customerOpenReceivables);
        }
        if (dailySettlementRatesDataNode) {
            dailySettlementRatesDataNode.textContent = JSON.stringify(dailySettlementRates);
        }
    };

    const replacePaymentHistoryData = (nextReceipts = [], nextAllocations = []) => {
        paymentReceipts = Array.isArray(nextReceipts) ? nextReceipts : [];
        paymentAllocations = Array.isArray(nextAllocations) ? nextAllocations : [];

        if (paymentReceiptsDataNode) {
            paymentReceiptsDataNode.textContent = JSON.stringify(paymentReceipts);
        }
        if (paymentAllocationsDataNode) {
            paymentAllocationsDataNode.textContent = JSON.stringify(paymentAllocations);
        }
    };

    const customerOutstandingByCurrency = () => {
        const datasetTotals = paymentPreviousBalanceInput
            ? parseBalanceMap(paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap || '{}')
            : {};
        const datasetEntries = Object.entries(datasetTotals).filter(([, amount]) => Math.abs(toNumber(amount || 0)) > 0.005);
        if (datasetEntries.length > 0) {
            return Object.fromEntries(datasetEntries.map(([currency, amount]) => [currency, roundToTwo(toNumber(amount || 0))]));
        }

        const totals = {};
        customerOpenReceivables.forEach((row) => {
            const currency = String(row?.currency || '').trim().toUpperCase();
            if (currency === '') {
                return;
            }

            const amount = Math.max(toNumber(row?.outstandingAmount || 0), 0);
            if (amount <= 0.005) {
                return;
            }

            totals[currency] = roundToTwo((totals[currency] || 0) + amount);
        });

        return totals;
    };

    const formatCurrencyTotalsInline = (totals = {}) => {
        const entries = Object.entries(totals).filter(([, amount]) => Math.abs(toNumber(amount || 0)) > 0.005);
        if (entries.length === 0) {
            return 'PKR 0.00';
        }

        return entries.map(([currency, amount]) => formatCurrencyAmount(currency, amount)).join(' / ');
    };

    const updateCustomerLedgerTarget = (bookingId = currentBookingId()) => {
        if (customerLedgerLinks.length === 0 && !(paymentLedgerLink instanceof HTMLAnchorElement)) {
            return;
        }

        const normalizedBookingId = Number.parseInt(String(bookingId || 0), 10) || 0;
        const ledgerUrl = normalizedBookingId > 0
            ? buildWorkspacePathUrl(`workspace/output?booking_id=${normalizedBookingId}&doc=account_statement`)
            : '#';

        const links = customerLedgerLinks.length > 0
            ? customerLedgerLinks
            : [paymentLedgerLink].filter((link) => link instanceof HTMLAnchorElement);

        links.forEach((link) => {
            link.href = ledgerUrl;
            link.classList.toggle('is-disabled', normalizedBookingId <= 0);
            link.setAttribute('aria-disabled', normalizedBookingId > 0 ? 'false' : 'true');
            if (normalizedBookingId > 0) {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener');
                link.removeAttribute('tabindex');
            } else {
                link.removeAttribute('target');
                link.removeAttribute('rel');
                link.tabIndex = -1;
            }
        });
    };

    const renderPaymentHistoryModal = () => {
        if (paymentHistorySummary instanceof HTMLElement) {
            const invoiceSnapshot = currentInvoiceSnapshot();
            paymentHistorySummary.innerHTML = `Current Invoice Total:<strong>${escapeHtml(formatCurrencyAmount(invoiceSnapshot.invoiceCurrency || 'PKR', invoiceSnapshot.invoiceAmount || 0))}</strong><span style="margin-left:12px;">Outstanding:<strong>${escapeHtml(formatCurrencyAmount(invoiceSnapshot.invoiceCurrency || 'PKR', invoiceSnapshot.invoiceBalance || 0))}</strong></span>`;
        }

        if (paymentHistoryReceiptsBody instanceof HTMLElement) {
            if (paymentReceipts.length === 0) {
                paymentHistoryReceiptsBody.innerHTML = '<tr><td colspan="12" class="empty-cell">No receipts recorded yet.</td></tr>';
            } else {
                const csrfField = paymentForm?.elements?.namedItem('_token');
                const csrfToken = csrfField instanceof HTMLInputElement ? csrfField.value.trim() : '';
                paymentHistoryReceiptsBody.innerHTML = paymentReceipts.map((receipt) => {
                    const receiptId = Number.parseInt(String(receipt?.id || 0), 10) || 0;
                    const bookingId = currentBookingId();
                    const statusRaw = String(receipt?.statusRaw || receipt?.status || '').trim().toLowerCase().replace(/\s+/g, '_');
                    const statusLabel = statusRaw === 'void'
                        ? 'VOID'
                        : String(receipt?.status || '').trim();
                    const printUrl = receiptId > 0 && bookingId > 0
                        ? buildWorkspacePathUrl(`workspace/output?booking_id=${bookingId}&doc=customer_receipt&receipt_id=${receiptId}`)
                        : '';
                    const metadataActionHtml = receiptId > 0 && bookingId > 0 && csrfToken !== ''
                        ? `<form method="post" action="${escapeHtml(buildWorkspacePathUrl('workspace/payments/receipts/metadata-save'))}" style="display:grid;gap:6px;min-width:190px;">
                            <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="booking_id" value="${bookingId}">
                            <input type="hidden" name="customer_receipt_id" value="${receiptId}">
                            <input type="text" name="receipt_reference_number" value="${escapeHtml(String(receipt?.referenceNumber || ''))}" placeholder="Payment reference" maxlength="100">
                            <input type="text" name="receipt_bank_card_detail" value="${escapeHtml(String(receipt?.bankCardDetail || ''))}" placeholder="Bank / Payment details" maxlength="190">
                            <input type="text" name="receipt_remarks" value="${escapeHtml(String(receipt?.remarks || ''))}" placeholder="Remarks / note" maxlength="4000">
                            <button class="btn btn-sm" type="submit">Save Notes</button>
                        </form>`
                        : '-';
                    const voidActionHtml = canVoidFinancials && statusRaw !== 'void' && receiptId > 0 && bookingId > 0 && csrfToken !== ''
                        ? `<form method="post" action="${escapeHtml(buildWorkspacePathUrl('workspace/payments/receipts/void'))}" onsubmit="return confirm('Void this receipt and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;">
                            <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="booking_id" value="${bookingId}">
                            <input type="hidden" name="customer_receipt_id" value="${receiptId}">
                            <input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required>
                            <button class="btn btn-sm" type="submit">Void</button>
                        </form>`
                        : '-';
                    const recreateActionHtml = statusRaw === 'void' && receiptId > 0 && bookingId > 0
                        ? `<a class="btn btn-sm" href="${escapeHtml(buildWorkspacePathUrl(`workspace?booking_id=${bookingId}&recreate_receipt_id=${receiptId}#dock-panel-payments`))}">Recreate</a>`
                        : '-';
                    return `<tr>
                        <td>${escapeHtml(String(receipt?.receiptNo || ''))}</td>
                        <td>${escapeHtml(String(receipt?.receiptDate || ''))}</td>
                        <td>${escapeHtml(String(receipt?.currency || ''))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.receivedAmount || 0)))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.allocatedAmount || 0)))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.unallocatedAmount || 0)))}</td>
                        <td>${escapeHtml(String(receipt?.paymentMethod || ''))}</td>
                        <td>${escapeHtml(statusLabel)}</td>
                        <td>${printUrl !== '' ? `<a href="${escapeHtml(printUrl)}" target="_blank" rel="noopener">Print</a>` : '-'}</td>
                        <td>${metadataActionHtml}</td>
                        <td>${voidActionHtml}</td>
                        <td>${recreateActionHtml}</td>
                    </tr>`;
                }).join('');
            }
        }

        if (paymentHistoryAllocationsBody instanceof HTMLElement) {
            if (paymentAllocations.length === 0) {
                paymentHistoryAllocationsBody.innerHTML = '<tr><td colspan="10" class="empty-cell">No allocations posted yet.</td></tr>';
            } else {
                paymentHistoryAllocationsBody.innerHTML = paymentAllocations.map((allocation) => {
                    const currency = String(allocation?.receivableCurrency || allocation?.currency || 'PKR');
                    const passenger = String(allocation?.passengerName || '').trim();
                    const receiptStatusRaw = String(allocation?.receiptStatusRaw || '').trim().toLowerCase().replace(/\s+/g, '_');
                    const remainingCell = receiptStatusRaw === 'void'
                        ? 'VOIDED'
                        : escapeHtml(formatCurrencyAmount(currency, toNumber(allocation?.remainingAfterAllocation || 0)));
                    return `<tr>
                        <td>${escapeHtml(String(allocation?.allocatedAt || ''))}</td>
                        <td>${escapeHtml(String(allocation?.receiptNo || ''))}</td>
                        <td>${escapeHtml(formatAllocationTypeLabel(allocation?.allocationType || 'Allocated'))}</td>
                        <td>${escapeHtml(String(allocation?.bookingReference || 'N/A'))}</td>
                        <td>${escapeHtml(String(allocation?.serviceLineReference || 'N/A'))}</td>
                        <td>${escapeHtml(String(allocation?.serviceType || 'Service'))}</td>
                        <td>${escapeHtml(passenger !== '' ? passenger : 'Customer')}</td>
                        <td>${escapeHtml(formatCurrencyAmount(currency, toNumber(allocation?.receivableAmountAllocated ?? allocation?.allocatedAmount ?? 0)))}</td>
                        <td>${remainingCell}</td>
                        <td>${escapeHtml(String(allocation?.allocationTrail || ''))}</td>
                    </tr>`;
                }).join('');
            }
        }
    };

    const refreshSettlementDataFromPayload = (payload = {}) => {
        const nextReceivables = Array.isArray(payload.customer_open_receivables)
            ? payload.customer_open_receivables
            : customerOpenReceivables;
        const nextRates = payload.daily_settlement_rates && typeof payload.daily_settlement_rates === 'object'
            ? payload.daily_settlement_rates
            : dailySettlementRates;

        replaceSettlementData(nextReceivables, nextRates);
    };

    const refreshPaymentHistoryFromPayload = (payload = {}) => {
        const nextReceipts = Array.isArray(payload.receipts) ? payload.receipts : paymentReceipts;
        const nextAllocations = Array.isArray(payload.allocations) ? payload.allocations : paymentAllocations;
        replacePaymentHistoryData(nextReceipts, nextAllocations);
        renderPaymentHistoryModal();
    };

    const readDisplayBackedAmount = (field, datasetValue) => {
        const persistedAmount = toNumber(datasetValue || 0);
        const visibleAmount = field ? toNumber(field.value || 0) : 0;
        return Math.max(persistedAmount, visibleAmount);
    };

    const currentInvoiceSnapshot = () => {
        const invoiceCurrency = String(paymentCurrentInvoiceInput?.dataset.paymentCurrency || serviceFields.currency?.value || 'PKR');
        const invoiceAmount = Math.max(readDisplayBackedAmount(
            paymentCurrentInvoiceInput,
            paymentCurrentInvoiceInput?.dataset.paymentCurrentInvoice || 0
        ), 0);
        const invoicePaid = Math.max(readDisplayBackedAmount(
            paymentAlreadyReceivedInput,
            paymentAlreadyReceivedInput?.dataset.paymentPersistedReceived || 0
        ), 0);
        const persistedBalance = Math.max(readDisplayBackedAmount(
            paymentCurrentBalanceInput,
            paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance || 0
        ), 0);
        const invoiceBalance = Math.max(invoiceAmount - invoicePaid, 0, persistedBalance);

        return {
            invoiceCurrency,
            invoiceAmount,
            invoicePaid,
            invoiceBalance,
        };
    };

    const currentInvoiceNeedsSettlementTarget = () => {
        const snapshot = currentInvoiceSnapshot();
        return snapshot.invoiceAmount > 0.005 || snapshot.invoiceBalance > 0.005 || snapshot.invoicePaid > 0.005;
    };

    const hasCurrentInvoiceSettlementTarget = () => currentInvoiceExchangeTargets().length > 0;

    const isCurrentInvoiceCrossCurrencySelection = (paymentCurrency = null) => {
        const snapshot = currentInvoiceSnapshot();
        const normalizedPaymentCurrency = String(paymentCurrency || paymentCurrencySelect?.value || '').trim().toUpperCase();
        if (normalizedPaymentCurrency === '') {
            return false;
        }

        return snapshot.invoiceBalance > 0.005
            && normalizedPaymentCurrency !== String(snapshot.invoiceCurrency || 'PKR').toUpperCase();
    };

    const shouldAutoTriggerExchangeSettlement = (paymentCurrency) => {
        const normalizedPaymentCurrency = String(paymentCurrency || '').trim().toUpperCase();
        if (normalizedPaymentCurrency === '') {
            return false;
        }

        const snapshot = currentInvoiceSnapshot();
        if (snapshot.invoiceBalance > 0.005 && normalizedPaymentCurrency !== snapshot.invoiceCurrency) {
            return true;
        }

        if (snapshot.invoiceBalance > 0.005) {
            return false;
        }

        const targets = currentSettlementTargets();
        if (targets.length === 0) {
            return false;
        }

        const hasSameCurrencyOpenBalance = targets.some((row) => String(row.currency || 'PKR').toUpperCase() === normalizedPaymentCurrency);
        const hasOtherCurrencyOpenBalance = targets.some((row) => String(row.currency || 'PKR').toUpperCase() !== normalizedPaymentCurrency);

        return !hasSameCurrencyOpenBalance && hasOtherCurrencyOpenBalance;
    };

    const updatePrintReceiptTarget = (receiptId, bookingId = currentBookingId()) => {
        if (!paymentPrintReceiptButton) {
            return;
        }

        const normalizedReceiptId = Number.parseInt(String(receiptId || 0), 10) || 0;
        const normalizedBookingId = Number.parseInt(String(bookingId || 0), 10) || 0;
        const nextUrl = normalizedReceiptId > 0 && normalizedBookingId > 0
            ? buildWorkspacePathUrl(`workspace/output?booking_id=${normalizedBookingId}&doc=customer_receipt&receipt_id=${normalizedReceiptId}`)
            : '';

        paymentPrintReceiptButton.dataset.paymentLatestReceiptId = String(normalizedReceiptId);
        paymentPrintReceiptButton.dataset.paymentPrintUrl = nextUrl;
    };

    const currentSavedPaymentApplies = () => savedPaymentState.saved && savedPaymentState.receiptId > 0 && savedPaymentState.bookingId === currentBookingId();

    const savedPaymentLockedMessage = () => {
        const receiptLabel = savedPaymentState.receiptNo !== ''
            ? savedPaymentState.receiptNo
            : (savedPaymentState.receiptId > 0 ? `receipt #${savedPaymentState.receiptId}` : 'this payment');

        return `This payment has already been saved${receiptLabel ? `: ${receiptLabel}` : ''}. Use New Payment to receive another amount.`;
    };

    const currentReceiptSummary = (payload = {}) => {
        const normalizedReceiptId = Number.parseInt(String(payload.receipt_id || 0), 10) || 0;
        const receiptRows = Array.isArray(payload.receipts) ? payload.receipts : [];
        const matchedReceipt = receiptRows.find((row) => (Number.parseInt(String(row?.id || 0), 10) || 0) === normalizedReceiptId) || null;

        return {
            receiptId: normalizedReceiptId,
            receiptNo: String(matchedReceipt?.receiptNo || '').trim(),
            amount: matchedReceipt ? Math.max(toNumber(matchedReceipt.receivedAmount || 0), 0) : Math.max(toNumber(payload?.lastAttemptedPayload?.received_amount || receivedNowInput?.value || 0), 0),
            currency: String(matchedReceipt?.currency || paymentCurrencySelect?.value || '').trim().toUpperCase(),
        };
    };

    const syncSavedPaymentUiState = () => {
        const locked = currentSavedPaymentApplies();

        if (paymentPrimarySaveButton) {
            if (paymentSubmitValidationInFlight) {
                paymentPrimarySaveButton.disabled = true;
                paymentPrimarySaveButton.textContent = 'Saving...';
            } else {
                paymentPrimarySaveButton.disabled = locked;
                paymentPrimarySaveButton.textContent = locked ? 'Payment Saved' : 'Save Payment';
            }
        }

        if (paymentNewEntryButton) {
            paymentNewEntryButton.disabled = !locked;
        }

        if (paymentExchangeSettlementButton) {
            paymentExchangeSettlementButton.disabled = locked;
        }

        if (paymentExchangeConfirmButton && !paymentExchangeConfirmInFlight) {
            if (locked) {
                paymentExchangeConfirmButton.disabled = true;
                paymentExchangeConfirmButton.textContent = 'Settlement Saved';
            } else {
                updateExchangeConfirmAvailability(paymentExchangeModal && !paymentExchangeModal.hidden ? exchangeSettlementPreview() : null);
            }
        }
    };

    const clearSavedPaymentState = (options = {}) => {
        const {
            clearAmount = false,
            resetPaymentCurrency = false,
            closeExchange = true,
            showReadyMessage = false,
        } = options;

        savedPaymentState = emptySavedPaymentState();
        savedPaymentEditNoticeShown = false;

        if (clearAmount && receivedNowInput) {
            receivedNowInput.value = '';
        }
        if (clearAmount && quickReceiveInput) {
            quickReceiveInput.value = '0.00';
        }

        if (resetPaymentCurrency && paymentCurrencySelect) {
            paymentCurrencySelect.value = currentInvoiceSnapshot().invoiceCurrency || 'PKR';
            syncPaymentCurrencyLabels(paymentCurrencySelect.value);
        }

        if (closeExchange) {
            closeExchangeSettlementModal();
        }

        clearExchangeSettlementFields();
        refreshPaymentPreview();
        syncSavedPaymentUiState();

        if (showReadyMessage) {
            showFeedback('New payment is ready. Enter another amount to save a new receipt.');
        }
    };

    const lockSavedPaymentStateFromPayload = (payload = {}, settlementMode = 'same_currency') => {
        const receipt = currentReceiptSummary(payload);
        savedPaymentState = {
            saved: receipt.receiptId > 0,
            bookingId: Number.parseInt(String(payload.booking_id || currentBookingId() || 0), 10) || 0,
            receiptId: receipt.receiptId,
            receiptNo: receipt.receiptNo,
            amount: receipt.amount,
            currency: receipt.currency,
            paymentMethod: String(paymentMethodSelect?.value || '').trim(),
            dueDate: String(paymentDueDateInput?.value || '').trim(),
            settlementMode,
        };
        savedPaymentEditNoticeShown = false;

        if (receivedNowInput) {
            receivedNowInput.value = receipt.amount > 0.005 ? receipt.amount.toFixed(2) : receivedNowInput.value;
        }

        if (paymentCurrencySelect && receipt.currency !== '') {
            paymentCurrencySelect.value = receipt.currency;
            syncPaymentCurrencyLabels(receipt.currency);
        }

        syncSavedPaymentUiState();
    };

    const noteSavedPaymentEditAttempt = () => {
        if (!currentSavedPaymentApplies()) {
            return false;
        }

        if (!savedPaymentEditNoticeShown) {
            showFeedback('Saved receipts cannot be edited here. Use New Payment for another receipt or void/reversal for correction.');
            savedPaymentEditNoticeShown = true;
        }

        syncSavedPaymentUiState();
        window.setTimeout(() => {
            syncSavedPaymentUiState();
        }, 0);

        return true;
    };

    const ensureExchangeSettlementTargetsReady = async () => {
        if (!currentInvoiceNeedsSettlementTarget() || hasCurrentInvoiceSettlementTarget()) {
            return true;
        }

        let refreshed = false;
        const servicePayload = await persistServiceAutosave();
        if (servicePayload) {
            refreshSettlementDataFromPayload(servicePayload);
            refreshed = true;
        }

        if (!hasCurrentInvoiceSettlementTarget()) {
            const invoicePayload = await persistInvoiceAutosave({ force: true });
            if (invoicePayload) {
                refreshSettlementDataFromPayload(invoicePayload);
                refreshed = true;
            }
        }

        if (refreshed) {
            refreshPaymentPreview();
        }

        return hasCurrentInvoiceSettlementTarget() || currentSettlementTargets().length > 0;
    };

    const exchangeSettlementPreview = () => {
        if (!paymentExchangeTargetSelect) {
            return null;
        }

        const invoiceSnapshot = currentInvoiceSnapshot();
        const target = currentInvoiceExchangeTarget();
        if (!target) {
            return null;
        }

        const paymentCurrency = paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const paymentAmountSource = paymentExchangePaymentAmountInput && paymentExchangeModal && !paymentExchangeModal.hidden
            ? paymentExchangePaymentAmountInput.value
            : receivedNowInput?.value || 0;
        const paymentAmount = Math.max(toNumber(paymentAmountSource || 0), 0);
        const targetCurrency = String(target.currency || 'PKR');
        const targetBalance = Math.max(
            toNumber(invoiceSnapshot.invoiceBalance || 0) > 0.005
                ? invoiceSnapshot.invoiceBalance
                : target.outstandingAmount || 0,
            0
        );
        const rateDate = paymentSettlementRateDateInput?.value || normalizeLooseDate(paymentReceiptDateInput?.value || '') || new Date().toISOString().slice(0, 10);
        const chosenQuote = preferredSettlementQuote(targetCurrency, paymentCurrency, dailySettlementRates, rateDate);
        const quote = {
            ...chosenQuote,
            targetCurrency,
            paymentCurrency,
        };

        if (paymentExchangeInvoiceNoInput) {
            paymentExchangeInvoiceNoInput.value = String(invoiceSnapshot.invoiceNo || target.bookingReference || '').trim();
        }
        if (paymentExchangeInvoiceCurrencyInput) {
            paymentExchangeInvoiceCurrencyInput.value = String(invoiceSnapshot.invoiceCurrency || targetCurrency || 'PKR');
        }
        if (paymentExchangeTargetCurrencyInput) {
            paymentExchangeTargetCurrencyInput.value = targetCurrency;
        }
        if (paymentExchangeTargetBalanceInput) {
            paymentExchangeTargetBalanceInput.value = formatCurrencyAmount(targetCurrency, targetBalance);
        }
        if (paymentExchangePaymentCurrencyInput) {
            paymentExchangePaymentCurrencyInput.value = paymentCurrency;
        }
        if (paymentExchangePaymentAmountInput) {
            if (document.activeElement !== paymentExchangePaymentAmountInput) {
                paymentExchangePaymentAmountInput.value = paymentAmount > 0 ? paymentAmount.toFixed(2) : '';
            }
        }
        if (paymentExchangeRateDateDisplay) {
            paymentExchangeRateDateDisplay.value = rateDate;
        }

        if (targetCurrency === paymentCurrency) {
            if (paymentExchangeRateRow) {
                paymentExchangeRateRow.hidden = true;
            }
            if (paymentExchangeRateHelp) {
                paymentExchangeRateHelp.hidden = true;
                paymentExchangeRateHelp.textContent = '';
            }
            if (paymentExchangeRateInput) {
                paymentExchangeRateInput.value = '1';
            }
        } else {
            if (paymentExchangeRateRow) {
                paymentExchangeRateRow.hidden = false;
            }
            if (paymentExchangeRateLabel) {
                paymentExchangeRateLabel.textContent = `Exchange Rate (1 ${quote.rateFromCurrency} = ? ${quote.rateToCurrency})`;
            }
            if (paymentExchangeRateInput && document.activeElement !== paymentExchangeRateInput) {
                const candidateRate = toNumber(paymentExchangeRateInput.value || quote.exchangeRate || 0);
                paymentExchangeRateInput.value = candidateRate > 0 ? String(candidateRate) : '';
            }
            if (paymentExchangeRateHelp) {
                const storedRate = toNumber(quote.exchangeRate || 0);
                paymentExchangeRateHelp.hidden = false;
                paymentExchangeRateHelp.textContent = storedRate > 0.005
                    ? `Today's exact rate is available: 1 ${quote.rateFromCurrency} = ${storedRate} ${quote.rateToCurrency}.`
                    : `Today's exact rate is missing. Enter it now to continue.`;
            }
        }

        const inputRate = targetCurrency === paymentCurrency
            ? 1
            : toNumber(paymentExchangeRateInput?.value || quote.exchangeRate || 0);
        const workingQuote = {
            ...quote,
            exchangeRate: inputRate,
        };

        const targetCapacity = paymentAmount > 0.005
            ? convertPaymentAmountToTargetAmount(paymentAmount, workingQuote)
            : 0;
        const targetSettled = roundToTwo(Math.min(targetBalance, targetCapacity));
        const paymentRequiredToFullyClearTarget = roundToTwo(convertTargetAmountToPaymentAmount(targetBalance, workingQuote));
        const paymentConsumed = roundToTwo(
            targetSettled >= targetBalance - 0.005
                ? Math.min(paymentAmount, paymentRequiredToFullyClearTarget)
                : paymentAmount
        );
        const remainingTargetBalance = roundToTwo(Math.max(targetBalance - targetSettled, 0));
        const remainingPaymentAmount = roundToTwo(Math.max(paymentAmount - paymentConsumed, 0));
        const samePaymentCurrencyOtherBalance = roundToTwo(
            currentSettlementTargets()
                .filter((row) => !row.isCurrentBooking)
                .filter((row) => String(row.currency || 'PKR') === paymentCurrency)
                .reduce((carry, row) => carry + Math.max(toNumber(row.outstandingAmount || 0), 0), 0)
        );
        const autoAppliedToSameCurrency = roundToTwo(Math.min(remainingPaymentAmount, samePaymentCurrencyOtherBalance));
        const returnOrCredit = roundToTwo(Math.max(remainingPaymentAmount - autoAppliedToSameCurrency, 0));

        if (paymentExchangePreviewRequired) {
            paymentExchangePreviewRequired.textContent = formatCurrencyAmount(paymentCurrency, paymentRequiredToFullyClearTarget);
        }
        if (paymentExchangePreviewSettled) {
            paymentExchangePreviewSettled.textContent = formatCurrencyAmount(targetCurrency, targetSettled);
        }
        if (paymentExchangePreviewConsumed) {
            paymentExchangePreviewConsumed.textContent = formatCurrencyAmount(paymentCurrency, paymentConsumed);
        }
        if (paymentExchangePreviewTargetRemaining) {
            paymentExchangePreviewTargetRemaining.textContent = formatCurrencyAmount(targetCurrency, remainingTargetBalance);
        }
        if (paymentExchangePreviewPaymentRemaining) {
            paymentExchangePreviewPaymentRemaining.textContent = formatCurrencyAmount(paymentCurrency, remainingPaymentAmount);
        }
        if (paymentExchangePreviewAutoApply) {
            paymentExchangePreviewAutoApply.textContent = formatCurrencyAmount(paymentCurrency, autoAppliedToSameCurrency);
        }
        if (paymentExchangePreviewReturn) {
            paymentExchangePreviewReturn.textContent = formatCurrencyAmount(paymentCurrency, returnOrCredit);
        }

        const preview = {
            target,
            quote: workingQuote,
            paymentAmount,
            targetBalance,
            targetSettled,
            paymentConsumed,
            remainingTargetBalance,
            remainingPaymentAmount,
            autoAppliedToSameCurrency,
            returnOrCredit,
            rateDate,
        };

        updateExchangeConfirmAvailability(preview);

        return preview;
    };

    const normalizeLooseDate = (value) => {
        const trimmed = String(value || '').trim();
        if (trimmed === '') {
            return '';
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
            return trimmed;
        }

        const slashMatch = trimmed.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/);
        if (slashMatch) {
            const [, day, month, year] = slashMatch;
            const normalized = `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
            const probe = new Date(`${normalized}T00:00:00`);
            if (!Number.isNaN(probe.getTime())) {
                return normalized;
            }
        }

        return '';
    };

    const dueHelperText = () => {
        if (!paymentDueDateInput || !paymentDueDateInput.value) {
            return 'Due date required';
        }

        const normalizedDate = normalizeLooseDate(paymentDueDateInput.value);
        if (!normalizedDate) {
            return 'Due date required';
        }

        const dueDate = new Date(`${normalizedDate}T00:00:00`);
        if (Number.isNaN(dueDate.getTime())) {
            return 'Due date required';
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const dayDiff = Math.round((dueDate.getTime() - today.getTime()) / 86400000);
        if (dayDiff === 0) {
            return 'Due Today';
        }

        if (dayDiff > 0) {
            return `Due in ${dayDiff} day${dayDiff === 1 ? '' : 's'}`;
        }

        return `Overdue by ${Math.abs(dayDiff)} day${dayDiff === -1 ? '' : 's'}`;
    };

    const syncInvoiceDueDateMirrors = () => {
        if (!paymentDueDateInput) {
            return;
        }

        const normalizedDate = normalizeLooseDate(paymentDueDateInput.value);
        const mirroredValue = normalizedDate || '';

        if (paymentDueDatePicker && normalizedDate) {
            paymentDueDatePicker.value = normalizedDate;
        }

        if (bookingDueDateField) {
            bookingDueDateField.value = mirroredValue;
        }

        if (autoBookingDueDateField) {
            autoBookingDueDateField.value = mirroredValue;
        }

        if (bookingDueDateDisplay) {
            bookingDueDateDisplay.value = mirroredValue;
        }
    };

    const setPaymentState = (state, helper) => {
        if (paymentStateLabel) {
            paymentStateLabel.textContent = state;
        }

        if (paymentDueHelper) {
            paymentDueHelper.textContent = helper;
        }

        if (paymentState) {
            const stateClass = {
                Draft: 'draft',
                Unpaid: 'unpaid',
                'Partially Paid': 'partial',
                Paid: 'paid',
                Overdue: 'overdue',
            }[state] || 'unpaid';
            paymentState.className = `legacy-payment-state legacy-payment-state--${stateClass}`;
        }
    };

    const persistedPaymentState = () => {
        if (!paymentState) {
            return {
                label: 'Draft',
                helper: '',
            };
        }

        return {
            label: paymentState.dataset.paymentPersistedState || 'Draft',
            helper: paymentState.dataset.paymentPersistedHelper || '',
        };
    };

    const refreshPaymentPreview = () => {
        if (!receivedNowInput || !paymentCurrentInvoiceInput || !paymentCurrentBalanceInput || !paymentTotalOutstandingInput) {
            return;
        }

        const invoiceCurrency = paymentCurrentInvoiceInput.dataset.paymentCurrency || serviceFields.currency?.value || 'PKR';
        const paymentCurrency = paymentCurrencySelect?.value || invoiceCurrency;
        const persistedState = persistedPaymentState();
        const currentInvoiceAmount = readDisplayBackedAmount(
            paymentCurrentInvoiceInput,
            paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice || 0
        );
        const persistedAlreadyReceived = readDisplayBackedAmount(
            paymentAlreadyReceivedInput,
            paymentAlreadyReceivedInput?.dataset.paymentPersistedReceived || 0
        );
        const persistedCurrentInvoiceBalance = readDisplayBackedAmount(
            paymentCurrentBalanceInput,
            paymentCurrentBalanceInput.dataset.paymentPersistedInvoiceBalance || 0
        );
        const receivedNow = Math.max(toNumber(receivedNowInput.value), 0);
        const hasPersistedInvoiceState = persistedState.label !== 'Draft'
            || autosavedHasSavedService
            || hasSavedServiceRows()
            || Math.abs(persistedCurrentInvoiceBalance) > 0.005;
        const currentInvoiceDueBeforeReceipt = hasPersistedInvoiceState
            ? Math.max(currentInvoiceAmount - persistedAlreadyReceived, 0)
            : Math.max(currentInvoiceAmount, 0);
        const currentBalance = Math.max(currentInvoiceDueBeforeReceipt, 0);
        const openBalanceDetails = syncOpenBalanceDisplay(invoiceCurrency, currentBalance);
        const balanceInPaymentCurrency = Math.max(toNumber(openBalanceDetails.openBalanceMap[paymentCurrency] || 0), 0);
        const returnAmount = Math.max(receivedNow - balanceInPaymentCurrency, 0);
        const hasRemainingOutstanding = balanceInPaymentCurrency > 0.005;
        const hasCurrentInvoiceAmount = currentInvoiceAmount > 0.005
            || currentBalance > 0.005
            || persistedAlreadyReceived > 0.005
            || persistedCurrentInvoiceBalance > 0.005
            || hasSavedServiceRows();

        syncPaymentCurrencyLabels(paymentCurrency);
        paymentCurrentInvoiceInput.value = formatCurrencyAmount(invoiceCurrency, currentInvoiceAmount);
        if (paymentAlreadyReceivedInput) {
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(invoiceCurrency, persistedAlreadyReceived);
        }
        paymentCurrentBalanceInput.value = formatCurrencyAmount(invoiceCurrency, currentBalance);
        paymentTotalOutstandingInput.value = formatCurrencyAmount(paymentCurrency, balanceInPaymentCurrency);
        paymentTotalOutstandingInput.dataset.paymentTotalDueNow = String(balanceInPaymentCurrency);
        syncCurrentBalancePkrEquivalent(currentBalance, invoiceCurrency);

        if (paymentNoCurrentInvoice) {
            paymentNoCurrentInvoice.hidden = hasCurrentInvoiceAmount;
        }
        if (paymentCurrentInvoiceRow) {
            paymentCurrentInvoiceRow.hidden = !hasCurrentInvoiceAmount;
        }
        if (paymentCurrentBalanceRow) {
            paymentCurrentBalanceRow.hidden = !hasCurrentInvoiceAmount;
        }
        if (paymentPaidCurrentInvoiceRow) {
            paymentPaidCurrentInvoiceRow.hidden = persistedAlreadyReceived <= 0.005;
        }

        if (paymentDueRow) {
            paymentDueRow.hidden = !hasRemainingOutstanding;
        }
        if (paymentDueDateInput) {
            paymentDueDateInput.disabled = !hasRemainingOutstanding;
            paymentDueDateInput.required = hasRemainingOutstanding;
        }
        if (paymentDueDateTrigger) {
            paymentDueDateTrigger.disabled = !hasRemainingOutstanding;
        }
        if (paymentDueDatePicker) {
            paymentDueDatePicker.disabled = !hasRemainingOutstanding;
        }

        if (paymentReturnRow) {
            paymentReturnRow.hidden = returnAmount <= 0.005;
        }

        if (paymentReturnAmountInput) {
            paymentReturnAmountInput.value = formatCurrencyAmount(paymentCurrency, returnAmount);
        }

        const realInvoiceExists = currentInvoiceAmount > 0.005
            || persistedCurrentInvoiceBalance > 0.005
            || hasSavedServiceRows();
        let computedState = 'Draft';
        let computedHelper = '';

        if (!realInvoiceExists && balanceInPaymentCurrency <= 0.005) {
            computedState = 'Draft';
        } else if (balanceInPaymentCurrency > 0.005 && receivedNow <= 0.005) {
            if (persistedState.label === 'Overdue') {
                computedState = 'Overdue';
                computedHelper = persistedState.helper || '';
            } else {
                computedState = 'Unpaid';
            }
        } else if (balanceInPaymentCurrency > 0.005 && receivedNow > 0.005) {
            computedState = 'Partially Paid';
        } else if (balanceInPaymentCurrency <= 0.005 && (realInvoiceExists || Object.values(openBalanceDetails.openBalanceMap).some((amount) => Math.abs(toNumber(amount)) > 0.005))) {
            computedState = 'Paid';
        }

        setPaymentState(computedState, computedHelper);

        updateWorkflowState();
    };

    const syncDefaultPaymentCurrency = (invoiceCurrency) => {
        if (!paymentCurrencySelect) {
            return;
        }

        const bookingKey = `${currentBookingId()}|${String(invoiceCurrency || 'PKR').toUpperCase()}`;
        const previousKey = String(paymentCurrencySelect.dataset.paymentDefaultContext || '');
        const shouldReset = paymentCurrencySelect.value.trim() === '' || previousKey !== bookingKey;

        if (shouldReset) {
            paymentCurrencySelect.value = invoiceCurrency;
        }

        paymentCurrencySelect.dataset.paymentDefaultContext = bookingKey;
    };

    const maybeOpenExchangeSettlementModal = async (options = {}) => {
        const {
            requireAmount = false,
            focusIfEmpty = false,
        } = options;

        if (currentSavedPaymentApplies()) {
            return false;
        }

        const snapshot = currentInvoiceSnapshot();
        const selectedPaymentCurrency = paymentCurrencySelect?.value || snapshot.invoiceCurrency || 'PKR';
        const enteredAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);

        if (selectedPaymentCurrency === snapshot.invoiceCurrency || snapshot.invoiceBalance <= 0.005) {
            if (paymentExchangeModal && !paymentExchangeModal.hidden && selectedPaymentCurrency === snapshot.invoiceCurrency) {
                closeExchangeSettlementModal();
            }
            return false;
        }

        if (requireAmount && enteredAmount <= 0.005) {
            return false;
        }

        if (paymentExchangeAutoOpenInFlight) {
            return false;
        }

        paymentExchangeAutoOpenInFlight = true;
        try {
            await openExchangeSettlementModal();
            if (focusIfEmpty && paymentExchangePaymentAmountInput && Math.max(toNumber(paymentExchangePaymentAmountInput.value || 0), 0) <= 0.005) {
                window.setTimeout(() => {
                    paymentExchangePaymentAmountInput.focus();
                }, 30);
            }
            return true;
        } finally {
            paymentExchangeAutoOpenInFlight = false;
        }
    };

    if (debugToolsEnabled) {
        window.debugWorkspaceCalc = () => {
            const invoiceSnapshot = currentInvoiceSnapshot();
            const invoiceCurrency = paymentCurrentInvoiceInput?.dataset.paymentCurrency || serviceFields.currency?.value || 'PKR';
            const paymentCurrency = paymentCurrencySelect?.value || invoiceCurrency;
            const taxTotal = currentServiceTaxTotal();
            const airlinePayable = currentServicePayableAmount();
            const suggestedFinalSalePrice = roundToTwo(defaultFinalSalePrice(airlinePayable));
            const finalSaleRawValue = String(finalSalePriceInput?.value ?? '');
            const finalSaleParsedValue = toNumber(finalSaleRawValue);
            const manualOverride = finalSalePriceInput?.dataset.manualOverride === '1';
            const staleZeroManualOverride = manualOverride
                && finalSaleParsedValue <= 0.005
                && suggestedFinalSalePrice > 0.005;
            const openBalanceDetails = syncOpenBalanceDisplay(invoiceSnapshot.invoiceCurrency, invoiceSnapshot.invoiceBalance);
            const balanceInPaymentCurrency = Math.max(toNumber(openBalanceDetails.openBalanceMap[paymentCurrency] || 0), 0);

            const debugPayload = {
                debugAvailable: true,
                location: window.location.href,
                serviceType: String(serviceTypeField?.value || ''),
                isAirTicket: isAirTicketServiceType(),
                selectors: {
                    sale: Boolean(serviceMetricInputs.sale),
                    tax: Boolean(serviceMetricInputs.tax),
                    vatInput: Boolean(serviceMetricInputs.vatInput),
                    spyi: Boolean(serviceMetricInputs.spyiAmount),
                    aqYrPk: Boolean(serviceMetricInputs.aqYrPkAmount),
                    yq: Boolean(serviceMetricInputs.yqAmount),
                    oth: Boolean(serviceMetricInputs.othAmount),
                    serviceCharge: Boolean(serviceMetricInputs.serviceCharge),
                    vatOutput: Boolean(serviceMetricInputs.vat),
                    discount: Boolean(serviceDiscountInput),
                    finalSale: Boolean(finalSalePriceInput),
                    airlinePayable: Boolean(airlinePayableField),
                    invoiceAmount: Boolean(paymentCurrentInvoiceInput),
                    invoiceBalance: Boolean(paymentCurrentBalanceInput),
                },
                rawValues: {
                    mktFare: String(serviceMetricInputs.sale?.value ?? ''),
                    taxes: String(serviceMetricInputs.tax?.value ?? ''),
                    vatInput: String(serviceMetricInputs.vatInput?.value ?? ''),
                    spyi: String(serviceMetricInputs.spyiAmount?.value ?? ''),
                    aqYrPk: String(serviceMetricInputs.aqYrPkAmount?.value ?? ''),
                    yq: String(serviceMetricInputs.yqAmount?.value ?? ''),
                    oth: String(serviceMetricInputs.othAmount?.value ?? ''),
                    serviceAmount: String(serviceMetricInputs.serviceCharge?.value ?? ''),
                    vatOutput: String(serviceMetricInputs.vat?.value ?? ''),
                    discountAmount: String(serviceDiscountInput?.value ?? ''),
                    purchaseCost: String(serviceMetricInputs.cost?.value ?? ''),
                    finalSaleAmount: finalSaleRawValue,
                    invoiceAmount: String(paymentCurrentInvoiceInput?.value ?? ''),
                    invoiceBalance: String(paymentCurrentBalanceInput?.value ?? ''),
                    paidOnThisInvoice: String(paymentAlreadyReceivedInput?.value ?? ''),
                },
                parsedValues: {
                    mktFare: toNumber(serviceMetricInputs.sale?.value),
                    taxes: toNumber(serviceMetricInputs.tax?.value),
                    vatInput: toNumber(serviceMetricInputs.vatInput?.value),
                    spyi: toNumber(serviceMetricInputs.spyiAmount?.value),
                    aqYrPk: toNumber(serviceMetricInputs.aqYrPkAmount?.value),
                    yq: toNumber(serviceMetricInputs.yqAmount?.value),
                    oth: toNumber(serviceMetricInputs.othAmount?.value),
                    serviceAmount: toNumber(serviceMetricInputs.serviceCharge?.value),
                    vatOutput: toNumber(serviceMetricInputs.vat?.value),
                    discountAmount: toNumber(serviceDiscountInput?.value),
                    purchaseCost: toNumber(serviceMetricInputs.cost?.value),
                    finalSaleAmount: finalSaleParsedValue,
                    paidOnThisInvoice: Math.max(readDisplayBackedAmount(
                        paymentAlreadyReceivedInput,
                        paymentAlreadyReceivedInput?.dataset.paymentPersistedReceived || 0
                    ), 0),
                },
                calculations: {
                    taxTotal,
                    frTx: airlinePayable,
                    finalSaleSuggested: suggestedFinalSalePrice,
                    fcReceivable: finalSaleParsedValue,
                    invoiceAmount: invoiceSnapshot.invoiceAmount,
                    invoiceBalance: invoiceSnapshot.invoiceBalance,
                    balanceInPaymentCurrency,
                    openBalanceMap: openBalanceDetails.openBalanceMap,
                },
                manualFinalSaleOverride: {
                    active: manualOverride,
                    staleZeroOverride: staleZeroManualOverride,
                    reason: manualOverride
                        ? (staleZeroManualOverride ? 'stale zero override' : 'user edited final sale')
                        : 'auto calculated',
                },
                eventBindings: {
                    listenerAttached: commercialTrace.listenerAttached,
                    lastEventFired: commercialTrace.lastEventFired,
                },
                autosave: {
                    lastOverwriteSource: commercialTrace.lastOverwriteSource,
                    lastFieldWritten: commercialTrace.lastFieldWritten,
                    autosavedReceivableAmount,
                    autosavedHasSavedService,
                    autosavedPaymentEligible,
                },
                commercialTrace: { ...commercialTrace },
            };

            if (typeof console !== 'undefined' && typeof console.debug === 'function') {
                console.debug('debugWorkspaceCalc', debugPayload);
            }

            return debugPayload;
        };
    } else {
        window.debugWorkspaceCalc = undefined;
    }

    const buildPaymentSubmitDebugState = () => ({
        saveButtonFound: Boolean(paymentPrimarySaveButton),
        handlerAttached: paymentSubmitDebug.handlerAttached,
        currentBookingId: currentBookingId(),
        parsedAmountReceiving: Math.max(toNumber(receivedNowInput?.value || 0), 0),
        paymentCurrency: paymentCurrencySelect?.value || '',
        invoiceCurrency: paymentCurrentInvoiceInput?.dataset.paymentCurrency || serviceFields.currency?.value || '',
        dueDate: paymentDueDateInput?.value || '',
        csrfFound: Boolean(paymentForm?.elements?.namedItem('_token') instanceof HTMLInputElement
            ? paymentForm.elements.namedItem('_token').value.trim() !== ''
            : false),
        routeUrl: paymentForm?.action || '',
        lastAttemptedPayload: paymentSubmitDebug.lastAttemptedPayload,
        lastBackendResponse: paymentSubmitDebug.lastBackendResponse,
        lastBackendError: paymentSubmitDebug.lastBackendError,
    });

    if (debugToolsEnabled) {
        window.debugPaymentSubmit = () => {
            const snapshot = buildPaymentSubmitDebugState();
            Object.assign(paymentSubmitDebug, snapshot);
            if (typeof console !== 'undefined' && typeof console.debug === 'function') {
                console.debug('debugPaymentSubmit', paymentSubmitDebug);
            }
            return { ...paymentSubmitDebug };
        };
    } else {
        window.debugPaymentSubmit = undefined;
    }

    if (quickReceiveInput && receivedNowInput) {
        quickReceiveInput.addEventListener('input', () => {
            const quickAmount = toNumber(quickReceiveInput.value);
            if (quickAmount > 0 || toNumber(receivedNowInput.value) <= 0) {
                receivedNowInput.value = quickReceiveInput.value;
                refreshPaymentPreview();
            }
        });
    }

    if (receivedNowInput) {
        receivedNowInput.addEventListener('input', refreshPaymentPreview);
        syncInvoiceDueDateMirrors();
        refreshPaymentPreview();
    }

    if (paymentCurrencySelect) {
        paymentCurrencySelect.addEventListener('change', async () => {
            if (noteSavedPaymentEditAttempt()) {
                syncPaymentCurrencyLabels(paymentCurrencySelect.value);
                refreshPaymentPreview();
                return;
            }

            syncPaymentCurrencyLabels(paymentCurrencySelect.value);
            refreshPaymentPreview();

            if (isCurrentInvoiceCrossCurrencySelection(paymentCurrencySelect.value)) {
                await maybeOpenExchangeSettlementModal({ focusIfEmpty: true });
                if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                    exchangeSettlementPreview();
                }
                return;
            }

            if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                closeExchangeSettlementModal();
            }
        });
    }

    if (paymentExchangeSettlementButton) {
        paymentExchangeSettlementButton.addEventListener('click', async () => {
            if (currentSavedPaymentApplies()) {
                showFeedback(savedPaymentLockedMessage());
                return;
            }

            const invoiceSnapshot = currentInvoiceSnapshot();
            const selectedPaymentCurrency = paymentCurrencySelect?.value || invoiceSnapshot.invoiceCurrency || 'PKR';
            if (invoiceSnapshot.invoiceBalance <= 0.005) {
                showFeedback('No current invoice balance is available for exchange settlement.');
                return;
            }
            if (selectedPaymentCurrency === invoiceSnapshot.invoiceCurrency) {
                showFeedback('Use Save Payment for same-currency receipts.');
                return;
            }

            await openExchangeSettlementModal();
        });
    }

    if (paymentPrintReceiptButton) {
        paymentPrintReceiptButton.addEventListener('click', () => {
            const receiptId = Number.parseInt(String(paymentPrintReceiptButton.dataset.paymentLatestReceiptId || 0), 10) || 0;
            const printUrl = String(paymentPrintReceiptButton.dataset.paymentPrintUrl || '').trim();

            if (receiptId <= 0 || printUrl === '') {
                showFeedback('No receipt has been saved yet. Please save payment first.');
                return;
            }

            window.open(printUrl, '_blank', 'noopener');
        });
    }

    const initialPaymentBookingField = [
        invoiceForm?.elements?.namedItem('booking_id'),
        serviceForm?.elements?.namedItem('booking_id'),
        paymentForm?.elements?.namedItem('booking_id'),
    ].find((candidate) => candidate instanceof HTMLInputElement);
    updatePrintReceiptTarget(
        paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0,
        initialPaymentBookingField instanceof HTMLInputElement
            ? (Number.parseInt(initialPaymentBookingField.value || '0', 10) || 0)
            : 0
    );
    syncSavedPaymentUiState();

    if (paymentExchangeTargetSelect) {
        paymentExchangeTargetSelect.addEventListener('change', () => {
            exchangeSettlementPreview();
        });
    }

    if (paymentExchangeRateInput) {
        paymentExchangeRateInput.addEventListener('input', () => {
            exchangeSettlementPreview();
        });
    }

    if (receivedNowInput) {
        receivedNowInput.addEventListener('input', async () => {
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            if (isCurrentInvoiceCrossCurrencySelection()) {
                await maybeOpenExchangeSettlementModal({ requireAmount: true });
            }
            if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                if (paymentExchangePaymentAmountInput && document.activeElement !== paymentExchangePaymentAmountInput) {
                    paymentExchangePaymentAmountInput.value = receivedNowInput.value;
                }
                exchangeSettlementPreview();
            }
        });
        receivedNowInput.addEventListener('change', async () => {
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            if (isCurrentInvoiceCrossCurrencySelection()) {
                await maybeOpenExchangeSettlementModal({ requireAmount: true });
            }
            if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                if (paymentExchangePaymentAmountInput && document.activeElement !== paymentExchangePaymentAmountInput) {
                    paymentExchangePaymentAmountInput.value = receivedNowInput.value;
                }
                exchangeSettlementPreview();
            }
        });
    }

    if (paymentExchangePaymentAmountInput) {
        ['input', 'change'].forEach((eventName) => {
            paymentExchangePaymentAmountInput.addEventListener(eventName, () => {
                if (receivedNowInput) {
                    receivedNowInput.value = paymentExchangePaymentAmountInput.value;
                }
                if (currentSavedPaymentApplies()) {
                    noteSavedPaymentEditAttempt();
                }
                refreshPaymentPreview();
                if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                    exchangeSettlementPreview();
                }
            });
        });
    }

    if (paymentExchangeConfirmButton) {
        paymentExchangeConfirmButton.addEventListener('click', async () => {
            if (paymentExchangeConfirmInFlight) {
                return;
            }

            if (currentSavedPaymentApplies()) {
                showExchangeFeedback(savedPaymentLockedMessage());
                syncSavedPaymentUiState();
                return;
            }

            clearExchangeFeedback();
            const preview = exchangeSettlementPreview();
            if (!preview) {
                showExchangeFeedback('Choose a settlement target first.');
                return;
            }

            if (preview.paymentAmount <= 0.005) {
                showExchangeFeedback('Enter a payment amount before confirming exchange settlement.');
                paymentExchangePaymentAmountInput?.focus();
                return;
            }

            if (preview.target.currency !== preview.quote.paymentCurrency && toNumber(preview.quote.exchangeRate || 0) <= 0.005) {
                showExchangeFeedback('Today\'s exchange rate is required before settlement can continue.');
                paymentExchangeRateInput?.focus();
                return;
            }

            const storedQuote = preferredSettlementQuote(
                String(preview.target.currency || 'PKR'),
                String(preview.quote.paymentCurrency || 'PKR'),
                dailySettlementRates,
                preview.rateDate
            );
            const storedRate = toNumber(storedQuote.exchangeRate || 0);
            const chosenRate = toNumber(preview.quote.exchangeRate || 0);
            if (
                preview.target.currency !== preview.quote.paymentCurrency
                && storedRate > 0.005
                && Math.abs(storedRate - chosenRate) > 0.00000001
            ) {
                const confirmed = window.confirm(
                    `Update today's exchange rate from ${storedRate.toFixed(8)} to ${chosenRate.toFixed(8)} for new settlements?`
                );
                if (!confirmed) {
                    paymentExchangeRateInput?.focus();
                    return;
                }
            }

            if (preview.targetSettled <= 0.005 || preview.paymentConsumed <= 0.005) {
                showExchangeFeedback('The entered payment does not settle any amount on the selected target.');
                return;
            }

            if (paymentSettlementModeInput) {
                paymentSettlementModeInput.value = 'exchange';
            }
            if (paymentSettlementTargetIdInput) {
                paymentSettlementTargetIdInput.value = String(preview.target.id || 0);
            }
            if (paymentSettlementTargetCurrencyInput) {
                paymentSettlementTargetCurrencyInput.value = String(preview.target.currency || 'PKR');
            }
            if (paymentSettlementTargetReceivableAmountInput) {
                paymentSettlementTargetReceivableAmountInput.value = preview.targetSettled.toFixed(2);
            }
            if (paymentSettlementTargetPaymentAmountInput) {
                paymentSettlementTargetPaymentAmountInput.value = preview.paymentConsumed.toFixed(2);
            }
            if (paymentSettlementRateFromInput) {
                paymentSettlementRateFromInput.value = String(preview.quote.rateFromCurrency || preview.target.currency || 'PKR');
            }
            if (paymentSettlementRateToInput) {
                paymentSettlementRateToInput.value = String(preview.quote.rateToCurrency || preview.quote.paymentCurrency || 'PKR');
            }
            if (paymentSettlementRateInput) {
                paymentSettlementRateInput.value = toNumber(preview.quote.exchangeRate || 0) > 0.005
                    ? Number(preview.quote.exchangeRate).toFixed(8)
                    : '';
            }
            if (paymentSettlementRateDateInput) {
                paymentSettlementRateDateInput.value = preview.rateDate;
            }

            if (!(paymentForm instanceof HTMLFormElement)) {
                showExchangeFeedback('Payment form is not available.');
                return;
            }

            if (receivedNowInput) {
                receivedNowInput.value = preview.paymentAmount.toFixed(2);
            }

            paymentExchangeConfirmInFlight = true;
            paymentExchangeConfirmButton.disabled = true;
            paymentExchangeConfirmButton.textContent = 'Saving...';

            try {
                const response = await fetch(paymentForm.action, {
                    method: 'POST',
                    body: new FormData(paymentForm),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                const payload = await response.json().catch(() => ({
                    ok: false,
                    message: 'The server returned an invalid exchange settlement response.',
                }));

                if (!response.ok || payload.ok === false) {
                    throw new Error(String(payload.message || 'Exchange settlement could not be saved.'));
                }

                syncBookingIdFields(Number.parseInt(String(payload.booking_id || 0), 10));
                applyAutosavedCustomer(payload.customer || null);
                if (payload.invoice_no) {
                    setInvoiceNumber(payload.invoice_no);
                }
                applyAutosavePaymentFoundation(payload);
                updatePrintReceiptTarget(payload.receipt_id || 0, payload.booking_id || currentBookingId());
                lockSavedPaymentStateFromPayload(payload, 'exchange');
                refreshPaymentPreview();
                const receiptSummary = currentReceiptSummary(payload);
                const successMessage = receiptSummary.receiptNo !== ''
                    ? `Payment saved: ${receiptSummary.receiptNo}`
                    : (payload.message || 'Exchange settlement recorded successfully.');
                showExchangeFeedback(successMessage);
                showFeedback(successMessage);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Exchange settlement could not be saved.';
                showExchangeFeedback(message);
            } finally {
                paymentExchangeConfirmInFlight = false;
                syncSavedPaymentUiState();
            }
        });
    }

    if (paymentDueDateInput) {
        paymentDueDateInput.addEventListener('input', () => {
            noteSavedPaymentEditAttempt();
            syncInvoiceDueDateMirrors();
            refreshPaymentPreview();
        });
        paymentDueDateInput.addEventListener('change', () => {
            noteSavedPaymentEditAttempt();
            syncInvoiceDueDateMirrors();
            refreshPaymentPreview();
        });
    }

    if (paymentMethodSelect) {
        paymentMethodSelect.addEventListener('change', () => {
            noteSavedPaymentEditAttempt();
        });
    }

    if (paymentDueDatePicker && paymentDueDateInput) {
        paymentDueDatePicker.addEventListener('change', () => {
            if (paymentDueDatePicker.value) {
                paymentDueDateInput.value = paymentDueDatePicker.value;
                syncInvoiceDueDateMirrors();
                refreshPaymentPreview();
            }
        });
    }

    if (paymentDueDateTrigger && paymentDueDatePicker) {
        paymentDueDateTrigger.addEventListener('click', () => {
            if (paymentDueDateTrigger.disabled || paymentDueDatePicker.disabled) {
                return;
            }

            const normalizedDate = normalizeLooseDate(paymentDueDateInput ? paymentDueDateInput.value : '');
            if (normalizedDate) {
                paymentDueDatePicker.value = normalizedDate;
            }

            paymentDueDatePicker.focus({ preventScroll: true });
            if (typeof paymentDueDatePicker.showPicker === 'function') {
                try {
                    paymentDueDatePicker.showPicker();
                    return;
                } catch (error) {
                    // Fall back to click below.
                }
            }

            paymentDueDatePicker.click();
        });
    }

    const performSameCurrencyPaymentSave = async () => {
        if (!(paymentForm instanceof HTMLFormElement)) {
            return;
        }

        if (paymentSubmitValidationInFlight) {
            return;
        }

        paymentSubmitValidationInFlight = true;
        paymentSubmitDebug.lastBackendError = null;
        paymentSubmitDebug.lastBackendResponse = null;

        try {
            if (currentBookingId() <= 0) {
                showFeedback('Customer-level payment without an open booking is not supported yet. Open an existing booking first.');
                return;
            }

            if (currentSavedPaymentApplies()) {
                showFeedback(savedPaymentLockedMessage());
                syncSavedPaymentUiState();
                return;
            }

            const isExchangeSettlement = paymentSettlementModeInput?.value === 'exchange';
            const selectedPaymentCurrency = paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
            const sameCurrencyDueNow = Math.max(
                toNumber(paymentTotalOutstandingInput?.dataset.paymentTotalDueNow || paymentTotalOutstandingInput?.value || 0),
                0
            );
            const invoiceSnapshot = currentInvoiceSnapshot();
            syncPaymentCurrencyLabels(selectedPaymentCurrency);

            if (selectedPaymentCurrency !== invoiceSnapshot.invoiceCurrency) {
                if (invoiceSnapshot.invoiceBalance > 0.005) {
                    clearExchangeSettlementFields();
                    await openExchangeSettlementModal();
                    return;
                }

                showFeedback('Cross-currency settlement is under final testing. Please use same-currency payment for now.');
                return;
            }

            if (isExchangeSettlement) {
                clearExchangeSettlementFields();
            }

            if (sameCurrencyDueNow <= 0.005) {
                showFeedback('No open balance exists in the selected payment currency.');
                return;
            }

            const currentServiceId = currentPersistedServiceId();
            const paymentReady = currentServiceId > 0
                && (
                    autosavedPaymentEligible
                    || autosavedReceivableAmount > 0.005
                    || hasValidSavedServiceForPayment()
                );

            if (!paymentReady && sameCurrencyDueNow <= 0.005) {
                showFeedback('No open balance exists in the selected payment currency.');
                return;
            }

            const receivedAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);
            if (receivedAmount <= 0.005) {
                showFeedback('Please enter a valid received amount.');
                receivedNowInput?.focus();
                return;
            }

            clearExchangeSettlementFields();

            const needsDueDate = paymentDueRow && !paymentDueRow.hidden && paymentDueDateInput && !paymentDueDateInput.disabled;
            if (paymentDueDateInput) {
                if (!needsDueDate) {
                    paymentDueDateInput.value = '';
                    if (paymentDueDatePicker) {
                        paymentDueDatePicker.value = '';
                    }
                } else {
                    const normalizedDate = normalizeLooseDate(paymentDueDateInput.value);
                    if (!normalizedDate) {
                        paymentDueDateInput.focus();
                        showFeedback('Enter a valid due date as YYYY-MM-DD or DD/MM/YYYY.');
                        return;
                    }

                    paymentDueDateInput.value = normalizedDate;
                    if (paymentDueDatePicker) {
                        paymentDueDatePicker.value = normalizedDate;
                    }
                    syncInvoiceDueDateMirrors();
                }
            }

            const csrfField = paymentForm.elements.namedItem('_token');
            const csrfToken = csrfField instanceof HTMLInputElement ? csrfField.value.trim() : '';
            const formData = new FormData(paymentForm);
            formData.set('receipt_action', 'save');

            paymentSubmitDebug.currentBookingId = currentBookingId();
            paymentSubmitDebug.parsedAmountReceiving = receivedAmount;
            paymentSubmitDebug.paymentCurrency = selectedPaymentCurrency;
            paymentSubmitDebug.invoiceCurrency = invoiceSnapshot.invoiceCurrency;
            paymentSubmitDebug.dueDate = paymentDueDateInput?.value || '';
            paymentSubmitDebug.csrfFound = csrfToken !== '';
            paymentSubmitDebug.routeUrl = paymentForm.action;
            paymentSubmitDebug.lastAttemptedPayload = Object.fromEntries(formData.entries());

            if (paymentPrimarySaveButton) {
                paymentPrimarySaveButton.disabled = true;
                paymentPrimarySaveButton.textContent = 'Saving...';
            }

            const response = await fetch(paymentForm.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({
                ok: false,
                message: 'The server returned an invalid receipt response.',
            }));

            paymentSubmitDebug.lastBackendResponse = payload;

            if (!response.ok || payload.ok === false) {
                throw new Error(String(payload.message || 'Customer receipt could not be saved.'));
            }

            syncBookingIdFields(Number.parseInt(String(payload.booking_id || 0), 10));
            applyAutosavedCustomer(payload.customer || null);
            if (payload.invoice_no) {
                setInvoiceNumber(payload.invoice_no);
            }
            applyAutosavePaymentFoundation(payload, { syncCommercialEditor: false });
            updatePrintReceiptTarget(payload.receipt_id || 0, payload.booking_id || currentBookingId());
            lockSavedPaymentStateFromPayload(payload, 'same_currency');
            refreshPaymentPreview();
            const receiptSummary = currentReceiptSummary(payload);
            showFeedback(receiptSummary.receiptNo !== '' ? `Payment saved: ${receiptSummary.receiptNo}` : (payload.message || 'Customer receipt recorded successfully.'));
        } catch (error) {
            paymentSubmitDebug.lastBackendError = error instanceof Error ? error.message : 'Customer receipt could not be saved.';
            showFeedback(paymentSubmitDebug.lastBackendError);
        } finally {
            paymentSubmitValidationInFlight = false;
            syncSavedPaymentUiState();
        }
    };

    if (paymentPrimarySaveButton) {
        paymentSubmitDebug.saveButtonFound = true;
        paymentPrimarySaveButton.addEventListener('click', () => {
            void performSameCurrencyPaymentSave();
        });
        paymentSubmitDebug.handlerAttached = true;
    }

    if (paymentNewEntryButton) {
        paymentNewEntryButton.addEventListener('click', () => {
            clearSavedPaymentState({
                clearAmount: true,
                resetPaymentCurrency: true,
                closeExchange: true,
                showReadyMessage: true,
            });
        });
    }

    if (paymentForm) {
        paymentForm.addEventListener('submit', (event) => {
            if (allowNativePaymentSubmit) {
                allowNativePaymentSubmit = false;
                return;
            }

            event.preventDefault();
            showFeedback('Use Save Payment to record the receipt.');
        });
    }

    customerDuesCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeCustomerDuesModal());
    });

    if (customerDuesSearchInput) {
        let duesSearchTimer = 0;
        customerDuesSearchInput.addEventListener('input', () => {
            const nextQuery = String(customerDuesSearchInput.value || '');
            if (normalizeCustomerDuesQuery(nextQuery) !== normalizeCustomerDuesQuery(customerDuesFinderState.query)) {
                resetCustomerDuesSelection();
                renderCustomerDuesFinder();
                setCustomerDuesFeedback('Search a customer with outstanding dues, then choose View Dues.');
            }
            window.clearTimeout(duesSearchTimer);
            duesSearchTimer = window.setTimeout(() => {
                void loadCustomerDuesFinder({
                    query: nextQuery,
                    travelerId: 0,
                    currency: customerDuesCurrencyFilter?.value || '',
                });
            }, 220);
        });
    }

    if (customerDuesCurrencyFilter) {
        customerDuesCurrencyFilter.addEventListener('change', () => {
            void loadCustomerDuesFinder({
                query: customerDuesSearchInput?.value || customerDuesFinderState.query,
                travelerId: customerDuesFinderState.selectedTravelerId,
                currency: customerDuesCurrencyFilter.value,
            });
        });
    }

    if (customerDuesCustomersBody) {
        customerDuesCustomersBody.addEventListener('click', (event) => {
            const button = event.target instanceof HTMLElement ? event.target.closest('[data-customer-dues-select]') : null;
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            const travelerId = Number.parseInt(String(button.dataset.customerDuesSelect || '0'), 10) || 0;
            if (travelerId <= 0) {
                return;
            }

            void loadCustomerDuesFinder({
                query: customerDuesSearchInput?.value || customerDuesFinderState.query,
                travelerId,
                currency: customerDuesCurrencyFilter?.value || '',
            });
        });
    }

    supplierHistoryCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeSupplierHistoryModal());
    });

    if (supplierHistorySearchInput) {
        let supplierHistorySearchTimer = 0;
        supplierHistorySearchInput.addEventListener('input', () => {
            const nextQuery = String(supplierHistorySearchInput.value || '');
            window.clearTimeout(supplierHistorySearchTimer);
            supplierHistorySearchTimer = window.setTimeout(() => {
                void loadSupplierHistoryFinder({ query: nextQuery });
            }, 220);
        });
    }

    const fillValue = (field, value) => {
        if (field) {
            field.value = value ?? '';
        }
    };

    const syncServiceCurrencyMirror = () => {
        if (!serviceFields.currencyMirror || !serviceFields.currency) {
            return;
        }

        serviceFields.currencyMirror.value = serviceFields.currency.value || 'PKR';
    };

    const currentBookingId = () => {
        const candidates = [
            invoiceForm?.elements?.namedItem('booking_id'),
            serviceForm?.elements?.namedItem('booking_id'),
            paymentForm?.elements?.namedItem('booking_id'),
        ];
        const field = candidates.find((candidate) => candidate instanceof HTMLInputElement);
        return field instanceof HTMLInputElement ? Number.parseInt(field.value || '0', 10) : 0;
    };

    const syncBookingIdFields = (bookingId) => {
        const normalizedBookingId = Number.parseInt(String(bookingId || 0), 10) || 0;
        if (savedPaymentState.saved && savedPaymentState.bookingId !== normalizedBookingId) {
            clearSavedPaymentState();
        }

        station.querySelectorAll('input[name="booking_id"]').forEach((field) => {
            if (field instanceof HTMLInputElement) {
                field.value = String(normalizedBookingId || 0);
            }
        });

        if (normalizedBookingId > 0) {
            station.querySelectorAll('[data-booking-gated-control]').forEach((control) => {
                if (control instanceof HTMLButtonElement || control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                    control.disabled = false;
                    control.dataset.workflowOriginalDisabled = '0';
                } else if (control instanceof HTMLAnchorElement) {
                    control.classList.remove('is-disabled');
                    control.setAttribute('aria-disabled', 'false');
                    control.removeAttribute('tabindex');
                }
            });
        }

        updateCustomerLedgerTarget(normalizedBookingId);
    };

    const currentPersistedServiceId = () => {
        const hiddenServiceId = Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10);
        if (hiddenServiceId > 0) {
            return hiddenServiceId;
        }

        if (autosavedPersistedServiceId > 0) {
            return autosavedPersistedServiceId;
        }

        const persistedLine = serviceLines.find((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0);
        return persistedLine ? Number.parseInt(String(persistedLine.serviceId || 0), 10) : 0;
    };

    const setInvoiceNumber = (label) => {
        if (invoiceNumberDisplay instanceof HTMLInputElement) {
            invoiceNumberDisplay.value = label || 'Draft';
        }
    };

    const setAutosaveStatus = (state, message) => {
        if (!(autosaveStatusLabel instanceof HTMLElement)) {
            return;
        }

        autosaveStatusLabel.dataset.state = state;
        autosaveStatusLabel.textContent = message;
    };

    const serializeForm = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return '';
        }

        return JSON.stringify(
            Array.from(new FormData(form).entries()).filter(([name]) => name !== '_token')
        );
    };

    const postAutosave = async (url, form) => {
        if (!(form instanceof HTMLFormElement) || url === '') {
            throw new Error('Autosave is not available for this form.');
        }

        const response = await fetch(url, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({
            ok: false,
            message: 'The server returned an invalid autosave response.',
        }));

        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Autosave failed.');
        }

        return payload;
    };

    const parseJsonDataNode = (node, label) => {
        if (!node) {
            updateCommercialTrace({
                lastEventFired: `parse:${label}`,
                firstFailurePoint: commercialTrace.firstFailurePoint === 'pending' || commercialTrace.firstFailurePoint === 'none yet'
                    ? `${label} data node missing`
                    : commercialTrace.firstFailurePoint,
            }, { log: false });
            return [];
        }

        try {
            const parsed = JSON.parse(node.textContent || '[]');
            updateCommercialTrace({
                lastEventFired: `parse:${label}`,
                bootStatus: 'json-parsed',
                firstFailurePoint: commercialTrace.firstFailurePoint === 'pending'
                    ? 'none yet'
                    : commercialTrace.firstFailurePoint,
            }, { log: false });
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            updateCommercialTrace({
                bootStatus: 'json-parse-failed',
                lastEventFired: `parse:${label}`,
                firstFailurePoint: `${label} JSON parse failed: ${error.message || 'unknown parse error'}`,
                lastOverwriteSource: `parse:${label}`,
            });
            return [];
        }
    };

    const serviceLinesDataNode = station.querySelector('#workspace-service-lines-data');
    serviceLines = parseJsonDataNode(serviceLinesDataNode, 'serviceLines');
    const hasPersistedServices = () => serviceLines.some((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0);
    hasSavedServiceRows = () => serviceLines.some((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0);
    const travelersDataNode = station.querySelector('#workspace-travelers-data');
    const travelers = parseJsonDataNode(travelersDataNode, 'travelers');
    const customerOpenReceivablesDataNode = station.querySelector('#workspace-customer-open-receivables-data');
    let customerOpenReceivables = parseJsonDataNode(customerOpenReceivablesDataNode, 'customerOpenReceivables');
    const dailySettlementRatesDataNode = station.querySelector('#workspace-daily-settlement-rates-data');
    let dailySettlementRates = parseBalanceMap(dailySettlementRatesDataNode?.textContent || '{}');
    const paymentReceiptsDataNode = station.querySelector('#workspace-payment-receipts-data');
    let paymentReceipts = parseJsonDataNode(paymentReceiptsDataNode, 'paymentReceipts');
    const paymentAllocationsDataNode = station.querySelector('#workspace-payment-allocations-data');
    let paymentAllocations = parseJsonDataNode(paymentAllocationsDataNode, 'paymentAllocations');
    const customerDuesFinderUrl = String(customerDuesModal?.dataset.customerDuesUrl || '').trim();
    let customerDuesFinderState = {
        query: '',
        selectedTravelerId: 0,
        currency: '',
        customers: [],
        selectedCustomer: null,
        openInvoices: [],
        requestToken: 0,
    };
    const supplierHistoryFinderUrl = String(supplierHistoryModal?.dataset.supplierHistoryUrl || '').trim();
    let supplierHistoryFinderState = {
        query: '',
        results: [],
        requestToken: 0,
    };
    const normalizeCustomerDuesQuery = (value) => String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    const formatAllocationTypeLabel = (value) => {
        if (String(value || '') === 'Previous Outstanding') {
            return 'Previous Balance';
        }
        if (String(value || '') === 'Customer Credit / Unallocated') {
            return 'Customer Credit';
        }
        return String(value || 'Allocated');
    };
    const resetCustomerDuesSelection = () => {
        customerDuesFinderState = {
            ...customerDuesFinderState,
            selectedTravelerId: 0,
            selectedCustomer: null,
            openInvoices: [],
        };
    };
    const workflowGates = {
        customerStage: station.querySelector('[data-workflow-gate="customer-stage"]'),
        serviceEntry: station.querySelector('[data-workflow-gate="service-entry"]'),
        postService: Array.from(station.querySelectorAll('[data-workflow-gate="post-service"]')),
        paymentStage: station.querySelector('[data-workflow-gate="payment-stage"]'),
    };
    const addServiceButtons = Array.from(station.querySelectorAll('[data-workflow-control="add-service"]'));
    const paymentHistoryButtons = Array.from(station.querySelectorAll('[data-workflow-control="payment-history"]'));
    const paymentSubmitButtons = Array.from(station.querySelectorAll('[data-payment-submit-action]'));
    renderPaymentHistoryModal();
    updateCustomerLedgerTarget(currentBookingId());
    const normalizePassengerName = (value) => String(value || '').trim().toLowerCase();
    const findTravelerByName = (name) => {
        const normalized = normalizePassengerName(name);
        if (normalized === '') {
            return null;
        }

        return travelers.find((traveler) => normalizePassengerName(traveler.fullName) === normalized) || null;
    };
    const defaultServiceTravelerId = () => {
        const leadTraveler = travelers.find((traveler) => String(traveler.travelerRole || '').toLowerCase() === 'lead') || travelers[0] || null;
        return leadTraveler && Number.parseInt(String(leadTraveler.travelerId || 0), 10) > 0 ? leadTraveler.travelerId : 0;
    };
    let serviceRows = Array.from(station.querySelectorAll('[data-service-row]'));
    const serviceTypeField = station.querySelector('[data-service-field="type"]');
    const serviceProfit = commercialLookup('commercial-service-profit', '[data-service-profit]');
    const airlinePayableField = commercialLookup('commercial-airline-payable', '[data-airline-payable-field]');
    const airlinePayableFinancialField = commercialLookup('commercial-airline-payable-financial', '[data-airline-payable-financial-field]');
    const airlinePayableSummary = commercialLookup('commercial-airline-payable-summary', '[data-airline-payable-summary]');
    const ticketValueField = commercialLookup('commercial-ticket-value', '[data-ticket-value-field]');
    const totalSpField = commercialLookup('commercial-total-sp', '[data-total-sp-field]');
    const clientReceivableField = commercialLookup('commercial-client-receivable', '[data-client-receivable-field]');
    const clientReceivableSummary = commercialLookup('commercial-client-receivable-summary', '[data-client-receivable-summary]');
    const otherPayableSummary = commercialLookup('commercial-other-payable-summary', '[data-other-payable-summary]');
    const activeServiceMode = station.querySelector('[data-active-service-mode]');
    const activeServiceReference = station.querySelector('[data-active-service-reference]');
    const activeServiceType = station.querySelector('[data-active-service-type]');
    const airTicketPanel = station.querySelector('[data-air-ticket-panel]');
    const airOnlyFields = Array.from(station.querySelectorAll('[data-air-only]'));
    const subtypePanels = Array.from(station.querySelectorAll('[data-service-subtype-panel]'));
    const serviceRefLabel = station.querySelector('[data-service-ref-label]');
    const serviceSecondRefLabel = station.querySelector('[data-service-second-ref-label]');
    const serviceSupplierLabel = station.querySelector('[data-service-supplier-label]');
    const serviceResetButton = station.querySelector('[data-service-reset]');
    const serviceSubmitButton = station.querySelector('[data-service-submit]');
    const serviceDeactivateId = station.querySelector('[data-service-deactivate-id]');
    const serviceDeactivateButton = station.querySelector('[data-service-deactivate-button]');
    const serviceCancelId = station.querySelector('[data-service-cancel-id]');
    const serviceCancelButton = station.querySelector('[data-service-cancel-button]');
    const serviceRefundId = station.querySelector('[data-service-refund-id]');
    const serviceRefundButton = station.querySelector('[data-service-refund-button]');
    const serviceSettlementId = station.querySelector('[data-service-settlement-id]');
    const serviceSettlementButton = station.querySelector('[data-service-settlement-button]');
    const serviceReissueId = station.querySelector('[data-service-reissue-id]');
    const serviceReissueButton = station.querySelector('[data-service-reissue-button]');
    const serviceEventBars = {
        cancel: station.querySelector('[data-service-event-bar="cancel"]'),
        refund: station.querySelector('[data-service-event-bar="refund"]'),
        settlement: station.querySelector('[data-service-event-bar="settlement"]'),
        reissue: station.querySelector('[data-service-event-bar="reissue"]'),
    };
    const lossAmountPanel = station.querySelector('[data-loss-amount-panel]');
    const lossReasonPanel = station.querySelector('[data-loss-reason-panel]');
    const chips = Array.from(station.querySelectorAll('.service-type-chip'));
    const serviceFields = {
        serviceId: station.querySelector('[data-service-field="serviceId"]'),
        lineNumber: station.querySelector('[data-service-field="lineNumber"]'),
        supplier: station.querySelector('[data-service-field="supplier"]'),
        currency: station.querySelector('[data-service-field="currency"]'),
        currencyMirror: station.querySelector('[data-service-field="currency-mirror"]'),
        status: station.querySelector('[data-service-field="status"]'),
        dueDate: station.querySelector('[data-service-field="due_date"]'),
        remarks: station.querySelector('[data-service-field="remarks"]'),
        lossReason: station.querySelector('[data-service-field="lossReason"]'),
        travelerId: station.querySelector('[data-service-field="travelerId"]'),
    };
    let supplierAdvanceLookupTimerId = 0;
    let supplierAdvanceLookupToken = 0;
    const finalSalePriceInput = commercialLookup('commercial-final-sale-price', '[data-service-final-sale]');
    const lossAmountInput = commercialLookup('commercial-loss-amount', '[data-service-loss-amount]');
    const servicePassengerNameField = commercialLookup('active-service-passenger-name', '[data-service-passenger-name]');
    const servicePassengerOptions = station.querySelector('#service-passenger-options');
    const syncServicePassengerName = (fallbackName = '') => {
        if (!servicePassengerNameField) {
            return;
        }

        const travelerId = serviceFields.travelerId ? String(serviceFields.travelerId.value || '0') : '0';
        const traveler = travelers.find((item) => String(item.travelerId || '0') === travelerId);
        servicePassengerNameField.value = traveler && traveler.fullName
            ? traveler.fullName
            : fallbackName;
    };
    const syncServiceTravelerIdFromName = () => {
        if (!servicePassengerNameField || !serviceFields.travelerId) {
            return;
        }

        const traveler = findTravelerByName(servicePassengerNameField.value);
        serviceFields.travelerId.value = traveler && Number.parseInt(String(traveler.travelerId || 0), 10) > 0
            ? String(traveler.travelerId)
            : '0';
    };
    const ensureServiceTravelerOption = (customer) => {
        if (!servicePassengerOptions || !customer || !customer.id) {
            return;
        }

        const customerId = String(customer.id);
        const customerName = String(customer.full_name || '').trim();
        if (customerName === '') {
            return;
        }

        let option = Array.from(servicePassengerOptions.options).find((item) => item.value === customerName);
        if (!option) {
            option = document.createElement('option');
            option.value = customerName;
            servicePassengerOptions.appendChild(option);
        }

        option.dataset.travelerId = customerId;

        if (!travelers.some((traveler) => String(traveler.travelerId || '') === customerId)) {
            travelers.push({
                travelerId: customer.id,
                travelerRole: 'lead',
                fullName: customer.full_name || '',
            });
        }
    };
    const useCustomerAsDraftServicePassenger = (customer) => {
        if (!servicePassengerNameField || !serviceFields.travelerId || !customer || !customer.id) {
            return;
        }

        ensureServiceTravelerOption(customer);

        const serviceId = serviceFields.serviceId
            ? Number.parseInt(String(serviceFields.serviceId.value || 0), 10)
            : 0;
        if (serviceId <= 0 || serviceFields.travelerId.value === '0' || serviceFields.travelerId.value === '') {
            fillValue(serviceFields.travelerId, customer.id);
            fillValue(servicePassengerNameField, customer.full_name || '');
        }
    };
    const serviceMetricInputs = {
        sale: commercialLookup('commercial-sale-price', '[data-service-metric="sale"]'),
        cost: commercialLookup('commercial-purchase-cost', '[data-service-metric="cost"]'),
        tax: commercialLookup('commercial-taxes', '[data-service-metric="tax"]'),
        otherFare: commercialLookup('commercial-other-fare', '[data-service-metric="other_fare"]'),
        sotoFare: commercialLookup('commercial-soto-fare', '[data-service-metric="soto_fare"]'),
        spyiAmount: commercialLookup('commercial-spyi-amount', '[data-service-metric="spyi_amount"]'),
        aqYrPkAmount: commercialLookup('commercial-aqyrpk-amount', '[data-service-metric="aq_yr_pk_amount"]'),
        yqAmount: commercialLookup('commercial-yq-amount', '[data-service-metric="yq_amount"]'),
        othAmount: commercialLookup('commercial-oth-amount', '[data-service-metric="oth_amount"]'),
        vatInput: commercialLookup('commercial-vat-input', '[data-service-metric="vat_input"]'),
        vat: commercialLookup('commercial-vat-output', '[data-service-metric="vat"]'),
        commission: station.querySelector('[data-service-metric="commission"]'),
        serviceCharge: commercialLookup('commercial-service-charge', '[data-service-metric="service_charge"]'),
    };
    const serviceDiscountInput = commercialLookup('commercial-discount-amount', '[data-service-discount]');
    const bottomTotalFields = {
        fare: station.querySelector('#commercial-bottom-fare'),
        taxes: station.querySelector('#commercial-bottom-taxes'),
        other: station.querySelector('#commercial-bottom-other'),
        sale: station.querySelector('#commercial-bottom-sp'),
        receivable: station.querySelector('#commercial-bottom-receivable'),
        payable: station.querySelector('#commercial-bottom-payable'),
        profit: station.querySelector('#commercial-bottom-profit'),
    };
    updateCommercialTrace({
        bootStatus: 'bindings-created',
        mktFareFieldFound: Boolean(serviceMetricInputs.sale),
        servAmountFieldFound: Boolean(serviceMetricInputs.serviceCharge),
        frtxFieldFound: Boolean(airlinePayableField),
        finalSaleFieldFound: Boolean(finalSalePriceInput),
        firstFailurePoint: !commercialEditor
            ? 'commercial editor wrapper not found'
            : !serviceMetricInputs.sale
                ? 'visible Mkt.Fare field not found'
                : !serviceMetricInputs.serviceCharge
                    ? 'visible Serv.Amount field not found'
                    : !airlinePayableField
                        ? 'visible Fr+Tx field not found'
                        : !finalSalePriceInput
                            ? 'visible Final Sale field not found'
                            : 'none yet',
    });
    [
        ['mktFare', serviceMetricInputs.sale],
        ['servAmount', serviceMetricInputs.serviceCharge],
        ['frtx', airlinePayableField],
        ['finalSale', finalSalePriceInput],
    ].forEach(([label, field]) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        ['focus', 'input', 'change', 'keyup'].forEach((eventName) => {
            field.addEventListener(eventName, () => traceInputValue(label, field, eventName));
        });
    });
    const airlineCommissionExtra = station.querySelector('[data-airline-commission-extra]');
    const airlineCommissionAdjustment = station.querySelector('[data-airline-commission-adjustment]');
    const airlineCommissionTotal = station.querySelector('[data-airline-commission-total]');
    const ticketFields = {
        pnr: station.querySelector('[data-ticket-field="pnr"]'),
        ticketNumber: station.querySelector('[data-ticket-field="ticket_number"]'),
        airline: station.querySelector('[data-ticket-field="airline"]'),
        class: station.querySelector('[data-ticket-field="class"]'),
        sectorFrom: station.querySelector('[data-ticket-field="sector_from"]'),
        sectorTo: station.querySelector('[data-ticket-field="sector_to"]'),
        departureDate: station.querySelector('[data-ticket-field="departure_date"]'),
        returnDate: station.querySelector('[data-ticket-field="return_date"]'),
        ticketRemarks: station.querySelector('[data-ticket-field="ticket_remarks"]'),
    };
    const ticketMetricFields = {
        fare: station.querySelector('[data-ticket-metric="fare"]'),
        tax: station.querySelector('[data-ticket-metric="tax"]'),
        vat: station.querySelector('[data-ticket-metric="vat"]'),
        commission: station.querySelector('[data-ticket-metric="commission"]'),
        supplierCost: station.querySelector('[data-ticket-metric="supplier_cost"]'),
        saleAmount: station.querySelector('[data-ticket-metric="sale_amount"]'),
    };
    const ticketRouteDisplay = station.querySelector('[data-ticket-route-display]');
    const subtypeFields = {
        visaCountry: station.querySelector('[data-subtype-field="visaCountry"]'),
        visaType: station.querySelector('[data-subtype-field="visaType"]'),
        visaApplicationReference: station.querySelector('[data-subtype-field="visaApplicationReference"]'),
        visaPassportNumber: station.querySelector('[data-subtype-field="visaPassportNumber"]'),
        visaSubmissionDate: station.querySelector('[data-subtype-field="visaSubmissionDate"]'),
        visaIssueDate: station.querySelector('[data-subtype-field="visaIssueDate"]'),
        visaExpiryDate: station.querySelector('[data-subtype-field="visaExpiryDate"]'),
        visaStatus: station.querySelector('[data-subtype-field="visaStatus"]'),
        visaRemarks: station.querySelector('[data-subtype-field="visaRemarks"]'),
        umrahPackageName: station.querySelector('[data-subtype-field="umrahPackageName"]'),
        umrahMofaReference: station.querySelector('[data-subtype-field="umrahMofaReference"]'),
        umrahDepartureDate: station.querySelector('[data-subtype-field="umrahDepartureDate"]'),
        umrahReturnDate: station.querySelector('[data-subtype-field="umrahReturnDate"]'),
        umrahHotelName: station.querySelector('[data-subtype-field="umrahHotelName"]'),
        umrahTransportNotes: station.querySelector('[data-subtype-field="umrahTransportNotes"]'),
        umrahRemarks: station.querySelector('[data-subtype-field="umrahRemarks"]'),
        hotelName: station.querySelector('[data-subtype-field="hotelName"]'),
        hotelCity: station.querySelector('[data-subtype-field="hotelCity"]'),
        hotelConfirmationNumber: station.querySelector('[data-subtype-field="hotelConfirmationNumber"]'),
        hotelCheckInDate: station.querySelector('[data-subtype-field="hotelCheckInDate"]'),
        hotelCheckOutDate: station.querySelector('[data-subtype-field="hotelCheckOutDate"]'),
        hotelRoomType: station.querySelector('[data-subtype-field="hotelRoomType"]'),
        hotelGuestCount: station.querySelector('[data-subtype-field="hotelGuestCount"]'),
        hotelRemarks: station.querySelector('[data-subtype-field="hotelRemarks"]'),
        transportMode: station.querySelector('[data-subtype-field="transportMode"]'),
        transportVehicleType: station.querySelector('[data-subtype-field="transportVehicleType"]'),
        transportPickupDate: station.querySelector('[data-subtype-field="transportPickupDate"]'),
        transportPickupLocation: station.querySelector('[data-subtype-field="transportPickupLocation"]'),
        transportDropoffLocation: station.querySelector('[data-subtype-field="transportDropoffLocation"]'),
        transportDriverDetail: station.querySelector('[data-subtype-field="transportDriverDetail"]'),
        transportRouteNotes: station.querySelector('[data-subtype-field="transportRouteNotes"]'),
        transportRemarks: station.querySelector('[data-subtype-field="transportRemarks"]'),
        tourName: station.querySelector('[data-subtype-field="tourName"]'),
        tourDestination: station.querySelector('[data-subtype-field="tourDestination"]'),
        tourConfirmationNumber: station.querySelector('[data-subtype-field="tourConfirmationNumber"]'),
        tourStartDate: station.querySelector('[data-subtype-field="tourStartDate"]'),
        tourEndDate: station.querySelector('[data-subtype-field="tourEndDate"]'),
        tourInclusions: station.querySelector('[data-subtype-field="tourInclusions"]'),
        tourRemarks: station.querySelector('[data-subtype-field="tourRemarks"]'),
        otherLabel: station.querySelector('[data-subtype-field="otherLabel"]'),
        otherReferenceNumber: station.querySelector('[data-subtype-field="otherReferenceNumber"]'),
        otherServiceDate: station.querySelector('[data-subtype-field="otherServiceDate"]'),
        otherProviderName: station.querySelector('[data-subtype-field="otherProviderName"]'),
        otherRemarks: station.querySelector('[data-subtype-field="otherRemarks"]'),
    };
    const serviceScaffoldFields = [
        ticketMetricFields.fare,
        serviceMetricInputs.sale,
        serviceMetricInputs.otherFare,
        serviceMetricInputs.sotoFare,
        serviceMetricInputs.spyiAmount,
        serviceMetricInputs.aqYrPkAmount,
        serviceMetricInputs.yqAmount,
        serviceMetricInputs.othAmount,
        serviceMetricInputs.vatInput,
        serviceMetricInputs.tax,
        serviceMetricInputs.commission,
        airlineCommissionExtra,
        airlineCommissionAdjustment,
        serviceMetricInputs.serviceCharge,
        serviceDiscountInput,
        serviceMetricInputs.vat,
        finalSalePriceInput,
    ].filter((field) => field instanceof HTMLInputElement);
    const paymentScaffoldFields = [receivedNowInput].filter((field) => field instanceof HTMLInputElement);
    const headerTitleInput = invoiceForm?.elements?.namedItem('party_label') instanceof HTMLInputElement
        ? invoiceForm.elements.namedItem('party_label')
        : null;
    const headerRemarksInput = invoiceForm?.elements?.namedItem('remarks') instanceof HTMLInputElement
        ? invoiceForm.elements.namedItem('remarks')
        : null;
    const overwriteSelectFields = [
        ...serviceScaffoldFields,
        serviceFields.remarks,
        serviceFields.lossReason,
        paymentDueDateInput,
        receivedNowInput,
        headerTitleInput,
        headerRemarksInput,
    ].filter((field) => field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement);

    const activeServiceId = () => {
        const rawValue = serviceFields.serviceId instanceof HTMLInputElement ? serviceFields.serviceId.value : '0';
        const parsedValue = Number.parseInt(String(rawValue || 0), 10);

        return Number.isFinite(parsedValue) ? parsedValue : 0;
    };

    const currentServiceDraftSnapshot = () => ({
        serviceId: activeServiceId(),
        lineNumber: serviceFields.lineNumber?.value || 'SV-DRAFT',
        type: serviceTypeField?.value || 'air ticket',
        supplier: serviceFields.supplier?.value || '',
        currency: serviceFields.currency?.value || 'PKR',
        status: serviceFields.status?.value || 'Open',
        displayStatus: serviceFields.status?.value || 'Open',
        dueDate: serviceFields.dueDate?.value || '',
        remarks: serviceFields.remarks?.value || '',
        lossReason: serviceFields.lossReason?.value || '',
        travelerId: Number.parseInt(String(serviceFields.travelerId?.value || '0'), 10) || 0,
        passengerName: servicePassengerNameField?.value || '',
        salePrice: toNumber(serviceMetricInputs.sale?.value || 0),
        purchaseCost: toNumber(serviceMetricInputs.cost?.value || 0),
        taxes: toNumber(serviceMetricInputs.tax?.value || 0),
        otherFare: toNumber(serviceMetricInputs.otherFare?.value || 0),
        sotoFare: toNumber(serviceMetricInputs.sotoFare?.value || 0),
        spyiAmount: toNumber(serviceMetricInputs.spyiAmount?.value || 0),
        aqYrPkAmount: toNumber(serviceMetricInputs.aqYrPkAmount?.value || 0),
        yqAmount: toNumber(serviceMetricInputs.yqAmount?.value || 0),
        othAmount: toNumber(serviceMetricInputs.othAmount?.value || 0),
        vatInput: toNumber(serviceMetricInputs.vatInput?.value || 0),
        vat: toNumber(serviceMetricInputs.vat?.value || 0),
        commission: toNumber(serviceMetricInputs.commission?.value || 0),
        serviceCharge: toNumber(serviceMetricInputs.serviceCharge?.value || 0),
        discountAmount: toNumber(serviceDiscountInput?.value || 0),
        finalSalePrice: toNumber(finalSalePriceInput?.value || 0),
        pnr: ticketFields.pnr?.value || '',
        ticketNumber: ticketFields.ticketNumber?.value || '',
        airline: ticketFields.airline?.value || '',
        class: ticketFields.class?.value || '',
        sectorFrom: ticketFields.sectorFrom?.value || '',
        sectorTo: ticketFields.sectorTo?.value || '',
        departureDate: ticketFields.departureDate?.value || '',
        returnDate: ticketFields.returnDate?.value || '',
        ticketRemarks: ticketFields.ticketRemarks?.value || '',
        fare: toNumber(ticketMetricFields.fare?.value || 0),
        ticketTax: toNumber(ticketMetricFields.tax?.value || 0),
        ticketVat: toNumber(ticketMetricFields.vat?.value || 0),
        ticketCommission: toNumber(ticketMetricFields.commission?.value || 0),
        visaCountry: subtypeFields.visaCountry?.value || '',
        visaType: subtypeFields.visaType?.value || '',
        visaApplicationReference: subtypeFields.visaApplicationReference?.value || '',
        visaPassportNumber: subtypeFields.visaPassportNumber?.value || '',
        visaSubmissionDate: subtypeFields.visaSubmissionDate?.value || '',
        visaIssueDate: subtypeFields.visaIssueDate?.value || '',
        visaExpiryDate: subtypeFields.visaExpiryDate?.value || '',
        visaStatus: subtypeFields.visaStatus?.value || '',
        visaRemarks: subtypeFields.visaRemarks?.value || '',
        umrahPackageName: subtypeFields.umrahPackageName?.value || '',
        umrahMofaReference: subtypeFields.umrahMofaReference?.value || '',
        umrahDepartureDate: subtypeFields.umrahDepartureDate?.value || '',
        umrahReturnDate: subtypeFields.umrahReturnDate?.value || '',
        umrahHotelName: subtypeFields.umrahHotelName?.value || '',
        umrahTransportNotes: subtypeFields.umrahTransportNotes?.value || '',
        umrahRemarks: subtypeFields.umrahRemarks?.value || '',
        hotelName: subtypeFields.hotelName?.value || '',
        hotelCity: subtypeFields.hotelCity?.value || '',
        hotelConfirmationNumber: subtypeFields.hotelConfirmationNumber?.value || '',
        hotelCheckInDate: subtypeFields.hotelCheckInDate?.value || '',
        hotelCheckOutDate: subtypeFields.hotelCheckOutDate?.value || '',
        hotelRoomType: subtypeFields.hotelRoomType?.value || '',
        hotelGuestCount: toNumber(subtypeFields.hotelGuestCount?.value || 0),
        hotelRemarks: subtypeFields.hotelRemarks?.value || '',
        transportMode: subtypeFields.transportMode?.value || '',
        transportVehicleType: subtypeFields.transportVehicleType?.value || '',
        transportPickupDate: subtypeFields.transportPickupDate?.value || '',
        transportPickupLocation: subtypeFields.transportPickupLocation?.value || '',
        transportDropoffLocation: subtypeFields.transportDropoffLocation?.value || '',
        transportDriverDetail: subtypeFields.transportDriverDetail?.value || '',
        transportRouteNotes: subtypeFields.transportRouteNotes?.value || '',
        transportRemarks: subtypeFields.transportRemarks?.value || '',
        tourName: subtypeFields.tourName?.value || '',
        tourDestination: subtypeFields.tourDestination?.value || '',
        tourConfirmationNumber: subtypeFields.tourConfirmationNumber?.value || '',
        tourStartDate: subtypeFields.tourStartDate?.value || '',
        tourEndDate: subtypeFields.tourEndDate?.value || '',
        tourInclusions: subtypeFields.tourInclusions?.value || '',
        tourRemarks: subtypeFields.tourRemarks?.value || '',
        otherLabel: subtypeFields.otherLabel?.value || '',
        otherReferenceNumber: subtypeFields.otherReferenceNumber?.value || '',
        otherServiceDate: subtypeFields.otherServiceDate?.value || '',
        otherProviderName: subtypeFields.otherProviderName?.value || '',
        otherRemarks: subtypeFields.otherRemarks?.value || '',
    });

    const primeScaffoldField = (field) => {
        if (!(field instanceof HTMLInputElement) || field.readOnly || field.disabled) {
            return;
        }

        field.dataset.scaffoldValue = isClearableScaffoldValue(field.value) ? field.value : '';
        delete field.dataset.scaffoldCleared;
    };

    const primeServiceScaffoldFields = () => {
        serviceScaffoldFields.forEach((field) => primeScaffoldField(field));
    };

    const primePaymentScaffoldFields = () => {
        paymentScaffoldFields.forEach((field) => primeScaffoldField(field));
    };

    const handleScaffoldFieldFocus = (field) => {
        if (!(field instanceof HTMLInputElement) || field.readOnly || field.disabled) {
            return;
        }

        const scaffoldValue = field.dataset.scaffoldValue || '';
        if (scaffoldValue === '' || field.value !== scaffoldValue || !isClearableScaffoldValue(field.value)) {
            return;
        }

        if (serviceScaffoldFields.includes(field) && activeServiceId() > 0) {
            return;
        }

        field.dataset.scaffoldCleared = '1';
        field.value = '';
    };

    const handleScaffoldFieldBlur = (field) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        if (field.dataset.scaffoldCleared === '1' && field.value.trim() === '') {
            const scaffoldValue = field.dataset.scaffoldValue || '';
            if (scaffoldValue !== '') {
                field.value = scaffoldValue;
            }
        }

        delete field.dataset.scaffoldCleared;
    };

    const selectFieldContent = (field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || field.readOnly || field.disabled) {
            return;
        }

        if (typeof field.select === 'function') {
            try {
                field.select();
            } catch (error) {
                return;
            }
        }
    };

    const shouldSelectAllOnFocus = (field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || field.readOnly || field.disabled) {
            return false;
        }

        if (field.value.trim() === '' || isClearableScaffoldValue(field.value)) {
            return false;
        }

        if (serviceFields.remarks && field === serviceFields.remarks) {
            return activeServiceId() > 0 || field.value.trim() !== '';
        }

        if (serviceFields.lossReason && field === serviceFields.lossReason) {
            return activeServiceId() > 0 || field.value.trim() !== '';
        }

        return true;
    };

    const hasRequiredLossReason = ({ focus = false, announce = false } = {}) => {
        if (!(serviceFields.lossReason instanceof HTMLTextAreaElement) || serviceFields.lossReason.disabled) {
            return true;
        }

        if (serviceFields.lossReason.value.trim() !== '') {
            return true;
        }

        if (announce) {
            showFeedback('Loss reason is required when Final Sale Amount is below Fr+Tx.');
            setAutosaveStatus('failed', 'Loss reason required');
        }

        if (focus) {
            serviceFields.lossReason.focus();
        }

        return false;
    };

    const scheduleSelectAll = (field) => {
        if (!shouldSelectAllOnFocus(field)) {
            return;
        }

        field.dataset.selectOnMouseup = '1';
        window.setTimeout(() => selectFieldContent(field), 0);
    };

    const attachScaffoldFieldHandlers = (field) => {
        if (!(field instanceof HTMLInputElement) || field.readOnly || field.disabled) {
            return;
        }

        field.addEventListener('focus', () => {
            handleScaffoldFieldFocus(field);
            if (field.dataset.scaffoldCleared === '1') {
                return;
            }

            scheduleSelectAll(field);
        });
        field.addEventListener('blur', () => {
            handleScaffoldFieldBlur(field);
            delete field.dataset.selectOnMouseup;
        });
        field.addEventListener('input', () => {
            if (field.dataset.scaffoldCleared === '1' && field.value.trim() !== '') {
                field.dataset.scaffoldValue = '';
            }
        });
        field.addEventListener('mouseup', (event) => {
            if (field.dataset.selectOnMouseup === '1') {
                event.preventDefault();
                delete field.dataset.selectOnMouseup;
            }
        });
    };

    serviceScaffoldFields.forEach((field) => attachScaffoldFieldHandlers(field));
    paymentScaffoldFields.forEach((field) => attachScaffoldFieldHandlers(field));
    primeServiceScaffoldFields();
    primePaymentScaffoldFields();

    overwriteSelectFields.forEach((field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || field.readOnly || field.disabled) {
            return;
        }

        if (serviceScaffoldFields.includes(field) || paymentScaffoldFields.includes(field)) {
            return;
        }

        field.addEventListener('focus', () => scheduleSelectAll(field));
        field.addEventListener('mouseup', (event) => {
            if (field.dataset.selectOnMouseup === '1') {
                event.preventDefault();
                delete field.dataset.selectOnMouseup;
            }
        });
        field.addEventListener('blur', () => {
            delete field.dataset.selectOnMouseup;
        });
    });

    const syncTicketCommercialMirrors = () => {
        if (ticketMetricFields.supplierCost && serviceMetricInputs.cost) {
            ticketMetricFields.supplierCost.value = serviceMetricInputs.cost.value || '0';
            updateCommercialTrace({
                lastFieldWritten: `ticket supplier cost mirror=${ticketMetricFields.supplierCost.value}`,
                lastOverwriteSource: 'syncTicketCommercialMirrors',
            });
        }

        if (ticketMetricFields.saleAmount) {
            ticketMetricFields.saleAmount.value = finalSalePriceInput?.value || '0';
            updateCommercialTrace({
                lastFieldWritten: `ticket sale amount mirror=${ticketMetricFields.saleAmount.value}`,
                lastOverwriteSource: 'syncTicketCommercialMirrors',
            });
        }
    };

    const hideSupplierAdvanceNote = () => {
        if (supplierAdvanceNote instanceof HTMLElement) {
            supplierAdvanceNote.hidden = true;
        }
        if (supplierAdvanceSummary instanceof HTMLElement) {
            supplierAdvanceSummary.textContent = '';
        }
        if (supplierAdvanceMessage instanceof HTMLElement) {
            supplierAdvanceMessage.textContent = 'This advance will be used automatically against Mkt. Fare.';
        }
    };

    const showSupplierAdvanceNote = (summaryText, messageText) => {
        if (supplierAdvanceSummary instanceof HTMLElement) {
            supplierAdvanceSummary.textContent = summaryText || '';
        }
        if (supplierAdvanceMessage instanceof HTMLElement) {
            supplierAdvanceMessage.textContent = messageText || 'This advance will be used automatically against Mkt. Fare.';
        }
        if (supplierAdvanceNote instanceof HTMLElement) {
            supplierAdvanceNote.hidden = String(summaryText || '').trim() === '';
        }
    };

    const isDraftServiceLineActive = () => {
        const serviceId = Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10) || 0;
        return serviceId <= 0;
    };

    const currentServiceBranchId = () => {
        const bookingIdField = serviceForm?.elements?.namedItem('booking_id');
        const branchIdField = serviceForm?.elements?.namedItem('auto_branch_id');
        const bookingId = bookingIdField instanceof HTMLInputElement
            ? Number.parseInt(bookingIdField.value || '0', 10) || 0
            : 0;
        const branchId = branchIdField instanceof HTMLInputElement
            ? Number.parseInt(branchIdField.value || '0', 10) || 0
            : 0;

        return { bookingId, branchId };
    };

    const refreshSupplierAdvanceBalance = async () => {
        if (supplierAdvanceLookupUrl === '') {
            hideSupplierAdvanceNote();
            return;
        }

        if (!isDraftServiceLineActive()) {
            hideSupplierAdvanceNote();
            return;
        }

        const supplierName = String(serviceFields.supplier?.value || '').trim();
        const currency = String(serviceFields.currency?.value || '').trim().toUpperCase();
        const { bookingId, branchId } = currentServiceBranchId();

        if (supplierName === '' || currency === '' || (bookingId <= 0 && branchId <= 0)) {
            hideSupplierAdvanceNote();
            return;
        }

        const requestToken = ++supplierAdvanceLookupToken;

        try {
            const url = new URL(supplierAdvanceLookupUrl, window.location.origin);
            url.searchParams.set('supplier_name', supplierName);
            url.searchParams.set('currency', currency);
            if (bookingId > 0) {
                url.searchParams.set('booking_id', String(bookingId));
            } else if (branchId > 0) {
                url.searchParams.set('branch_id', String(branchId));
            }

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({
                success: false,
                available: false,
            }));

            if (requestToken !== supplierAdvanceLookupToken) {
                return;
            }

            if (!response.ok || payload.success === false || payload.available !== true) {
                hideSupplierAdvanceNote();
                return;
            }

            const formattedAmount = String(payload.formatted_available_amount || '').trim();
            if (formattedAmount === '') {
                hideSupplierAdvanceNote();
                return;
            }

            showSupplierAdvanceNote(
                `Available prepaid supplier balance: ${formattedAmount}`,
                'This advance will be used automatically against Mkt. Fare.'
            );
        } catch (error) {
            if (requestToken !== supplierAdvanceLookupToken) {
                return;
            }
            hideSupplierAdvanceNote();
        }
    };

    const scheduleSupplierAdvanceBalanceRefresh = (delay = 180) => {
        if (supplierAdvanceLookupTimerId) {
            window.clearTimeout(supplierAdvanceLookupTimerId);
        }
        supplierAdvanceLookupTimerId = window.setTimeout(() => {
            supplierAdvanceLookupTimerId = 0;
            void refreshSupplierAdvanceBalance();
        }, delay);
    };

    const isAirTicketServiceType = () => String(serviceTypeField?.value || '').trim().toLowerCase() === 'air ticket';

    const fillSubtypeFieldsFromServiceLine = (serviceLine = {}) => {
        Object.entries(subtypeFields).forEach(([key, field]) => {
            fillValue(field, serviceLine[key] ?? '');
        });
    };

    const clearSubtypeFields = () => {
        Object.entries(subtypeFields).forEach(([key, field]) => {
            fillValue(field, key === 'hotelGuestCount' ? 0 : '');
        });
    };

    const serviceLineReferenceLabel = (serviceLine = {}) => {
        const type = String(serviceLine.type || 'air ticket');
        if (type === 'air ticket') {
            return serviceLine.ticketNumber || serviceLine.lineNumber || 'SV-DRAFT';
        }

        return serviceLine.ticketNumber
            || serviceLine.visaApplicationReference
            || serviceLine.umrahMofaReference
            || serviceLine.hotelConfirmationNumber
            || serviceLine.tourConfirmationNumber
            || serviceLine.otherReferenceNumber
            || serviceLine.lineNumber
            || 'SV-DRAFT';
    };

    const serviceLineDetailLabel = (serviceLine = {}) => {
        const type = String(serviceLine.type || 'air ticket');
        if (type === 'air ticket') {
            return [serviceLine.sectorFrom || '', serviceLine.sectorTo || ''].filter(Boolean).join('-') || serviceLine.remarks || '';
        }

        if (type === 'visa') {
            return [serviceLine.visaCountry || '', serviceLine.visaType || '', serviceLine.visaStatus || ''].filter(Boolean).join(' / ') || serviceLine.remarks || '';
        }

        if (type === 'umrah') {
            return [serviceLine.umrahPackageName || '', serviceLine.umrahHotelName || ''].filter(Boolean).join(' / ') || serviceLine.remarks || '';
        }

        if (type === 'hotel') {
            return [serviceLine.hotelName || '', serviceLine.hotelCity || ''].filter(Boolean).join(' / ') || serviceLine.remarks || '';
        }

        if (type === 'transport') {
            return [serviceLine.transportPickupLocation || '', serviceLine.transportDropoffLocation || ''].filter(Boolean).join(' -> ') || serviceLine.transportRouteNotes || serviceLine.remarks || '';
        }

        if (type === 'tourism') {
            return [serviceLine.tourName || '', serviceLine.tourDestination || ''].filter(Boolean).join(' / ') || serviceLine.remarks || '';
        }

        return [serviceLine.otherLabel || '', serviceLine.otherProviderName || ''].filter(Boolean).join(' / ') || serviceLine.remarks || '';
    };

    const currentServicePayableAmount = () => {
        const isAirTicket = isAirTicketServiceType();

        return isAirTicket
            ? roundToTwo(toNumber(serviceMetricInputs.sale?.value) + currentServiceTaxTotal())
            : roundToTwo(toNumber(serviceMetricInputs.cost?.value));
    };

    const defaultFinalSalePrice = (receivableBaseOverride = null) => {
        const receivableBase = receivableBaseOverride !== null && Number.isFinite(receivableBaseOverride)
            ? receivableBaseOverride
            : currentServicePayableAmount();

        return receivableBase
            + toNumber(serviceMetricInputs.serviceCharge?.value)
            + toNumber(serviceMetricInputs.vat?.value)
            - toNumber(serviceDiscountInput?.value);
    };

    const currentServiceTaxTotal = () => {
        return roundToTwo(
            toNumber(serviceMetricInputs.spyiAmount?.value)
            + toNumber(serviceMetricInputs.aqYrPkAmount?.value)
            + toNumber(serviceMetricInputs.yqAmount?.value)
            + toNumber(serviceMetricInputs.othAmount?.value)
            + toNumber(serviceMetricInputs.vatInput?.value)
            + toNumber(serviceMetricInputs.tax?.value)
        );
    };

    const refreshAirlineCommissionTotal = () => {
        if (!airlineCommissionTotal) {
            return;
        }

        const totalCommission = toNumber(serviceMetricInputs.commission?.value)
            + toNumber(airlineCommissionExtra?.value)
            + toNumber(airlineCommissionAdjustment?.value);
        airlineCommissionTotal.value = formatMoney(totalCommission);
    };

    const setActiveTypeChip = (typeValue) => {
        chips.forEach((chip) => {
            const chipValue = chip.textContent.trim().toLowerCase();
            const normalizedType = typeValue.toLowerCase();
            const isActive = chipValue === normalizedType || (normalizedType === 'other' && chipValue === 'other package');
            chip.classList.toggle('active', isActive);
        });
    };

    const refreshSubtypeVisibility = () => {
        if (!serviceTypeField) {
            return;
        }

        const isAirTicket = serviceTypeField.value === 'air ticket';
        if (airTicketPanel) {
            airTicketPanel.style.display = isAirTicket ? 'block' : 'none';
        }
        airOnlyFields.forEach((field) => {
            field.hidden = !isAirTicket;
            field.setAttribute('aria-hidden', isAirTicket ? 'false' : 'true');
        });
        subtypePanels.forEach((panel) => {
            const active = !isAirTicket && panel.dataset.serviceSubtypePanel === serviceTypeField.value;
            panel.hidden = !active;
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        if (serviceRefLabel) {
            serviceRefLabel.textContent = isAirTicket ? 'Ticket No. / Ref No.' : 'Service Ref.';
        }
        if (serviceSecondRefLabel) {
            serviceSecondRefLabel.textContent = isAirTicket ? 'PNR.#' : 'Secondary Ref.';
        }
        if (serviceSupplierLabel) {
            serviceSupplierLabel.textContent = isAirTicket ? 'Tkt.Purchase From' : 'Supplier / Provider';
        }
        setActiveTypeChip(serviceTypeField.value);
        if (activeServiceType) {
            activeServiceType.textContent = serviceTypeField.value.replace(/\b\w/g, (character) => character.toUpperCase());
        }
    };

    const serviceReceivableAmount = (serviceLine) => {
        const savedFinalSale = toNumber(serviceLine.finalSalePrice);
        if (Math.abs(savedFinalSale) > 0.005) {
            return savedFinalSale;
        }

        const receivableBase = String(serviceLine.type || 'air ticket') === 'air ticket'
            ? toNumber(serviceLine.purchaseCost)
            : toNumber(serviceLine.salePrice);

        return roundToTwo(
            receivableBase
            + toNumber(serviceLine.serviceCharge)
            + toNumber(serviceLine.vat)
            - toNumber(serviceLine.discountAmount)
        );
    };

    const hasManualFinalSaleOverride = (serviceLine) => {
        const savedFinalSale = toNumber(serviceLine.finalSalePrice);
        const receivableBase = String(serviceLine.type || 'air ticket') === 'air ticket'
            ? toNumber(serviceLine.purchaseCost)
            : toNumber(serviceLine.salePrice);
        const suggestedFinalSale = roundToTwo(
            receivableBase
            + toNumber(serviceLine.serviceCharge)
            + toNumber(serviceLine.vat)
            - toNumber(serviceLine.discountAmount)
        );

        if (Math.abs(savedFinalSale) <= 0.005 && Math.abs(suggestedFinalSale) > 0.005) {
            return false;
        }

        return Math.abs(savedFinalSale - suggestedFinalSale) > 0.005;
    };

    const syncPaymentInvoiceTotals = (activeReceivable = 0) => {
        if (!paymentCurrentInvoiceInput) {
            updateCommercialTrace({
                lastEventFired: 'syncPaymentInvoiceTotals',
                firstFailurePoint: commercialTrace.firstFailurePoint === 'none yet'
                    ? 'payment current invoice field missing'
                    : commercialTrace.firstFailurePoint,
            });
            return;
        }

        const activeCurrency = serviceFields.currency ? String(serviceFields.currency.value || '') : '';
        const invoiceCurrency = activeCurrency !== ''
            ? activeCurrency
            : (paymentCurrentInvoiceInput.dataset.paymentCurrency || 'PKR');
        const activeLineId = activeServiceId();
        const persistedTotal = serviceLines.reduce((total, serviceLine) => {
            const serviceCurrency = String(serviceLine.currency || 'PKR');
            const serviceId = Number.parseInt(String(serviceLine.serviceId || 0), 10) || 0;
            if (activeLineId > 0 && serviceId === activeLineId) {
                return total;
            }

            return total + (serviceCurrency === invoiceCurrency ? serviceReceivableAmount(serviceLine) : 0);
        }, 0);
        const activeInvoiceTotal = activeCurrency === invoiceCurrency
            ? Math.max(activeReceivable, 0)
            : 0;
        const provisionalTotal = roundToTwo(Math.max(persistedTotal + activeInvoiceTotal, 0));

        paymentCurrentInvoiceInput.dataset.paymentCurrency = invoiceCurrency;
        paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = String(provisionalTotal);
        updateCommercialTrace({
            lastEventFired: 'syncPaymentInvoiceTotals',
            lastFieldWritten: `payment current invoice dataset=${provisionalTotal.toFixed(2)}`,
            lastOverwriteSource: 'syncPaymentInvoiceTotals',
        });
        syncServiceCurrencyMirror();
        refreshPaymentPreview();
    };

    const refreshProfit = (source = 'refreshProfit') => {
        updateCommercialTrace({
            bootStatus: 'refresh-running',
            lastEventFired: source,
        });

        if (!serviceProfit || !serviceMetricInputs.sale || !serviceMetricInputs.cost || !serviceMetricInputs.tax || !serviceMetricInputs.vat || !serviceMetricInputs.commission || !serviceMetricInputs.serviceCharge || !finalSalePriceInput) {
            const missingRequirements = [
                !serviceProfit ? 'serviceProfit' : null,
                !serviceMetricInputs.sale ? 'sale' : null,
                !serviceMetricInputs.cost ? 'cost' : null,
                !serviceMetricInputs.tax ? 'tax' : null,
                !serviceMetricInputs.vat ? 'vat' : null,
                !serviceMetricInputs.commission ? 'commission' : null,
                !serviceMetricInputs.serviceCharge ? 'serviceCharge' : null,
                !finalSalePriceInput ? 'finalSalePrice' : null,
            ].filter(Boolean).join(', ');
            updateCommercialTrace({
                bootStatus: 'refresh-guard-failed',
                firstFailurePoint: commercialTrace.firstFailurePoint === 'none yet'
                    ? `refreshProfit guard missing: ${missingRequirements}`
                    : commercialTrace.firstFailurePoint,
                lastOverwriteSource: `refreshProfit guard failed (${missingRequirements})`,
            });
            return;
        }

        refreshAirlineCommissionTotal();
        const isAirTicket = isAirTicketServiceType();
        const taxTotal = currentServiceTaxTotal();
        const airlinePayable = currentServicePayableAmount();
        const otherPayable = 0;
        const currentManualOverride = finalSalePriceInput.dataset.manualOverride === '1';
        const suggestedFinalSalePrice = defaultFinalSalePrice(isAirTicket ? airlinePayable : toNumber(serviceMetricInputs.sale?.value));
        updateCommercialTrace({
            lastMktFareRead: toNumber(serviceMetricInputs.sale?.value).toFixed(2),
            lastServAmountRead: toNumber(serviceMetricInputs.serviceCharge?.value).toFixed(2),
            lastFrTxComputed: airlinePayable.toFixed(2),
            lastFinalSaleComputed: suggestedFinalSalePrice.toFixed(2),
        });
        const existingFinalSalePrice = toNumber(finalSalePriceInput.value);
        const staleZeroManualOverride = currentManualOverride
            && existingFinalSalePrice <= 0.005
            && suggestedFinalSalePrice > 0.005;
        if (!currentManualOverride || finalSalePriceInput.value === '' || staleZeroManualOverride) {
            finalSalePriceInput.value = suggestedFinalSalePrice.toFixed(2);
            finalSalePriceInput.dataset.manualOverride = staleZeroManualOverride ? '0' : '0';
            updateCommercialTrace({
                lastFieldWritten: `final sale=${finalSalePriceInput.value}`,
                lastOverwriteSource: source,
            });
        }
        const finalSalePrice = toNumber(finalSalePriceInput.value);
        const receivable = finalSalePrice;
        const profit = receivable - airlinePayable - otherPayable;
        const lossAmount = Math.max(airlinePayable - finalSalePrice, 0);
        const hasLoss = lossAmount > 0.005;

        if (serviceMetricInputs.cost) {
            serviceMetricInputs.cost.value = airlinePayable.toFixed(2);
            updateCommercialTrace({
                lastFieldWritten: `purchase_cost=${serviceMetricInputs.cost.value}`,
                lastOverwriteSource: source,
            });
        }

        [airlinePayableField, airlinePayableFinancialField, airlinePayableSummary, ticketValueField].forEach((node) => {
            if (node) {
                node.textContent = node.tagName === 'STRONG' ? formatMoney(airlinePayable) : node.textContent;
                if ('value' in node) {
                    node.value = formatMoney(airlinePayable);
                    updateCommercialTrace({
                        lastFieldWritten: `${node.id || node.dataset.airlinePayableField || node.dataset.airlinePayableFinancialField || node.dataset.ticketValueField || 'airlinePayableNode'}=${node.value}`,
                        lastOverwriteSource: source,
                    });
                }
            }
        });
        [clientReceivableField, clientReceivableSummary, totalSpField].forEach((node) => {
            if (node) {
                node.textContent = node.tagName === 'STRONG' ? formatMoney(receivable) : node.textContent;
                if ('value' in node) {
                    node.value = formatMoney(receivable);
                    updateCommercialTrace({
                        lastFieldWritten: `${node.id || node.dataset.clientReceivableField || node.dataset.totalSpField || 'receivableNode'}=${node.value}`,
                        lastOverwriteSource: source,
                    });
                }
            }
        });
        if (otherPayableSummary) {
            otherPayableSummary.textContent = formatMoney(otherPayable);
            updateCommercialTrace({
                lastFieldWritten: `otherPayableSummary=${otherPayableSummary.textContent}`,
                lastOverwriteSource: source,
            });
        }
        if (bottomTotalFields.fare) {
            bottomTotalFields.fare.value = formatMoney(toNumber(serviceMetricInputs.sale?.value));
        }
        if (bottomTotalFields.taxes) {
            bottomTotalFields.taxes.value = formatMoney(taxTotal);
        }
        if (bottomTotalFields.other) {
            bottomTotalFields.other.value = formatMoney(toNumber(serviceMetricInputs.serviceCharge?.value));
        }
        if (bottomTotalFields.sale) {
            bottomTotalFields.sale.value = formatMoney(receivable);
        }
        if (bottomTotalFields.receivable) {
            bottomTotalFields.receivable.value = formatMoney(receivable);
        }
        if (bottomTotalFields.payable) {
            bottomTotalFields.payable.value = formatMoney(airlinePayable);
        }
        if (bottomTotalFields.profit) {
            bottomTotalFields.profit.value = formatMoney(profit);
        }
        syncPaymentInvoiceTotals(receivable);

        serviceProfit.textContent = formatMoney(profit);
        serviceProfit.style.color = profit < 0 ? '#a23737' : '#0a4d73';
        if (lossAmountPanel) {
            lossAmountPanel.hidden = !hasLoss;
        }
        if (lossReasonPanel) {
            lossReasonPanel.hidden = !hasLoss;
        }
        if (lossAmountInput) {
            lossAmountInput.value = lossAmount.toFixed(2);
        }
        if (serviceFields.lossReason) {
            serviceFields.lossReason.required = hasLoss;
            serviceFields.lossReason.disabled = !hasLoss;
            if (!hasLoss) {
                serviceFields.lossReason.value = '';
            }
        }

        if (commercialDebug) {
            updateCommercialTrace({
                bootStatus: 'refresh-complete',
                lastMktFareRead: toNumber(serviceMetricInputs.sale?.value).toFixed(2),
                lastServAmountRead: toNumber(serviceMetricInputs.serviceCharge?.value).toFixed(2),
                lastFrTxComputed: airlinePayable.toFixed(2),
                lastFinalSaleComputed: finalSalePrice.toFixed(2),
            }, { log: false });
        }

        const activeRow = station.querySelector('[data-service-row].is-active');
        if (!activeRow) {
            return;
        }

        const profitCell = activeRow.querySelector('.profit-cell');
        if (!profitCell) {
            return;
        }

        profitCell.textContent = formatMoney(profit);
        profitCell.classList.toggle('negative', profit < 0);
        profitCell.classList.toggle('positive', profit >= 0);
    };

    const updateServiceActionState = (serviceLine) => {
        const isPersisted = Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0;
        const status = String(serviceLine.status || serviceLine.displayStatus || '').trim().toLowerCase();
        const type = String(serviceLine.type || '').trim().toLowerCase();

        if (isPersisted) {
            hideSupplierAdvanceNote();
        }

        if (serviceEventBars.cancel) {
            serviceEventBars.cancel.hidden = !isPersisted || status === 'cancelled';
        }

        if (serviceEventBars.refund) {
            serviceEventBars.refund.hidden = !isPersisted;
        }

        if (serviceEventBars.settlement) {
            serviceEventBars.settlement.hidden = !isPersisted || status !== 'cancelled';
        }

        if (serviceEventBars.reissue) {
            serviceEventBars.reissue.hidden = !isPersisted || type !== 'air ticket';
        }

        if (serviceSubmitButton) {
            serviceSubmitButton.textContent = isPersisted ? 'Update Service' : (hasPersistedServices() ? 'Save New Service' : 'Save First Service');
        }

        if (activeServiceMode) {
            activeServiceMode.textContent = isPersisted ? 'Editing Service Line' : (hasPersistedServices() ? 'New Service Line' : 'First Service Line');
        }

        if (serviceDeactivateId) {
            serviceDeactivateId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceDeactivateButton) {
            serviceDeactivateButton.disabled = !isPersisted;
        }

        if (serviceCancelId) {
            serviceCancelId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceCancelButton) {
            serviceCancelButton.disabled = !isPersisted || status === 'cancelled';
        }

        if (serviceRefundId) {
            serviceRefundId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceRefundButton) {
            serviceRefundButton.disabled = !isPersisted;
        }

        if (serviceSettlementId) {
            serviceSettlementId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceSettlementButton) {
            serviceSettlementButton.disabled = !isPersisted || status !== 'cancelled';
        }

        if (serviceReissueId) {
            serviceReissueId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceReissueButton) {
            serviceReissueButton.disabled = !isPersisted || type !== 'air ticket';
        }
    };

    const loadServiceLine = (index) => {
        const serviceLine = serviceLines[index];
        if (!serviceLine) {
            return;
        }

        autosavedPersistedServiceId = Number.parseInt(String(serviceLine.serviceId || 0), 10) || autosavedPersistedServiceId;
        autosavedHasSavedService = autosavedHasSavedService || autosavedPersistedServiceId > 0;
        autosavedReceivableAmount = Math.max(autosavedReceivableAmount, serviceReceivableAmount(serviceLine));
        autosavedPaymentEligible = serviceLine.paymentEligible === true
            || (
                Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0
                && serviceReceivableAmount(serviceLine) > 0.005
            );

        fillValue(serviceFields.serviceId, serviceLine.serviceId ?? 0);
        fillValue(serviceFields.lineNumber, serviceLine.lineNumber || 'SV-DRAFT');
        fillValue(serviceTypeField, serviceLine.type || 'air ticket');
        fillValue(serviceFields.supplier, serviceLine.supplier || '');
        fillValue(serviceFields.currency, serviceLine.currency || 'PKR');
        fillValue(serviceFields.status, serviceLine.status || 'Open');
        fillValue(serviceFields.dueDate, serviceLine.dueDate || '');
        fillValue(serviceFields.remarks, serviceLine.remarks || '');
        fillValue(serviceFields.lossReason, serviceLine.lossReason || '');
        fillValue(serviceFields.travelerId, serviceLine.travelerId || 0);
        syncServicePassengerName(serviceLine.passengerName || '');
        fillValue(serviceMetricInputs.sale, serviceLine.salePrice ?? 0);
        fillValue(serviceMetricInputs.cost, serviceLine.purchaseCost ?? 0);
        fillValue(serviceMetricInputs.tax, serviceLine.taxes ?? 0);
        fillValue(serviceMetricInputs.otherFare, serviceLine.otherFare ?? 0);
        fillValue(serviceMetricInputs.sotoFare, serviceLine.sotoFare ?? 0);
        fillValue(serviceMetricInputs.spyiAmount, serviceLine.spyiAmount ?? 0);
        fillValue(serviceMetricInputs.aqYrPkAmount, serviceLine.aqYrPkAmount ?? 0);
        fillValue(serviceMetricInputs.yqAmount, serviceLine.yqAmount ?? 0);
        fillValue(serviceMetricInputs.othAmount, serviceLine.othAmount ?? 0);
        fillValue(serviceMetricInputs.vatInput, serviceLine.vatInput ?? 0);
        fillValue(serviceMetricInputs.vat, serviceLine.vat ?? 0);
        fillValue(serviceMetricInputs.commission, serviceLine.commission ?? 0);
        fillValue(serviceMetricInputs.serviceCharge, serviceLine.serviceCharge ?? 0);
        fillValue(serviceDiscountInput, serviceLine.discountAmount ?? 0);
        fillValue(finalSalePriceInput, serviceLine.finalSalePrice ?? 0);
        if (finalSalePriceInput) {
            finalSalePriceInput.dataset.manualOverride = hasManualFinalSaleOverride(serviceLine) ? '1' : '0';
        }
        fillValue(airlineCommissionExtra, 0);
        fillValue(airlineCommissionAdjustment, 0);
        fillValue(ticketFields.pnr, serviceLine.pnr || '');
        fillValue(ticketFields.ticketNumber, serviceLine.ticketNumber || '');
        fillValue(ticketFields.airline, serviceLine.airline || '');
        fillValue(ticketFields.class, serviceLine.class || '');
        fillValue(ticketFields.sectorFrom, serviceLine.sectorFrom || '');
        fillValue(ticketFields.sectorTo, serviceLine.sectorTo || '');
        fillValue(ticketRouteDisplay, [serviceLine.sectorFrom || '', serviceLine.sectorTo || ''].filter(Boolean).join('-'));
        fillValue(ticketFields.departureDate, serviceLine.departureDate || '');
        fillValue(ticketFields.returnDate, serviceLine.returnDate || '');
        fillValue(ticketFields.ticketRemarks, serviceLine.ticketRemarks || '');
        fillValue(ticketMetricFields.fare, serviceLine.fare ?? 0);
        fillValue(ticketMetricFields.tax, serviceLine.ticketTax ?? 0);
        fillValue(ticketMetricFields.vat, serviceLine.ticketVat ?? 0);
        fillValue(ticketMetricFields.commission, serviceLine.ticketCommission ?? 0);
        fillValue(ticketMetricFields.supplierCost, serviceLine.supplierCost ?? 0);
        fillValue(ticketMetricFields.saleAmount, serviceLine.saleAmount ?? 0);
        fillSubtypeFieldsFromServiceLine(serviceLine);
        syncTicketCommercialMirrors();
        syncServiceCurrencyMirror();
        scheduleSupplierAdvanceBalanceRefresh(0);

        if (activeServiceReference) {
            activeServiceReference.textContent = serviceLine.lineNumber || '';
        }

        updateServiceActionState(serviceLine);
        primeServiceScaffoldFields();
        updateCommercialTrace({
            lastEventFired: `loadServiceLine(${index})`,
            lastOverwriteSource: 'loadServiceLine',
        });
        refreshProfit(`loadServiceLine(${index})`);
        refreshSubtypeVisibility();
    };

    const resetServiceLine = () => {
        serviceRows.forEach((item) => item.classList.remove('is-active'));
        autosavedPaymentEligible = false;
        autosavedPersistedServiceId = 0;
        autosavedHasSavedService = hasPersistedServices();
        autosavedReceivableAmount = 0;
        fillValue(serviceFields.serviceId, 0);
        fillValue(serviceFields.lineNumber, 'SV-DRAFT');
        fillValue(serviceTypeField, 'air ticket');
        fillValue(serviceFields.supplier, '');
        fillValue(serviceFields.currency, paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR');
        fillValue(serviceFields.status, 'Open');
        fillValue(serviceFields.dueDate, '');
        fillValue(serviceFields.remarks, '');
        fillValue(serviceFields.lossReason, '');
        fillValue(serviceFields.travelerId, defaultServiceTravelerId());
        syncServicePassengerName();
        fillValue(serviceMetricInputs.sale, 0);
        fillValue(serviceMetricInputs.cost, 0);
        fillValue(serviceMetricInputs.tax, 0);
        fillValue(serviceMetricInputs.otherFare, 0);
        fillValue(serviceMetricInputs.sotoFare, 0);
        fillValue(serviceMetricInputs.spyiAmount, 0);
        fillValue(serviceMetricInputs.aqYrPkAmount, 0);
        fillValue(serviceMetricInputs.yqAmount, 0);
        fillValue(serviceMetricInputs.othAmount, 0);
        fillValue(serviceMetricInputs.vatInput, 0);
        fillValue(serviceMetricInputs.vat, 0);
        fillValue(serviceMetricInputs.commission, 0);
        fillValue(serviceMetricInputs.serviceCharge, 0);
        fillValue(serviceDiscountInput, 0);
        fillValue(finalSalePriceInput, 0);
        if (finalSalePriceInput) {
            finalSalePriceInput.dataset.manualOverride = '0';
        }
        fillValue(airlineCommissionExtra, 0);
        fillValue(airlineCommissionAdjustment, 0);
        fillValue(ticketFields.pnr, '');
        fillValue(ticketFields.ticketNumber, '');
        fillValue(ticketFields.airline, '');
        fillValue(ticketFields.class, '');
        fillValue(ticketFields.sectorFrom, '');
        fillValue(ticketFields.sectorTo, '');
        fillValue(ticketRouteDisplay, '');
        fillValue(ticketFields.departureDate, '');
        fillValue(ticketFields.returnDate, '');
        fillValue(ticketFields.ticketRemarks, '');
        fillValue(ticketMetricFields.fare, 0);
        fillValue(ticketMetricFields.tax, 0);
        fillValue(ticketMetricFields.vat, 0);
        fillValue(ticketMetricFields.commission, 0);
        fillValue(ticketMetricFields.supplierCost, 0);
        fillValue(ticketMetricFields.saleAmount, 0);
        clearSubtypeFields();
        syncTicketCommercialMirrors();
        syncServiceCurrencyMirror();
        scheduleSupplierAdvanceBalanceRefresh(0);

        if (activeServiceReference) {
            activeServiceReference.textContent = 'SV-DRAFT';
        }

        updateServiceActionState({ serviceId: 0 });
        primeServiceScaffoldFields();
        updateCommercialTrace({
            lastEventFired: 'resetServiceLine',
            lastOverwriteSource: 'resetServiceLine',
        });
        refreshProfit('resetServiceLine');
        refreshSubtypeVisibility();
    };

    const renderServiceRows = (activeServiceId = 0) => {
        if (!(serviceTableBody instanceof HTMLElement)) {
            return;
        }

        serviceTableBody.innerHTML = '';
        serviceLines.forEach((serviceLine, serviceIndex) => {
            const row = document.createElement('tr');
            const lineTicket = serviceLineReferenceLabel(serviceLine);
            const lineSector = serviceLineDetailLabel(serviceLine);
            const lineFare = toNumber(serviceLine.fare) > 0.005 ? toNumber(serviceLine.fare) : toNumber(serviceLine.salePrice);
            const lineTaxes = toNumber(serviceLine.spyiAmount)
                + toNumber(serviceLine.aqYrPkAmount)
                + toNumber(serviceLine.yqAmount)
                + toNumber(serviceLine.othAmount)
                + toNumber(serviceLine.vatInput)
                + toNumber(serviceLine.taxes);
            const lineOther = toNumber(serviceLine.serviceCharge);
            const lineSpTotal = Math.abs(toNumber(serviceLine.rowSpTotal)) > 0.005
                ? toNumber(serviceLine.rowSpTotal)
                : serviceReceivableAmount(serviceLine);
            const lineReceivable = Math.abs(toNumber(serviceLine.rowReceivable)) > 0.005
                ? toNumber(serviceLine.rowReceivable)
                : serviceReceivableAmount(serviceLine);
            const linePayable = Math.abs(toNumber(serviceLine.rowPayable)) > 0.005
                ? toNumber(serviceLine.rowPayable)
                : toNumber(serviceLine.purchaseCost);
            const lineProfit = Math.abs(toNumber(serviceLine.rowProfit)) > 0.005
                ? toNumber(serviceLine.rowProfit)
                : (lineReceivable - linePayable);
            const isActive = activeServiceId > 0
                ? Number.parseInt(String(serviceLine.serviceId || 0), 10) === activeServiceId
                : serviceIndex === 0;

            row.className = isActive ? 'is-active' : '';
            row.dataset.serviceRow = 'true';
            row.dataset.serviceIndex = String(serviceIndex);
            row.innerHTML = `
                <td>${serviceIndex + 1}</td>
                <td>${String(serviceLine.type || 'air ticket').replace(/\b\w/g, (character) => character.toUpperCase())}</td>
                <td>${lineTicket}</td>
                <td>${serviceLine.passengerName || ''}</td>
                <td>${lineSector || serviceLine.remarks || ''}</td>
                <td>${formatMoney(lineFare)}</td>
                <td>${formatMoney(lineTaxes)}</td>
                <td>${formatMoney(lineOther)}</td>
                <td>${formatMoney(lineSpTotal)}</td>
                <td>${formatMoney(lineReceivable)}</td>
                <td>${formatMoney(linePayable)}</td>
                <td class="profit-cell ${lineProfit < 0 ? 'negative' : 'positive'}">${formatMoney(lineProfit)}</td>
                <td>${String(serviceLine.displayStatus || serviceLine.status || 'Open').slice(0, 4).toUpperCase()}</td>
            `;
            serviceTableBody.appendChild(row);
        });

        serviceRows = Array.from(station.querySelectorAll('[data-service-row]'));
    };

    const bindServiceRowClicks = () => {
        serviceRows.forEach((row) => {
            row.onclick = () => {
                serviceRows.forEach((item) => item.classList.remove('is-active'));
                row.classList.add('is-active');
                loadServiceLine(Number.parseInt(row.dataset.serviceIndex || '0', 10));
                activateDock('services', { message: `Working service line ${row.cells[0]?.textContent?.trim() || ''} loaded.` });
            };
        });
    };

    const upsertAutosavedServiceLine = (serviceLine) => {
        if (!serviceLine) {
            return;
        }

        const serviceId = Number.parseInt(String(serviceLine.serviceId || 0), 10);
        const existingIndex = serviceLines.findIndex((line) => Number.parseInt(String(line.serviceId || 0), 10) === serviceId && serviceId > 0);

        if (existingIndex >= 0) {
            serviceLines[existingIndex] = serviceLine;
        } else {
            const firstDraftIndex = serviceLines.findIndex((line) => Number.parseInt(String(line.serviceId || 0), 10) <= 0);
            if (firstDraftIndex >= 0) {
                serviceLines[firstDraftIndex] = serviceLine;
            } else {
                serviceLines.push(serviceLine);
            }
        }

        renderServiceRows(serviceId);
        bindServiceRowClicks();
        const activeIndex = serviceLines.findIndex((line) => Number.parseInt(String(line.serviceId || 0), 10) === serviceId);
        if (activeIndex >= 0) {
            loadServiceLine(activeIndex);
        }
    };

    const normalizeAutosavedServiceLine = (serviceLine, payload = {}) => {
        const payloadServiceId = Number.parseInt(String(payload.service_id || serviceLine?.serviceId || 0), 10) || 0;
        const existingLine = serviceLines.find((line) => Number.parseInt(String(line.serviceId || 0), 10) === payloadServiceId)
            || serviceLines.find((line) => Number.parseInt(String(line.serviceId || 0), 10) === activeServiceId())
            || null;
        const draftSnapshot = currentServiceDraftSnapshot();
        const mergedLine = {
            ...draftSnapshot,
            ...(existingLine || {}),
            ...(serviceLine || {}),
        };

        mergedLine.serviceId = payloadServiceId > 0 ? payloadServiceId : Number.parseInt(String(mergedLine.serviceId || 0), 10) || 0;
        mergedLine.lineNumber = mergedLine.lineNumber || draftSnapshot.lineNumber || 'SV-DRAFT';
        mergedLine.type = mergedLine.type || draftSnapshot.type || 'air ticket';
        mergedLine.currency = mergedLine.currency || draftSnapshot.currency || 'PKR';
        mergedLine.status = mergedLine.status || 'Open';
        mergedLine.displayStatus = mergedLine.displayStatus || mergedLine.status || 'Open';
        mergedLine.passengerName = mergedLine.passengerName || draftSnapshot.passengerName || '';
        mergedLine.rowReceivable = Math.abs(toNumber(mergedLine.rowReceivable)) > 0.005
            ? toNumber(mergedLine.rowReceivable)
            : serviceReceivableAmount(mergedLine);
        mergedLine.rowPayable = Math.abs(toNumber(mergedLine.rowPayable)) > 0.005
            ? toNumber(mergedLine.rowPayable)
            : toNumber(mergedLine.purchaseCost);
        mergedLine.rowSpTotal = Math.abs(toNumber(mergedLine.rowSpTotal)) > 0.005
            ? toNumber(mergedLine.rowSpTotal)
            : mergedLine.rowReceivable;
        mergedLine.rowProfit = Math.abs(toNumber(mergedLine.rowProfit)) > 0.005
            ? toNumber(mergedLine.rowProfit)
            : (mergedLine.rowReceivable - mergedLine.rowPayable);
        mergedLine.persisted = mergedLine.serviceId > 0;
        mergedLine.saved = mergedLine.serviceId > 0;
        mergedLine.paymentEligible = payload.payment_eligible === true
            || mergedLine.paymentEligible === true
            || mergedLine.rowReceivable > 0.005
            || toNumber(mergedLine.finalSalePrice) > 0.005;

        return mergedLine;
    };

    const applySavedServiceUiState = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const bookingId = Number.parseInt(String(payload.booking_id || 0), 10) || 0;
        const serviceId = Number.parseInt(String(payload.service_id || payload.service_line?.serviceId || 0), 10) || 0;
        const normalizedServiceLine = payload.service_line
            ? normalizeAutosavedServiceLine(payload.service_line, payload)
            : null;

        syncBookingIdFields(bookingId);
        applyAutosavedCustomer(payload.customer || null);
        fillValue(serviceFields.serviceId, serviceId || 0);
        autosavedPersistedServiceId = serviceId;
        autosavedPaymentEligible = payload.payment_eligible === true
            || normalizedServiceLine?.paymentEligible === true;
        autosavedHasSavedService = payload.totals?.has_saved_service === true
            || serviceId > 0
            || normalizedServiceLine?.persisted === true;
        autosavedReceivableAmount = Math.max(
            toNumber(payload.totals?.receivable_client || 0),
            toNumber(payload.totals?.total_receivable || 0),
            toNumber(normalizedServiceLine?.rowReceivable || 0),
            toNumber(normalizedServiceLine?.finalSalePrice || 0)
        );

        if (normalizedServiceLine) {
            fillValue(serviceFields.lineNumber, normalizedServiceLine.lineNumber || 'SV-DRAFT');
            upsertAutosavedServiceLine(normalizedServiceLine);
            updateServiceActionState(normalizedServiceLine);
        }

        station.dataset.hasSavedService = autosavedHasSavedService ? '1' : '0';
        setInvoiceNumber(payload.invoice_no || 'Draft');
        applyAutosavePaymentFoundation(payload);

        if (autosavedHasSavedService) {
            setGateState(workflowGates.postService, true);
        }

        if (bookingId > 0 && serviceId > 0 && autosavedPaymentEligible && autosavedReceivableAmount > 0.005) {
            setGateState(workflowGates.paymentStage, true);
        }

        updateWorkflowState();
    };

    bindServiceRowClicks();

    Object.values(serviceMetricInputs).forEach((input) => {
        if (input) {
            input.addEventListener('input', () => {
                updateCommercialTrace({
                    listenerAttached: true,
                    lastEventFired: `input:${input.name || input.id || 'metric'}`,
                });
                refreshProfit(`input:${input.name || input.id || 'metric'}`);
                syncTicketCommercialMirrors();
            });
        }
    });
    updateCommercialTrace({
        listenerAttached: Object.values(serviceMetricInputs).some((input) => Boolean(input)),
    });

    if (serviceDiscountInput) {
        serviceDiscountInput.addEventListener('input', () => {
            updateCommercialTrace({
                listenerAttached: true,
                lastEventFired: `input:${serviceDiscountInput.name || serviceDiscountInput.id || 'discount'}`,
            });
            refreshProfit(`input:${serviceDiscountInput.name || serviceDiscountInput.id || 'discount'}`);
        });
    }

    if (finalSalePriceInput) {
        finalSalePriceInput.addEventListener('input', () => {
            finalSalePriceInput.dataset.manualOverride = finalSalePriceInput.value === '' ? '0' : '1';
            updateCommercialTrace({
                listenerAttached: true,
                lastEventFired: `input:${finalSalePriceInput.name || finalSalePriceInput.id || 'finalSale'}`,
            });
            refreshProfit(`input:${finalSalePriceInput.name || finalSalePriceInput.id || 'finalSale'}`);
            syncTicketCommercialMirrors();
        });
    }

    [airlineCommissionExtra, airlineCommissionAdjustment].forEach((input) => {
        if (input) {
            input.addEventListener('input', refreshAirlineCommissionTotal);
        }
    });

    if (serviceTypeField) {
        serviceTypeField.addEventListener('change', () => {
            refreshSubtypeVisibility();
            refreshProfit('change:service_type');
            scheduleServiceAutosave();
        });
    }

    if (serviceFields.currency) {
        serviceFields.currency.addEventListener('change', () => {
            refreshProfit(`change:${serviceFields.currency.name || 'currency'}`);
            syncTicketCommercialMirrors();
            syncServiceCurrencyMirror();
            refreshPaymentPreview();
        });
    }

    if (serviceFields.travelerId) {
        serviceFields.travelerId.addEventListener('change', () => {
            syncServicePassengerName(servicePassengerNameField ? servicePassengerNameField.value : '');
        });
    }

    if (servicePassengerNameField) {
        servicePassengerNameField.addEventListener('input', syncServiceTravelerIdFromName);
        servicePassengerNameField.addEventListener('change', syncServiceTravelerIdFromName);
    }

    if (serviceResetButton) {
        serviceResetButton.addEventListener('click', () => {
            resetServiceLine();
            showFeedback('New service row ready. Enter details in the service band.');
            focusTarget('[data-service-field="type"]');
        });
    }

    const syncAutoBookingFields = () => {
        if (!(invoiceForm instanceof HTMLFormElement) || !(serviceForm instanceof HTMLFormElement)) {
            return;
        }

        Array.from(serviceForm.querySelectorAll('[data-auto-booking-field]')).forEach((target) => {
            const sourceName = target.dataset.autoBookingField;
            if (!sourceName) {
                return;
            }

            const source = invoiceForm.elements.namedItem(sourceName);
            if (source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement || source instanceof HTMLSelectElement) {
                target.value = source.value;
            }
        });

    };

    if (invoiceForm) {
        invoiceForm.addEventListener('submit', (event) => {
            if (autosaveInvoiceUrl === '') {
                return;
            }

            event.preventDefault();
            void persistInvoiceAutosave({ force: true });
        });
    }

    if (serviceForm) {
        serviceForm.addEventListener('submit', (event) => {
            syncAutoBookingFields();
            syncServiceTravelerIdFromName();

            if (!hasRequiredLossReason({ focus: true, announce: true })) {
                event.preventDefault();
                return;
            }

            const bookingIdField = serviceForm.elements.namedItem('booking_id');
            const bookingId = bookingIdField instanceof HTMLInputElement ? Number.parseInt(bookingIdField.value || '0', 10) : 0;
            if (bookingId > 0) {
                return;
            }

            const bookingDate = serviceForm.elements.namedItem('auto_booking_date');
            const customerName = serviceForm.elements.namedItem('auto_lead_traveler_name');
            const partyLabel = serviceForm.elements.namedItem('auto_party_label');
            const selectedCustomerId = serviceForm.elements.namedItem('auto_selected_customer_id');
            const hasDate = bookingDate instanceof HTMLInputElement && bookingDate.value.trim() !== '';
            const hasCustomer = (customerName instanceof HTMLInputElement && customerName.value.trim() !== '')
                || (selectedCustomerId instanceof HTMLInputElement && Number.parseInt(selectedCustomerId.value || '0', 10) > 0)
                || (partyLabel instanceof HTMLInputElement && partyLabel.value.trim() !== '');

            if (!hasDate || !hasCustomer) {
                event.preventDefault();
                showFeedback('Select a customer and invoice date before saving the first service.');
                if (!hasCustomer) {
                    focusTarget('input[name="lead_traveler_name"]');
                }
                return;
            }

            if (autosaveServiceUrl !== '') {
                event.preventDefault();
                void persistServiceAutosave();
            }
        });

        const serviceAutosaveFields = new Set([
            'service_type',
            'currency',
            'service_traveler_id',
            'service_passenger_name',
            'ticket_number',
            'ticket_pnr',
            'ticket_airline',
            'ticket_sector_from',
            'ticket_sector_to',
            'ticket_departure_date',
            'ticket_return_date',
            'ticket_class',
            'ticket_fare',
            'ticket_tax',
            'sale_price',
            'purchase_cost',
            'taxes',
            'other_fare',
            'soto_fare',
            'spyi_amount',
            'aq_yr_pk_amount',
            'yq_amount',
            'oth_amount',
            'vat_input',
            'vat',
            'commission',
            'service_charge',
            'discount_amount',
            'final_sale_price',
            'remarks',
            'supplier_name',
            'due_date',
            'service_status',
            'loss_reason',
            'visa_country',
            'visa_type',
            'visa_application_reference',
            'visa_passport_number',
            'visa_submission_date',
            'visa_issue_date',
            'visa_expiry_date',
            'visa_status',
            'visa_remarks',
            'umrah_package_name',
            'umrah_mofa_reference',
            'umrah_departure_date',
            'umrah_return_date',
            'umrah_hotel_name',
            'umrah_transport_notes',
            'umrah_remarks',
            'hotel_name',
            'hotel_city',
            'hotel_confirmation_number',
            'hotel_check_in_date',
            'hotel_check_out_date',
            'hotel_room_type',
            'hotel_guest_count',
            'hotel_remarks',
            'transport_mode',
            'transport_vehicle_type',
            'transport_pickup_date',
            'transport_pickup_location',
            'transport_dropoff_location',
            'transport_driver_detail',
            'transport_route_notes',
            'transport_remarks',
            'tour_name',
            'tour_destination',
            'tour_confirmation_number',
            'tour_start_date',
            'tour_end_date',
            'tour_inclusions',
            'tour_remarks',
            'other_label',
            'other_reference_number',
            'other_service_date',
            'other_provider_name',
            'other_remarks',
        ]);

        const isServiceAutosaveTarget = (target) => {
            if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) {
                return false;
            }

            if (!serviceAutosaveFields.has(target.name)) {
                return false;
            }

            if (target.form === serviceForm) {
                return true;
            }

            return target.getAttribute('form') === 'legacy-service-form';
        };

        station.addEventListener('change', (event) => {
            if (suppressAutosave || !isServiceAutosaveTarget(event.target)) {
                return;
            }

            scheduleServiceAutosave();
        }, true);

        station.addEventListener('focusout', (event) => {
            if (suppressAutosave || !isServiceAutosaveTarget(event.target)) {
                return;
            }

            scheduleServiceAutosave();
        }, true);
    }

    if (invoiceForm) {
        const invoiceAutosaveFields = new Set(['booking_date', 'booking_status', 'party_label', 'remarks']);
        invoiceForm.addEventListener('change', (event) => {
            if (suppressAutosave || !(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement)) {
                return;
            }

            if (!invoiceAutosaveFields.has(event.target.name)) {
                return;
            }

            if (!autosaveBookingReady()) {
                return;
            }

            scheduleInvoiceAutosave();
        });

        invoiceForm.addEventListener('focusout', (event) => {
            if (suppressAutosave || !(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement)) {
                return;
            }

            if (!invoiceAutosaveFields.has(event.target.name)) {
                return;
            }

            if (!autosaveBookingReady()) {
                return;
            }

            scheduleInvoiceAutosave();
        });
    }

    const customerDirectoryDataNode = station.querySelector('#workspace-customer-directory-data');
    const customerDirectory = parseJsonDataNode(customerDirectoryDataNode, 'customerDirectory');
    const travelerRows = Array.from(station.querySelectorAll('[data-traveler-row]'));
    const activeTravelerReference = station.querySelector('[data-active-traveler-reference]');
    const bookingLeadField = station.querySelector('input[name="lead_traveler_name"]');
    const bookingMobileField = station.querySelector('[data-booking-mobile-field]');
    const bookingPassportField = station.querySelector('[data-booking-passport-field]');
    const bookingBranchField = station.querySelector('select[name="branch_id"]');
    const bookingSelectedTravelerIdField = station.querySelector('[data-booking-selected-customer-id]');
    const customerSummaryClient = station.querySelector('[data-customer-summary-client]');
    const customerSummaryMobile = station.querySelector('[data-customer-summary-mobile]');
    const customerSummaryFamily = station.querySelector('[data-customer-summary-family]');
    const customerSummaryColor = station.querySelector('[data-customer-summary-color]');
    const formatCustomerColorTag = (colorTag) => {
        return colorTag
            ? String(colorTag).replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
            : '-';
    };
    const updateCustomerSummary = (customer = null) => {
        fillValue(customerSummaryClient, customer?.id ? String(customer.id) : '-');
        fillValue(customerSummaryMobile, customer?.mobile || '-');
        fillValue(customerSummaryFamily, customer?.family_id || '-');
        fillValue(customerSummaryColor, formatCustomerColorTag(customer?.color_tag || ''));
        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = JSON.stringify(customer?.previous_balance_totals || {});
        }
    };
    const hasSelectedCustomer = () => {
        const selectedCustomerId = Number.parseInt(String(bookingSelectedTravelerIdField?.value || '0'), 10);
        if (selectedCustomerId > 0) {
            return true;
        }

        const leadName = bookingLeadField instanceof HTMLInputElement
            ? bookingLeadField.value.trim()
            : '';
        if (leadName !== '') {
            return true;
        }

        const titleField = invoiceForm?.elements?.namedItem('party_label');
        const partyLabel = titleField instanceof HTMLInputElement
            ? titleField.value.trim()
            : '';
        if (partyLabel !== '') {
            return true;
        }

        return station.dataset.hasSelectedCustomer === '1';
    };

    const hasInvoiceTotals = () => {
        const persistedInvoiceAmount = toNumber(
            paymentCurrentInvoiceInput?.dataset.paymentCurrentInvoice || 0
        );
        if (persistedInvoiceAmount > 0.005) {
            return true;
        }

        const persistedInvoiceBalance = toNumber(
            paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance || 0
        );
        if (persistedInvoiceBalance > 0.005) {
            return true;
        }

        const persistedServiceTotal = serviceLines.reduce((total, serviceLine) => {
            if (Number.parseInt(String(serviceLine.serviceId || 0), 10) <= 0) {
                return total;
            }

            const savedFinalSale = toNumber(serviceLine.finalSalePrice);
            if (Math.abs(savedFinalSale) > 0.005) {
                return total + savedFinalSale;
            }

            const receivableBase = String(serviceLine.type || 'air ticket') === 'air ticket'
                ? toNumber(serviceLine.purchaseCost)
                : toNumber(serviceLine.salePrice);

            return total
                + receivableBase
                + toNumber(serviceLine.serviceCharge)
                + toNumber(serviceLine.vat)
                - toNumber(serviceLine.discountAmount);
        }, 0);

        return persistedServiceTotal > 0.005;
    };

    const hasValidSavedServiceForPayment = () => serviceLines.some((serviceLine) => {
        if (Number.parseInt(String(serviceLine.serviceId || 0), 10) <= 0) {
            return false;
        }

        return serviceLine.paymentEligible === true
            || serviceReceivableAmount(serviceLine) > 0.005
            || toNumber(serviceLine.purchaseCost) > 0.005;
    });

    const serviceHasFinancialValue = () => {
        return toNumber(finalSalePriceInput?.value || 0) > 0.005
            || toNumber(serviceMetricInputs.cost?.value || 0) > 0.005
            || toNumber(serviceMetricInputs.sale?.value || 0) > 0.005;
    };

    const serviceHasMeaningfulDraftData = () => {
        const textFields = [
            ticketFields.ticketNumber,
            ticketFields.pnr,
            ticketFields.airline,
            serviceFields.supplier,
            serviceFields.remarks,
        ];
        const hasText = textFields.some((field) => field instanceof HTMLInputElement && field.value.trim() !== '');
        return hasText || serviceHasFinancialValue();
    };

    const serviceAutosaveReady = () => {
        const bookingReady = currentBookingId() > 0 || autosaveBookingReady();
        const hasPassenger = (servicePassengerNameField instanceof HTMLInputElement && servicePassengerNameField.value.trim() !== '')
            || (bookingLeadField instanceof HTMLInputElement && bookingLeadField.value.trim() !== '')
            || Number.parseInt(String(serviceFields.travelerId?.value || '0'), 10) > 0;
        const hasServiceType = serviceTypeField instanceof HTMLSelectElement
            ? serviceTypeField.value.trim() !== ''
            : false;
        const financialAmount = Math.max(
            toNumber(finalSalePriceInput?.value || 0),
            toNumber(serviceMetricInputs.sale?.value || 0),
            toNumber(ticketMetricFields.fare?.value || 0)
        );

        return bookingReady
            && hasPassenger
            && hasServiceType
            && financialAmount > 0.005
            && serviceHasMeaningfulDraftData();
    };

    const autosaveBookingReady = () => {
        const bookingDateField = invoiceForm?.elements?.namedItem('booking_date');
        const selectedCustomerId = Number.parseInt(String(bookingSelectedTravelerIdField?.value || '0'), 10);
        const leadName = bookingLeadField instanceof HTMLInputElement ? bookingLeadField.value.trim() : '';
        return (selectedCustomerId > 0 || leadName !== '')
            && bookingDateField instanceof HTMLInputElement
            && bookingDateField.value.trim() !== '';
    };

    const updateTotalsFromAutosave = (totals = {}, options = {}) => {
        const {
            syncCommercialEditor = true,
        } = options;
        const receivable = toNumber(totals.total_receivable || 0);
        const payable = toNumber(totals.total_payable || 0);
        const profit = toNumber(totals.profit_loss || 0);
        const totalFare = toNumber(totals.total_fare || 0);
        const totalTaxes = toNumber(totals.total_taxes || 0);
        const totalOther = toNumber(totals.total_other || 0);
        const currentBalance = toNumber(totals.current_invoice_balance || 0);
        const totalOutstanding = toNumber(totals.total_outstanding || 0);
        const totalReceived = toNumber(totals.total_received || 0);
        const currentBalancePkrRate = toNumber(totals.current_invoice_balance_pkr_rate || 0);
        const currentBalancePkrEquivalent = toNumber(totals.current_invoice_balance_pkr_equivalent || 0);
        const currentInvoiceCurrency = String(
            totals.current_invoice_currency
            || paymentCurrentInvoiceInput?.dataset.paymentCurrency
            || 'PKR'
        );
        const previousBalanceMap = totals.previous_balance_map && typeof totals.previous_balance_map === 'object'
            ? totals.previous_balance_map
            : null;
        const fullCustomerOutstandingMap = totals.full_customer_outstanding_map && typeof totals.full_customer_outstanding_map === 'object'
            ? totals.full_customer_outstanding_map
            : null;
        const otherCurrencyPreviousBalanceMap = totals.other_currency_previous_balance_map && typeof totals.other_currency_previous_balance_map === 'object'
            ? totals.other_currency_previous_balance_map
            : null;

        if (syncCommercialEditor) {
            [airlinePayableField, airlinePayableFinancialField, airlinePayableSummary, ticketValueField].forEach((node) => {
                if (!node) {
                    return;
                }

                if ('value' in node) {
                    node.value = formatMoney(payable);
                } else {
                    node.textContent = formatMoney(payable);
                }
            });
            [clientReceivableField, clientReceivableSummary, totalSpField].forEach((node) => {
                if (!node) {
                    return;
                }

                if ('value' in node) {
                    node.value = formatMoney(receivable);
                } else {
                    node.textContent = formatMoney(receivable);
                }
            });
            if (otherPayableSummary) {
                otherPayableSummary.textContent = formatMoney(toNumber(totals.other_payable || 0));
            }
            if (serviceProfit) {
                serviceProfit.textContent = formatMoney(profit);
                serviceProfit.style.color = profit < 0 ? '#a23737' : '#0a4d73';
            }
            if (bottomTotalFields.fare) {
                bottomTotalFields.fare.value = formatMoney(totalFare);
            }
            if (bottomTotalFields.taxes) {
                bottomTotalFields.taxes.value = formatMoney(totalTaxes);
            }
            if (bottomTotalFields.other) {
                bottomTotalFields.other.value = formatMoney(totalOther);
            }
            if (bottomTotalFields.sale) {
                bottomTotalFields.sale.value = formatMoney(receivable);
            }
            if (bottomTotalFields.receivable) {
                bottomTotalFields.receivable.value = formatMoney(receivable);
            }
            if (bottomTotalFields.payable) {
                bottomTotalFields.payable.value = formatMoney(payable);
            }
            if (bottomTotalFields.profit) {
                bottomTotalFields.profit.value = formatMoney(profit);
            }
        }
        if (paymentCurrentInvoiceInput) {
            paymentCurrentInvoiceInput.dataset.paymentCurrency = currentInvoiceCurrency;
            paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = String(receivable);
            paymentCurrentInvoiceInput.value = formatCurrencyAmount(
                currentInvoiceCurrency,
                receivable
            );
        }
        if (paymentAlreadyReceivedInput) {
            paymentAlreadyReceivedInput.dataset.paymentPersistedReceived = String(totalReceived);
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(
                currentInvoiceCurrency,
                totalReceived
            );
        }
        if (paymentCurrentBalanceInput) {
            paymentCurrentBalanceInput.dataset.paymentPersistedInvoiceBalance = String(currentBalance);
        }
        if (paymentTotalOutstandingInput) {
            paymentTotalOutstandingInput.dataset.paymentTotalOutstanding = String(totalOutstanding);
            paymentTotalOutstandingInput.dataset.paymentTotalDueNow = String(totalOutstanding);
        }
        if (paymentCurrentBalancePkrInput) {
            paymentCurrentBalancePkrInput.dataset.paymentPkrRate = String(currentBalancePkrRate);
            if (currentInvoiceCurrency !== 'PKR' && currentBalancePkrRate > 0.005) {
                paymentCurrentBalancePkrInput.value = formatCurrencyAmount('PKR', currentBalancePkrEquivalent);
            } else {
                paymentCurrentBalancePkrInput.value = 'PKR 0.00';
            }
        }
        if (paymentPreviousBalanceInput && previousBalanceMap) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = JSON.stringify(previousBalanceMap);
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = JSON.stringify(fullCustomerOutstandingMap || previousBalanceMap);
        }

        if (serviceFields.currency && currentBookingId() <= 0 && Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10) <= 0) {
            serviceFields.currency.value = currentInvoiceCurrency;
            syncServiceCurrencyMirror();
        }
        syncDefaultPaymentCurrency(currentInvoiceCurrency);

        syncPaymentCurrencyLabels(paymentCurrencySelect?.value || currentInvoiceCurrency);
        syncCurrentBalancePkrEquivalent(currentBalance, currentInvoiceCurrency);

        if (receivable <= 0.005 && currentBalance <= 0.005) {
            setPaymentState('Draft', '');
        } else if (currentBalance <= 0.005) {
            setPaymentState('Paid', '');
        } else if (toNumber(totals.total_received || 0) > 0.005) {
            setPaymentState('Partially Paid', '');
        } else {
            setPaymentState('Unpaid', '');
        }

        refreshPaymentPreview();
    };

    const applyAutosavePaymentFoundation = (payload = {}, options = {}) => {
        refreshSettlementDataFromPayload(payload);
        refreshPaymentHistoryFromPayload(payload);
        updateTotalsFromAutosave(payload.totals || {}, options);
    };

    const persistInvoiceAutosave = (options = {}) => {
        if (!(invoiceForm instanceof HTMLFormElement) || autosaveInvoiceUrl === '') {
            return Promise.resolve(null);
        }

        const { force = false } = options;
        if (!autosaveBookingReady()) {
            return Promise.resolve(null);
        }

        const payloadKey = serializeForm(invoiceForm);
        if (!force && payloadKey === lastInvoiceAutosaveKey && currentBookingId() > 0) {
            return Promise.resolve(null);
        }

        if (invoiceAutosaveInFlight) {
            pendingInvoiceAutosave = true;
            pendingInvoiceAutosaveForce = pendingInvoiceAutosaveForce || force;
            return invoiceAutosavePromise;
        }

        invoiceAutosaveInFlight = true;
        invoiceAutosavePromise = (async () => {
            setAutosaveStatus('saving', 'Saving...');
            if (currentBookingId() <= 0) {
                setInvoiceNumber('Draft');
            }

            const payload = await postAutosave(autosaveInvoiceUrl, invoiceForm);
            lastInvoiceAutosaveKey = serializeForm(invoiceForm);
            syncBookingIdFields(Number.parseInt(String(payload.booking_id || 0), 10));
            applyAutosavedCustomer(payload.customer || null);
            setInvoiceNumber(payload.invoice_no || 'Draft');
            applyAutosavePaymentFoundation(payload);
            setAutosaveStatus('saved', 'Saved');
            updateWorkflowState();
            return payload;
        })().catch((error) => {
            console.error('Invoice autosave failed.', error);
            setAutosaveStatus('failed', 'Save failed');
            showFeedback(error.message || 'Invoice autosave failed.');
            return null;
        }).finally(() => {
            invoiceAutosaveInFlight = false;

            if (pendingInvoiceAutosave) {
                const queuedForce = pendingInvoiceAutosaveForce;
                pendingInvoiceAutosave = false;
                pendingInvoiceAutosaveForce = false;
                window.setTimeout(() => {
                    void persistInvoiceAutosave({ force: queuedForce });
                }, 0);
                return;
            }

            pendingInvoiceAutosaveForce = false;
        });

        return invoiceAutosavePromise;
    };

    const persistServiceAutosave = () => {
        if (!(serviceForm instanceof HTMLFormElement) || autosaveServiceUrl === '') {
            return Promise.resolve(null);
        }

        if (!serviceAutosaveReady()) {
            return Promise.resolve(null);
        }

        if (!hasRequiredLossReason({ focus: true, announce: true })) {
            return Promise.resolve(null);
        }

        if (serviceAutosaveInFlight) {
            pendingServiceAutosave = true;
            return serviceAutosavePromise;
        }

        serviceAutosaveInFlight = true;
        serviceAutosavePromise = (async () => {
            syncAutoBookingFields();
            syncServiceTravelerIdFromName();

            if (currentBookingId() <= 0) {
                const invoicePayload = await persistInvoiceAutosave({ force: true });
                if (!invoicePayload || Number.parseInt(String(invoicePayload.booking_id || '0'), 10) <= 0) {
                    return null;
                }
            }

            const payloadKey = serializeForm(serviceForm);
            if (payloadKey === lastServiceAutosaveKey && Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10) > 0) {
                return null;
            }

            setAutosaveStatus('saving', 'Saving...');
            const payload = await postAutosave(autosaveServiceUrl, serviceForm);
            lastInvoiceAutosaveKey = serializeForm(invoiceForm);
            lastServiceAutosaveKey = serializeForm(serviceForm);
            applySavedServiceUiState(payload);
            setAutosaveStatus('saved', 'Saved');
            return payload;
        })().catch((error) => {
            console.error('Service autosave failed.', error);
            setAutosaveStatus('failed', 'Save failed');
            showFeedback(error.message || 'Service autosave failed.');
            return null;
        }).finally(() => {
            serviceAutosaveInFlight = false;
            if (pendingServiceAutosave) {
                pendingServiceAutosave = false;
                window.setTimeout(() => {
                    void persistServiceAutosave();
                }, 0);
            }
        });

        return serviceAutosavePromise;
    };

    const scheduleInvoiceAutosave = () => {
        window.clearTimeout(invoiceAutosaveTimerId);
        setAutosaveStatus('dirty', 'Unsaved changes');
        invoiceAutosaveTimerId = window.setTimeout(() => {
            void persistInvoiceAutosave();
        }, 700);
    };

    const scheduleServiceAutosave = () => {
        window.clearTimeout(serviceAutosaveTimerId);
        setAutosaveStatus('dirty', 'Unsaved changes');
        serviceAutosaveTimerId = window.setTimeout(() => {
            void persistServiceAutosave();
        }, 1000);
    };

    const setGateState = (gate, enabled) => {
        const gates = Array.isArray(gate) ? gate : [gate];
        gates.forEach((entry) => {
            if (!(entry instanceof HTMLElement)) {
                return;
            }

            entry.classList.toggle('workflow-gate--disabled', !enabled);
            entry.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            entry.querySelectorAll('input, select, textarea, button').forEach((control) => {
                setControlDisabled(control, !enabled);
            });
            entry.querySelectorAll('a').forEach((link) => {
                setLinkDisabled(link, !enabled);
            });
        });
    };

    const setElementsEnabled = (elements, enabled) => {
        elements.forEach((element) => {
            if (element instanceof HTMLAnchorElement) {
                setLinkDisabled(element, !enabled);
                return;
            }

            setControlDisabled(element, !enabled);
        });
    };

    updateWorkflowState = () => {
        const customerReady = hasSelectedCustomer();
        const hasPersistedService = currentPersistedServiceId() > 0
            || serviceLines.some((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0);
        const serviceReady = customerReady || hasPersistedService;
        const postServiceReady = autosavedHasSavedService || hasPersistedService || hasSavedServiceRows();

        setGateState(workflowGates.customerStage, customerReady);
        setGateState(workflowGates.serviceEntry, serviceReady);
        setGateState(workflowGates.postService, postServiceReady);
        setGateState(workflowGates.paymentStage, true);
        setElementsEnabled(addServiceButtons, postServiceReady);
        setElementsEnabled(paymentHistoryButtons, true);
        setElementsEnabled(paymentSubmitButtons, true);
    };

    const travelerFields = {
        travelerId: station.querySelector('[data-traveler-field="travelerId"]'),
        travelerNo: station.querySelector('[data-traveler-field="travelerNo"]'),
        type: station.querySelector('[data-traveler-field="type"]'),
        status: station.querySelector('[data-traveler-field="status"]'),
        travelerRole: station.querySelector('[data-traveler-field="travelerRole"]'),
        branchId: station.querySelector('[data-traveler-field="branchId"]'),
        fullName: station.querySelector('[data-traveler-field="fullName"]'),
        gender: station.querySelector('[data-traveler-field="gender"]'),
        dateOfBirth: station.querySelector('[data-traveler-field="dateOfBirth"]'),
        passportNo: station.querySelector('[data-traveler-field="passportNo"]'),
        passportExpiry: station.querySelector('[data-traveler-field="passportExpiry"]'),
        mobile: station.querySelector('[data-traveler-field="mobile"]'),
        address: station.querySelector('[data-traveler-field="address"]'),
        nationality: station.querySelector('[data-traveler-field="nationality"]'),
        notes: station.querySelector('[data-traveler-field="notes"]'),
    };

    let customerPickerSelectionIndex = 0;
    let filteredCustomers = customerDirectory.slice();
    let customerAutocompleteSelectionIndex = 0;
    let filteredAutocompleteCustomers = customerDirectory.slice(0, 12);
    let suppressCustomerAutocompleteInput = false;
    const resolveBookingLeadField = (preferredField = null) => {
        if (preferredField instanceof HTMLInputElement) {
            return preferredField;
        }

        return station.querySelector('[data-customer-autocomplete-input]');
    };

    const resetNewCustomerForm = () => {
        if (!(newCustomerForm instanceof HTMLFormElement)) {
            return;
        }

        newCustomerForm.reset();
        if (newCustomerTravelerId) {
            newCustomerTravelerId.value = '0';
        }
        if (newCustomerTitle) {
            newCustomerTitle.textContent = 'New Customer';
        }
        if (newCustomerSubmit) {
            newCustomerSubmit.textContent = 'Save Customer';
        }
    };

    const customerLabel = (customer) => {
        return [
            customer.first_name || '',
            customer.last_name || '',
            customer.full_name || '',
            customer.family_id || '',
            customer.passport_number || '',
            customer.mobile || '',
            customer.village || '',
            customer.district || '',
            customer.current_residence || '',
            customer.permanent_residence || '',
            customer.address || '',
            customer.occupation || '',
            customer.notes || '',
            customer.nationality || '',
        ].join(' ').toLowerCase();
    };

    const applyCustomerToForms = (customer) => {
        if (!customer) {
            return;
        }

        suppressCustomerAutocompleteInput = true;
        station.dataset.hasSelectedCustomer = '1';
        fillValue(bookingSelectedTravelerIdField, customer.id || '');
        fillValue(resolveBookingLeadField(), customer.full_name || '');
        fillValue(bookingMobileField, customer.mobile || '');
        fillValue(bookingPassportField, customer.passport_number || '');
        updateCustomerSummary(customer);
        fillValue(travelerFields.travelerId, customer.id || '');
        fillValue(travelerFields.travelerNo, customer.id ? `TRV-${String(customer.id).padStart(3, '0')}` : 'TRV-DRAFT');
        fillValue(travelerFields.type, 'Lead');
        fillValue(travelerFields.status, 'Selected Customer Profile');
        fillValue(travelerFields.travelerRole, 'lead');
        fillValue(travelerFields.branchId, customer.branch_id || '');
        fillValue(travelerFields.fullName, customer.full_name || '');
        fillValue(travelerFields.gender, customer.gender || 'unspecified');
        fillValue(travelerFields.dateOfBirth, customer.date_of_birth || '');
        fillValue(travelerFields.passportNo, customer.passport_number || '');
        fillValue(travelerFields.passportExpiry, customer.passport_expiry || '');
        fillValue(travelerFields.mobile, customer.mobile || '');
        fillValue(travelerFields.address, customer.current_residence || customer.address || '');
        fillValue(travelerFields.nationality, customer.nationality || '');
        fillValue(travelerFields.notes, customer.notes || '');

        if (bookingBranchField && customer.branch_id) {
            bookingBranchField.value = String(customer.branch_id);
        }

        if (activeTravelerReference) {
            activeTravelerReference.textContent = customer.id ? `TRV-${String(customer.id).padStart(3, '0')}` : 'TRV-DRAFT';
        }

        useCustomerAsDraftServicePassenger(customer);
        refreshPaymentPreview();
        updateWorkflowState();
        hideCustomerAutocomplete();
        showFeedback(`${customer.full_name || 'Customer'} loaded into the booking form.`);
        closeCustomerPicker();
        if (autosaveInvoiceUrl !== '') {
            void persistInvoiceAutosave({ force: true });
        }
        window.setTimeout(() => {
            suppressCustomerAutocompleteInput = false;
        }, 0);
    };

    const applyAutosavedCustomer = (customer) => {
        if (!customer || Number.parseInt(String(customer.id || 0), 10) <= 0) {
            return;
        }

        station.dataset.hasSelectedCustomer = '1';
        fillValue(bookingSelectedTravelerIdField, customer.id || '');
        if (bookingLeadField instanceof HTMLInputElement && bookingLeadField.value.trim() === '' && customer.full_name) {
            bookingLeadField.value = customer.full_name;
        }
        fillValue(bookingMobileField, customer.mobile || '');
        fillValue(bookingPassportField, customer.passport_number || '');
        updateCustomerSummary(customer);
        fillValue(travelerFields.travelerId, customer.id || '');
        fillValue(travelerFields.travelerNo, customer.id ? `TRV-${String(customer.id).padStart(3, '0')}` : 'TRV-DRAFT');
        fillValue(travelerFields.type, 'Lead');
        fillValue(travelerFields.status, 'Selected Customer Profile');
        fillValue(travelerFields.travelerRole, 'lead');
        fillValue(travelerFields.branchId, customer.branch_id || bookingBranchField?.value || '');
        fillValue(travelerFields.fullName, customer.full_name || bookingLeadField?.value || '');
        fillValue(travelerFields.gender, customer.gender || 'unspecified');
        fillValue(travelerFields.dateOfBirth, customer.date_of_birth || '');
        fillValue(travelerFields.passportNo, customer.passport_number || '');
        fillValue(travelerFields.passportExpiry, customer.passport_expiry || '');
        fillValue(travelerFields.mobile, customer.mobile || '');
        fillValue(travelerFields.address, customer.current_residence || customer.address || '');
        fillValue(travelerFields.nationality, customer.nationality || '');
        fillValue(travelerFields.notes, customer.notes || '');

        if (activeTravelerReference) {
            activeTravelerReference.textContent = `TRV-${String(customer.id).padStart(3, '0')}`;
        }

        useCustomerAsDraftServicePassenger(customer);
        refreshPaymentPreview();
        updateWorkflowState();
    };

    const renderInlineCustomerLabel = (customer) => {
        const idLabel = customer.id ? `#${customer.id}` : '-';
        const phoneLabel = customer.mobile || '-';
        const passportLabel = customer.passport_number || '-';
        const familyLabel = customer.family_id || idLabel;
        const addressLabel = customer.village || customer.district || customer.current_residence || customer.address || customer.notes || '-';
        const colorLabel = customer.color_tag ? String(customer.color_tag).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()) : '-';

        return `
            <span class="customer-inline-picker__cell customer-inline-picker__cell--name">${customer.full_name || ''}</span>
            <span class="customer-inline-picker__cell customer-inline-picker__cell--id">${familyLabel}</span>
            <span class="customer-inline-picker__cell customer-inline-picker__cell--passport">${passportLabel}</span>
            <span class="customer-inline-picker__cell customer-inline-picker__cell--phone">${phoneLabel}</span>
            <span class="customer-inline-picker__cell customer-inline-picker__cell--address">${addressLabel}</span>
            <span class="customer-inline-picker__cell customer-inline-picker__cell--address">${colorLabel}</span>
        `;
    };

    const positionCustomerAutocomplete = () => {
        if (!customerAutocompletePanel || !customerAutocompleteAnchor || customerAutocompletePanel.hidden) {
            return;
        }

        const anchorRect = customerAutocompleteAnchor.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const horizontalPadding = 12;
        const verticalGap = 6;
        const panelWidth = Math.min(Math.max(anchorRect.width, 720), viewportWidth - (horizontalPadding * 2));
        const left = Math.max(
            horizontalPadding,
            Math.min(anchorRect.left, viewportWidth - panelWidth - horizontalPadding)
        );

        customerAutocompletePanel.style.width = `${panelWidth}px`;
        customerAutocompletePanel.style.maxWidth = `${viewportWidth - (horizontalPadding * 2)}px`;
        customerAutocompletePanel.style.left = `${left}px`;

        const panelHeight = customerAutocompletePanel.offsetHeight || 320;
        const spaceBelow = viewportHeight - anchorRect.bottom - verticalGap - horizontalPadding;
        const spaceAbove = anchorRect.top - verticalGap - horizontalPadding;
        const shouldOpenAbove = spaceBelow < Math.min(panelHeight, 260) && spaceAbove > spaceBelow;
        const top = shouldOpenAbove
            ? Math.max(horizontalPadding, anchorRect.top - panelHeight - verticalGap)
            : Math.min(anchorRect.bottom + verticalGap, viewportHeight - panelHeight - horizontalPadding);

        customerAutocompletePanel.style.top = `${Math.max(horizontalPadding, top)}px`;
    };

    function hideCustomerAutocomplete() {
        if (!customerAutocompletePanel) {
            return;
        }

        customerAutocompletePanel.hidden = true;
    }

    const renderCustomerAutocomplete = () => {
        if (!customerAutocompleteResults || !customerAutocompletePanel) {
            return;
        }

        customerAutocompleteResults.innerHTML = '';

        if (filteredAutocompleteCustomers.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'customer-inline-picker__empty';
            empty.textContent = 'No customer matches this search.';
            customerAutocompleteResults.appendChild(empty);
            customerAutocompletePanel.hidden = false;
            positionCustomerAutocomplete();
            return;
        }

        filteredAutocompleteCustomers.forEach((customer, index) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'customer-inline-picker__row';
            row.classList.toggle('is-active', index === customerAutocompleteSelectionIndex);
            row.innerHTML = renderInlineCustomerLabel(customer);
            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                applyCustomerToForms(customer);
            });
            customerAutocompleteResults.appendChild(row);
        });

        customerAutocompletePanel.hidden = false;
        positionCustomerAutocomplete();
    };

    const filterAutocompleteCustomers = () => {
        const query = (customerAutocompleteInput?.value || '').trim().toLowerCase();
        filteredAutocompleteCustomers = query === ''
            ? customerDirectory.slice(0, 12)
            : customerDirectory.filter((customer) => customerLabel(customer).includes(query)).slice(0, 12);
        customerAutocompleteSelectionIndex = 0;
        renderCustomerAutocomplete();
    };

    const openCustomerAutocomplete = () => {
        if (!bookingLeadField || bookingLeadField.disabled || bookingLeadField.readOnly) {
            return;
        }

        filterAutocompleteCustomers();
    };

    const renderCustomerPicker = () => {
        if (!customerPickerResults) {
            return;
        }

        customerPickerResults.innerHTML = '';

        if (filteredCustomers.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="7" class="empty-cell">No customer matches this search.</td>';
            customerPickerResults.appendChild(row);
            return;
        }

        filteredCustomers.forEach((customer, index) => {
            const row = document.createElement('tr');
            row.tabIndex = 0;
            row.dataset.customerPickerRow = 'true';
            row.classList.toggle('is-active', index === customerPickerSelectionIndex);
            row.innerHTML = `
                <td>${customer.full_name || ''}</td>
                <td>${customer.family_id || ''}</td>
                <td>${customer.passport_number || ''}</td>
                <td>${customer.mobile || ''}</td>
                <td>${customer.village || customer.district || customer.current_residence || customer.address || ''}</td>
                <td>${customer.color_tag ? String(customer.color_tag).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()) : ''}</td>
                <td><button class="btn btn-sm" type="button" data-customer-edit="${customer.id || ''}">Edit</button></td>
            `;
            row.addEventListener('click', () => applyCustomerToForms(customer));
            row.addEventListener('dblclick', () => applyCustomerToForms(customer));
            customerPickerResults.appendChild(row);

            const editButton = row.querySelector('[data-customer-edit]');
            if (editButton) {
                editButton.addEventListener('click', (event) => {
                    event.stopPropagation();
                    openNewCustomerModal(customer);
                });
            }
        });
    };

    const filterCustomers = () => {
        const query = (customerPickerInput?.value || '').trim().toLowerCase();
        filteredCustomers = query === ''
            ? customerDirectory.slice()
            : customerDirectory.filter((customer) => customerLabel(customer).includes(query));
        customerPickerSelectionIndex = 0;
        renderCustomerPicker();
    };

    const syncCustomerPickerQueryFromHeader = (sourceField = null) => {
        const liveLeadField = resolveBookingLeadField(sourceField);
        if (!customerPickerInput || !liveLeadField) {
            return;
        }

        customerPickerInput.value = liveLeadField.value || '';
        filterCustomers();
    };

    function openCustomerPicker(sourceField = null) {
        if (!customerPicker) {
            return;
        }

        customerPicker.hidden = false;
        customerPicker.setAttribute('aria-hidden', 'false');
        syncCustomerPickerQueryFromHeader(sourceField);
        window.setTimeout(() => {
            focusTarget('[data-customer-picker-input]');
            if (customerPickerInput) {
                customerPickerInput.selectionStart = customerPickerInput.value.length;
                customerPickerInput.selectionEnd = customerPickerInput.value.length;
            }
        }, 60);
    }

    function closeCustomerPicker() {
        if (!customerPicker) {
            return;
        }

        customerPicker.hidden = true;
        customerPicker.setAttribute('aria-hidden', 'true');
    }

    function openNewCustomerModal(customer = null) {
        if (!newCustomerModal) {
            return;
        }

        resetNewCustomerForm();

        if (customer && newCustomerForm instanceof HTMLFormElement) {
            if (newCustomerTravelerId) {
                newCustomerTravelerId.value = String(customer.id || 0);
            }
            if (newCustomerTitle) {
                newCustomerTitle.textContent = 'Edit Customer';
            }
            if (newCustomerSubmit) {
                newCustomerSubmit.textContent = 'Update Customer';
            }

            const fill = (name, value) => {
                const field = newCustomerForm.elements.namedItem(name);
                if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                    field.value = value ?? '';
                }
            };

            fill('traveler_branch_id', customer.branch_id || '');
            fill('first_name', customer.first_name || '');
            fill('last_name', customer.last_name || '');
            fill('gender', customer.gender || 'unspecified');
            fill('date_of_birth', customer.date_of_birth || '');
            fill('passport_number', customer.passport_number || '');
            fill('passport_expiry', customer.passport_expiry || '');
            fill('mobile', customer.mobile || '');
            fill('occupation', customer.occupation || '');
            fill('current_residence', customer.current_residence || customer.address || '');
            fill('permanent_residence', customer.permanent_residence || customer.address || '');
            fill('village', customer.village || '');
            fill('district', customer.district || '');
            fill('family_id', customer.family_id || '');
            fill('color_tag', customer.color_tag || 'none');
            fill('address', customer.current_residence || customer.address || '');
            fill('nationality', customer.nationality || '');
            fill('notes', customer.notes || '');
        }

        newCustomerModal.hidden = false;
        newCustomerModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => focusTarget('[data-new-customer-focus]'), 60);
    }

    function closeNewCustomerModal() {
        if (!newCustomerModal) {
            return;
        }

        newCustomerModal.hidden = true;
        newCustomerModal.setAttribute('aria-hidden', 'true');
        resetNewCustomerForm();
    }

    function openPaymentHistoryModal() {
        if (!paymentHistoryModal) {
            return;
        }

        renderPaymentHistoryModal();
        paymentHistoryModal.hidden = false;
        paymentHistoryModal.setAttribute('aria-hidden', 'false');
        showFeedback('Invoice payment history opened. Customer ledger remains separate.');
    }

    function closePaymentHistoryModal() {
        if (!paymentHistoryModal) {
            return;
        }

        paymentHistoryModal.hidden = true;
        paymentHistoryModal.setAttribute('aria-hidden', 'true');
    }

    const setCustomerDuesFeedback = (message, visible = true) => {
        if (!(customerDuesFeedback instanceof HTMLElement)) {
            return;
        }

        customerDuesFeedback.textContent = message || '';
        customerDuesFeedback.hidden = !visible || String(message || '').trim() === '';
    };

    const setSupplierHistoryFeedback = (message, visible = true) => {
        if (!(supplierHistoryFeedback instanceof HTMLElement)) {
            return;
        }

        supplierHistoryFeedback.textContent = message || '';
        supplierHistoryFeedback.hidden = !visible || String(message || '').trim() === '';
    };

    const renderSupplierHistoryFinder = () => {
        if (!(supplierHistoryResultsBody instanceof HTMLElement)) {
            return;
        }

        if (normalizeCustomerDuesQuery(supplierHistoryFinderState.query) === '') {
            supplierHistoryResultsBody.innerHTML = '<tr><td colspan="11" class="empty-cell">Search a supplier or booking to load supplier payment history.</td></tr>';
            return;
        }

        if (!Array.isArray(supplierHistoryFinderState.results) || supplierHistoryFinderState.results.length === 0) {
            supplierHistoryResultsBody.innerHTML = '<tr><td colspan="11" class="empty-cell">No supplier payment history matched this search.</td></tr>';
            return;
        }

        supplierHistoryResultsBody.innerHTML = supplierHistoryFinderState.results.map((row) => `<tr>
            <td>${escapeHtml(String(row?.supplier_name || ''))}</td>
            <td>${escapeHtml(String(row?.booking_reference || ''))}</td>
            <td>${escapeHtml(String(row?.booking_date || ''))}</td>
            <td>${escapeHtml(String(row?.branch_name || ''))}</td>
            <td>${escapeHtml(String(row?.currency || 'PKR'))}</td>
            <td>${escapeHtml(formatMoney(toNumber(row?.total_gross_amount || 0)))}</td>
            <td>${escapeHtml(formatMoney(toNumber(row?.total_paid_amount || 0)))}</td>
            <td>${escapeHtml(formatMoney(toNumber(row?.total_balance_amount || 0)))}</td>
            <td>${escapeHtml(String(row?.due_date || ''))}</td>
            <td>${escapeHtml(String(row?.status || 'Recorded'))}</td>
            <td><a class="btn btn-sm" href="${escapeHtml(String(row?.open_url || '#'))}">Open History</a></td>
        </tr>`).join('');
    };

    const loadSupplierHistoryFinder = async ({ query = supplierHistoryFinderState.query } = {}) => {
        if (supplierHistoryFinderUrl === '') {
            setSupplierHistoryFeedback('Supplier payment finder route is unavailable.');
            return;
        }

        const requestToken = Date.now();
        supplierHistoryFinderState.requestToken = requestToken;
        supplierHistoryFinderState.query = String(query || '');

        if (normalizeCustomerDuesQuery(supplierHistoryFinderState.query) === '') {
            supplierHistoryFinderState = {
                ...supplierHistoryFinderState,
                results: [],
                requestToken,
            };
            renderSupplierHistoryFinder();
            setSupplierHistoryFeedback('Search a supplier name, supplier code, or booking reference to reopen supplier payment history.');
            return;
        }

        setSupplierHistoryFeedback('Loading supplier payment history…');

        try {
            const url = new URL(supplierHistoryFinderUrl, window.location.origin);
            url.searchParams.set('q', supplierHistoryFinderState.query.trim());

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({ ok: false, message: 'The server returned an invalid supplier payment finder response.' }));
            if (supplierHistoryFinderState.requestToken !== requestToken) {
                return;
            }

            if (!response.ok || payload.ok === false) {
                throw new Error(String(payload.message || 'Supplier payment finder could not be loaded.'));
            }

            supplierHistoryFinderState = {
                ...supplierHistoryFinderState,
                query: String(payload.query || supplierHistoryFinderState.query || ''),
                results: Array.isArray(payload.results) ? payload.results : [],
                requestToken,
            };
            renderSupplierHistoryFinder();
            setSupplierHistoryFeedback(
                String(payload.message || '').trim() !== ''
                    ? String(payload.message || '')
                    : 'Select Open History to jump into the booking supplier payment workspace.',
                true
            );
        } catch (error) {
            if (supplierHistoryFinderState.requestToken !== requestToken) {
                return;
            }
            renderSupplierHistoryFinder();
            setSupplierHistoryFeedback(error instanceof Error ? error.message : 'Supplier payment finder could not be loaded.');
        }
    };

    const renderCustomerDuesFinder = () => {
        if (customerDuesSelectedSummary instanceof HTMLElement) {
            const selectedCustomer = customerDuesFinderState.selectedCustomer;
            const balanceLabel = selectedCustomer && selectedCustomer.open_balance_totals
                ? formatCurrencyTotalsInline(selectedCustomer.open_balance_totals)
                : 'No open balance.';
            customerDuesSelectedSummary.textContent = selectedCustomer
                ? `${selectedCustomer.full_name || 'Customer'} | Open Balance: ${balanceLabel}`
                : 'No customer selected.';
        }

        if (customerDuesCustomersBody instanceof HTMLElement) {
            if (customerDuesFinderState.customers.length === 0) {
                customerDuesCustomersBody.innerHTML = '<tr><td colspan="6" class="empty-cell">No customer with outstanding dues matched this search.</td></tr>';
            } else {
                customerDuesCustomersBody.innerHTML = customerDuesFinderState.customers.map((customer) => {
                    const isSelected = Number.parseInt(String(customer?.id || 0), 10) === Number.parseInt(String(customerDuesFinderState.selectedTravelerId || 0), 10);
                    return `<tr class="${isSelected ? 'is-active' : ''}">
                        <td>${escapeHtml(String(customer?.full_name || ''))}</td>
                        <td>${escapeHtml(String(customer?.passport_number || ''))}</td>
                        <td>${escapeHtml(String(customer?.mobile || ''))}</td>
                        <td>${escapeHtml(String(customer?.branch_name || ''))}</td>
                        <td>${escapeHtml(formatCurrencyTotalsInline(customer?.open_balance_totals || {}))}</td>
                        <td><button class="btn btn-sm" type="button" data-customer-dues-select="${Number.parseInt(String(customer?.id || 0), 10) || 0}">View Dues</button></td>
                    </tr>`;
                }).join('');
            }
        }

        if (customerDuesInvoicesBody instanceof HTMLElement) {
            if (customerDuesFinderState.selectedTravelerId <= 0) {
                customerDuesInvoicesBody.innerHTML = '<tr><td colspan="11" class="empty-cell">Select a customer with View Dues to load unpaid invoices.</td></tr>';
            } else if (customerDuesFinderState.openInvoices.length === 0) {
                customerDuesInvoicesBody.innerHTML = '<tr><td colspan="11" class="empty-cell">No unpaid invoices found for the selected customer and filter.</td></tr>';
            } else {
                customerDuesInvoicesBody.innerHTML = customerDuesFinderState.openInvoices.map((invoice) => `<tr>
                    <td>${escapeHtml(String(invoice?.booking_reference || ''))}</td>
                    <td>${escapeHtml(String(invoice?.booking_date || ''))}</td>
                    <td>${escapeHtml(String(invoice?.service_type || 'Service'))}</td>
                    <td>${escapeHtml(String(invoice?.passenger_name || ''))}</td>
                    <td>${escapeHtml(String(invoice?.currency || 'PKR'))}</td>
                    <td>${escapeHtml(formatMoney(toNumber(invoice?.due_amount || 0)))}</td>
                    <td>${escapeHtml(formatMoney(toNumber(invoice?.allocated_amount || 0)))}</td>
                    <td>${escapeHtml(formatMoney(toNumber(invoice?.outstanding_amount || 0)))}</td>
                    <td>${escapeHtml(String(invoice?.due_date || ''))}</td>
                    <td>${escapeHtml(String(invoice?.status || 'Open'))}</td>
                    <td><a class="btn btn-sm" href="${escapeHtml(String(invoice?.open_url || '#'))}">Open / Pay</a></td>
                </tr>`).join('');
            }
        }
    };

    const loadCustomerDuesFinder = async ({ query = customerDuesFinderState.query, travelerId = customerDuesFinderState.selectedTravelerId, currency = customerDuesFinderState.currency } = {}) => {
        if (customerDuesFinderUrl === '') {
            setCustomerDuesFeedback('Customer dues finder route is unavailable.');
            return;
        }

        const requestToken = Date.now();
        customerDuesFinderState.requestToken = requestToken;
        customerDuesFinderState.query = String(query || '');
        customerDuesFinderState.currency = String(currency || '').toUpperCase();
        setCustomerDuesFeedback('Loading customer dues…');

        try {
            const url = new URL(customerDuesFinderUrl, window.location.origin);
            if (customerDuesFinderState.query.trim() !== '') {
                url.searchParams.set('q', customerDuesFinderState.query.trim());
            }
            if ((Number.parseInt(String(travelerId || 0), 10) || 0) > 0) {
                url.searchParams.set('traveler_id', String(Number.parseInt(String(travelerId || 0), 10)));
            }
            if (customerDuesFinderState.currency !== '') {
                url.searchParams.set('currency', customerDuesFinderState.currency);
            }

            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({ ok: false, message: 'The server returned an invalid customer dues response.' }));
            if (customerDuesFinderState.requestToken !== requestToken) {
                return;
            }

            if (!response.ok || payload.ok === false) {
                throw new Error(String(payload.message || 'Customer dues finder could not be loaded.'));
            }

            customerDuesFinderState = {
                ...customerDuesFinderState,
                query: String(payload.query || customerDuesFinderState.query || ''),
                selectedTravelerId: Number.parseInt(String(payload.selected_traveler_id || 0), 10) || 0,
                currency: String(payload.currency_filter || customerDuesFinderState.currency || ''),
                customers: Array.isArray(payload.customers) ? payload.customers : [],
                selectedCustomer: payload.selected_customer && typeof payload.selected_customer === 'object' ? payload.selected_customer : null,
                openInvoices: Array.isArray(payload.open_invoices) ? payload.open_invoices : [],
                requestToken,
            };
            renderCustomerDuesFinder();
            setCustomerDuesFeedback(
                String(payload.message || '').trim() !== ''
                    ? String(payload.message || '')
                    : customerDuesFinderState.selectedCustomer
                    ? `Loaded dues for ${customerDuesFinderState.selectedCustomer.full_name || 'customer'}. Open a booking to use the stable same-currency payment flow.`
                    : 'Search a customer with outstanding dues, then open the required booking.',
                true
            );
        } catch (error) {
            if (customerDuesFinderState.requestToken !== requestToken) {
                return;
            }
            renderCustomerDuesFinder();
            setCustomerDuesFeedback(error instanceof Error ? error.message : 'Customer dues finder could not be loaded.');
        }
    };

    function openCustomerDuesModal() {
        if (!customerDuesModal) {
            return;
        }

        customerDuesModal.hidden = false;
        customerDuesModal.setAttribute('aria-hidden', 'false');
        renderCustomerDuesFinder();
        void loadCustomerDuesFinder({
            query: customerDuesSearchInput?.value || customerDuesFinderState.query,
            travelerId: customerDuesFinderState.selectedTravelerId,
            currency: customerDuesCurrencyFilter?.value || customerDuesFinderState.currency,
        });
        window.setTimeout(() => {
            customerDuesSearchInput?.focus();
            customerDuesSearchInput?.select();
        }, 40);
    }

    function closeCustomerDuesModal() {
        if (!customerDuesModal) {
            return;
        }

        customerDuesModal.hidden = true;
        customerDuesModal.setAttribute('aria-hidden', 'true');
    }

    function openSupplierHistoryModal() {
        if (!supplierHistoryModal) {
            return;
        }

        supplierHistoryModal.hidden = false;
        supplierHistoryModal.setAttribute('aria-hidden', 'false');
        renderSupplierHistoryFinder();
        void loadSupplierHistoryFinder({
            query: supplierHistorySearchInput?.value || supplierHistoryFinderState.query,
        });
        window.setTimeout(() => {
            supplierHistorySearchInput?.focus();
            supplierHistorySearchInput?.select();
        }, 40);
    }

    function closeSupplierHistoryModal() {
        if (!supplierHistoryModal) {
            return;
        }

        supplierHistoryModal.hidden = true;
        supplierHistoryModal.setAttribute('aria-hidden', 'true');
    }

    function openSupplierSettlementModal() {
        if (!supplierSettlementModal) {
            return;
        }

        supplierSettlementModal.hidden = false;
        supplierSettlementModal.setAttribute('aria-hidden', 'false');
        showFeedback('Supplier settlement opened. Review payable, paid amount, balance, and available advance.');
    }

    function closeSupplierSettlementModal() {
        if (!supplierSettlementModal) {
            return;
        }

        supplierSettlementModal.hidden = true;
        supplierSettlementModal.setAttribute('aria-hidden', 'true');
    }

    function updateSimplePostpaidSupplierForm() {
        if (!simplePostpaidForm) {
            return;
        }

        const availableRows = simplePostpaidSelectors.length;
        const selectedRows = simplePostpaidSelectors.filter((input) => input.checked);
        const selectedSuppliers = Array.from(new Set(selectedRows.map((input) => String(input.dataset.supplierName || '')))).filter(Boolean);
        const selectedCurrencies = Array.from(new Set(selectedRows.map((input) => String(input.dataset.currency || '')))).filter(Boolean);
        const selectedTotal = selectedRows.reduce((sum, input) => sum + Number(input.dataset.balance || 0), 0);
        const enteredAmount = Number(simplePostpaidAmountInput ? simplePostpaidAmountInput.value || 0 : 0);

        if (simplePostpaidSupplierDisplay) {
            if (selectedSuppliers.length === 1) {
                simplePostpaidSupplierDisplay.value = selectedSuppliers[0];
            } else if (selectedSuppliers.length > 1) {
                simplePostpaidSupplierDisplay.value = `${selectedSuppliers.length} suppliers selected`;
            } else {
                simplePostpaidSupplierDisplay.value = '';
            }
        }

        if (simplePostpaidCurrencyDisplay) {
            simplePostpaidCurrencyDisplay.value = selectedCurrencies.length === 1 ? selectedCurrencies[0] : '';
        }

        if (simplePostpaidCurrencyInput) {
            simplePostpaidCurrencyInput.value = selectedCurrencies.length === 1 ? selectedCurrencies[0] : '';
        }

        if (simplePostpaidTotal) {
            simplePostpaidTotal.textContent = selectedCurrencies.length === 1
                ? `${selectedCurrencies[0]} ${selectedTotal.toFixed(2)}`
                : selectedTotal.toFixed(2);
        }

        let feedbackMessage = '';
        if (availableRows === 0) {
            feedbackMessage = 'No open supplier payable is available to settle.';
        } else if (selectedRows.length === 0) {
            feedbackMessage = 'Please select at least one supplier payable.';
        } else if (selectedCurrencies.length > 1) {
            feedbackMessage = 'Please select payable rows with the same currency.';
        } else if (enteredAmount > selectedTotal) {
            feedbackMessage = 'Payment exceeds selected supplier payable. Reduce the amount or use Prepaid Supplier Payment.';
        }

        if (simplePostpaidFeedback) {
            simplePostpaidFeedback.textContent = feedbackMessage;
        }
    }

    function openGlobalPrepaidSupplierModal() {
        if (!globalPrepaidSupplierModal) {
            return;
        }

        globalPrepaidSupplierModal.hidden = false;
        globalPrepaidSupplierModal.setAttribute('aria-hidden', 'false');
        showFeedback('Global prepaid supplier payment opened. Record supplier advance before purchase.');
    }

    function closeGlobalPrepaidSupplierModal() {
        if (!globalPrepaidSupplierModal) {
            return;
        }

        globalPrepaidSupplierModal.hidden = true;
        globalPrepaidSupplierModal.setAttribute('aria-hidden', 'true');
    }

    async function openExchangeSettlementModal() {
        if (!paymentExchangeModal || !paymentExchangeTargetSelect) {
            return;
        }

        await ensureExchangeSettlementTargetsReady();
        const target = currentInvoiceExchangeTarget();
        if (!target) {
            const message = currentInvoiceNeedsSettlementTarget()
                ? 'Save the current invoice first so it becomes available for exchange settlement.'
                : 'No current invoice receivable is available for exchange settlement.';
            showFeedback(message);
            return;
        }

        paymentExchangeTargetSelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = String(target.id || 0);
        option.textContent = settlementTargetLabel(target);
        option.selected = true;
        paymentExchangeTargetSelect.appendChild(option);

        clearExchangeSettlementFields();
        if (paymentExchangePaymentAmountInput) {
            paymentExchangePaymentAmountInput.value = receivedNowInput?.value || '';
        }
        paymentExchangeModal.hidden = false;
        paymentExchangeModal.setAttribute('aria-hidden', 'false');
        exchangeSettlementPreview();
        window.setTimeout(() => {
            if (paymentExchangePaymentAmountInput && Math.max(toNumber(paymentExchangePaymentAmountInput.value || 0), 0) <= 0.005) {
                paymentExchangePaymentAmountInput.focus();
                return;
            }
            if (paymentExchangeRateInput && !paymentExchangeRateRow?.hidden && toNumber(paymentExchangeRateInput.value || 0) <= 0.005) {
                paymentExchangeRateInput.focus();
                return;
            }
            paymentExchangeConfirmButton?.focus();
        }, 20);
    }

    function closeExchangeSettlementModal(options = {}) {
        if (!paymentExchangeModal) {
            return;
        }

        const { clear = true } = options;
        paymentExchangeModal.hidden = true;
        paymentExchangeModal.setAttribute('aria-hidden', 'true');
        if (clear) {
            clearExchangeSettlementFields();
        }
    }

    const loadTraveler = (index) => {
        const traveler = travelers[index];
        if (!traveler) {
            return;
        }

        Object.entries(travelerFields).forEach(([fieldName, field]) => {
            fillValue(field, traveler[fieldName] ?? '');
        });

        if (activeTravelerReference) {
            activeTravelerReference.textContent = traveler.travelerNo || '';
        }
    };

    travelerRows.forEach((row) => {
        row.addEventListener('click', () => {
            travelerRows.forEach((item) => item.classList.remove('is-active'));
            row.classList.add('is-active');
            loadTraveler(Number.parseInt(row.dataset.travelerIndex || '0', 10));
            activateDock('travelers', { message: `Traveler ${row.cells[2]?.textContent?.trim() || ''} loaded.` });
        });
    });

    customerPickerCloseButtons.forEach((button) => {
        button.addEventListener('click', closeCustomerPicker);
    });

    newCustomerCloseButtons.forEach((button) => {
        button.addEventListener('click', closeNewCustomerModal);
    });

    paymentHistoryCloseButtons.forEach((button) => {
        button.addEventListener('click', closePaymentHistoryModal);
    });

    supplierSettlementCloseButtons.forEach((button) => {
        button.addEventListener('click', closeSupplierSettlementModal);
    });

    simplePostpaidSelectors.forEach((input) => {
        input.addEventListener('change', updateSimplePostpaidSupplierForm);
    });

    if (simplePostpaidAmountInput) {
        simplePostpaidAmountInput.addEventListener('input', updateSimplePostpaidSupplierForm);
    }

    if (simplePostpaidForm) {
        simplePostpaidForm.addEventListener('submit', (event) => {
            const availableRows = simplePostpaidSelectors.length;
            const selectedRows = simplePostpaidSelectors.filter((input) => input.checked);
            const selectedCurrencies = Array.from(new Set(selectedRows.map((input) => String(input.dataset.currency || '')))).filter(Boolean);
            const selectedTotal = selectedRows.reduce((sum, input) => sum + Number(input.dataset.balance || 0), 0);
            const enteredAmount = Number(simplePostpaidAmountInput ? simplePostpaidAmountInput.value || 0 : 0);

            let feedbackMessage = '';
            if (availableRows === 0) {
                feedbackMessage = 'No open supplier payable is available to settle.';
            } else if (selectedRows.length === 0) {
                feedbackMessage = 'Please select at least one supplier payable.';
            } else if (selectedCurrencies.length > 1) {
                feedbackMessage = 'Please select payable rows with the same currency.';
            } else if (enteredAmount > selectedTotal) {
                feedbackMessage = 'Payment exceeds selected supplier payable. Reduce the amount or use Prepaid Supplier Payment.';
            }

            if (feedbackMessage !== '') {
                event.preventDefault();
                if (simplePostpaidFeedback) {
                    simplePostpaidFeedback.textContent = feedbackMessage;
                }
            }
        });
        updateSimplePostpaidSupplierForm();
    }

    globalPrepaidSupplierOpenButtons.forEach((button) => {
        button.addEventListener('click', openGlobalPrepaidSupplierModal);
    });

    globalPrepaidSupplierCloseButtons.forEach((button) => {
        button.addEventListener('click', closeGlobalPrepaidSupplierModal);
    });

    if (serviceFields.supplier instanceof HTMLInputElement) {
        serviceFields.supplier.addEventListener('input', () => {
            scheduleSupplierAdvanceBalanceRefresh(220);
        });
        serviceFields.supplier.addEventListener('change', () => {
            scheduleSupplierAdvanceBalanceRefresh(0);
        });
    }

    if (serviceFields.currency instanceof HTMLSelectElement) {
        serviceFields.currency.addEventListener('change', () => {
            scheduleSupplierAdvanceBalanceRefresh(0);
        });
    }

    if (serviceForm && isDraftServiceLineActive()) {
        scheduleSupplierAdvanceBalanceRefresh(0);
    }

    paymentExchangeCloseButtons.forEach((button) => {
        button.addEventListener('click', closeExchangeSettlementModal);
    });

    if (customerAutocompletePanel) {
        customerAutocompletePanel.classList.add('customer-inline-picker--overlay');
        document.body.appendChild(customerAutocompletePanel);
        window.addEventListener('resize', positionCustomerAutocomplete);
        document.addEventListener('scroll', positionCustomerAutocomplete, true);
    }

    const unlockCustomerLookupField = (field) => {
        if (!(field instanceof HTMLInputElement)) {
            return null;
        }

        field.disabled = false;
        field.readOnly = false;
        return field;
    };

    const resolveCustomerLookupFieldFromEvent = (eventTarget) => {
        if (!(eventTarget instanceof HTMLElement)) {
            return null;
        }

        const directField = eventTarget.closest('[data-customer-autocomplete-input]');
        if (directField instanceof HTMLInputElement) {
            return directField;
        }

        const wrapper = eventTarget.closest('[data-customer-inline-search]');
        if (!(wrapper instanceof HTMLElement)) {
            return null;
        }

        return wrapper.querySelector('[data-customer-autocomplete-input]');
    };

    const clearHeaderSelectedCustomerState = () => {
        station.dataset.hasSelectedCustomer = '0';
        if (bookingSelectedTravelerIdField) {
            bookingSelectedTravelerIdField.value = '';
        }
        updateCustomerSummary(null);
        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = '{}';
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = '{}';
            paymentPreviousBalanceInput.dataset.paymentPreviousBalance = '0';
        }
        if (paymentAlreadyReceivedInput) {
            paymentAlreadyReceivedInput.dataset.paymentPersistedReceived = '0';
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(
                paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR',
                0
            );
        }
        if (paymentCurrentBalancePkrInput) {
            paymentCurrentBalancePkrInput.dataset.paymentPkrRate = '0';
            paymentCurrentBalancePkrInput.value = 'PKR 0.00';
        }
        if (paymentCurrentBalancePkrRow) {
            paymentCurrentBalancePkrRow.hidden = true;
        }
    };

    const openCustomerAutocompleteFromHeaderField = (sourceField = null) => {
        const field = unlockCustomerLookupField(resolveBookingLeadField(sourceField));
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        openCustomerAutocomplete();
    };

    if (typeof window.workspaceOpenInlineCustomerLookup !== 'function') {
        window.workspaceOpenInlineCustomerLookup = (sourceField = null) => {
            openCustomerAutocompleteFromHeaderField(sourceField);
        };
    }

    if (typeof window.workspaceHandleInlineCustomerLookupInput !== 'function') {
        window.workspaceHandleInlineCustomerLookupInput = (sourceField = null) => {
            const field = unlockCustomerLookupField(resolveBookingLeadField(sourceField));
            if (!(field instanceof HTMLInputElement) || suppressCustomerAutocompleteInput) {
                return;
            }

            clearHeaderSelectedCustomerState();
            openCustomerAutocompleteFromHeaderField(field);
            refreshPaymentPreview();
            updateWorkflowState();
        };
    }

    if (typeof window.workspaceHandleInlineCustomerLookupKeydown !== 'function') {
        window.workspaceHandleInlineCustomerLookupKeydown = (event, sourceField = null) => {
            const field = unlockCustomerLookupField(resolveBookingLeadField(sourceField));
            if (!(field instanceof HTMLInputElement) || !(event instanceof KeyboardEvent)) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                customerAutocompleteSelectionIndex = Math.min(
                    customerAutocompleteSelectionIndex + 1,
                    Math.max(filteredAutocompleteCustomers.length - 1, 0)
                );
                renderCustomerAutocomplete();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                customerAutocompleteSelectionIndex = Math.max(customerAutocompleteSelectionIndex - 1, 0);
                renderCustomerAutocomplete();
                return;
            }

            if (event.key === 'Enter') {
                if (!customerAutocompletePanel?.hidden) {
                    event.preventDefault();
                    applyCustomerToForms(filteredAutocompleteCustomers[customerAutocompleteSelectionIndex] || null);
                }
                return;
            }

            if (event.key === 'Escape') {
                hideCustomerAutocomplete();
            }
        };
    }

    if (bookingLeadField) {
        unlockCustomerLookupField(bookingLeadField);
        bookingLeadField.addEventListener('focus', () => {
            openCustomerAutocompleteFromHeaderField(bookingLeadField);
        });
        bookingLeadField.addEventListener('click', () => {
            openCustomerAutocompleteFromHeaderField(bookingLeadField);
        });
        bookingLeadField.addEventListener('input', () => {
            if (suppressCustomerAutocompleteInput) {
                return;
            }

            clearHeaderSelectedCustomerState();
            openCustomerAutocompleteFromHeaderField(bookingLeadField);
            refreshPaymentPreview();
            updateWorkflowState();
        });
        bookingLeadField.addEventListener('change', () => {
            if (suppressCustomerAutocompleteInput) {
                return;
            }

            if (autosaveBookingReady()) {
                scheduleInvoiceAutosave();
            }
        });
        bookingLeadField.addEventListener('blur', () => {
            if (suppressCustomerAutocompleteInput) {
                return;
            }

            if (!customerAutocompletePanel?.contains(document.activeElement) && autosaveBookingReady()) {
                scheduleInvoiceAutosave();
            }
        });
        bookingLeadField.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                customerAutocompleteSelectionIndex = Math.min(
                    customerAutocompleteSelectionIndex + 1,
                    Math.max(filteredAutocompleteCustomers.length - 1, 0)
                );
                renderCustomerAutocomplete();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                customerAutocompleteSelectionIndex = Math.max(customerAutocompleteSelectionIndex - 1, 0);
                renderCustomerAutocomplete();
                return;
            }

            if (event.key === 'Enter') {
                if (!customerAutocompletePanel?.hidden) {
                    event.preventDefault();
                    applyCustomerToForms(filteredAutocompleteCustomers[customerAutocompleteSelectionIndex] || null);
                }
                return;
            }

            if (event.key === 'Escape') {
                hideCustomerAutocomplete();
            }
        });
    }

    station.addEventListener('focusin', (event) => {
        const field = unlockCustomerLookupField(resolveCustomerLookupFieldFromEvent(event.target));
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        openCustomerAutocompleteFromHeaderField(field);
    }, true);

    station.addEventListener('mousedown', (event) => {
        const field = unlockCustomerLookupField(resolveCustomerLookupFieldFromEvent(event.target));
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        if (event.target !== field) {
            event.preventDefault();
            field.focus();
        }

        window.setTimeout(() => openCustomerAutocompleteFromHeaderField(field), 0);
    }, true);

    station.addEventListener('click', (event) => {
        const field = unlockCustomerLookupField(resolveCustomerLookupFieldFromEvent(event.target));
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        openCustomerAutocompleteFromHeaderField(field);
    }, true);

    station.addEventListener('input', (event) => {
        const field = unlockCustomerLookupField(resolveCustomerLookupFieldFromEvent(event.target));
        if (!(field instanceof HTMLInputElement) || suppressCustomerAutocompleteInput) {
            return;
        }

        clearHeaderSelectedCustomerState();
        openCustomerAutocompleteFromHeaderField(field);
        refreshPaymentPreview();
        updateWorkflowState();
    }, true);

    station.addEventListener('keydown', (event) => {
        const field = unlockCustomerLookupField(resolveCustomerLookupFieldFromEvent(event.target));
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        if (!['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) {
            return;
        }

        if (event.key === 'Escape') {
            hideCustomerAutocomplete();
            return;
        }

        openCustomerAutocompleteFromHeaderField(field);
    }, true);

    if (customerPickerInput) {
        customerPickerInput.addEventListener('input', filterCustomers);
        customerPickerInput.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                customerPickerSelectionIndex = Math.min(customerPickerSelectionIndex + 1, Math.max(filteredCustomers.length - 1, 0));
                renderCustomerPicker();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                customerPickerSelectionIndex = Math.max(customerPickerSelectionIndex - 1, 0);
                renderCustomerPicker();
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                applyCustomerToForms(filteredCustomers[customerPickerSelectionIndex] || null);
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeCustomerPicker();
            }
        });
    }

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Node)) {
            return;
        }

        const clickedInsideAutocomplete = Boolean(
            (customerAutocompleteAnchor && customerAutocompleteAnchor.contains(event.target))
            || (customerAutocompletePanel && customerAutocompletePanel.contains(event.target))
        );
        if (!clickedInsideAutocomplete) {
            hideCustomerAutocomplete();
        }
    });

    if (serviceLines.length > 0) {
        loadServiceLine(0);
    } else {
        updateCommercialTrace({
            lastEventFired: 'boot-no-service-lines',
            lastOverwriteSource: 'boot-no-service-lines',
        });
        refreshProfit('boot-no-service-lines');
        refreshSubtypeVisibility();
    }

    if (travelers.length > 0) {
        loadTraveler(0);
    }

    setAutosaveStatus('idle', currentBookingId() > 0 ? 'Saved' : 'Draft');

    updateWorkflowState();

    const requestedPanelId = window.location.hash.replace('#', '');
    const requestedPanel = requestedPanelId !== '' ? station.querySelector(`#${requestedPanelId}`) : null;
    if (requestedPanel && requestedPanel.dataset.dockPanel) {
        activateDock(requestedPanel.dataset.dockPanel);
    } else {
        activateDock('overview');
    }

    if (requestedPanelId === 'dock-panel-suppliers') {
        openSupplierSettlementModal();
    }

    const focusMode = new URLSearchParams(window.location.search).get('focus');
    if (focusMode === 'customer' && bookingLeadField) {
        window.setTimeout(() => {
            bookingLeadField.focus();
            bookingLeadField.select();
            filterAutocompleteCustomers();
        }, 120);
    } else if (quickSearch) {
        window.setTimeout(() => {
            quickSearch.focus();
            quickSearch.select();
        }, 120);
    }
});
