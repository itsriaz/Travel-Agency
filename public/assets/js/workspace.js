document.addEventListener('DOMContentLoaded', () => {
    let sendWorkspaceClientError = null;
    if (!Array.isArray(window.__travelWorkspaceClientErrors)) {
        window.__travelWorkspaceClientErrors = [];
    }
    const pushWorkspaceClientError = (type, payload) => {
        const entry = {
            type,
            timestamp: new Date().toISOString(),
            ...payload,
        };
        window.__travelWorkspaceClientErrors.push(entry);
        if (typeof console !== 'undefined' && typeof console.error === 'function') {
            console.error('[workspace-client-error]', entry);
        }
        if (typeof sendWorkspaceClientError === 'function') {
            sendWorkspaceClientError(entry);
        }
    };
    window.addEventListener('error', (event) => {
        pushWorkspaceClientError('error', {
            message: String(event.message || 'Unknown workspace error'),
            file: String(event.filename || ''),
            line: Number(event.lineno || 0),
            column: Number(event.colno || 0),
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason instanceof Error
            ? `${event.reason.name}: ${event.reason.message}`
            : String(event.reason || 'Unhandled promise rejection');
        pushWorkspaceClientError('unhandledrejection', {
            message: reason,
        });
    });

    const station = document.querySelector('[data-workspace-station]');

    if (!station) {
        return;
    }
    const workspaceScriptInstanceId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    let customerOpenReceivables = [];
    let customerDuesFinderState = {
        query: '',
        selectedTravelerId: 0,
        currency: '',
        customers: [],
        selectedCustomer: null,
        openInvoices: [],
        requestToken: 0,
    };
    const clientErrorLogUrl = String(station.dataset.clientErrorLogUrl || '').trim();
    const csrfToken = String(station.dataset.csrfToken || '').trim();
    sendWorkspaceClientError = (entry) => {
        if (clientErrorLogUrl === '') {
            return;
        }

        const payload = JSON.stringify({
            type: String(entry?.type || 'client_runtime'),
            message: String(entry?.message || 'Client runtime event captured.'),
            file: String(entry?.file || ''),
            line: Number(entry?.line || 0),
            column: Number(entry?.column || 0),
            timestamp: String(entry?.timestamp || ''),
            extra: entry,
        });

        fetch(clientErrorLogUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
            },
            body: payload,
            keepalive: true,
        }).catch(() => {
            // Do not recurse on logging transport failures.
        });
    };
    const workspaceOpenedExistingBookingAtLoad = station.dataset.workspaceOpenedExistingBooking === '1';

    const pendingFreshCustomerKey = 'travel_ops_pending_fresh_customer';
    const pendingFreshWorkspaceActionKey = 'travel_ops_pending_fresh_workspace_action';
    const pendingTreasuryWorkspaceStateKey = 'travel_ops_pending_treasury_workspace_state';
    const pendingServiceEditBookingModalKey = 'travel_ops_pending_service_edit_booking_modal';
    const pendingPenaltyRefundModalKey = 'travel_ops_pending_penalty_refund_modal';

    const feedback = station.querySelector('[data-workspace-feedback]');
    const quickSearchForm = station.querySelector('#workspace-search-form');
    const quickSearch = quickSearchForm?.querySelector('input[name="q"]') || null;
    const quickSearchSubmit = quickSearchForm?.querySelector('[data-workspace-search-submit]') || null;
    const actionButtons = Array.from(station.querySelectorAll('[data-workspace-action]'));
    const reminderToggleButtons = Array.from(station.querySelectorAll('[data-workspace-action="add-reminder"]'));
    const invoiceForm = station.querySelector('.legacy-invoice-header');
    const bookingBranchField = invoiceForm?.elements?.namedItem('branch_id') instanceof HTMLSelectElement
        ? invoiceForm.elements.namedItem('branch_id')
        : null;
    const bookingLeadField = station.querySelector('input[name="lead_traveler_name"]');
    const bookingMobileField = station.querySelector('[data-booking-mobile-field]');
    const bookingPassportField = station.querySelector('[data-booking-passport-field]');
    const bookingSelectedTravelerIdField = station.querySelector('[data-booking-selected-customer-id]');
    const customerSummaryClient = station.querySelector('[data-customer-summary-client]');
    const customerSummaryMobile = station.querySelector('[data-customer-summary-mobile]');
    const customerSummaryFamily = station.querySelector('[data-customer-summary-family]');
    const customerSummaryColor = station.querySelector('[data-customer-summary-color]');
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
    const customerAdvanceOpenButton = station.querySelector('[data-customer-advance-open]');
    const customerAdvanceModal = station.querySelector('[data-customer-advance-modal]');
    const customerAdvanceAvailableUrl = customerAdvanceModal?.dataset.customerAdvanceAvailableUrl || '';
    const customerAdvanceCloseButtons = Array.from(station.querySelectorAll('[data-customer-advance-close]'));
    const customerAdvanceForm = station.querySelector('[data-customer-advance-form]');
    const customerAdvanceTravelerId = station.querySelector('[data-customer-advance-traveler-id]');
    const customerAdvanceCustomerName = station.querySelector('[data-customer-advance-customer-name]');
    const customerAdvanceSearchInput = station.querySelector('[data-customer-advance-search]');
    const customerAdvanceResults = station.querySelector('[data-customer-advance-results]');
    const customerAdvanceNewCustomerButton = station.querySelector('[data-customer-advance-new-customer]');
    const customerAdvanceReturnTo = station.querySelector('[data-customer-advance-return-to]');
    const customerAdvancePrintAfterSave = station.querySelector('[data-customer-advance-print-after-save]');
    const customerAdvanceBranch = station.querySelector('[data-customer-advance-branch]');
    const customerAdvanceCurrency = station.querySelector('[data-customer-advance-currency]');
    const customerAdvanceAmount = station.querySelector('[data-customer-advance-amount]');
    const customerAdvanceMethod = station.querySelector('[data-customer-advance-method]');
    const customerAdvanceTreasuryAccount = station.querySelector('[data-customer-advance-treasury-account]');
    const customerAdvanceBankDetailField = station.querySelector('[data-customer-advance-bank-detail-field]');
    const customerAdvanceFeedback = station.querySelector('[data-customer-advance-feedback]');
    const customerAdvanceRefundForm = station.querySelector('[data-customer-advance-refund-form]');
    const customerAdvanceRefundTravelerId = station.querySelector('[data-customer-advance-refund-traveler-id]');
    const customerAdvanceRefundReturnTo = station.querySelector('[data-customer-advance-refund-return-to]');
    const customerAdvanceRefundBranch = station.querySelector('[data-customer-advance-refund-branch]');
    const customerAdvanceRefundCurrency = station.querySelector('[data-customer-advance-refund-currency]');
    const customerAdvanceRefundReceipt = station.querySelector('[data-customer-advance-refund-receipt]');
    const customerAdvanceRefundAmount = station.querySelector('[data-customer-advance-refund-amount]');
    const customerAdvanceRefundMethod = station.querySelector('[data-customer-advance-refund-method]');
    const customerAdvanceRefundTreasuryAccount = station.querySelector('[data-customer-advance-refund-treasury-account]');
    const customerAdvanceRefundFeedback = station.querySelector('[data-customer-advance-refund-feedback]');
    const supplierHistoryModal = station.querySelector('[data-supplier-history-modal]');
    const supplierHistoryCloseButtons = Array.from(station.querySelectorAll('[data-supplier-history-close]'));
    const supplierHistorySearchInput = station.querySelector('[data-supplier-history-search]');
    const supplierHistoryFeedback = station.querySelector('[data-supplier-history-feedback]');
    const supplierHistoryResultsBody = station.querySelector('[data-supplier-history-results-body]');
    const serviceEditBookingModal = station.querySelector('[data-service-edit-booking-modal]');
    const serviceEditBookingCloseButtons = Array.from(station.querySelectorAll('[data-service-edit-booking-close]'));
    const serviceEditBookingOpenButtons = Array.from(station.querySelectorAll('[data-workspace-action="service-edit-booking"]'));
    const servicePenaltyRefundModal = station.querySelector('[data-service-penalty-refund-modal]');
    const servicePenaltyRefundCloseButtons = Array.from(station.querySelectorAll('[data-service-penalty-refund-close]'));
    const servicePenaltyRefundOpenButtons = Array.from(station.querySelectorAll('[data-workspace-action="service-penalty-refund"]'));
    const supplierSettlementModal = station.querySelector('[data-supplier-settlement-modal]');
    const supplierSettlementCloseButtons = Array.from(station.querySelectorAll('[data-supplier-settlement-close]'));
    const simplePostpaidForm = station.querySelector('[data-simple-postpaid-form]');
    const simplePostpaidSelectors = Array.from(station.querySelectorAll('[data-simple-postpaid-select]'));
    const simplePostpaidSelectAll = station.querySelector('[data-simple-postpaid-select-all]');
    const simplePostpaidSupplierDisplay = station.querySelector('[data-simple-postpaid-supplier-display]');
    const simplePostpaidCurrencyDisplay = station.querySelector('[data-simple-postpaid-currency-display]');
    const simplePostpaidCurrencyInput = station.querySelector('[data-simple-postpaid-currency-input]');
    const simplePostpaidAmountInput = station.querySelector('[data-simple-postpaid-amount]');
    const simplePostpaidTotal = station.querySelector('[data-simple-postpaid-total]');
    const simplePostpaidFeedback = station.querySelector('[data-simple-postpaid-feedback]');
    const simplePostpaidSubmit = station.querySelector('[data-simple-postpaid-submit]');
    const supplierTreasuryRows = Array.from(station.querySelectorAll('[data-supplier-treasury-row]'));
    const supplierTreasurySelects = Array.from(station.querySelectorAll('[data-supplier-treasury-select]'));
    const globalPrepaidSupplierModal = station.querySelector('[data-global-prepaid-supplier-modal]');
    const globalPrepaidSupplierOpenButtons = Array.from(station.querySelectorAll('[data-global-prepaid-supplier-open]'));
    const globalPrepaidSupplierCloseButtons = Array.from(station.querySelectorAll('[data-global-prepaid-supplier-close]'));
    const globalPrepaidSupplierField = station.querySelector('[data-global-prepaid-supplier]');
    const globalPrepaidBranchField = station.querySelector('[data-global-prepaid-branch]');
    const globalPrepaidCurrencyField = station.querySelector('[data-global-prepaid-currency]');
    const globalPrepaidAmountField = station.querySelector('[data-global-prepaid-amount]');
    const globalPrepaidSupplierForm = station.querySelector('[data-global-prepaid-supplier-form]');
    const globalPrepaidSupplierFeedback = station.querySelector('[data-global-prepaid-supplier-feedback]');
    const globalPrepaidSupplierSubmit = station.querySelector('[data-global-prepaid-supplier-submit]');
    const supplierAdvanceLookupUrl = station.dataset.supplierAdvanceLookupUrl || '';
    const supplierRegisterUrl = station.dataset.supplierRegisterUrl || '';
    const businessSourceRegisterUrl = station.dataset.businessSourceRegisterUrl || '';
    const supplierAdvanceNote = station.querySelector('[data-supplier-advance-note]');
    const supplierAdvanceSummary = station.querySelector('[data-supplier-advance-summary]');
    const supplierAdvanceMessage = station.querySelector('[data-supplier-advance-message]');
    const supplierAdvanceFxUse = station.querySelector('[data-supplier-advance-fx-use]');
    const supplierAdvanceFxAdvanceId = station.querySelector('[data-supplier-advance-fx-advance-id]');
    const supplierAdvanceFxRate = station.querySelector('[data-supplier-advance-fx-rate]');
    const supplierAdvanceFxRateDate = station.querySelector('[data-supplier-advance-fx-rate-date]');
    const supplierInput = station.querySelector('[data-service-supplier-input]');
    const businessSourceInput = station.querySelector('[data-business-source-input]');
    const supplierOptionsNode = document.getElementById('workspace-service-suppliers-data');
    const businessSourceOptionsNode = document.getElementById('workspace-business-sources-data');
    const paymentTreasuryAccountsNode = document.getElementById('workspace-payment-treasury-accounts-data');
    const branchOptionsNode = document.getElementById('workspace-branch-options-data');
    const supplierOptionsList = document.getElementById('service-supplier-options');
    const addSupplierOptionValue = '__add_supplier__';
    const addBusinessSourceOptionValue = '__add_business_source__';
    const supplierAddModal = station.querySelector('[data-service-supplier-add-modal]');
    const supplierAddForm = station.querySelector('[data-service-supplier-add-form]');
    const supplierAddCloseButtons = Array.from(station.querySelectorAll('[data-service-supplier-add-close]'));
    const supplierAddName = station.querySelector('[data-service-supplier-add-name]');
    const supplierAddBranch = station.querySelector('[data-service-supplier-add-branch]');
    const supplierAddCurrency = station.querySelector('[data-service-supplier-add-currency]');
    const supplierAddFeedback = station.querySelector('[data-service-supplier-add-feedback]');
    const supplierAddSubmit = station.querySelector('[data-service-supplier-add-submit]');
    const businessSourceAddModal = station.querySelector('[data-business-source-add-modal]');
    const businessSourceAddForm = station.querySelector('[data-business-source-add-form]');
    const businessSourceAddCloseButtons = Array.from(station.querySelectorAll('[data-business-source-add-close]'));
    const businessSourceAddName = station.querySelector('[data-business-source-add-name]');
    const businessSourceAddPhone = station.querySelector('[data-business-source-add-phone]');
    const businessSourceAddAddress = station.querySelector('[data-business-source-add-address]');
    const businessSourceAddDescription = station.querySelector('[data-business-source-add-description]');
    const businessSourceAddFeedback = station.querySelector('[data-business-source-add-feedback]');
    const businessSourceAddSubmit = station.querySelector('[data-business-source-add-submit]');
    const supplierAddMode = supplierAddForm?.elements?.namedItem('supplier_mode') || null;
    const supplierAddNotes = supplierAddForm?.elements?.namedItem('notes') instanceof HTMLInputElement
        ? supplierAddForm.elements.namedItem('notes')
        : null;
    const paymentForm = station.querySelector('.legacy-payment-strip');
    const documentUploadForm = station.querySelector('[data-documents-upload-form]');
    const documentFileInput = station.querySelector('[data-document-file-input]');
    const documentUploadFeedback = station.querySelector('[data-document-upload-feedback]');
    const paymentTreasuryAccountsUrl = station.dataset.paymentTreasuryAccountsUrl || '';
    const offlinePingUrl = station.dataset.offlinePingUrl || '';
    const offlineSnapshotUrl = station.dataset.offlineSnapshotUrl || '';
    const offlineSyncUrl = station.dataset.offlineSyncUrl || '';
    const offlineStatus = station.querySelector('[data-offline-status]');
    const offlineSnapshotButton = station.querySelector('[data-offline-action="snapshot"]');
    const offlineSaveCustomerButtons = Array.from(station.querySelectorAll('[data-offline-action="save-customer"]'));
    const offlineSyncButton = station.querySelector('[data-offline-action="sync"]');
    const offlineSearchPanel = station.querySelector('[data-offline-search-results]');
    const offlineSearchTitle = station.querySelector('[data-offline-search-title]');
    const offlineSearchCount = station.querySelector('[data-offline-search-count]');
    const offlineSearchEmpty = station.querySelector('[data-offline-search-empty]');
    const offlineSearchTableWrap = station.querySelector('[data-offline-search-table-wrap]');
    const offlineSearchResultsBody = station.querySelector('[data-offline-search-results-body]');
    const offlineQueuePreview = station.querySelector('[data-offline-queue-preview]');
    const offlineQueueCount = station.querySelector('[data-offline-queue-count]');
    const offlineQueueList = station.querySelector('[data-offline-queue-list]');
    const offlineEditLockNotice = station.querySelector('[data-offline-edit-lock]');
    const offlineQueueStorageKey = 'travel_ops_offline_workspace_queue_v1';
    const offlineSnapshotStorageKey = 'travel_ops_offline_workspace_snapshot_v1';
    const offlineMetaStorageKey = 'travel_ops_offline_workspace_meta_v1';
    const requestedCustomerEditId = (() => {
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.get('customer_edit') !== '1') {
                return 0;
            }

            return Number.parseInt(
                params.get('traveler_id') || params.get('customer_id') || '0',
                10
            );
        } catch (error) {
            return 0;
        }
    })();
    const debugToolsEnabled = station.dataset.debugToolsEnabled === '1';
    const canVoidFinancials = station.dataset.canVoidFinancials === '1';
    const currentServiceIdField = serviceForm?.elements?.namedItem('service_id') instanceof HTMLInputElement
        ? serviceForm.elements.namedItem('service_id')
        : null;
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
    const lockSavedServiceFinancialEditor = () => {
        if (!(commercialEditor instanceof HTMLElement)) {
            return;
        }

        const serviceId = Number.parseInt(String(currentServiceIdField?.value || '0'), 10) || 0;
        const shouldLock = serviceId > 0;
        commercialEditor.dataset.financialEditorLocked = shouldLock ? '1' : '0';

        const fields = commercialEditor.querySelectorAll('input, textarea, select');
        fields.forEach((field) => {
            if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) {
                return;
            }

            if (field.type === 'hidden') {
                return;
            }

            if (field.closest('.legacy-payment-strip')) {
                if (field instanceof HTMLSelectElement) {
                    field.disabled = false;
                } else {
                    field.readOnly = false;
                    field.classList.remove('is-readonly');
                }
                return;
            }

            if (field instanceof HTMLSelectElement) {
                field.disabled = shouldLock;
                return;
            }

            field.readOnly = shouldLock;
            field.classList.toggle('is-readonly', shouldLock);
        });
    };
    lockSavedServiceFinancialEditor();
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
    const paymentCustomerCreditInput = commercialLookup('commercial-payment-customer-credit', '[data-payment-customer-credit]');
    const paymentCurrentInvoiceRow = station.querySelector('[data-payment-current-invoice-row]');
    const paymentPaidCurrentInvoiceRow = station.querySelector('[data-payment-paid-current-invoice-row]');
    const paymentCurrentBalanceRow = station.querySelector('[data-payment-current-balance-row]');
    const paymentCustomerCreditRow = station.querySelector('[data-payment-customer-credit-row]');
    const paymentNoCurrentInvoice = station.querySelector('[data-payment-no-current-invoice]');
    const paymentTotalOutstandingInput = commercialLookup('commercial-payment-total-outstanding', '[data-payment-total-outstanding]');
    const paymentPreviousBalanceInput = station.querySelector('[data-payment-previous-balance]');
    const paymentPreviousBalancesBlock = station.querySelector('[data-payment-previous-balances-block]');
    const paymentPreviousBalanceList = station.querySelector('[data-payment-previous-balance-list]');
    const paymentPassengerBalanceHeading = station.querySelector('[data-payment-passenger-balance-heading]');
    const paymentPassengerBalanceList = station.querySelector('[data-payment-passenger-balance-list]');
    const paymentNoPreviousBalance = station.querySelector('[data-payment-no-previous-balance]');
    const paymentTotalOutstandingLabel = station.querySelector('[data-payment-total-outstanding-label]');
    const paymentCurrencySelect = paymentForm?.elements?.namedItem('receipt_currency') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('receipt_currency')
        : null;
    const paymentReceiptScopeSelect = paymentForm?.elements?.namedItem('receipt_scope') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('receipt_scope')
        : null;
    const paymentTargetReceivableSelect = paymentForm?.elements?.namedItem('target_receivable_item_id') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('target_receivable_item_id')
        : null;
    const paymentReceiptScopeRow = station.querySelector('[data-payment-receipt-scope-row]');
    const paymentTargetRow = station.querySelector('[data-payment-target-row]');
    const paymentScopeNote = station.querySelector('[data-payment-scope-note]');
    const paymentAdvanceRow = station.querySelector('[data-payment-advance-row]');
    const paymentAdvanceSelect = paymentForm?.elements?.namedItem('advance_receipt_id') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('advance_receipt_id')
        : null;
    const paymentAdvanceAmountRow = station.querySelector('[data-payment-advance-amount-row]');
    const paymentAdvanceAmountInput = paymentForm?.elements?.namedItem('advance_apply_amount') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('advance_apply_amount')
        : null;
    let paymentAdvanceOptions = [];
    let paymentAdvanceLoadController = null;
    const paymentCurrentBalancePkrRow = station.querySelector('[data-payment-current-balance-pkr-row]');
    const paymentCurrentBalancePkrInput = commercialLookup('commercial-payment-current-balance-pkr', '[data-payment-current-balance-pkr]');
    var dailySettlementRatesDataNode = null;
    var dailySettlementRates = {};
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
    const paymentExchangePaymentAmountRow = station.querySelector('[data-payment-exchange-payment-amount-row]');
    const paymentExchangePaymentAmountLabel = station.querySelector('[data-payment-exchange-payment-amount-label]');
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
    const paymentWhatsappLedgerButton = station.querySelector('[data-payment-action="whatsapp-ledger"]');
    const customerLedgerLinks = Array.from(station.querySelectorAll('[data-customer-ledger-link]'));
    const paymentReceiptDateInput = paymentForm?.elements?.namedItem('receipt_date') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('receipt_date')
        : null;
    const paymentMethodSelect = paymentForm?.elements?.namedItem('payment_method') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('payment_method')
        : null;
    const paymentTreasuryAccountSelect = paymentForm?.elements?.namedItem('treasury_account_id') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('treasury_account_id')
        : null;
    const paymentTreasuryAccountRow = station.querySelector('[data-payment-treasury-row]');
    const directSupplierPaymentRow = station.querySelector('[data-direct-supplier-payment-row]');
    const directSupplierObligationSelect = paymentForm?.elements?.namedItem('direct_supplier_obligation_id') instanceof HTMLSelectElement
        ? paymentForm.elements.namedItem('direct_supplier_obligation_id')
        : null;
    const directSupplierServiceLineReferenceInput = paymentForm?.elements?.namedItem('direct_supplier_service_line_reference') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('direct_supplier_service_line_reference')
        : null;
    const paymentReferenceInput = paymentForm?.elements?.namedItem('reference_number') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('reference_number')
        : null;
    const paymentBankCardDetailInput = paymentForm?.elements?.namedItem('bank_card_detail') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('bank_card_detail')
        : null;
    const paymentChargesAmountInput = paymentForm?.elements?.namedItem('charges_amount') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('charges_amount')
        : null;
    const paymentRemarksInput = paymentForm?.elements?.namedItem('receipt_remarks') instanceof HTMLInputElement
        ? paymentForm.elements.namedItem('receipt_remarks')
        : null;
    const paymentDetailModal = station.querySelector('[data-payment-detail-modal]');
    const paymentDetailCloseButtons = Array.from(station.querySelectorAll('[data-payment-detail-close]'));
    const paymentDetailMethodLabel = station.querySelector('[data-payment-detail-method-label]');
    const paymentDetailReferenceInput = station.querySelector('[data-payment-detail-reference]');
    const paymentDetailBankCardInput = station.querySelector('[data-payment-detail-bank-card]');
    const paymentDetailChargesInput = station.querySelector('[data-payment-detail-charges]');
    const paymentDetailRemarksInput = station.querySelector('[data-payment-detail-remarks]');
    const paymentDetailApplyButton = station.querySelector('[data-payment-detail-apply]');
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
    const bookingBranchLabelField = station.querySelector('[data-booking-branch-label]');
    const autoBookingDueDateField = station.querySelector('[data-auto-booking-field="due_date"]');
    const paymentReturnRow = station.querySelector('[data-payment-return-row]');
    const paymentReturnAmountInput = commercialLookup('commercial-payment-return-amount', '[data-payment-return-amount]');
    const autosaveInvoiceUrl = station.dataset.autosaveInvoiceUrl || '';
    const autosaveServiceUrl = station.dataset.autosaveServiceUrl || '';
    const invoiceNumberDisplay = station.querySelector('[data-invoice-number-display]');
    const autosaveStatusLabel = station.querySelector('[data-autosave-status]');
    const serviceTableBody = station.querySelector('.legacy-service-table tbody');
    let suppressAutosave = false;
    let suppressAutosaveTimerId = 0;
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
    let pendingInvoiceAutosaveAllowCreate = false;
    let pendingServiceAutosave = false;
    let pendingServiceAutosaveAllowCreate = false;
    let autosavedPaymentEligible = false;
    let autosavedPersistedServiceId = 0;
    let autosavedHasSavedService = false;
    let autosavedReceivableAmount = 0;
    // Production users do not want partial invoice/service edits saved while they are still typing.
    // Keep explicit saves through Save Service/Save Payment, but disable change/focusout autosave.
    const fieldAutosaveEnabled = false;
    let allowNativePaymentSubmit = false;
    let paymentSubmitValidationInFlight = false;
    let paymentExchangeConfirmInFlight = false;
    let paymentExchangeAutoOpenInFlight = false;
    let paymentExchangeConfirmFocusDone = false;
    let paymentExchangeManualTarget = null;
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
    window.workspaceSuppressAutosaveTemporarily = (reason = 'manual', durationMs = 450) => {
        suppressAutosave = true;
        window.clearTimeout(suppressAutosaveTimerId);
        suppressAutosaveTimerId = window.setTimeout(() => {
            suppressAutosave = false;
            suppressAutosaveTimerId = 0;
            if (typeof console !== 'undefined' && typeof console.debug === 'function') {
                console.debug('[workspace-autosave]', 'suppress cleared', {
                    reason,
                    durationMs,
                });
            }
        }, Math.max(Number(durationMs) || 0, 0));

        if (typeof console !== 'undefined' && typeof console.debug === 'function') {
            console.debug('[workspace-autosave]', 'suppress enabled', {
                reason,
                durationMs,
            });
        }
    };
    window.workspaceDebugEnterFlow = (stage, details = {}) => {
        if (typeof console !== 'undefined' && typeof console.debug === 'function') {
            console.debug('[workspace-enter]', stage, details);
        }
    };
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
    const paymentExchangeRateSaveUrl = buildWorkspacePathUrl('/workspace/payments/exchange-rate/save');

    const logWorkflowTrace = (stage, extra = {}) => {
        if (typeof sendWorkspaceClientError !== 'function') {
            return;
        }

        sendWorkspaceClientError({
            type: 'workspace_workflow_trace',
            message: `Workflow trace: ${stage}`,
            extra: {
                stage,
                bookingId: currentBookingId(),
                persistedServiceId: currentPersistedServiceId(),
                activeServiceId: activeServiceId(),
                savedPaymentApplies: currentSavedPaymentApplies(),
                hasSavedReceipt: Number.parseInt(String(paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0), 10) > 0,
                receiptId: Number.parseInt(String(paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0), 10) || 0,
                receiptUrl: String(paymentPrintReceiptButton?.dataset.paymentPrintUrl || '').trim(),
                scriptInstanceId: workspaceScriptInstanceId,
                enteredPaymentAmount: Math.max(toNumber(receivedNowInput?.value || 0), 0),
                invoiceDraftDirty: (() => {
                    try {
                        return invoiceForm instanceof HTMLFormElement && serializeForm(invoiceForm) !== lastInvoiceAutosaveKey;
                    } catch (error) {
                        return null;
                    }
                })(),
                serviceDraftDirty: (() => {
                    try {
                        return serviceForm instanceof HTMLFormElement && serializeForm(serviceForm) !== lastServiceAutosaveKey;
                    } catch (error) {
                        return null;
                    }
                })(),
                autosaveState: String(autosaveStatusLabel?.dataset.state || '').trim().toLowerCase(),
                serviceAutosaveReady: typeof serviceAutosaveReady === 'function' ? serviceAutosaveReady() : null,
                autosaveBookingReady: typeof autosaveBookingReady === 'function' ? autosaveBookingReady() : null,
                ...extra,
            },
        });
    };

    const showFeedback = (message) => {
        if (!feedback) {
            return;
        }

        const normalizedMessage = String(message || '').trim();
        const now = Date.now();
        if (normalizedMessage !== '') {
            const lastMessage = String(showFeedback.lastMessage || '');
            const lastShownAt = Number(showFeedback.lastShownAt || 0);
            if (lastMessage === normalizedMessage && now - lastShownAt < 4500) {
                return;
            }

            showFeedback.lastMessage = normalizedMessage;
            showFeedback.lastShownAt = now;
        }

        feedback.textContent = message;
        feedback.classList.add('is-visible');
        window.clearTimeout(showFeedback.timerId);
        showFeedback.timerId = window.setTimeout(() => {
            feedback.classList.remove('is-visible');
        }, 4800);
    };

    const allowedDocumentExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    const showDocumentUploadFeedback = (message) => {
        if (!(documentUploadFeedback instanceof HTMLElement)) {
            showFeedback(message);
            return;
        }

        documentUploadFeedback.textContent = message;
        documentUploadFeedback.hidden = false;
        documentUploadFeedback.style.display = 'block';
    };
    const clearDocumentUploadFeedback = () => {
        if (!(documentUploadFeedback instanceof HTMLElement)) {
            return;
        }

        documentUploadFeedback.textContent = '';
        documentUploadFeedback.hidden = true;
        documentUploadFeedback.style.display = '';
    };
    const validateDocumentFileSelection = () => {
        if (!(documentFileInput instanceof HTMLInputElement)) {
            return true;
        }

        const file = documentFileInput.files && documentFileInput.files.length > 0
            ? documentFileInput.files[0]
            : null;
        if (!file) {
            clearDocumentUploadFeedback();
            return true;
        }

        const fileName = String(file.name || '').trim();
        const extension = fileName.includes('.')
            ? fileName.split('.').pop().toLowerCase()
            : '';
        if (!allowedDocumentExtensions.includes(extension)) {
            showDocumentUploadFeedback('This file type is blocked. Please upload only PDF, JPG, JPEG, PNG, or WEBP documents.');
            documentFileInput.value = '';
            showFeedback('Document upload blocked: only PDF, JPG, JPEG, PNG, or WEBP files are allowed.');
            return false;
        }

        clearDocumentUploadFeedback();
        return true;
    };

    const readStoredJson = (key, fallback) => {
        try {
            const raw = window.localStorage.getItem(key);
            if (!raw) {
                return fallback;
            }

            const parsed = JSON.parse(raw);
            return parsed == null ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    };

    const writeStoredJson = (key, value) => {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (error) {
            return false;
        }
    };

    let workspaceConnectivityState = window.navigator.onLine === false ? 'offline' : 'online';
    let workspaceConnectivityCheckPromise = null;
    let reapplyActiveServiceActionState = () => {};
    const browserIsOffline = () => window.navigator.onLine === false || workspaceConnectivityState === 'offline';
    const setWorkspaceConnectivityState = (state) => {
        workspaceConnectivityState = state === 'offline' ? 'offline' : 'online';
        syncNewCustomerSaveMode();
        syncOfflineEditLockMode();
        if (workspaceConnectivityState === 'online') {
            window.setTimeout(() => reapplyActiveServiceActionState(), 0);
        }
    };
    const pingUrlWithCacheBust = () => {
        if (offlinePingUrl === '') {
            return '';
        }

        const separator = offlinePingUrl.includes('?') ? '&' : '?';
        return `${offlinePingUrl}${separator}_=${Date.now()}`;
    };
    const refreshWorkspaceConnectivity = async () => {
        if (window.navigator.onLine === false) {
            setWorkspaceConnectivityState('offline');
            return false;
        }

        if (offlinePingUrl === '') {
            setWorkspaceConnectivityState('online');
            return true;
        }

        if (workspaceConnectivityCheckPromise) {
            return workspaceConnectivityCheckPromise;
        }

        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 2500);

        workspaceConnectivityCheckPromise = fetch(pingUrlWithCacheBust(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            signal: controller.signal,
        }).then((response) => {
            const isOnline = response.ok;
            setWorkspaceConnectivityState(isOnline ? 'online' : 'offline');
            return isOnline;
        }).catch(() => {
            setWorkspaceConnectivityState('offline');
            return false;
        }).finally(() => {
            window.clearTimeout(timeoutId);
            workspaceConnectivityCheckPromise = null;
        });

        return workspaceConnectivityCheckPromise;
    };

    let offlineQueue = Array.isArray(readStoredJson(offlineQueueStorageKey, []))
        ? readStoredJson(offlineQueueStorageKey, [])
        : [];
    let offlineSnapshotCache = readStoredJson(offlineSnapshotStorageKey, null);
    let offlineMeta = readStoredJson(offlineMetaStorageKey, {
        last_snapshot_at: '',
        last_sync_at: '',
    });
    let offlineQueuePreviewVisible = false;

    const getCsrfToken = () => {
        const tokenField = station.querySelector('input[name="_token"]');
        if (tokenField instanceof HTMLInputElement && tokenField.value.trim() !== '') {
            return tokenField.value.trim();
        }

        return String(station.dataset.csrfToken || '').trim();
    };

    const setOfflineToken = (token) => {
        const normalized = String(token || '').trim();
        if (normalized === '') {
            return;
        }

        station.dataset.csrfToken = normalized;
        station.querySelectorAll('input[name="_token"]').forEach((field) => {
            if (field instanceof HTMLInputElement) {
                field.value = normalized;
            }
        });
    };

    const offlineSourceDevice = () => {
        const agent = String(window.navigator.userAgent || 'Browser').trim();
        return `Workspace Browser / ${agent}`.slice(0, 120);
    };

    const formatOfflineTimestamp = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) {
            return raw;
        }

        return parsed.toLocaleString();
    };

    const formatOfflineAge = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) {
            return '';
        }

        const diffMs = Date.now() - parsed.getTime();
        if (diffMs < 60000) {
            return 'just now';
        }

        const minutes = Math.floor(diffMs / 60000);
        if (minutes < 60) {
            return `${minutes}m ago`;
        }

        const hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return `${hours}h ago`;
        }

        const days = Math.floor(hours / 24);
        if (days < 30) {
            return `${days}d ago`;
        }

        const months = Math.floor(days / 30);
        if (months < 12) {
            return `${months}mo ago`;
        }

        const years = Math.floor(days / 365);
        return `${years}y ago`;
    };

    const persistOfflineState = () => {
        writeStoredJson(offlineQueueStorageKey, offlineQueue);
        writeStoredJson(offlineSnapshotStorageKey, offlineSnapshotCache);
        writeStoredJson(offlineMetaStorageKey, offlineMeta);
    };

    const offlineDraftDisplay = (draft) => {
        const payload = draft && typeof draft === 'object' && draft.payload && typeof draft.payload === 'object'
            ? draft.payload
            : {};
        const name = [payload.first_name, payload.last_name].map((value) => String(value || '').trim()).filter(Boolean).join(' ');
        const label = String(draft?.label || name || 'Customer draft').trim();
        const details = [
            payload.mobile ? `Mobile: ${payload.mobile}` : '',
            payload.passport_number ? `Passport: ${payload.passport_number}` : '',
            payload.family_id ? `Family ID: ${payload.family_id}` : '',
            draft?.queued_at ? `Queued: ${formatOfflineTimestamp(draft.queued_at)}` : '',
        ].filter(Boolean);

        return {
            label,
            type: String(draft?.type || 'traveler.create') === 'traveler.create' ? 'Customer' : String(draft?.type || 'Draft'),
            details: details.join(' - '),
        };
    };

    const hideOfflineQueuePreview = () => {
        offlineQueuePreviewVisible = false;
        if (offlineQueuePreview instanceof HTMLElement) {
            offlineQueuePreview.hidden = true;
        }
        if (offlineStatus instanceof HTMLElement) {
            offlineStatus.setAttribute('aria-expanded', 'false');
        }
    };

    const renderOfflineQueuePreview = () => {
        const rows = Array.isArray(offlineQueue) ? offlineQueue : [];
        if (!(offlineQueuePreview instanceof HTMLElement) || !(offlineQueueList instanceof HTMLElement)) {
            return;
        }

        if (rows.length === 0) {
            offlineQueueList.innerHTML = '';
            if (offlineQueueCount instanceof HTMLElement) {
                offlineQueueCount.textContent = '0 draft(s)';
            }
            hideOfflineQueuePreview();
            return;
        }

        if (offlineQueueCount instanceof HTMLElement) {
            offlineQueueCount.textContent = `${rows.length} draft${rows.length === 1 ? '' : 's'}`;
        }

        offlineQueueList.innerHTML = rows.map((draft) => {
            const display = offlineDraftDisplay(draft);
            return `<div class="legacy-offline-queue-preview__item">
                <strong>${escapeHtml(display.label)}</strong>
                <span>${escapeHtml(display.type)}</span>
                ${display.details !== '' ? `<small>${escapeHtml(display.details)}</small>` : ''}
            </div>`;
        }).join('');

        offlineQueuePreview.hidden = !offlineQueuePreviewVisible;
        if (offlineStatus instanceof HTMLElement) {
            offlineStatus.setAttribute('aria-expanded', offlineQueuePreviewVisible ? 'true' : 'false');
        }
    };

    const toggleOfflineQueuePreview = () => {
        const queueCount = Array.isArray(offlineQueue) ? offlineQueue.length : 0;
        if (queueCount === 0) {
            hideOfflineQueuePreview();
            return;
        }

        offlineQueuePreviewVisible = !offlineQueuePreviewVisible;
        renderOfflineQueuePreview();
    };

    const offlineLockedActionSelectors = [
        '[data-workspace-action="new-booking"]',
        '[data-workspace-action="customer-dues-finder"]',
        '[data-workspace-action="supplier-history-finder"]',
        '[data-workspace-action="add-service"]',
        '[data-global-prepaid-supplier-open]',
    ];

    const offlineLockedControlSelector = 'input, select, textarea, button';
    const lockableControl = (control) => control instanceof HTMLInputElement
        || control instanceof HTMLSelectElement
        || control instanceof HTMLTextAreaElement
        || control instanceof HTMLButtonElement;
    const setOfflineLockedControl = (control, locked) => {
        if (!lockableControl(control)) {
            return;
        }

        if (locked) {
            if (!Object.prototype.hasOwnProperty.call(control.dataset, 'offlinePreviousDisabled')) {
                control.dataset.offlinePreviousDisabled = control.disabled ? '1' : '0';
            }
            control.disabled = true;
            return;
        }

        if (Object.prototype.hasOwnProperty.call(control.dataset, 'offlinePreviousDisabled')) {
            control.disabled = control.dataset.offlinePreviousDisabled === '1';
            delete control.dataset.offlinePreviousDisabled;
        }
    };

    const offlineLockedZones = () => [
        invoiceForm,
        serviceForm,
        paymentForm,
        simplePostpaidForm,
        globalPrepaidSupplierForm,
        supplierSettlementModal,
        ...Array.from(station.querySelectorAll('[data-payment-detail-modal], [data-payment-exchange-modal]')),
    ].filter((zone) => zone instanceof HTMLElement || zone instanceof HTMLFormElement);

    const syncOfflineEditLockMode = () => {
        const locked = browserIsOffline();

        offlineLockedZones().forEach((zone) => {
            zone.querySelectorAll(offlineLockedControlSelector).forEach((control) => setOfflineLockedControl(control, locked));
            zone.classList.toggle('is-offline-locked', locked);
        });

        offlineLockedActionSelectors.forEach((selector) => {
            station.querySelectorAll(selector).forEach((control) => setOfflineLockedControl(control, locked));
        });

        if (offlineEditLockNotice instanceof HTMLElement) {
            offlineEditLockNotice.hidden = !locked;
            offlineEditLockNotice.style.display = locked ? 'block' : '';
        }

        if (customerPicker instanceof HTMLElement && !customerPicker.hidden) {
            renderCustomerPicker();
        }
    };

    const preventOfflineLockedSubmit = (event) => {
        if (!browserIsOffline()) {
            return;
        }

        event.preventDefault();
        showFeedback('Offline mode is lookup-only. Reconnect before changing bookings, services, or payments.');
        syncOfflineEditLockMode();
    };

    [invoiceForm, serviceForm, paymentForm, simplePostpaidForm, globalPrepaidSupplierForm]
        .filter((form) => form instanceof HTMLFormElement)
        .forEach((form) => form.addEventListener('submit', preventOfflineLockedSubmit));

    station.querySelectorAll('[data-service-event-bar]').forEach((form) => {
        if (form instanceof HTMLFormElement) {
            form.addEventListener('submit', preventOfflineLockedSubmit);
        }
    });

    const syncNewCustomerSaveMode = () => {
        const isOffline = browserIsOffline();
        const editingCustomer = Number.parseInt(String(newCustomerTravelerId?.value || '0'), 10) > 0;

        if (newCustomerSubmit instanceof HTMLButtonElement) {
            newCustomerSubmit.hidden = isOffline;
        }

        offlineSaveCustomerButtons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.hidden = !isOffline;
            button.disabled = !isOffline || editingCustomer;
            button.title = editingCustomer
                ? 'Offline customer updates are not supported. Reconnect to update this customer.'
                : 'Save this new customer into the offline queue.';
        });
    };

    const renderOfflineStatus = () => {
        if (!(offlineStatus instanceof HTMLElement)) {
            return;
        }

        const queueCount = Array.isArray(offlineQueue) ? offlineQueue.length : 0;
        const snapshotLabel = formatOfflineTimestamp(offlineMeta?.last_snapshot_at || '');
        const snapshotAge = formatOfflineAge(offlineMeta?.last_snapshot_at || '');
        const syncLabel = formatOfflineTimestamp(offlineMeta?.last_sync_at || '');
        const parts = [];

        if (queueCount > 0) {
            parts.push(`${queueCount} offline draft${queueCount === 1 ? '' : 's'} queued`);
        } else {
            parts.push('Offline queue empty');
        }

        if (snapshotLabel !== '') {
            parts.push(`snapshot ${snapshotAge !== '' ? snapshotAge : snapshotLabel}`);
        }

        if (syncLabel !== '') {
            parts.push(`last sync ${syncLabel}`);
        }

        offlineStatus.textContent = parts.join(' • ');
        offlineStatus.classList.toggle('is-pending', queueCount > 0);
        offlineStatus.classList.toggle('is-ready', queueCount === 0 && (snapshotLabel !== '' || syncLabel !== ''));
        offlineStatus.title = offlineStatus.textContent;

        if (queueCount === 0) {
            hideOfflineQueuePreview();
        }
        renderOfflineQueuePreview();
    };

    const normalizeOfflineSearchText = (value) => String(value || '').trim().toLowerCase();
    const snapshotSourceLabel = 'Snapshot';
    const isSnapshotRecord = (record) => String(record?.__source || '') === 'offline_snapshot';
    const snapshotFreshnessText = () => formatOfflineAge(offlineMeta?.last_snapshot_at || offlineSnapshotCache?.generated_at || '');
    const renderSnapshotBadge = (record) => {
        if (!isSnapshotRecord(record)) {
            return '';
        }

        const freshness = snapshotFreshnessText();
        const badgeText = freshness !== '' ? `${snapshotSourceLabel} • ${freshness}` : snapshotSourceLabel;
        const titleText = freshness !== '' ? `Loaded from cached offline snapshot • cached ${freshness}` : 'Loaded from cached offline snapshot';
        return `<span class="offline-source-badge" title="${escapeHtml(titleText)}">${escapeHtml(badgeText)}</span>`;
    };

    const ticketOrPnrLabel = (booking) => {
        const ticket = String(booking?.ticket_number || '').trim();
        const pnr = String(booking?.pnr || '').trim();
        if (ticket !== '' && pnr !== '') {
            return `${ticket} / ${pnr}`;
        }

        return ticket || pnr || '-';
    };

    const offlineBookingLabel = (booking) => [
        booking?.booking_reference,
        booking?.lead_traveler_name,
        booking?.contact_mobile,
        booking?.passport_number,
        booking?.service_line_reference,
        booking?.ticket_number,
        booking?.pnr,
        booking?.supplier_name,
        booking?.receipt_no,
        booking?.branch_name,
    ].map(normalizeOfflineSearchText).join(' ');

    const hideOfflineSearchResults = () => {
        if (!(offlineSearchPanel instanceof HTMLElement)) {
            return;
        }

        offlineSearchPanel.hidden = true;
    };

    const renderOfflineSearchResults = (query, rows) => {
        if (!(offlineSearchPanel instanceof HTMLElement) || !(offlineSearchResultsBody instanceof HTMLElement)) {
            return;
        }

        const normalizedRows = Array.isArray(rows) ? rows : [];
        if (normalizedRows.length === 0) {
            offlineSearchPanel.hidden = true;
            offlineSearchResultsBody.innerHTML = '';
            return;
        }

        offlineSearchPanel.hidden = false;
        if (offlineSearchTitle instanceof HTMLElement) {
            offlineSearchTitle.textContent = query
                ? `Offline Snapshot Results for "${query}"`
                : 'Offline Snapshot Results';
        }
        if (offlineSearchCount instanceof HTMLElement) {
            const freshness = snapshotFreshnessText();
            offlineSearchCount.textContent = `${normalizedRows.length} match${normalizedRows.length === 1 ? '' : 'es'}${freshness !== '' ? ` • cached ${freshness}` : ''}`;
        }
        if (offlineSearchEmpty instanceof HTMLElement) {
            offlineSearchEmpty.hidden = normalizedRows.length !== 0;
        }
        if (offlineSearchTableWrap instanceof HTMLElement) {
            offlineSearchTableWrap.hidden = false;
        }

        offlineSearchResultsBody.innerHTML = normalizedRows.map((row) => {
            const currency = String(row?.booking_currency || row?.base_currency || 'PKR').trim() || 'PKR';
            const outstanding = formatMoney(toNumber(row?.total_outstanding || 0));
            const reference = String(row?.booking_reference || '').trim();
            return `<tr>
                <td>${escapeHtml(reference)} ${renderSnapshotBadge(row)}</td>
                <td>${escapeHtml(String(row?.lead_traveler_name || ''))}</td>
                <td>${escapeHtml(String(row?.branch_name || ''))}</td>
                <td>${escapeHtml(String(row?.booking_date || ''))}</td>
                <td>${escapeHtml(currency)}</td>
                <td>${escapeHtml(`${currency} ${outstanding}`)}</td>
                <td>${escapeHtml(String(row?.service_line_reference || '-'))}</td>
                <td>${escapeHtml(ticketOrPnrLabel(row))}</td>
                <td><span class="table-note">Reconnect to open ${escapeHtml(reference || 'this booking')}.</span></td>
            </tr>`;
        }).join('');
    };

    const integrateOfflineSnapshot = (snapshot) => {
        if (!snapshot || typeof snapshot !== 'object') {
            return;
        }

        const snapshotTravelers = Array.isArray(snapshot.travelers) ? snapshot.travelers : [];
        snapshotTravelers.forEach((traveler) => {
            const enrichedTraveler = {
                ...traveler,
                __source: 'offline_snapshot',
            };
            const travelerId = Number.parseInt(String(traveler?.id || 0), 10);
            if (travelerId > 0) {
                const existingIndex = customerDirectory.findIndex((entry) => Number(entry?.id || 0) === travelerId);
                if (existingIndex >= 0) {
                    customerDirectory[existingIndex] = { ...customerDirectory[existingIndex], ...enrichedTraveler };
                } else {
                    customerDirectory.push(enrichedTraveler);
                }
            }
        });

        const snapshotBookings = Array.isArray(snapshot.bookings) ? snapshot.bookings : [];
        offlineSnapshotCache = {
            ...snapshot,
            bookings: snapshotBookings.map((booking) => ({
                ...booking,
                __source: 'offline_snapshot',
            })),
            travelers: snapshotTravelers.map((traveler) => ({
                ...traveler,
                __source: 'offline_snapshot',
            })),
        };

        filteredCustomers = customerDirectory.slice();
        filteredAutocompleteCustomers = customerDirectory.slice(0, 12);
    };

    const searchOfflineSnapshotBookings = (query) => {
        const bookings = Array.isArray(offlineSnapshotCache?.bookings) ? offlineSnapshotCache.bookings : [];
        const normalizedQuery = normalizeOfflineSearchText(query);
        if (normalizedQuery === '') {
            return bookings.slice(0, 20);
        }

        return bookings
            .filter((booking) => offlineBookingLabel(booking).includes(normalizedQuery))
            .slice(0, 20);
    };

    const tryOfflineQuickSearch = () => {
        const query = String(quickSearch?.value || '').trim();
        const offlineRows = searchOfflineSnapshotBookings(query);
        renderOfflineSearchResults(query, offlineRows);
        if (offlineRows.length === 0) {
            showFeedback('No cached offline booking matched this search. Refresh the offline snapshot when you are back online.');
            return;
        }

        showFeedback(`Showing ${offlineRows.length} offline snapshot match${offlineRows.length === 1 ? '' : 'es'}. Reconnect to open the full booking.`);
    };

    const createOfflineDraftId = (type) => `${String(type || 'draft')}-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;

    const formValue = (form, name) => {
        if (!(form instanceof HTMLFormElement)) {
            return '';
        }

        const field = form.elements.namedItem(name);
        if (field instanceof RadioNodeList) {
            return String(field.value || '').trim();
        }

        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
            return String(field.value || '').trim();
        }

        return '';
    };

    const queueOfflineDraft = (type, payload, label, options = {}) => {
        offlineQueue.push({
            client_draft_id: options.clientDraftId || createOfflineDraftId(type),
            type,
            payload,
            label: String(label || '').trim(),
            queued_at: new Date().toISOString(),
        });
        persistOfflineState();
        renderOfflineStatus();
    };

    const downloadJsonFile = (fileName, payload) => {
        const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
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

        if (browserIsOffline()) {
            if (offlineSnapshotCache) {
                tryOfflineQuickSearch();
            } else {
                showFeedback('You are offline and no snapshot is cached yet. Download an offline snapshot while connected first.');
            }
            return;
        }

        hideOfflineSearchResults();

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

    const revealWorkspaceSection = (sectionId, options = {}) => {
        const { message = null, focusSelector = null } = options;
        const section = station.querySelector(`#${sectionId}`);
        if (!(section instanceof HTMLElement)) {
            return false;
        }

        section.hidden = false;
        if (sectionId === 'dock-panel-reminders') {
            reminderToggleButtons.forEach((button) => {
                if (button instanceof HTMLButtonElement) {
                    button.setAttribute('aria-expanded', 'true');
                }
            });
        }
        section.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });

        if (message) {
            showFeedback(message);
        }

        if (focusSelector) {
            window.setTimeout(() => focusTarget(focusSelector), 80);
        }

        return true;
    };

    const toggleWorkspaceSection = (sectionId, options = {}) => {
        const { message = null, hideMessage = null, focusSelector = null } = options;
        const section = station.querySelector(`#${sectionId}`);
        if (!(section instanceof HTMLElement)) {
            return false;
        }

        if (section.hidden) {
            return revealWorkspaceSection(sectionId, { message, focusSelector });
        }

        const activeElement = document.activeElement;
        if (activeElement instanceof HTMLElement && section.contains(activeElement)) {
            const fallbackButton = reminderToggleButtons[0];
            if (fallbackButton instanceof HTMLButtonElement) {
                fallbackButton.focus({ preventScroll: true });
            }
        }

        section.hidden = true;
        if (sectionId === 'dock-panel-reminders') {
            reminderToggleButtons.forEach((button) => {
                if (button instanceof HTMLButtonElement) {
                    button.setAttribute('aria-expanded', 'false');
                }
            });
        }

        if (window.location.hash === `#${sectionId}`) {
            try {
                const cleanedUrl = new URL(window.location.href);
                cleanedUrl.hash = '';
                window.history.replaceState({}, '', `${cleanedUrl.pathname}${cleanedUrl.search}`);
            } catch (error) {
                // Ignore URL cleanup errors.
            }
        }

        if (hideMessage) {
            showFeedback(hideMessage);
        }

        return true;
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

    const prepareNewServiceEntry = () => {
        resetServiceLine();
        clearSavedPaymentState({
            clearAmount: true,
            resetPaymentCurrency: true,
            closeExchange: true,
            showReadyMessage: false,
        });
        clearPaymentDetailHiddenFields();
        closePaymentDetailModal();
        showFeedback('Service line is ready. Confirm passenger details and continue.');
        if (servicePassengerNameField instanceof HTMLElement) {
            servicePassengerNameField.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            window.setTimeout(() => {
                servicePassengerNameField.focus();
                if (servicePassengerNameField instanceof HTMLInputElement) {
                    servicePassengerNameField.select();
                }
            }, 80);
        } else {
            focusTarget('[data-service-passenger-name]');
        }
    };

    const currentInvoiceDraftIsDirty = () => {
        if (!(invoiceForm instanceof HTMLFormElement)) {
            return false;
        }

        return serializeForm(invoiceForm) !== lastInvoiceAutosaveKey;
    };

    const currentServiceDraftIsDirty = () => {
        if (!(serviceForm instanceof HTMLFormElement)) {
            return false;
        }

        return serializeForm(serviceForm) !== lastServiceAutosaveKey;
    };

    const currentServiceDraftNeedsPersistForWorkflow = () => {
        if (currentBookingId() <= 0) {
            return autosaveBookingReady() || serviceAutosaveReady();
        }

        if (!serviceAutosaveReady()) {
            return false;
        }

        if (currentPersistedServiceId() <= 0) {
            return true;
        }

        return currentServiceDraftIsDirty() || currentInvoiceDraftIsDirty();
    };

    actionButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            switch (button.dataset.workspaceAction) {
                case 'new-booking':
                    window.location.href = station.dataset.newBookingUrl || '/workspace?new=1';
                    break;
                case 'new-customer':
                    if (startFreshWorkspaceForCustomerAction('new-customer')) {
                        break;
                    }
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
                    if (startFreshWorkspaceForCustomerAction('find-customer')) {
                        break;
                    }
                    openCustomerPicker();
                    break;
                case 'customer-dues-finder':
                    openCustomerDuesModal();
                    break;
                case 'supplier-history-finder':
                    openSupplierHistoryModal();
                    break;
                case 'service-edit-booking':
                    openServiceEditBookingModal();
                    break;
                case 'service-penalty-refund':
                    openServicePenaltyRefundModal();
                    break;
                case 'recent-bookings':
                    toggleWorkspaceSection('workspace-recent-bookings', {
                        message: 'Recent invoices opened.',
                        hideMessage: 'Recent invoices hidden.',
                    });
                    break;
                case 'add-service':
                    {
                        logWorkflowTrace('add-service:clicked');
                        const enteredPaymentAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);
                        const addServicePayload = await performSameCurrencyPaymentSave({
                            autoOpenReceipt: false,
                            suppressReceiptCreation: enteredPaymentAmount <= 0.005,
                            workflowOrigin: 'add_service',
                        });

                        logWorkflowTrace('add-service:shared-save-finished', {
                            enteredPaymentAmount,
                            payloadOk: Boolean(addServicePayload),
                            paymentStillUnsaved: !currentSavedPaymentApplies(),
                            currentPersistedServiceId: currentPersistedServiceId(),
                        });

                        if (enteredPaymentAmount > 0.005) {
                            const remainingEnteredAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);
                            if (!currentSavedPaymentApplies() && remainingEnteredAmount > 0.005) {
                                logWorkflowTrace('add-service:blocked-unsaved-payment', {
                                    remainingEnteredAmount,
                                });
                                return;
                            }
                        } else if (currentPersistedServiceId() <= 0) {
                            logWorkflowTrace('add-service:blocked-unsaved-service', {
                                currentPersistedServiceId: currentPersistedServiceId(),
                            });
                            showFeedback('The current passenger/service could not be saved yet. Complete the required fields before Add Service.');
                            return;
                        }

                        logWorkflowTrace('add-service:prepare-new-service-entry');
                        prepareNewServiceEntry();
                    }
                    break;
                case 'add-payment':
                    activateDock('payments', {
                        message: 'Payments opened. Record the receipt first, then allocate it if needed.',
                        focusSelector: '[data-payment-focus="received_amount"]',
                    });
                    break;
                case 'add-reminder':
                    if (!toggleWorkspaceSection('dock-panel-reminders', {
                        message: 'Reminders opened. All follow-up stays linked to this invoice.',
                        hideMessage: 'Reminders hidden.',
                        focusSelector: '[data-reminder-focus="task"]',
                    })) {
                        activateDock('reminders', {
                            message: 'Reminders opened. All follow-up stays linked to this invoice.',
                            focusSelector: '[data-reminder-focus="task"]',
                        });
                    }
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

    Array.from(station.querySelectorAll('[data-workspace-close-section]')).forEach((button) => {
        button.addEventListener('click', () => {
            const sectionId = String(button.dataset.workspaceCloseSection || '').trim();
            if (sectionId === '') {
                return;
            }

            toggleWorkspaceSection(sectionId, {
                message: 'Section opened.',
                hideMessage: 'Section hidden.',
            });
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

    quickSearchForm?.addEventListener('submit', (event) => {
        if (browserIsOffline()) {
            event.preventDefault();
            if (offlineSnapshotCache) {
                tryOfflineQuickSearch();
            } else {
                showFeedback('You are offline and no snapshot is cached yet. Download an offline snapshot while connected first.');
            }
            return;
        }

        hideOfflineSearchResults();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeCustomerPicker();
            closeCustomerDuesModal();
            closeSupplierHistoryModal();
            closeNewCustomerModal();
            closePaymentHistoryModal();
            closeSupplierSettlementModal();
            closeExchangeSettlementModal({ clear: false });
            closePaymentDetailModal();
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

    const roundMoneyValue = (value) => Math.round(Number(value || 0));
    const formatNumberInputValue = (value) => String(roundMoneyValue(value));

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

        const settlementBar = control.closest('[data-service-event-bar="settlement"]');
        if (settlementBar instanceof HTMLElement && !settlementBar.hidden) {
            control.dataset.workflowOriginalDisabled = '0';
            control.disabled = false;
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
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

    const formatCurrencyAmount = (currency, amount) => `${currency || 'PKR'} ${formatMoney(Math.max(amount, 0))}`;
    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');


    const paymentMethodLabels = {
        bank_transfer: 'Bank Transfer',
        debit_card: 'Debit Card',
        credit_card: 'Credit Card',
    };

    const nonCashPaymentMethods = new Set(Object.keys(paymentMethodLabels));

    const isNonCashPaymentMethod = (method) => nonCashPaymentMethods.has(String(method || '').trim());
    const isDirectSupplierPaymentMethod = (method) => String(method || '').trim() === 'customer_paid_supplier';

    const currentPaymentMethodLabel = () => {
        const method = String(paymentMethodSelect?.value || '').trim();

        return paymentMethodLabels[method] || method.replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase()) || 'Payment';
    };

    const closePaymentDetailModal = () => {
        if (!paymentDetailModal) {
            return;
        }

        paymentDetailModal.hidden = true;
        paymentDetailModal.setAttribute('aria-hidden', 'true');
    };

    let paymentTreasuryAccounts = [];
    try {
        paymentTreasuryAccounts = JSON.parse(paymentTreasuryAccountsNode?.textContent || '[]');
    } catch (error) {
        paymentTreasuryAccounts = [];
    }
    let paymentTreasuryRefreshInFlight = null;

    const addPaymentTreasuryAccountValue = '__add_treasury_account__';
    const captureFormState = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return [];
        }

        return Array.from(form.elements)
            .filter((field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)
            .filter((field) => field.name && field.name !== '_token' && field.type !== 'file')
            .map((field) => ({
                name: field.name,
                type: field.type || field.tagName.toLowerCase(),
                value: field.value,
                checked: 'checked' in field ? Boolean(field.checked) : false,
            }));
    };
    const restoreFormState = (form, entries = [], options = {}) => {
        if (!(form instanceof HTMLFormElement) || !Array.isArray(entries)) {
            return;
        }

        const suppressedEventNames = options.suppressedEventNames instanceof Set
            ? options.suppressedEventNames
            : new Set(Array.isArray(options.suppressedEventNames) ? options.suppressedEventNames : []);

        entries.forEach((entry) => {
            const field = form.elements.namedItem(entry.name);
            if (!field) {
                return;
            }

            const applyField = (target) => {
                if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) {
                    return;
                }

                if (target.type === 'checkbox' || target.type === 'radio') {
                    target.checked = Boolean(entry.checked);
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }

                target.value = String(entry.value ?? '');
                if (suppressedEventNames.has(String(entry.name || ''))) {
                    return;
                }
                target.dispatchEvent(new Event('input', { bubbles: true }));
                target.dispatchEvent(new Event('change', { bubbles: true }));
            };

            if (field instanceof RadioNodeList) {
                Array.from(field).forEach(applyField);
                return;
            }

            applyField(field);
        });
    };
    const workspaceReturnUrlForTreasury = () => {
        try {
            const url = new URL(window.location.href);
            const bookingId = currentBookingId();
            if (bookingId > 0) {
                url.searchParams.set('booking_id', String(bookingId));
            }
            url.searchParams.delete('focus');

            return url;
        } catch (error) {
            return null;
        }
    };
    const hasPendingTreasuryWorkspaceStateForCurrentRoute = () => {
        let raw = '';
        try {
            raw = window.localStorage.getItem(pendingTreasuryWorkspaceStateKey) || '';
        } catch (error) {
            return false;
        }

        if (raw.trim() === '') {
            return false;
        }

        try {
            const payload = JSON.parse(raw);
            const currentRoute = `${window.location.pathname}${window.location.search}`;
            return Boolean(payload && payload.route === currentRoute);
        } catch (error) {
            return false;
        }
    };
    const persistWorkspaceStateForTreasuryReturn = () => {
        try {
            const returnUrl = workspaceReturnUrlForTreasury();
            const payload = {
                route: returnUrl ? `${returnUrl.pathname}${returnUrl.search}` : `${window.location.pathname}${window.location.search}`,
                invoice: captureFormState(invoiceForm),
                service: captureFormState(serviceForm),
                payment: captureFormState(paymentForm),
            };
            window.localStorage.setItem(pendingTreasuryWorkspaceStateKey, JSON.stringify(payload));
        } catch (error) {
            // Best-effort only.
        }
    };
    const restoreWorkspaceStateAfterTreasuryReturn = () => {
        let raw = '';
        try {
            raw = window.localStorage.getItem(pendingTreasuryWorkspaceStateKey) || '';
        } catch (error) {
            return;
        }

        if (raw.trim() === '') {
            return;
        }

        try {
            const payload = JSON.parse(raw);
            const currentRoute = `${window.location.pathname}${window.location.search}`;
            if (!payload || payload.route !== currentRoute) {
                return;
            }

            const restoredPaymentCurrency = String(payload?.payment?.receipt_currency?.value || '').trim().toUpperCase();
            window.localStorage.removeItem(pendingTreasuryWorkspaceStateKey);
            station.dataset.suppressDueDateAutoOpen = '1';
            station.dataset.suppressExchangeAutoOpen = '1';
            restoreFormState(invoiceForm, payload.invoice, {
                suppressedEventNames: ['lead_traveler_name'],
            });
            restoreFormState(serviceForm, payload.service);
            restoreFormState(paymentForm, payload.payment, {
                suppressedEventNames: ['receipt_currency'],
            });

            window.setTimeout(() => {
                if (typeof refreshSubtypeVisibility === 'function') {
                    refreshSubtypeVisibility();
                }
                if (typeof refreshProfit === 'function') {
                    refreshProfit('restore-treasury-return');
                }
                if (typeof syncServiceCurrencyMirror === 'function') {
                    syncServiceCurrencyMirror();
                }
                if (restoredPaymentCurrency !== '' && typeof markManualPaymentCurrencySelection === 'function') {
                    markManualPaymentCurrencySelection(
                        restoredPaymentCurrency,
                        currentInvoiceSnapshot().invoiceCurrency || 'PKR'
                    );
                }
                if (typeof syncPaymentTreasurySelector === 'function') {
                    syncPaymentTreasurySelector();
                }
                if (typeof refreshPaymentPreview === 'function') {
                    refreshPaymentPreview();
                }
                if (typeof updateWorkflowState === 'function') {
                    updateWorkflowState();
                }
                const treasuryFocusTarget = paymentTreasuryAccountSelect instanceof HTMLSelectElement
                    && paymentTreasuryAccountRow instanceof HTMLElement
                    && !paymentTreasuryAccountRow.hidden
                    && !paymentTreasuryAccountSelect.disabled
                    ? paymentTreasuryAccountSelect
                    : null;

                if (treasuryFocusTarget) {
                    treasuryFocusTarget.focus();
                } else if (receivedNowInput instanceof HTMLInputElement) {
                    receivedNowInput.focus();
                    receivedNowInput.select();
                }
                delete station.dataset.suppressDueDateAutoOpen;
                delete station.dataset.suppressExchangeAutoOpen;
            }, 80);
        } catch (error) {
            delete station.dataset.suppressDueDateAutoOpen;
            delete station.dataset.suppressExchangeAutoOpen;
            try {
                window.localStorage.removeItem(pendingTreasuryWorkspaceStateKey);
            } catch (cleanupError) {
                // ignore
            }
        }
    };
    const paymentMethodRequiresTreasurySelection = (method) => ['cash', 'bank_transfer'].includes(String(method || '').trim());
    const treasuryAccountsUrl = station.dataset.treasuryAccountsUrl || '';
    const paymentTreasuryTypesForMethod = (method) => {
        switch (String(method || '').trim()) {
            case 'cash':
                return ['cash'];
            case 'bank_transfer':
                return ['bank'];
            default:
                return [];
        }
    };
    const buildPaymentTreasuryLabel = (account) => {
        const label = String(account?.label || account?.accountName || '').trim();
        const currency = String(account?.currency || '').trim();
        return currency !== '' ? `${label} (${currency})` : label;
    };
    const openTreasuryAccountSetup = (method = '') => {
        if (treasuryAccountsUrl === '') {
            return false;
        }

        persistWorkspaceStateForTreasuryReturn();

        const normalizedMethod = String(method || paymentMethodSelect?.value || '').trim();
        const branchField = paymentForm?.elements?.namedItem('branch_id');
        const branchId = branchField instanceof HTMLInputElement || branchField instanceof HTMLSelectElement
            ? String(branchField.value || '').trim()
            : '';
        const currency = String(paymentCurrencySelect?.value || '').trim().toUpperCase();
        const params = new URLSearchParams();
        const returnUrl = workspaceReturnUrlForTreasury();
        params.set('return_to', returnUrl ? returnUrl.toString() : window.location.href);

        if (branchId !== '') {
            params.set('branch_id', branchId);
        }

        if (currency !== '') {
            params.set('currency', currency);
        }

        if (normalizedMethod === 'bank_transfer') {
            params.set('account_type', 'bank');
        } else if (normalizedMethod === 'cash') {
            params.set('account_type', 'cash');
        }

        window.open(`${treasuryAccountsUrl}?${params.toString()}`, 'travel_ops_treasury_setup');
        return true;
    };
    const eligiblePaymentTreasuryAccounts = () => {
        const method = String(paymentMethodSelect?.value || '').trim();
        const currency = String(paymentCurrencySelect?.value || '').trim().toUpperCase();
        const branchField = paymentForm?.elements?.namedItem('branch_id');
        const branchId = branchField instanceof HTMLInputElement || branchField instanceof HTMLSelectElement
            ? Number.parseInt(String(branchField.value || '0'), 10) || 0
            : 0;
        const compatibleTypes = paymentTreasuryTypesForMethod(method);

        return paymentTreasuryAccounts.filter((account) => {
            return (branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === currency;
        });
    };
    const defaultPaymentTreasuryAccount = (accounts) => {
        const eligible = Array.isArray(accounts) ? accounts : [];
        const explicitDefault = eligible.find((account) => account && account.isDefault);
        if (explicitDefault) {
            return explicitDefault;
        }

        return eligible.length > 0 ? eligible[0] : null;
    };
    const replacePaymentTreasuryAccounts = (accounts) => {
        paymentTreasuryAccounts = Array.isArray(accounts) ? accounts : [];
        if (paymentTreasuryAccountsNode) {
            paymentTreasuryAccountsNode.textContent = JSON.stringify(paymentTreasuryAccounts);
        }
    };
    const refreshTreasurySelectors = () => {
        if (typeof syncPaymentTreasurySelector === 'function') {
            syncPaymentTreasurySelector();
        }
        if (typeof syncRefundTreasurySelector === 'function') {
            syncRefundTreasurySelector();
        }
        if (typeof syncSupplierRefundTreasurySelector === 'function') {
            syncSupplierRefundTreasurySelector();
        }
        if (typeof syncCorrectionRefundTreasurySelector === 'function') {
            syncCorrectionRefundTreasurySelector();
        }
        if (typeof syncCorrectionSupplierRefundTreasurySelector === 'function') {
            syncCorrectionSupplierRefundTreasurySelector();
        }
        if (typeof syncSupplierTreasurySelectors === 'function') {
            syncSupplierTreasurySelectors();
        }
    };
    const refreshPaymentTreasuryAccountsFromServer = async (options = {}) => {
        if (paymentTreasuryAccountsUrl === '') {
            return paymentTreasuryAccounts;
        }

        if (paymentTreasuryRefreshInFlight) {
            return paymentTreasuryRefreshInFlight;
        }

        const clearPendingState = options.clearPendingState === true;
        const announceRefresh = options.announceRefresh === true;
        const previousSnapshot = JSON.stringify(paymentTreasuryAccounts);
        paymentTreasuryRefreshInFlight = fetch(paymentTreasuryAccountsUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Treasury account refresh failed with status ${response.status}`);
                }

                const payload = await response.json();
                const accounts = Array.isArray(payload?.accounts) ? payload.accounts : [];
                replacePaymentTreasuryAccounts(accounts);
                refreshTreasurySelectors();

                if (announceRefresh) {
                    const refreshedSnapshot = JSON.stringify(paymentTreasuryAccounts);
                    const eligibleCount = eligiblePaymentTreasuryAccounts().length;
                    if (refreshedSnapshot !== previousSnapshot) {
                        showFeedback(eligibleCount > 0
                            ? 'Treasury accounts refreshed. The latest eligible account is now available.'
                            : 'Treasury accounts refreshed.');
                    } else {
                        showFeedback('Treasury accounts checked. No new eligible account matched this branch and currency yet.');
                    }
                }

                if (clearPendingState) {
                    try {
                        window.localStorage.removeItem(pendingTreasuryWorkspaceStateKey);
                    } catch (error) {
                        // ignore local storage cleanup errors
                    }
                }

                return paymentTreasuryAccounts;
            })
            .catch((error) => {
                if (typeof console !== 'undefined' && typeof console.warn === 'function') {
                    console.warn('Unable to refresh treasury accounts after setup return.', error);
                }

                return paymentTreasuryAccounts;
            })
            .finally(() => {
                paymentTreasuryRefreshInFlight = null;
            });

        return paymentTreasuryRefreshInFlight;
    };
    const directSupplierPendingOptionValue = '__current_service__';
    let directSupplierOpenObligations = [];

    const normalizeDirectSupplierObligation = (row = {}) => {
        const id = Number.parseInt(String(row?.id || 0), 10) || 0;
        const supplier = String(row?.supplier || row?.supplier_name || 'Supplier').trim() || 'Supplier';
        const serviceLineReference = String(row?.serviceLineReference || row?.service_line_reference || '').trim();
        const currency = String(row?.currency || '').trim().toUpperCase();
        const balance = Math.max(
            toNumber(row?.balanceDueAmount ?? row?.netPayableAmount ?? row?.net_payable_amount ?? row?.balance ?? 0),
            0
        );

        return { id, supplier, serviceLineReference, currency, balance };
    };

    const directSupplierOptionsFromSelect = () => {
        if (!directSupplierObligationSelect) {
            return [];
        }

        return Array.from(directSupplierObligationSelect.options || [])
            .map((option) => ({
                id: Number.parseInt(String(option.value || '0'), 10) || 0,
                supplier: String(option.textContent || 'Supplier').split('/')[0]?.trim() || 'Supplier',
                serviceLineReference: '',
                currency: String(option.dataset.currency || '').trim().toUpperCase(),
                balance: toNumber(option.dataset.balance || 0),
            }))
            .filter((row) => row.id > 0);
    };

    directSupplierOpenObligations = directSupplierOptionsFromSelect();

    const updateDirectSupplierServiceLineReference = (payload = {}) => {
        if (!directSupplierServiceLineReferenceInput) {
            return;
        }

        const payloadLine = String(
            payload?.service_line?.serviceLineReference
            || payload?.service_line?.lineNumber
            || payload?.service_line_reference
            || ''
        ).trim();
        if (payloadLine !== '') {
            directSupplierServiceLineReferenceInput.value = payloadLine;
        }
    };

    const syncDirectSupplierObligationOptions = (payload = {}) => {
        if (!directSupplierObligationSelect) {
            return;
        }

        updateDirectSupplierServiceLineReference(payload);

        const payloadObligations = payload?.supplier_foundation?.openObligations;
        if (Array.isArray(payloadObligations)) {
            directSupplierOpenObligations = payloadObligations
                .map(normalizeDirectSupplierObligation)
                .filter((row) => row.id > 0 && row.balance > 0.005);
        }

        const selectedBefore = String(directSupplierObligationSelect.value || '').trim();
        const paymentCurrency = String(paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR').trim().toUpperCase();
        const eligible = directSupplierOpenObligations.filter((row) => {
            return paymentCurrency === '' || row.currency === paymentCurrency;
        });

        directSupplierObligationSelect.innerHTML = '';
        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = eligible.length > 0 ? 'Select supplier payable' : 'No supplier payable saved yet';
        directSupplierObligationSelect.appendChild(promptOption);

        eligible.forEach((row) => {
            const option = document.createElement('option');
            option.value = String(row.id);
            option.dataset.currency = row.currency;
            option.dataset.balance = String(row.balance);
            option.dataset.serviceLineReference = row.serviceLineReference;
            option.textContent = [
                row.supplier,
                row.serviceLineReference,
                `${row.currency || paymentCurrency || 'PKR'} ${formatMoney(row.balance)}`,
            ].filter(Boolean).join(' / ');
            directSupplierObligationSelect.appendChild(option);
        });

        const currentServiceLineReference = String(directSupplierServiceLineReferenceInput?.value || '').trim();
        let nextValue = '';
        if (selectedBefore !== '' && eligible.some((row) => String(row.id) === selectedBefore)) {
            nextValue = selectedBefore;
        } else if (currentServiceLineReference !== '') {
            const matchingServiceObligation = eligible.find((row) => row.serviceLineReference === currentServiceLineReference);
            if (matchingServiceObligation) {
                nextValue = String(matchingServiceObligation.id);
            }
        }
        if (nextValue === '' && eligible.length === 1) {
            nextValue = String(eligible[0].id);
        }

        if (nextValue === '' && isDirectSupplierPaymentMethod(paymentMethodSelect?.value || '')) {
            const supplierName = String(supplierInput?.value || '').trim();
            const draftBalance = Math.max(
                toNumber(paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance || paymentCurrentBalanceInput?.value || 0),
                toNumber(paymentCurrentInvoiceInput?.dataset.paymentCurrentInvoice || paymentCurrentInvoiceInput?.value || 0),
                0
            );
            if (supplierName !== '' && supplierName !== addSupplierOptionValue && draftBalance > 0.005) {
                const pendingOption = document.createElement('option');
                pendingOption.value = directSupplierPendingOptionValue;
                pendingOption.dataset.currency = paymentCurrency;
                pendingOption.dataset.balance = String(draftBalance);
                pendingOption.textContent = `${supplierName} / current invoice / ${paymentCurrency || 'PKR'} ${formatMoney(draftBalance)}`;
                directSupplierObligationSelect.appendChild(pendingOption);
                nextValue = directSupplierPendingOptionValue;
            }
        }

        directSupplierObligationSelect.value = nextValue;
    };

    const syncPaymentTreasurySelector = () => {
        if (!paymentTreasuryAccountSelect || !paymentTreasuryAccountRow || !paymentMethodSelect) {
            return;
        }

        const method = String(paymentMethodSelect.value || '').trim();
        const isDirectSupplierPayment = isDirectSupplierPaymentMethod(method);
        if (directSupplierPaymentRow && directSupplierObligationSelect) {
            syncDirectSupplierObligationOptions();
            directSupplierPaymentRow.hidden = !isDirectSupplierPayment;
            directSupplierObligationSelect.disabled = !isDirectSupplierPayment;
            if (!isDirectSupplierPayment) {
                directSupplierObligationSelect.value = '';
            }
        }
        const requiresTreasury = paymentMethodRequiresTreasurySelection(method);
        const eligibleAccounts = requiresTreasury ? eligiblePaymentTreasuryAccounts() : [];
        const selectedBefore = String(paymentTreasuryAccountSelect.value || paymentTreasuryAccountSelect.dataset.initialValue || '').trim();
        const preferredAccount = defaultPaymentTreasuryAccount(eligibleAccounts);

        paymentTreasuryAccountSelect.innerHTML = '';

        if (!requiresTreasury) {
            paymentTreasuryAccountSelect.dataset.initialValue = '';
            paymentTreasuryAccountRow.hidden = true;
            return;
        }

        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = eligibleAccounts.length > 0
            ? (method === 'cash' ? 'Select cash account' : 'Select bank account')
            : 'No eligible account configured';
        paymentTreasuryAccountSelect.appendChild(promptOption);

        eligibleAccounts.forEach((account) => {
            const option = document.createElement('option');
            option.value = String(account.id || '');
            option.textContent = buildPaymentTreasuryLabel(account);
            paymentTreasuryAccountSelect.appendChild(option);
        });

        if (method === 'bank_transfer') {
            const addOption = document.createElement('option');
            addOption.value = addPaymentTreasuryAccountValue;
            addOption.textContent = '+ Add bank account...';
            paymentTreasuryAccountSelect.appendChild(addOption);
        }

        let nextValue = '';
        if (selectedBefore !== '' && eligibleAccounts.some((account) => String(account.id) === selectedBefore)) {
            nextValue = selectedBefore;
        } else if (preferredAccount) {
            nextValue = String(preferredAccount.id || '');
        }
        paymentTreasuryAccountSelect.value = nextValue;
        paymentTreasuryAccountSelect.dataset.initialValue = '';

        paymentTreasuryAccountRow.hidden = false;
    };
    const promptTreasuryAccountSetupIfMissing = (method) => {
        const normalizedMethod = String(method || '').trim();
        if (!paymentMethodRequiresTreasurySelection(normalizedMethod)) {
            return false;
        }

        if (
            paymentTreasuryAccountSelect
            && paymentTreasuryAccountRow
            && !paymentTreasuryAccountRow.hidden
            && !paymentTreasuryAccountSelect.disabled
        ) {
            const selectedValue = String(paymentTreasuryAccountSelect.value || '').trim();
            if (selectedValue !== '' && selectedValue !== addPaymentTreasuryAccountValue) {
                const selectedOptionExists = Array.from(paymentTreasuryAccountSelect.options || []).some((option) => {
                    return String(option?.value || '').trim() === selectedValue;
                });
                if (selectedOptionExists) {
                    return false;
                }
            }
        }

        const eligibleAccounts = eligiblePaymentTreasuryAccounts();
        if (eligibleAccounts.length > 0) {
            return false;
        }

        const accountLabel = normalizedMethod === 'bank_transfer' ? 'bank account' : 'cash account';
        const message = `No eligible ${accountLabel} is configured for this branch and currency. Open Treasury Accounts now to add it?`;
        showFeedback(`Please configure a ${accountLabel} for this branch and currency.`);

        if (window.confirm(message)) {
            openTreasuryAccountSetup(normalizedMethod);
        }

        return true;
    };
    const promptDirectSupplierPayableIfMissing = () => {
        if (!paymentMethodSelect || !isDirectSupplierPaymentMethod(paymentMethodSelect.value)) {
            return false;
        }

        if (directSupplierObligationSelect && String(directSupplierObligationSelect.value || '').trim() !== '') {
            return false;
        }

        showFeedback('Select the supplier payable that the customer paid directly.');
        if (directSupplierObligationSelect) {
            directSupplierObligationSelect.focus();
        }

        return true;
    };
    const supplierTreasuryContext = (form) => {
        const methodField = form?.elements?.namedItem('supplier_payment_method');
        const currencyField = form?.elements?.namedItem('supplier_payment_currency');
        const branchField = form?.elements?.namedItem('branch_id');

        return {
            method: methodField instanceof HTMLSelectElement || methodField instanceof HTMLInputElement
                ? String(methodField.value || '').trim()
                : '',
            currency: currencyField instanceof HTMLSelectElement || currencyField instanceof HTMLInputElement
                ? String(currencyField.value || '').trim().toUpperCase()
                : '',
            branchId: branchField instanceof HTMLSelectElement || branchField instanceof HTMLInputElement
                ? Number.parseInt(String(branchField.value || '0'), 10) || 0
                : 0,
        };
    };
    const eligibleSupplierTreasuryAccounts = (form) => {
        const context = supplierTreasuryContext(form);
        const compatibleTypes = paymentTreasuryTypesForMethod(context.method);

        return paymentTreasuryAccounts.filter((account) => {
            return (context.branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === context.branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === context.currency;
        });
    };
    const syncSupplierTreasurySelector = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const form = select.form;
        const row = select.closest('[data-supplier-treasury-row]');
        const context = supplierTreasuryContext(form);
        const requiresTreasury = paymentMethodRequiresTreasurySelection(context.method);
        const eligibleAccounts = requiresTreasury ? eligibleSupplierTreasuryAccounts(form) : [];
        const selectedBefore = String(select.value || '').trim();
        const preferredAccount = defaultPaymentTreasuryAccount(eligibleAccounts);

        select.innerHTML = '';

        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = eligibleAccounts.length > 0
            ? (context.method === 'cash' ? 'Select cash account' : 'Select bank account')
            : 'No eligible source account configured';
        select.appendChild(promptOption);

        eligibleAccounts.forEach((account) => {
            const option = document.createElement('option');
            option.value = String(account.id || '');
            option.textContent = buildPaymentTreasuryLabel(account);
            select.appendChild(option);
        });

        let nextValue = '';
        if (selectedBefore !== '' && eligibleAccounts.some((account) => String(account.id || '') === selectedBefore)) {
            nextValue = selectedBefore;
        } else if (preferredAccount) {
            nextValue = String(preferredAccount.id || '');
        }
        select.value = nextValue;
        select.disabled = !requiresTreasury;
        if (row instanceof HTMLElement) {
            row.hidden = !requiresTreasury;
        }
    };
    const syncSupplierTreasurySelectors = () => {
        supplierTreasurySelects.forEach(syncSupplierTreasurySelector);
    };
    const ensureSupplierTreasuryReady = (form) => {
        const context = supplierTreasuryContext(form);
        if (!paymentMethodRequiresTreasurySelection(context.method)) {
            return true;
        }

        const select = form?.elements?.namedItem('supplier_treasury_account_id');
        if (select instanceof HTMLSelectElement && String(select.value || '').trim() !== '') {
            return true;
        }

        const accountLabel = context.method === 'cash' ? 'cash account' : 'bank account';
        showFeedback(`Select a supplier payment source ${accountLabel} before saving.`);
        if (select instanceof HTMLSelectElement) {
            select.focus();
        }

        return false;
    };
    const handleTreasurySetupReturn = () => {
        if (!hasPendingTreasuryWorkspaceStateForCurrentRoute()) {
            return;
        }

        refreshPaymentTreasuryAccountsFromServer({
            clearPendingState: true,
            announceRefresh: true,
        }).then(() => {
            refreshPaymentPreview();
        }).catch(() => {
            // Best-effort only.
        });
    };
    if (paymentTreasuryAccountSelect) {
        paymentTreasuryAccountSelect.addEventListener('change', () => {
            if (paymentTreasuryAccountSelect.value !== addPaymentTreasuryAccountValue) {
                return;
            }

            paymentTreasuryAccountSelect.value = '';
            openTreasuryAccountSetup(paymentMethodSelect?.value || '');
        });
        paymentTreasuryAccountSelect.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (paymentTreasuryAccountSelect.value === addPaymentTreasuryAccountValue) {
                paymentTreasuryAccountSelect.value = '';
                openTreasuryAccountSetup(paymentMethodSelect?.value || '');
                return;
            }

            if (paymentPrimarySaveButton instanceof HTMLButtonElement) {
                paymentPrimarySaveButton.focus();
                window.setTimeout(() => {
                    paymentPrimarySaveButton.click();
                }, 60);
            }
        });
    }

    const clearPaymentDetailHiddenFields = () => {
        if (paymentReferenceInput) {
            paymentReferenceInput.value = '';
        }
        if (paymentBankCardDetailInput) {
            paymentBankCardDetailInput.value = '';
        }
        if (paymentChargesAmountInput) {
            paymentChargesAmountInput.value = '0';
        }
        if (paymentRemarksInput) {
            paymentRemarksInput.value = '';
        }
    };

    const fillPaymentDetailModalFromHidden = () => {
        if (paymentDetailReferenceInput) {
            paymentDetailReferenceInput.value = paymentReferenceInput?.value || '';
        }
        if (paymentDetailBankCardInput) {
            paymentDetailBankCardInput.value = paymentBankCardDetailInput?.value || '';
        }
        if (paymentDetailChargesInput) {
            paymentDetailChargesInput.value = paymentChargesAmountInput?.value || '0';
        }
        if (paymentDetailRemarksInput) {
            paymentDetailRemarksInput.value = paymentRemarksInput?.value || '';
        }
    };

    const openPaymentDetailModal = () => {
        if (!paymentDetailModal) {
            return false;
        }

        if (paymentDetailMethodLabel) {
            paymentDetailMethodLabel.textContent = `Record reference details for ${currentPaymentMethodLabel()}.`;
        }

        fillPaymentDetailModalFromHidden();
        paymentDetailModal.hidden = false;
        paymentDetailModal.setAttribute('aria-hidden', 'false');

        window.setTimeout(() => {
            paymentDetailReferenceInput?.focus();
            paymentDetailReferenceInput?.select();
        }, 60);

        return true;
    };

    let paymentDetailCloseFocusMode = 'method';
    const focusAfterPaymentDetailModalClose = () => {
        const treasuryVisible = paymentTreasuryAccountSelect
            && paymentTreasuryAccountRow
            && !paymentTreasuryAccountRow.hidden
            && !paymentTreasuryAccountSelect.disabled;

        const nextTarget = treasuryVisible
            ? paymentTreasuryAccountSelect
            : paymentPrimarySaveButton;

        if (nextTarget instanceof HTMLElement) {
            nextTarget.focus();
            if (typeof nextTarget.select === 'function' && nextTarget.tagName.toLowerCase() === 'input') {
                nextTarget.select();
            }
        }
    };

    const applyPaymentDetailModalValues = ({ close = true } = {}) => {
        if (paymentReferenceInput) {
            paymentReferenceInput.value = String(paymentDetailReferenceInput?.value || '').trim();
        }
        if (paymentBankCardDetailInput) {
            paymentBankCardDetailInput.value = String(paymentDetailBankCardInput?.value || '').trim();
        }
        if (paymentChargesAmountInput) {
            paymentChargesAmountInput.value = formatNumberInputValue(Math.max(toNumber(paymentDetailChargesInput?.value || 0), 0));
        }
        if (paymentRemarksInput) {
            paymentRemarksInput.value = String(paymentDetailRemarksInput?.value || '').trim();
        }

        if (close) {
            closePaymentDetailModal();
        }
        showFeedback(`${currentPaymentMethodLabel()} details applied.`);
    };

    const hasPaymentDetailMetadata = () => {
        return String(paymentReferenceInput?.value || '').trim() !== ''
            || String(paymentBankCardDetailInput?.value || '').trim() !== ''
            || String(paymentRemarksInput?.value || '').trim() !== ''
            || Math.max(toNumber(paymentChargesAmountInput?.value || 0), 0) > 0.005;
    };

    const ensurePaymentDetailReadyForSave = () => {
        if (!paymentMethodSelect || !isNonCashPaymentMethod(paymentMethodSelect.value)) {
            return true;
        }

        if (hasPaymentDetailMetadata()) {
            return true;
        }

        openPaymentDetailModal();
        showFeedback(`Enter ${currentPaymentMethodLabel()} details before saving payment.`);
        return false;
    };
    let serviceSupplierOptions = [];
    try {
        serviceSupplierOptions = JSON.parse(supplierOptionsNode?.textContent || '[]');
    } catch (error) {
        serviceSupplierOptions = [];
    }
    let businessSourceOptions = [];
    try {
        businessSourceOptions = JSON.parse(businessSourceOptionsNode?.textContent || '[]');
    } catch (error) {
        businessSourceOptions = [];
    }
    let workspaceBranchOptions = [];
    try {
        workspaceBranchOptions = JSON.parse(branchOptionsNode?.textContent || '[]');
    } catch (error) {
        workspaceBranchOptions = [];
    }
    const workspaceBranchById = (branchId) => {
        const normalizedBranchId = Number.parseInt(String(branchId || '0'), 10) || 0;
        return workspaceBranchOptions.find((branch) => Number.parseInt(String(branch?.id || '0'), 10) === normalizedBranchId) || null;
    };
    const workspaceBranchBaseCurrency = (branchId) => String(workspaceBranchById(branchId)?.baseCurrency || 'PKR').trim().toUpperCase() || 'PKR';

    const normalizeSupplierName = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
    const normalizeBusinessSourceName = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
    const supplierExists = (name) => {
        const normalized = normalizeSupplierName(name);
        return normalized !== '' && serviceSupplierOptions.some((supplier) => normalizeSupplierName(supplier?.name) === normalized);
    };
    const businessSourceExists = (name) => {
        const normalized = normalizeBusinessSourceName(name);
        return normalized !== '' && businessSourceOptions.some((row) => normalizeBusinessSourceName(row?.name) === normalized);
    };
    let supplierAddReturnContext = 'service';
    const addSupplierToSelect = (select, savedName) => {
        if (!(select instanceof HTMLSelectElement) || savedName === '') {
            return;
        }

        const alreadyExists = Array.from(select.options).some((option) => normalizeSupplierName(option.value) === normalizeSupplierName(savedName));
        if (alreadyExists) {
            return;
        }

        const selectOption = document.createElement('option');
        selectOption.value = savedName;
        selectOption.textContent = savedName;
        const addOption = Array.from(select.options).find((option) => option.value === addSupplierOptionValue);
        select.insertBefore(selectOption, addOption || null);
    };
    const addBusinessSourceToSelect = (select, savedAccount) => {
        if (!(select instanceof HTMLSelectElement) || !savedAccount) {
            return;
        }

        const savedId = String(savedAccount.id || '').trim();
        const savedName = String(savedAccount.name || '').trim();
        if (savedId === '' || savedName === '') {
            return;
        }

        const alreadyExists = Array.from(select.options).some((option) => option.value === savedId);
        if (alreadyExists) {
            return;
        }

        const option = document.createElement('option');
        option.value = savedId;
        option.textContent = savedName;
        const addOption = Array.from(select.options).find((row) => row.value === addBusinessSourceOptionValue);
        select.insertBefore(option, addOption || null);
    };
    const closeBusinessSourceAddModal = () => {
        if (!businessSourceAddModal) {
            return;
        }

        businessSourceAddModal.hidden = true;
        businessSourceAddModal.setAttribute('aria-hidden', 'true');
        if (businessSourceAddFeedback) {
            businessSourceAddFeedback.hidden = true;
            businessSourceAddFeedback.textContent = '';
        }
    };
    const openBusinessSourceAddModal = (name = '') => {
        if (!businessSourceAddModal || !businessSourceAddForm || !businessSourceRegisterUrl) {
            return false;
        }

        if (businessSourceAddName) {
            businessSourceAddName.value = String(name || businessSourceInput?.selectedOptions?.[0]?.textContent || '').trim();
        }
        if (businessSourceAddPhone instanceof HTMLInputElement) {
            businessSourceAddPhone.value = '';
        }
        if (businessSourceAddAddress instanceof HTMLInputElement) {
            businessSourceAddAddress.value = '';
        }
        if (businessSourceAddDescription instanceof HTMLInputElement) {
            businessSourceAddDescription.value = '';
        }

        businessSourceAddModal.hidden = false;
        businessSourceAddModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            businessSourceAddName?.focus();
            businessSourceAddName?.select();
        }, 60);

        return true;
    };
    const focusSupplierAddReturnTarget = () => {
        if (supplierAddReturnContext === 'global-prepaid') {
            const focusTarget = globalPrepaidSupplierField instanceof HTMLSelectElement && globalPrepaidSupplierField.value !== ''
                ? globalPrepaidAmountField
                : globalPrepaidSupplierField;
            if (focusTarget instanceof HTMLElement) {
                focusTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                window.setTimeout(() => {
                    focusTarget.focus();
                    if (focusTarget instanceof HTMLInputElement) {
                        focusTarget.select();
                    }
                }, 80);
            }
            return;
        }

        supplierInput?.focus();
    };
    const closeSupplierAddModal = () => {
        if (!supplierAddModal) {
            return;
        }

        supplierAddModal.hidden = true;
        supplierAddModal.setAttribute('aria-hidden', 'true');
        if (supplierAddFeedback) {
            supplierAddFeedback.hidden = true;
            supplierAddFeedback.textContent = '';
        }
    };
    const focusTicketTypeAfterSupplier = () => {
        const nextField = station.querySelector('select[name="ticket_type"]');
        if (nextField instanceof HTMLElement) {
            nextField.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            window.setTimeout(() => nextField.focus(), 80);
        }
    };
    businessSourceAddCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeBusinessSourceAddModal();
            if (businessSourceInput instanceof HTMLElement) {
                businessSourceInput.focus();
            }
        });
    });
    const businessSourceAddFieldOrder = [
        businessSourceAddName,
        businessSourceAddPhone,
        businessSourceAddAddress,
        businessSourceAddDescription,
    ].filter((field) => field instanceof HTMLElement);
    const focusBusinessSourceAddField = (field) => {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.focus();
        if (field instanceof HTMLInputElement) {
            field.select();
        }
    };
    const submitBusinessSourceAddFormFromKeyboard = () => {
        if (!(businessSourceAddForm instanceof HTMLFormElement) || !businessSourceAddSubmit || businessSourceAddSubmit.disabled) {
            return;
        }

        if (typeof businessSourceAddForm.requestSubmit === 'function') {
            businessSourceAddForm.requestSubmit(businessSourceAddSubmit);
            return;
        }

        businessSourceAddSubmit.click();
    };
    if (businessSourceAddForm) {
        businessSourceAddForm.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            const currentIndex = businessSourceAddFieldOrder.indexOf(target);
            const nextField = businessSourceAddFieldOrder[currentIndex + 1] || businessSourceAddSubmit;
            if (currentIndex === businessSourceAddFieldOrder.length - 1) {
                submitBusinessSourceAddFormFromKeyboard();
                return;
            }

            focusBusinessSourceAddField(nextField);
        }, true);
        businessSourceAddForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!businessSourceRegisterUrl) {
                return;
            }

            const accountName = String(businessSourceAddName?.value || '').trim();
            if (accountName === '') {
                if (businessSourceAddFeedback) {
                    businessSourceAddFeedback.hidden = false;
                    businessSourceAddFeedback.textContent = 'Enter account name.';
                }
                businessSourceAddName?.focus();
                return;
            }

            if (businessSourceAddSubmit) {
                businessSourceAddSubmit.disabled = true;
            }

            try {
                const response = await fetch(businessSourceRegisterUrl, {
                    method: 'POST',
                    body: new FormData(businessSourceAddForm),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.ok === false) {
                    throw new Error(payload.message || 'Account could not be saved.');
                }

                const account = payload.account || {};
                const savedName = String(account.name || accountName).trim();
                if (!businessSourceExists(savedName)) {
                    businessSourceOptions.push(account);
                }
                addBusinessSourceToSelect(businessSourceInput, account);

                if (businessSourceInput instanceof HTMLSelectElement) {
                    businessSourceInput.value = String(account.id || '');
                    businessSourceInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                closeBusinessSourceAddModal();
                showFeedback(payload.message || 'Account added.');
            } catch (error) {
                if (businessSourceAddFeedback) {
                    businessSourceAddFeedback.hidden = false;
                    businessSourceAddFeedback.textContent = error.message || 'Account could not be saved.';
                } else {
                    showFeedback(error.message || 'Account could not be saved.');
                }
            } finally {
                if (businessSourceAddSubmit) {
                    businessSourceAddSubmit.disabled = false;
                }
            }
        });
    }
    const openNativeSelect = (select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (typeof select.showPicker === 'function') {
            try {
                select.showPicker();
            } catch (error) {
                // Some browsers only allow showPicker from direct user actions.
            }
        }
    };
    const openSupplierAddModal = (name = '', context = 'service') => {
        if (!supplierAddModal || !supplierAddForm || !supplierRegisterUrl) {
            return false;
        }

        supplierAddReturnContext = context === 'global-prepaid' ? 'global-prepaid' : 'service';
        const sourceField = supplierAddReturnContext === 'global-prepaid' ? globalPrepaidSupplierField : supplierInput;
        if (supplierAddName) {
            supplierAddName.value = String(name || sourceField?.value || '').trim();
        }

        const bookingBranch = supplierAddReturnContext === 'global-prepaid'
            ? String(globalPrepaidBranchField?.value || '')
            : serviceForm?.elements?.namedItem('auto_branch_id')?.value || invoiceForm?.elements?.namedItem('branch_id')?.value || '';
        if (supplierAddBranch && bookingBranch !== '') {
            supplierAddBranch.value = bookingBranch;
        }

        const currencyField = station.querySelector('[data-service-field="currency-mirror"]');
        const supplierCurrency = supplierAddReturnContext === 'global-prepaid'
            ? String(globalPrepaidCurrencyField?.value || '')
            : String(currencyField?.value || '');
        if (supplierAddCurrency && supplierCurrency !== '') {
            supplierAddCurrency.value = supplierCurrency;
        }

        supplierAddModal.hidden = false;
        supplierAddModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            supplierAddName?.focus();
            supplierAddName?.select();
        }, 60);

        return true;
    };
    window.workspaceMaybeOpenSupplierAddModal = (field) => {
        const value = String(field?.value || '').trim();
        if (value === addSupplierOptionValue) {
            return openSupplierAddModal('');
        }

        if (value === '' || supplierExists(value)) {
            return false;
        }

        return openSupplierAddModal(value);
    };
    window.workspaceFocusSupplierDropdown = () => {
        if (!(supplierInput instanceof HTMLSelectElement)) {
            return false;
        }

        supplierInput.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        supplierInput.focus();
        openNativeSelect(supplierInput);
        return true;
    };

    supplierAddCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closeSupplierAddModal();
            focusSupplierAddReturnTarget();
        });
    });

    const supplierAddFieldOrder = [
        supplierAddName,
        supplierAddBranch,
        supplierAddCurrency,
        supplierAddMode,
        supplierAddNotes,
    ].filter((field) => field instanceof HTMLElement);

    const focusSupplierAddField = (field) => {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.focus();
        if (field instanceof HTMLInputElement) {
            field.select();
        }
    };

    const submitSupplierAddFormFromKeyboard = () => {
        if (!(supplierAddForm instanceof HTMLFormElement) || !supplierAddSubmit || supplierAddSubmit.disabled) {
            return;
        }

        if (typeof supplierAddForm.requestSubmit === 'function') {
            supplierAddForm.requestSubmit(supplierAddSubmit);
            return;
        }

        supplierAddSubmit.click();
    };

    const handleSupplierAddFormEnter = (event) => {
        if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const currentIndex = supplierAddFieldOrder.indexOf(target);
        const isLastField = target === supplierAddNotes || currentIndex === supplierAddFieldOrder.length - 1;

        if (isLastField) {
            submitSupplierAddFormFromKeyboard();
            return;
        }

        const nextField = supplierAddFieldOrder[currentIndex + 1] || supplierAddNotes || supplierAddSubmit;
        focusSupplierAddField(nextField);
    };

    if (supplierAddForm) {
        supplierAddForm.addEventListener('keydown', handleSupplierAddFormEnter, true);
    }

    supplierAddForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!supplierRegisterUrl) {
            return;
        }

        const supplierName = String(supplierAddName?.value || '').trim();
        if (supplierName === '') {
            if (supplierAddFeedback) {
                supplierAddFeedback.hidden = false;
                supplierAddFeedback.textContent = 'Enter supplier name.';
            }
            supplierAddName?.focus();
            return;
        }

        if (supplierAddSubmit) {
            supplierAddSubmit.disabled = true;
        }

        try {
            const response = await fetch(supplierRegisterUrl, {
                method: 'POST',
                body: new FormData(supplierAddForm),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'Supplier could not be saved.');
            }

            const supplier = payload.supplier || {};
            const savedName = String(supplier.name || supplierName).trim();
            const supplierMode = String(supplier.supplier_mode || supplierAddMode?.value || '').trim();
            if (savedName !== '' && !supplierExists(savedName)) {
                serviceSupplierOptions.push(supplier);
                addSupplierToSelect(supplierInput, savedName);
                addSupplierToSelect(globalPrepaidSupplierField, savedName);
                if (supplierOptionsList) {
                    const option = document.createElement('option');
                    option.value = savedName;
                    supplierOptionsList.appendChild(option);
                }
            }

            const targetSupplierField = supplierAddReturnContext === 'global-prepaid' ? globalPrepaidSupplierField : supplierInput;
            if (targetSupplierField) {
                targetSupplierField.value = savedName;
                targetSupplierField.dispatchEvent(new Event('input', { bubbles: true }));
                targetSupplierField.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const returnContext = supplierAddReturnContext;
            closeSupplierAddModal();
            showFeedback(payload.message || 'Supplier added.');
            if (returnContext === 'service' && supplierMode === 'running_balance') {
                openGlobalPrepaidSupplierModal({
                    supplierName: savedName,
                    branchId: supplier.branch_id || supplierAddBranch?.value || '',
                    currency: supplier.default_currency || supplierAddCurrency?.value || '',
                    returnToTicketType: true,
                });
            } else {
                if (returnContext === 'global-prepaid') {
                    focusSupplierAddReturnTarget();
                } else {
                    focusTicketTypeAfterSupplier();
                }
            }
        } catch (error) {
            if (supplierAddFeedback) {
                supplierAddFeedback.hidden = false;
                supplierAddFeedback.textContent = error.message || 'Supplier could not be saved.';
            } else {
                showFeedback(error.message || 'Supplier could not be saved.');
            }
        } finally {
            if (supplierAddSubmit) {
                supplierAddSubmit.disabled = false;
            }
        }
    });

    supplierInput?.addEventListener('focus', () => {
        supplierInput.dataset.previousSupplierValue = supplierInput.value === addSupplierOptionValue ? '' : supplierInput.value;
        window.setTimeout(() => openNativeSelect(supplierInput), 0);
    });

    if (businessSourceInput instanceof HTMLSelectElement) {
        businessSourceInput.addEventListener('focus', () => {
            businessSourceInput.dataset.previousBusinessSourceValue = businessSourceInput.value === addBusinessSourceOptionValue
                ? ''
                : businessSourceInput.value;
            window.setTimeout(() => openNativeSelect(businessSourceInput), 0);
        });

        businessSourceInput.addEventListener('change', () => {
            window.setTimeout(() => {
                if (businessSourceAddModal?.hidden === false) {
                    return;
                }

                if (businessSourceInput.value === addBusinessSourceOptionValue) {
                    const previousValue = businessSourceInput.dataset.previousBusinessSourceValue || '';
                    businessSourceInput.value = previousValue;
                    openBusinessSourceAddModal('');
                    return;
                }

                businessSourceInput.dataset.previousBusinessSourceValue = businessSourceInput.value;
            }, 80);
        });
    }

    supplierInput?.addEventListener('change', () => {
        window.setTimeout(() => {
            if (!supplierInput || supplierAddModal?.hidden === false) {
                return;
            }

            if (supplierInput.value === addSupplierOptionValue) {
                const previousValue = supplierInput.dataset.previousSupplierValue || '';
                supplierInput.value = previousValue;
                openSupplierAddModal('');
                return;
            }

            supplierInput.dataset.previousSupplierValue = supplierInput.value;
            window.workspaceMaybeOpenSupplierAddModal(supplierInput);
        }, 80);
    });

    if (globalPrepaidSupplierField instanceof HTMLSelectElement) {
        globalPrepaidSupplierField.addEventListener('focus', () => {
            globalPrepaidSupplierField.dataset.previousSupplierValue = globalPrepaidSupplierField.value === addSupplierOptionValue
                ? ''
                : globalPrepaidSupplierField.value;
            window.setTimeout(() => openNativeSelect(globalPrepaidSupplierField), 0);
        });

        const handleGlobalPrepaidSupplierSelection = () => {
            if (supplierAddModal?.hidden === false) {
                return;
            }

            if (globalPrepaidSupplierField.value === addSupplierOptionValue) {
                const previousValue = globalPrepaidSupplierField.dataset.previousSupplierValue || '';
                globalPrepaidSupplierField.value = previousValue;
                openSupplierAddModal('', 'global-prepaid');
                return;
            }

            globalPrepaidSupplierField.dataset.previousSupplierValue = globalPrepaidSupplierField.value;
        };

        ['change', 'input', 'click', 'keyup'].forEach((eventName) => {
            globalPrepaidSupplierField.addEventListener(eventName, () => {
                window.setTimeout(handleGlobalPrepaidSupplierSelection, 0);
            });
        });
    }

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

    const syncCustomerCreditDisplay = (currentInvoiceCurrency, creditMap = null) => {
        if (!paymentCustomerCreditInput) {
            return;
        }

        const invoiceCurrency = String(currentInvoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR').toUpperCase();
        const resolvedCreditMap = creditMap && typeof creditMap === 'object' && !Array.isArray(creditMap)
            ? creditMap
            : parseBalanceMap(paymentCustomerCreditInput.dataset.paymentCustomerCreditMap || '{}');
        const sameCurrencyCredit = Math.max(toNumber(resolvedCreditMap[invoiceCurrency] || 0), 0);

        paymentCustomerCreditInput.dataset.paymentCustomerCredit = String(sameCurrencyCredit);
        paymentCustomerCreditInput.dataset.paymentCustomerCreditMap = JSON.stringify(resolvedCreditMap);
        paymentCustomerCreditInput.value = formatCurrencyAmount(invoiceCurrency, sameCurrencyCredit);

        if (paymentCustomerCreditRow) {
            paymentCustomerCreditRow.hidden = sameCurrencyCredit <= 0.005;
        }
    };

    const roundToTwo = (value) => roundMoneyValue(value);
    const roundExchangeRate = (value) => Math.round(toNumber(value || 0) * 100000000) / 100000000;

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
        const normalizedCurrency = currentInvoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const normalizedInvoiceBalance = Math.max(toNumber(currentInvoiceBalance), 0);
        const hasFullOpenBalanceMap = Object.keys(fullOpenBalanceMap).length > 0;
        const openBalanceMap = {
            ...(hasFullOpenBalanceMap ? fullOpenBalanceMap : previousBalanceMap),
        };

        // A full server balance already contains the persisted current invoice. Remove that
        // persisted portion before adding the live editor value, otherwise the total becomes stale.
        if (hasFullOpenBalanceMap) {
            const persistedCurrentBalance = Math.max(toNumber(
                paymentCurrentBalanceInput?.dataset.paymentSavedInvoiceBalance
                || paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance
                || 0
            ), 0);
            openBalanceMap[normalizedCurrency] = roundToTwo(Math.max(
                toNumber(openBalanceMap[normalizedCurrency] || 0) - persistedCurrentBalance,
                0
            ));
        }

        if (normalizedInvoiceBalance > 0.005) {
            openBalanceMap[normalizedCurrency] = roundToTwo(
                toNumber(openBalanceMap[normalizedCurrency] || 0) + normalizedInvoiceBalance
            );
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

        if (paymentPassengerBalanceList instanceof HTMLElement) {
            const passengerRows = currentBookingPassengerDueRows();
            paymentPassengerBalanceList.innerHTML = '';

            passengerRows.forEach((row, index) => {
                const item = document.createElement('div');
                item.className = `legacy-payment-passenger-item${index === 0 ? ' is-lead' : ''}`;

                const top = document.createElement('div');
                top.className = 'legacy-payment-passenger-item__top';
                const name = document.createElement('strong');
                name.textContent = String(row?.passengerName || '').trim() || 'Passenger';
                const relation = document.createElement('span');
                relation.textContent = index === 0 ? 'Self / Lead Traveler' : 'Passenger';
                top.append(name, relation);

                const meta = document.createElement('div');
                meta.className = 'legacy-payment-passenger-item__meta';
                const paid = document.createElement('span');
                paid.textContent = `Paid: ${formatCurrencyAmount(String(row?.currency || 'PKR'), Math.max(toNumber(row?.allocatedAmount || 0), 0))}`;
                const outstanding = document.createElement('span');
                outstanding.textContent = `Outstanding: ${formatCurrencyAmount(String(row?.currency || 'PKR'), Math.max(toNumber(row?.outstandingAmount || 0), 0))}`;
                meta.append(paid, outstanding);

                item.append(top, meta);
                paymentPassengerBalanceList.appendChild(item);
            });

            paymentPassengerBalanceList.hidden = passengerRows.length === 0;
            if (paymentPassengerBalanceHeading instanceof HTMLElement) {
                paymentPassengerBalanceHeading.hidden = passengerRows.length === 0;
            }
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
        const liveQuote = preferredSettlementQuote(
            String(displayCurrency).toUpperCase(),
            'PKR',
            dailySettlementRates || {},
            normalizeLooseDate(paymentReceiptDateInput?.value || '')
        );
        const conversionQuote = {
            ...liveQuote,
            targetCurrency: String(displayCurrency).toUpperCase(),
            paymentCurrency: 'PKR',
        };
        let candidateRate = 0;
        if (toNumber(liveQuote?.exchangeRate || 0) > 0.005) {
            candidateRate = convertTargetAmountToPaymentAmount(1, conversionQuote);
        }
        if (candidateRate <= 0.005) {
            candidateRate = toNumber(paymentCurrentBalancePkrInput.dataset.paymentPkrRate || 0);
        }
        const shouldShow = displayCurrency !== 'PKR' && candidateRate > 0.005;

        paymentCurrentBalancePkrRow.hidden = !shouldShow;
        if (!shouldShow) {
            paymentCurrentBalancePkrInput.value = 'PKR 0';
            return;
        }

        const pkrEquivalent = Math.max(currentBalance, 0) * candidateRate;
        paymentCurrentBalancePkrInput.dataset.paymentPkrRate = String(candidateRate);
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
        if (paymentExchangePaymentAmountInput) {
            paymentExchangePaymentAmountInput.value = '';
        }
        if (paymentExchangePaymentAmountRow) {
            paymentExchangePaymentAmountRow.hidden = true;
        }
        paymentExchangeConfirmInFlight = false;
    };

    const updateExchangeConfirmAvailability = (preview = null) => {
        if (!paymentExchangeConfirmButton || paymentExchangeConfirmInFlight) {
            return;
        }

        const hasSettlementReady = Boolean(preview)
            && preview.paymentAmount > 0.005
            && preview.targetSettled > 0.005
            && preview.paymentConsumed > 0.005
            && (
                preview.target.currency === preview.quote.paymentCurrency
                || toNumber(preview.quote.exchangeRate || 0) > 0.005
            );
        const hasRateOnlySave = Boolean(preview)
            && preview.target.currency !== preview.quote.paymentCurrency
            && toNumber(preview.quote.exchangeRate || 0) > 0.005;

        paymentExchangeConfirmButton.disabled = !(hasSettlementReady || hasRateOnlySave);
        paymentExchangeConfirmButton.textContent = hasSettlementReady
            ? 'Confirm Settlement'
            : (hasRateOnlySave ? 'Save Rate' : 'Confirm Settlement');
    };

    const focusExchangeConfirmWhenReady = (preview = null) => {
        if (
            !preview
            || !paymentExchangeModal
            || paymentExchangeModal.hidden
            || !paymentExchangeConfirmButton
            || paymentExchangeConfirmButton.disabled
            || paymentExchangeConfirmFocusDone
        ) {
            return;
        }

        const activeElement = document.activeElement;
        const focusCameFromSettlementInput = activeElement === receivedNowInput;
        if (!focusCameFromSettlementInput) {
            return;
        }

        paymentExchangeConfirmFocusDone = true;
        window.setTimeout(() => {
            paymentExchangeConfirmButton.scrollIntoView({ behavior: 'smooth', block: 'center' });
            paymentExchangeConfirmButton.focus({ preventScroll: true });
        }, 80);
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

    const currentBookingReceivableTargets = (currency = '') => {
        const bookingId = currentBookingId();
        if (bookingId <= 0) {
            return [];
        }

        const normalizedCurrency = String(currency || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();

        return Array.from(customerOpenReceivables)
            .filter((row) => Number.parseInt(String(row?.bookingId || 0), 10) === bookingId)
            .filter((row) => String(row?.currency || 'PKR').toUpperCase() === normalizedCurrency)
            .filter((row) => toNumber(row?.outstandingAmount || 0) > 0.005)
            .sort((left, right) => {
                const leftService = String(left?.serviceLineReference || '');
                const rightService = String(right?.serviceLineReference || '');
                if (leftService !== rightService) {
                    return leftService.localeCompare(rightService);
                }

                const leftPassenger = String(left?.passengerName || '');
                const rightPassenger = String(right?.passengerName || '');
                if (leftPassenger !== rightPassenger) {
                    return leftPassenger.localeCompare(rightPassenger);
                }

                return (Number.parseInt(String(left?.id || 0), 10) || 0) - (Number.parseInt(String(right?.id || 0), 10) || 0);
            });
    };

    const paymentTargetLabel = (row) => {
        const passenger = String(row?.passengerName || '').trim();
        const servicePnr = (() => {
            const lineReference = String(row?.serviceLineReference || '').trim();
            const matchingService = Array.from(serviceLines).find((serviceLine) => String(serviceLine?.lineNumber || '').trim() === lineReference);
            const pnr = String(matchingService?.pnr || '').trim();
            return pnr !== '' ? pnr : 'No PNR';
        })();
        const amount = formatCurrencyAmount(String(row?.currency || 'PKR'), toNumber(row?.outstandingAmount || 0));

        return [
            passenger !== '' ? passenger : 'Passenger',
            servicePnr,
            amount,
        ].join(' / ');
    };

    const preferredPaymentTargetId = (targets = []) => {
        const activeServiceLineReference = String(
            station.querySelector('[data-service-field="lineNumber"]')?.value
            || station.querySelector('input[name="line_number"]')?.value
            || ''
        ).trim();
        const activePnr = String(
            station.querySelector('[data-ticket-field="pnr"]')?.value
            || station.querySelector('input[name="ticket_pnr"]')?.value
            || ''
        ).trim().toUpperCase();
        const activePassenger = String(
            station.querySelector('[data-service-passenger-name]')?.value
            || station.querySelector('input[name="service_passenger_name"]')?.value
            || ''
        ).trim().toUpperCase();

        if (targets.length === 0) {
            return '';
        }

        const directServiceMatch = targets.find((row) => String(row?.serviceLineReference || '').trim() === activeServiceLineReference);
        if (directServiceMatch) {
            return String(Number.parseInt(String(directServiceMatch.id || 0), 10) || 0);
        }

        const pnrMatch = targets.find((row) => {
            const lineReference = String(row?.serviceLineReference || '').trim();
            const matchingService = Array.from(serviceLines).find((serviceLine) => String(serviceLine?.lineNumber || '').trim() === lineReference);
            return String(matchingService?.pnr || '').trim().toUpperCase() === activePnr && activePnr !== '';
        });
        if (pnrMatch) {
            return String(Number.parseInt(String(pnrMatch.id || 0), 10) || 0);
        }

        const passengerMatch = targets.find((row) => String(row?.passengerName || '').trim().toUpperCase() === activePassenger && activePassenger !== '');
        if (passengerMatch) {
            return String(Number.parseInt(String(passengerMatch.id || 0), 10) || 0);
        }

        return '';
    };

    const receiptScopeHelpText = () => {
        if (!(paymentReceiptScopeSelect instanceof HTMLSelectElement)) {
            return '';
        }

        return paymentReceiptScopeSelect.value === 'passenger_specific'
            ? 'Selected Passenger Due Only keeps this payment tied to one passenger/service due.'
            : 'Whole Invoice / Auto Split applies this receipt across the current invoice dues in order.';
    };

    const activeServiceLineReference = () => String(
        station.querySelector('[data-service-field="lineNumber"]')?.value
        || station.querySelector('input[name="line_number"]')?.value
        || ''
    ).trim();

    const liveCurrentServiceDuePreview = () => {
        const snapshot = currentInvoiceSnapshot();

        return {
            currency: String(snapshot.invoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR').toUpperCase(),
            dueAmount: Math.max(toNumber(snapshot.invoiceAmount || 0), 0),
            allocatedAmount: Math.max(toNumber(snapshot.invoicePaid || 0), 0),
            outstandingAmount: Math.max(toNumber(snapshot.invoiceBalance || 0), 0),
        };
    };

    const currentBookingPassengerDueRows = () => {
        const bookingId = currentBookingId();
        if (bookingId <= 0) {
            return [];
        }

        const liveLineReference = activeServiceLineReference();
        const livePreview = liveCurrentServiceDuePreview();

        return Array.from(customerOpenReceivables)
            .filter((row) => Number.parseInt(String(row?.bookingId || 0), 10) === bookingId)
            .map((row) => {
                if (liveLineReference === '' || String(row?.serviceLineReference || '').trim() !== liveLineReference) {
                    return row;
                }

                return {
                    ...row,
                    currency: livePreview.currency,
                    dueAmount: livePreview.dueAmount,
                    allocatedAmount: livePreview.allocatedAmount,
                    outstandingAmount: livePreview.outstandingAmount,
                };
            })
            .filter((row) => {
                const dueAmount = toNumber(row?.dueAmount || 0);
                const outstandingAmount = toNumber(row?.outstandingAmount || 0);
                const allocatedAmount = toNumber(row?.allocatedAmount || 0);
                return dueAmount > 0.005 || outstandingAmount > 0.005 || allocatedAmount > 0.005;
            })
            .sort((left, right) => {
                const leftService = String(left?.serviceLineReference || '');
                const rightService = String(right?.serviceLineReference || '');
                if (leftService !== rightService) {
                    return leftService.localeCompare(rightService);
                }

                const leftPassenger = String(left?.passengerName || '');
                const rightPassenger = String(right?.passengerName || '');
                if (leftPassenger !== rightPassenger) {
                    return leftPassenger.localeCompare(rightPassenger);
                }

                return (Number.parseInt(String(left?.id || 0), 10) || 0) - (Number.parseInt(String(right?.id || 0), 10) || 0);
            });
    };

    const syncActivePassengerSummaryDisplay = () => {
        const activeRow = station.querySelector('.legacy-passenger-box [data-service-row].is-active')
            || station.querySelector('.legacy-passenger-box [data-service-row]');
        if (!(activeRow instanceof HTMLElement)) {
            return;
        }

        const livePreview = liveCurrentServiceDuePreview();
        const invoiceCell = activeRow.querySelector('[data-passenger-summary-invoice]');
        const paidCell = activeRow.querySelector('[data-passenger-summary-paid]');
        const outstandingCell = activeRow.querySelector('[data-passenger-summary-outstanding]');

        if (invoiceCell instanceof HTMLElement) {
            invoiceCell.textContent = formatCurrencyAmount(livePreview.currency, livePreview.dueAmount);
        }
        if (paidCell instanceof HTMLElement) {
            paidCell.textContent = formatCurrencyAmount(livePreview.currency, livePreview.allocatedAmount);
        }
        if (outstandingCell instanceof HTMLElement) {
            outstandingCell.textContent = formatCurrencyAmount(livePreview.currency, livePreview.outstandingAmount);
        }
    };

    const settlementFieldShouldSync = (field, switchedServiceLine) => {
        if (!(field instanceof HTMLInputElement)) {
            return false;
        }

        if (switchedServiceLine) {
            field.dataset.settlementDirty = '0';
            return true;
        }

        return String(field.dataset.settlementDirty || '0') !== '1';
    };

    const bindSettlementFieldDraftBehavior = (field, options = {}) => {
        const { selectZeroOnFocus = false } = options;
        if (!(field instanceof HTMLInputElement) || field.dataset.settlementFieldBound === '1') {
            return;
        }

        field.dataset.settlementFieldBound = '1';
        const markDirty = () => {
            field.dataset.settlementDirty = '1';
        };

        field.addEventListener('input', markDirty);
        field.addEventListener('change', markDirty);

        if (selectZeroOnFocus) {
            field.addEventListener('focus', () => {
                if (toNumber(field.value || 0) <= 0.005) {
                    field.select();
                }
            });
        }
    };

    const syncSettlementFormFromServiceLine = (serviceLine) => {
        if (!(serviceEventBars.settlement instanceof HTMLFormElement)) {
            return;
        }

        const customerPenaltyField = serviceEventBars.settlement.elements.namedItem('customer_penalty_amount');
        const expectedSupplierRefundField = serviceEventBars.settlement.elements.namedItem('expected_supplier_refund_amount');
        const supplierPenaltyField = serviceEventBars.settlement.elements.namedItem('supplier_penalty_amount');
        const settlementReasonField = serviceEventBars.settlement.elements.namedItem('settlement_reason');
        const settlementDateField = serviceEventBars.settlement.elements.namedItem('settlement_event_date');
        const settlementSummaryNote = serviceEventBars.settlement.querySelector('[data-service-settlement-summary]');
        const serviceLineKey = String(
            serviceLine?.serviceId
            || serviceLine?.lineReference
            || serviceLine?.lineNumber
            || 'draft'
        );
        const previousServiceLineKey = String(serviceEventBars.settlement.dataset.settlementServiceKey || '');
        const switchedServiceLine = serviceLineKey !== previousServiceLineKey;
        serviceEventBars.settlement.dataset.settlementServiceKey = serviceLineKey;

        const customerPenaltyAmount = toNumber(serviceLine?.latestCancelCustomerPenaltyAmount || 0);
        const supplierPenaltyAmount = toNumber(serviceLine?.latestCancelSupplierPenaltyAmount || 0);
        const supplierCostAmount = toNumber(serviceLine?.purchaseCost || serviceLine?.supplierCost || 0);
        const expectedSupplierRefundAmount = toNumber(
            serviceLine?.latestCancelExpectedSupplierRefundAmount
            || Math.max(supplierCostAmount - supplierPenaltyAmount, 0)
        );
        const customerRefundCredit = toNumber(serviceLine?.latestCancelReleasedCustomerCreditAmount || 0);
        const supplierRefundCredit = toNumber(serviceLine?.latestCancelReleasedSupplierCreditAmount || 0);
        const invoiceCurrency = String(serviceLine?.currency || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();

        if (customerPenaltyField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(customerPenaltyField, { selectZeroOnFocus: true });
            if (settlementFieldShouldSync(customerPenaltyField, switchedServiceLine)) {
                customerPenaltyField.value = formatNumberInputValue(customerPenaltyAmount);
            }
        }
        if (expectedSupplierRefundField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(expectedSupplierRefundField, { selectZeroOnFocus: true });
            if (settlementFieldShouldSync(expectedSupplierRefundField, switchedServiceLine)) {
                expectedSupplierRefundField.value = formatNumberInputValue(expectedSupplierRefundAmount);
            }
        }
        if (supplierPenaltyField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(supplierPenaltyField);
            if (settlementFieldShouldSync(supplierPenaltyField, switchedServiceLine)) {
                supplierPenaltyField.value = formatNumberInputValue(Math.max(supplierCostAmount - expectedSupplierRefundAmount, 0));
            }
        }
        if (settlementReasonField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(settlementReasonField);
            if (settlementFieldShouldSync(settlementReasonField, switchedServiceLine)) {
                settlementReasonField.value = String(serviceLine?.latestCancelReason || '');
            }
        }
        if (settlementDateField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(settlementDateField);
            if (settlementFieldShouldSync(settlementDateField, switchedServiceLine)) {
                settlementDateField.value = String(serviceLine?.latestCancelEventDate || '') || settlementDateField.value || todayIso();
            }
        }
        const syncSettlementDerivedAmounts = () => {
            const liveCustomerPenaltyAmount = toNumber(customerPenaltyField?.value || 0);
            const liveExpectedSupplierRefundAmount = Math.min(
                Math.max(toNumber(expectedSupplierRefundField?.value || 0), 0),
                Math.max(supplierCostAmount, 0)
            );
            const liveSupplierPenaltyAmount = Math.max(supplierCostAmount - liveExpectedSupplierRefundAmount, 0);
            if (supplierPenaltyField instanceof HTMLInputElement) {
                supplierPenaltyField.value = formatNumberInputValue(liveSupplierPenaltyAmount);
            }
            if (!(settlementSummaryNote instanceof HTMLElement)) {
                return;
            }
            const hasSummary = liveCustomerPenaltyAmount > 0.005
                || liveExpectedSupplierRefundAmount > 0.005
                || liveSupplierPenaltyAmount > 0.005
                || customerRefundCredit > 0.005
                || supplierRefundCredit > 0.005;
            settlementSummaryNote.hidden = !hasSummary;
            if (hasSummary) {
                settlementSummaryNote.textContent = [
                    `Customer penalty: ${formatCurrencyAmount(invoiceCurrency, liveCustomerPenaltyAmount)}.`,
                    `Expected supplier refund: ${formatCurrencyAmount(invoiceCurrency, liveExpectedSupplierRefundAmount)}.`,
                    `Supplier penalty: ${formatCurrencyAmount(invoiceCurrency, liveSupplierPenaltyAmount)}.`,
                    `Customer refund credit released: ${formatCurrencyAmount(invoiceCurrency, customerRefundCredit)}.`,
                    `Supplier refundable credit released: ${formatCurrencyAmount(invoiceCurrency, supplierRefundCredit)}.`,
                ].join(' ');
            } else {
                settlementSummaryNote.textContent = '';
            }
        };
        customerPenaltyField?.addEventListener('input', syncSettlementDerivedAmounts);
        expectedSupplierRefundField?.addEventListener('input', syncSettlementDerivedAmounts);
        syncSettlementDerivedAmounts();
    };

    const syncSettlementCorrectionFormFromServiceLine = (serviceLine) => {
        if (!(serviceCorrectionSettlementForm instanceof HTMLFormElement)) {
            return;
        }

        const customerPenaltyField = serviceCorrectionSettlementForm.elements.namedItem('customer_penalty_amount');
        const expectedSupplierRefundField = serviceCorrectionSettlementForm.elements.namedItem('expected_supplier_refund_amount');
        const supplierPenaltyField = serviceCorrectionSettlementForm.elements.namedItem('supplier_penalty_amount');
        const settlementReasonField = serviceCorrectionSettlementForm.elements.namedItem('settlement_reason')
            || serviceCorrectionSettlementForm.elements.namedItem('correction_reason');
        const settlementDateField = serviceCorrectionSettlementForm.elements.namedItem('settlement_event_date')
            || serviceCorrectionSettlementForm.elements.namedItem('correction_event_date');
        const serviceLineKey = String(
            serviceLine?.serviceId
            || serviceLine?.lineReference
            || serviceLine?.lineNumber
            || 'draft'
        );
        const previousServiceLineKey = String(serviceCorrectionSettlementForm.dataset.settlementServiceKey || '');
        const switchedServiceLine = serviceLineKey !== previousServiceLineKey;
        serviceCorrectionSettlementForm.dataset.settlementServiceKey = serviceLineKey;

        const customerPenaltyAmount = toNumber(serviceLine?.latestCancelCustomerPenaltyAmount || 0);
        const supplierPenaltyAmount = toNumber(serviceLine?.latestCancelSupplierPenaltyAmount || 0);
        const supplierCostAmount = toNumber(serviceLine?.purchaseCost || serviceLine?.supplierCost || 0);
        const expectedSupplierRefundAmount = toNumber(
            serviceLine?.latestCancelExpectedSupplierRefundAmount
            || Math.max(supplierCostAmount - supplierPenaltyAmount, 0)
        );
        const customerRefundCredit = toNumber(serviceLine?.latestCancelReleasedCustomerCreditAmount || 0);
        const supplierRefundCredit = toNumber(serviceLine?.latestCancelReleasedSupplierCreditAmount || 0);
        const invoiceCurrency = String(serviceLine?.currency || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();

        if (customerPenaltyField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(customerPenaltyField, { selectZeroOnFocus: true });
            if (settlementFieldShouldSync(customerPenaltyField, switchedServiceLine)) {
                customerPenaltyField.value = formatNumberInputValue(customerPenaltyAmount);
            }
        }

        if (expectedSupplierRefundField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(expectedSupplierRefundField, { selectZeroOnFocus: true });
            if (settlementFieldShouldSync(expectedSupplierRefundField, switchedServiceLine)) {
                expectedSupplierRefundField.value = formatNumberInputValue(expectedSupplierRefundAmount);
            }
        }

        if (supplierPenaltyField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(supplierPenaltyField);
            if (settlementFieldShouldSync(supplierPenaltyField, switchedServiceLine)) {
                supplierPenaltyField.value = formatNumberInputValue(Math.max(supplierCostAmount - expectedSupplierRefundAmount, 0));
            }
        }

        if (settlementReasonField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(settlementReasonField);
            if (settlementFieldShouldSync(settlementReasonField, switchedServiceLine)) {
                settlementReasonField.value = String(serviceLine?.latestCancelReason || '');
            }
        }

        if (settlementDateField instanceof HTMLInputElement) {
            bindSettlementFieldDraftBehavior(settlementDateField);
            if (settlementFieldShouldSync(settlementDateField, switchedServiceLine)) {
                settlementDateField.value = String(serviceLine?.latestCancelEventDate || '') || settlementDateField.value || todayIso();
            }
        }

        const syncSettlementCorrectionDerivedAmounts = () => {
            const liveCustomerPenaltyAmount = Math.max(toNumber(customerPenaltyField?.value || 0), 0);
            const liveExpectedSupplierRefundAmount = Math.min(
                Math.max(toNumber(expectedSupplierRefundField?.value || 0), 0),
                Math.max(supplierCostAmount, 0)
            );
            const liveSupplierPenaltyAmount = Math.max(supplierCostAmount - liveExpectedSupplierRefundAmount, 0);

            if (supplierPenaltyField instanceof HTMLInputElement) {
                supplierPenaltyField.value = formatNumberInputValue(liveSupplierPenaltyAmount);
            }

            if (!(serviceCorrectionSettlementSummaryNote instanceof HTMLElement)) {
                return;
            }

            const hasSummary = liveCustomerPenaltyAmount > 0.005
                || liveExpectedSupplierRefundAmount > 0.005
                || liveSupplierPenaltyAmount > 0.005
                || customerRefundCredit > 0.005
                || supplierRefundCredit > 0.005;

            serviceCorrectionSettlementSummaryNote.hidden = !hasSummary;
            serviceCorrectionSettlementSummaryNote.textContent = hasSummary
                ? [
                    `Customer penalty: ${formatCurrencyAmount(invoiceCurrency, liveCustomerPenaltyAmount)}.`,
                    `Expected supplier refund: ${formatCurrencyAmount(invoiceCurrency, liveExpectedSupplierRefundAmount)}.`,
                    `Supplier penalty: ${formatCurrencyAmount(invoiceCurrency, liveSupplierPenaltyAmount)}.`,
                    `Customer refund credit released: ${formatCurrencyAmount(invoiceCurrency, customerRefundCredit)}.`,
                    `Supplier refundable credit released: ${formatCurrencyAmount(invoiceCurrency, supplierRefundCredit)}.`,
                ].join(' ')
                : '';
        };

        customerPenaltyField?.addEventListener('input', syncSettlementCorrectionDerivedAmounts);
        expectedSupplierRefundField?.addEventListener('input', syncSettlementCorrectionDerivedAmounts);
        syncSettlementCorrectionDerivedAmounts();
    };

    const syncRefundCorrectionFormFromServiceLine = (serviceLine) => {
        if (!(serviceCorrectionRefundForm instanceof HTMLFormElement)) {
            return;
        }

        const serviceLineKey = String(
            serviceLine?.serviceId
            || serviceLine?.lineReference
            || serviceLine?.lineNumber
            || 'draft'
        );
        const previousServiceLineKey = String(serviceCorrectionRefundForm.dataset.refundServiceKey || '');
        const switchedServiceLine = serviceLineKey !== previousServiceLineKey;
        serviceCorrectionRefundForm.dataset.refundServiceKey = serviceLineKey;

        const customerRefundAmountField = serviceCorrectionRefundForm.elements.namedItem('customer_refund_amount');
        const supplierRefundAmountField = serviceCorrectionRefundForm.elements.namedItem('supplier_refund_amount');
        const refundDateField = serviceCorrectionRefundForm.elements.namedItem('refund_event_date')
            || serviceCorrectionRefundForm.elements.namedItem('correction_event_date');
        const customerRefundAmount = toNumber(serviceLine?.latestCustomerRefundAmountOnly || 0);
        const supplierRefundAmount = toNumber(serviceLine?.latestSupplierRefundAmountOnly || 0);

        if (customerRefundAmountField instanceof HTMLInputElement) {
            if (switchedServiceLine || String(customerRefundAmountField.dataset.refundBound || '') !== '1') {
                customerRefundAmountField.value = formatNumberInputValue(customerRefundAmount);
            }
            customerRefundAmountField.dataset.refundBound = '1';
        }

        if (supplierRefundAmountField instanceof HTMLInputElement) {
            if (switchedServiceLine || String(supplierRefundAmountField.dataset.refundBound || '') !== '1') {
                supplierRefundAmountField.value = formatNumberInputValue(supplierRefundAmount);
            }
            supplierRefundAmountField.dataset.refundBound = '1';
        }

        if (refundDateField instanceof HTMLInputElement && switchedServiceLine) {
            refundDateField.value = refundDateField.value || todayIso();
        }
    };

    const syncReceiptScopeTargets = () => {
        if (!(paymentReceiptScopeSelect instanceof HTMLSelectElement) || !(paymentTargetReceivableSelect instanceof HTMLSelectElement)) {
            return;
        }

        const invoiceCurrency = String(currentInvoiceSnapshot().invoiceCurrency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR').toUpperCase();
        const targets = currentBookingReceivableTargets(invoiceCurrency);
        const previousValue = String(paymentTargetReceivableSelect.value || '');

        paymentTargetReceivableSelect.innerHTML = '<option value="">Select passenger due</option>';
        targets.forEach((row) => {
            const option = document.createElement('option');
            option.value = String(Number.parseInt(String(row.id || 0), 10) || 0);
            option.textContent = paymentTargetLabel(row);
            paymentTargetReceivableSelect.appendChild(option);
        });

        const preferredValue = preferredPaymentTargetId(targets);

        if (targets.some((row) => String(Number.parseInt(String(row.id || 0), 10) || 0) === previousValue)) {
            paymentTargetReceivableSelect.value = previousValue;
        } else if (preferredValue !== '') {
            paymentTargetReceivableSelect.value = preferredValue;
        } else if (targets.length === 1) {
            paymentTargetReceivableSelect.value = String(Number.parseInt(String(targets[0].id || 0), 10) || 0);
        } else {
            paymentTargetReceivableSelect.value = '';
        }

        const passengerSpecific = paymentReceiptScopeSelect.value === 'passenger_specific';
        const hasTargets = targets.length > 0;
        if (paymentTargetRow instanceof HTMLElement) {
            paymentTargetRow.hidden = !(passengerSpecific && hasTargets);
        }

        if (passengerSpecific && !hasTargets) {
            paymentReceiptScopeSelect.value = 'whole_invoice';
            if (paymentTargetRow instanceof HTMLElement) {
                paymentTargetRow.hidden = true;
            }
        }

        if (paymentScopeNote instanceof HTMLElement) {
            paymentScopeNote.textContent = receiptScopeHelpText();
        }
    };

    const settlementTargetLabel = (target) => {
        const prefix = target.isCurrentBooking ? 'Current Invoice' : 'Outstanding Balance';
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

    const buildManualExchangeSettlementTarget = (options = {}) => {
        const { allowZeroBalance = false } = options;
        const snapshot = currentInvoiceSnapshot();
        const visibleInvoiceAmount = Math.max(
            toNumber(paymentCurrentInvoiceInput?.dataset.paymentCurrentInvoice || 0),
            toNumber(paymentCurrentInvoiceInput?.value || 0),
            0
        );
        const outstandingAmount = Math.max(
            toNumber(snapshot.invoiceBalance || 0),
            visibleInvoiceAmount,
            0
        );
        if (!allowZeroBalance && outstandingAmount <= 0.005) {
            return null;
        }

        return {
            id: 0,
            bookingId: currentBookingId(),
            bookingReference: String(invoiceNumberDisplay?.value || paymentExchangeInvoiceNoInput?.value || '').trim() || 'Current Invoice',
            bookingDate: '',
            serviceLineReference: allowZeroBalance ? 'Rate preview' : 'Unsaved invoice',
            nextDueDate: String(paymentDueDateInput?.value || '').trim(),
            currency: String(snapshot.invoiceCurrency || 'PKR').toUpperCase(),
            outstandingAmount,
            isCurrentBooking: true,
            isManualPreviewOnly: true,
        };
    };

    const selectedExchangeSettlementTarget = () => {
        const selectedId = Number.parseInt(String(paymentExchangeTargetSelect?.value || 0), 10) || 0;
        if (selectedId > 0) {
            return currentSettlementTargets().find((row) => (Number.parseInt(String(row?.id || 0), 10) || 0) === selectedId) || null;
        }

        return paymentExchangeManualTarget || currentInvoiceExchangeTarget();
    };

    const replaceSettlementData = (nextReceivables = [], nextRates = {}) => {
        customerOpenReceivables = Array.isArray(nextReceivables) ? nextReceivables : [];
        dailySettlementRates = nextRates && typeof nextRates === 'object' ? nextRates : {};

        if (customerOpenReceivablesDataNode) {
            customerOpenReceivablesDataNode.textContent = JSON.stringify(customerOpenReceivables);
        }
        if (dailySettlementRatesDataNode) {
            dailySettlementRatesDataNode.textContent = JSON.stringify(dailySettlementRates);
        }

        syncReceiptScopeTargets();
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

    let renderReceivableAlerts = () => {};

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
            return 'PKR 0';
        }

        return entries.map(([currency, amount]) => formatCurrencyAmount(currency, amount)).join(' / ');
    };

    const updateCustomerLedgerTarget = (bookingId = currentBookingId()) => {
        if (customerLedgerLinks.length === 0 && !(paymentLedgerLink instanceof HTMLAnchorElement)) {
            updateWhatsappLedgerActionState();
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

        updateWhatsappLedgerActionState();
    };

    const normalizePhoneDigits = (value, branchName = '') => {
        const raw = String(value || '').trim();
        if (raw === '' || raw === '-') {
            return '';
        }

        let digits = raw.replace(/\D+/g, '');
        if (digits === '') {
            return '';
        }

        if (digits.startsWith('00')) {
            digits = digits.slice(2);
        }

        if (digits.startsWith('92') || digits.startsWith('971') || digits.startsWith('964')) {
            return digits;
        }

        const branch = String(branchName || '').trim().toLowerCase();
        const isUaeBranch = branch !== '' && (branch.includes('noble') || branch.includes('dubai') || branch.includes('uae'));
        const isPakistanBranch = branch !== '' && (branch.includes('imdad') || branch.includes('swat') || branch.includes('pakistan'));

        if (digits.startsWith('0')) {
            const localDigits = digits.replace(/^0+/, '');
            if (localDigits === '') {
                return '';
            }

            if (isUaeBranch) {
                return `971${localDigits}`;
            }

            if (isPakistanBranch) {
                return `92${localDigits}`;
            }

            return localDigits;
        }

        if (isUaeBranch && digits.length === 9 && digits.startsWith('5')) {
            return `971${digits}`;
        }

        if (isPakistanBranch && digits.length === 10 && digits.startsWith('3')) {
            return `92${digits}`;
        }

        return digits;
    };

    const currentLedgerPrintUrl = () => {
        const bookingId = currentBookingId();
        if (bookingId <= 0) {
            return '';
        }

        const params = new URLSearchParams();
        const customerName = customerAutocompleteInput instanceof HTMLInputElement
            ? String(customerAutocompleteInput.value || '').trim()
            : '';
        const branchId = bookingBranchField instanceof HTMLSelectElement
            ? Number.parseInt(String(bookingBranchField.value || '0'), 10) || 0
            : 0;
        const businessSourceId = businessSourceInput instanceof HTMLSelectElement
            ? Number.parseInt(String(businessSourceInput.value || '0'), 10) || 0
            : 0;

        if (branchId > 0) {
            params.set('branch_id', String(branchId));
        }
        if (businessSourceId > 0) {
            params.set('business_source_id', String(businessSourceId));
        }
        if (customerName !== '') {
            params.set('customer_name', customerName);
        }

        return buildWorkspacePathUrl(`reports/account-ledger-print?${params.toString()}`);
    };

    const updateWhatsappLedgerActionState = () => {
        if (!(paymentWhatsappLedgerButton instanceof HTMLButtonElement)) {
            return;
        }

        const ledgerPrintUrl = currentLedgerPrintUrl();
        const branchLabel = bookingBranchLabelField instanceof HTMLInputElement
            ? String(bookingBranchLabelField.value || '').trim()
            : '';
        const mobile = bookingMobileField instanceof HTMLInputElement
            ? String(bookingMobileField.value || '').trim()
            : '';
        const phoneDigits = normalizePhoneDigits(mobile, branchLabel);

        paymentWhatsappLedgerButton.dataset.ledgerPrintUrl = ledgerPrintUrl;
        paymentWhatsappLedgerButton.dataset.whatsappDigits = phoneDigits;
        paymentWhatsappLedgerButton.disabled = ledgerPrintUrl === '';
    };

    const openWhatsappLedgerFlow = () => {
        if (!(paymentWhatsappLedgerButton instanceof HTMLButtonElement)) {
            return false;
        }

        const ledgerPrintUrl = String(paymentWhatsappLedgerButton.dataset.ledgerPrintUrl || '').trim();
        if (ledgerPrintUrl === '') {
            showFeedback('Save or open a booking first, then send its ledger.');
            return false;
        }

        const customerName = customerAutocompleteInput instanceof HTMLInputElement
            ? String(customerAutocompleteInput.value || '').trim()
            : '';
        const invoiceNo = invoiceNumberDisplay instanceof HTMLElement
            ? String(invoiceNumberDisplay.textContent || '').trim()
            : '';
        const phoneDigits = String(paymentWhatsappLedgerButton.dataset.whatsappDigits || '').trim();

        if (window.travelLauncher && typeof window.travelLauncher.saveLedgerPdfAndOpenWhatsApp === 'function') {
            paymentWhatsappLedgerButton.disabled = true;
            paymentWhatsappLedgerButton.textContent = 'Preparing...';

            window.travelLauncher.saveLedgerPdfAndOpenWhatsApp({
                ledgerUrl: ledgerPrintUrl,
                phoneDigits,
                customerName,
                invoiceNo
            }).then((result) => {
                const savedPath = String(result?.filePath || '').trim();
                if (savedPath !== '') {
                    showFeedback(`Ledger saved: ${savedPath}`);
                } else if (phoneDigits === '') {
                    showFeedback('Ledger saved, but customer mobile is missing so WhatsApp was not opened.');
                }
            }).catch((error) => {
                const message = error instanceof Error ? error.message : 'Could not save ledger PDF from launcher.';
                showFeedback(message);
            }).finally(() => {
                paymentWhatsappLedgerButton.disabled = ledgerPrintUrl === '';
                paymentWhatsappLedgerButton.textContent = 'WhatsApp Ledger';
            });

            return true;
        }

        const printPopup = window.open(ledgerPrintUrl, '_blank', 'noopener');

        if (phoneDigits === '') {
            showFeedback('Ledger opened. Customer mobile is missing, so WhatsApp could not be prepared.');
            return printPopup !== null;
        }

        const messageParts = [
            customerName !== '' ? `Account ledger for ${customerName}` : 'Account ledger',
            invoiceNo !== '' ? `(${invoiceNo})` : '',
            'is ready. Please attach the PDF from the ledger tab.',
        ].filter((part) => part !== '');
        const whatsappUrl = `https://wa.me/${encodeURIComponent(phoneDigits)}?text=${encodeURIComponent(messageParts.join(' '))}`;
        const whatsappPopup = window.open(whatsappUrl, '_blank', 'noopener');

        if (whatsappPopup === null && printPopup !== null) {
            showFeedback('Ledger opened. Allow pop-ups if you also want WhatsApp to open automatically.');
        }

        return printPopup !== null || whatsappPopup !== null;
    };

    const renderPaymentHistoryModal = () => {
        if (paymentHistorySummary instanceof HTMLElement) {
            const invoiceSnapshot = currentInvoiceSnapshot();
            paymentHistorySummary.innerHTML = `Current Invoice Total:<strong>${escapeHtml(formatCurrencyAmount(invoiceSnapshot.invoiceCurrency || 'PKR', invoiceSnapshot.invoiceAmount || 0))}</strong><span style="margin-left:12px;">Outstanding:<strong>${escapeHtml(formatCurrencyAmount(invoiceSnapshot.invoiceCurrency || 'PKR', invoiceSnapshot.invoiceBalance || 0))}</strong></span>`;
        }

        if (paymentHistoryReceiptsBody instanceof HTMLElement) {
            const receiptKeys = new Set();
            const displayReceipts = Array.isArray(paymentReceipts) ? [...paymentReceipts] : [];
            displayReceipts.forEach((receipt) => {
                const receiptId = Number.parseInt(String(receipt?.id || 0), 10) || 0;
                const receiptNo = String(receipt?.receiptNo || '').trim();
                if (receiptId > 0) {
                    receiptKeys.add(`id:${receiptId}`);
                }
                if (receiptNo !== '') {
                    receiptKeys.add(`no:${receiptNo}`);
                }
            });

            const advanceReceiptRows = new Map();
            if (Array.isArray(paymentAllocations)) {
                paymentAllocations.forEach((allocation) => {
                    const purpose = String(allocation?.receiptPurpose || 'booking_payment').trim().toLowerCase().replace(/\s+/g, '_');
                    if (purpose !== 'customer_advance') {
                        return;
                    }

                    const receiptStatusRaw = String(allocation?.receiptStatusRaw || '').trim().toLowerCase().replace(/\s+/g, '_');
                    if (receiptStatusRaw === 'void') {
                        return;
                    }

                    const receiptId = Number.parseInt(String(allocation?.receiptId || 0), 10) || 0;
                    const receiptNo = String(allocation?.receiptNo || '').trim();
                    const key = receiptId > 0 ? `id:${receiptId}` : (receiptNo !== '' ? `no:${receiptNo}` : `advance:${advanceReceiptRows.size}`);
                    if (receiptKeys.has(key)) {
                        return;
                    }

                    const currency = String(allocation?.receivableCurrency || allocation?.currency || 'PKR');
                    const appliedAmount = toNumber(allocation?.receivableAmountAllocated ?? allocation?.allocatedAmount ?? 0);
                    if (Math.abs(appliedAmount) <= 0.005) {
                        return;
                    }

                    const existing = advanceReceiptRows.get(key) || {
                        id: 0,
                        receiptNo: receiptNo || 'Advance',
                        receiptDate: String(allocation?.receiptDate || String(allocation?.allocatedAt || '').slice(0, 10)),
                        currency,
                        tenderedAmount: 0,
                        receivedAmount: 0,
                        returnedAmount: 0,
                        allocatedAmount: 0,
                        paymentMethod: 'Customer Advance',
                        status: 'Advance Applied',
                        statusRaw: 'advance_applied',
                        isAdvanceApplication: true,
                    };
                    existing.allocatedAmount = toNumber(existing.allocatedAmount || 0) + appliedAmount;
                    advanceReceiptRows.set(key, existing);
                });
            }

            advanceReceiptRows.forEach((row) => displayReceipts.push(row));

            if (displayReceipts.length === 0) {
                paymentHistoryReceiptsBody.innerHTML = '<tr><td colspan="12" class="empty-cell">No receipts recorded yet.</td></tr>';
            } else {
                const csrfField = paymentForm?.elements?.namedItem('_token');
                const csrfToken = csrfField instanceof HTMLInputElement ? csrfField.value.trim() : '';
                paymentHistoryReceiptsBody.innerHTML = displayReceipts.map((receipt) => {
                    const receiptId = Number.parseInt(String(receipt?.id || 0), 10) || 0;
                    const bookingId = currentBookingId();
                    const isAdvanceApplication = receipt?.isAdvanceApplication === true;
                    const statusRaw = String(receipt?.statusRaw || receipt?.status || '').trim().toLowerCase().replace(/\s+/g, '_');
                    const statusLabel = statusRaw === 'void'
                        ? 'VOID'
                        : String(receipt?.status || '').trim();
                    const printUrl = !isAdvanceApplication && receiptId > 0 && bookingId > 0
                        ? buildWorkspacePathUrl(`workspace/output?booking_id=${bookingId}&doc=customer_receipt&receipt_id=${receiptId}`)
                        : '';
                    const metadataActionHtml = !isAdvanceApplication && receiptId > 0 && bookingId > 0 && csrfToken !== '' && statusRaw !== 'void'
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
                    const voidActionHtml = !isAdvanceApplication && canVoidFinancials && statusRaw !== 'void' && receiptId > 0 && bookingId > 0 && csrfToken !== ''
                        ? `<form method="post" action="${escapeHtml(buildWorkspacePathUrl('workspace/payments/receipts/void'))}" onsubmit="return confirm('Void this receipt and reverse its allocations?');" style="display:grid;gap:6px;min-width:150px;">
                            <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="booking_id" value="${bookingId}">
                            <input type="hidden" name="customer_receipt_id" value="${receiptId}">
                            <input type="text" name="void_reason" value="" placeholder="Void reason" minlength="5" maxlength="1000" required>
                            <button class="btn btn-sm" type="submit">Void</button>
                        </form>`
                        : '-';
                    const recreateActionHtml = !isAdvanceApplication && statusRaw === 'void' && receiptId > 0 && bookingId > 0
                        ? `<a class="btn btn-sm" href="${escapeHtml(buildWorkspacePathUrl(`workspace?booking_id=${bookingId}&recreate_receipt_id=${receiptId}#dock-panel-payments`))}">Recreate</a>`
                        : '-';
                    return `<tr>
                        <td>${escapeHtml(String(receipt?.receiptNo || ''))}</td>
                        <td>${escapeHtml(String(receipt?.receiptDate || ''))}</td>
                        <td>${escapeHtml(String(receipt?.currency || ''))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.tenderedAmount || receipt?.receivedAmount || 0)))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.returnedAmount || 0)))}</td>
                        <td>${escapeHtml(formatMoney(toNumber(receipt?.allocatedAmount || 0)))}</td>
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
        renderReceivableAlerts();
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

    const paymentInvoiceBalanceIsLocked = () => (
        paymentCurrentBalanceInput?.dataset.paymentBalanceLocked === '1'
    );

    const persistedInvoiceBalanceAmount = () => Math.max(toNumber(
        paymentCurrentBalanceInput?.dataset.paymentSavedInvoiceBalance
        || paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance
        || 0
    ), 0);

    function currentInvoiceSnapshot() {
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
        const invoiceBalance = paymentInvoiceBalanceIsLocked()
            ? persistedInvoiceBalanceAmount()
            : Math.max(invoiceAmount - invoicePaid, 0, persistedBalance);

        return {
            invoiceCurrency,
            invoiceAmount,
            invoicePaid,
            invoiceBalance,
        };
    }

    const applyManualInvoiceAmountOverride = (rawValue) => {
        if (!(finalSalePriceInput instanceof HTMLInputElement) || !(paymentCurrentInvoiceInput instanceof HTMLInputElement)) {
            return;
        }

        const invoiceAmount = Math.max(toNumber(rawValue), 0);
        finalSalePriceInput.value = formatNumberInputValue(invoiceAmount);
        finalSalePriceInput.dataset.manualOverride = invoiceAmount > 0.005 ? '1' : '0';
        paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = String(invoiceAmount);
        paymentCurrentInvoiceInput.value = formatNumberInputValue(invoiceAmount);

        refreshProfit('manual:payment_current_invoice');
        syncTicketCommercialMirrors();
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

    const openCustomerReceiptWindow = () => {
        const receiptId = Number.parseInt(String(paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0), 10) || 0;
        const printUrl = String(paymentPrintReceiptButton?.dataset.paymentPrintUrl || '').trim();

        if (receiptId <= 0 || printUrl === '') {
            return false;
        }

        const popup = window.open(printUrl, '_blank', 'noopener');
        return popup !== null;
    };

    if (!window.__travelReceiptOpenGuard || typeof window.__travelReceiptOpenGuard !== 'object') {
        window.__travelReceiptOpenGuard = {
            key: '',
            openedAt: 0,
        };
    }
    if (!window.__travelReceiptClickGuard || typeof window.__travelReceiptClickGuard !== 'object') {
        window.__travelReceiptClickGuard = {
            clickedAt: 0,
            clickId: '',
        };
    }
    const openCustomerReceiptWindowOnce = (reason = 'manual') => {
        const receiptId = Number.parseInt(String(paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0), 10) || 0;
        const printUrl = String(paymentPrintReceiptButton?.dataset.paymentPrintUrl || '').trim();
        const existingGuard = window.__travelReceiptOpenGuard || { key: '', openedAt: 0 };

        logWorkflowTrace('receipt-open:requested', {
            reason,
            receiptId,
            hasPrintUrl: printUrl !== '',
            printUrl,
            existingGuardKey: String(existingGuard.key || ''),
            existingGuardAgeMs: Date.now() - Number(existingGuard.openedAt || 0),
        });

        if (receiptId <= 0 || printUrl === '') {
            logWorkflowTrace('receipt-open:blocked-missing-target', {
                reason,
                receiptId,
            });
            return false;
        }

        const openKey = `${receiptId}|${printUrl}`;
        const now = Date.now();
        const receiptOpenGuard = window.__travelReceiptOpenGuard || { key: '', openedAt: 0 };
        if (now - Number(receiptOpenGuard.openedAt || 0) < 2500) {
            logWorkflowTrace('receipt-open:blocked-duplicate', {
                reason,
                receiptId,
                previousKey: String(receiptOpenGuard.key || ''),
                nextKey: openKey,
                ageMs: now - Number(receiptOpenGuard.openedAt || 0),
            });
            return true;
        }

        window.__travelReceiptOpenGuard = {
            key: openKey,
            openedAt: now,
            reason,
            scriptInstanceId: workspaceScriptInstanceId,
        };

        logWorkflowTrace('receipt-open:calling-window-open', {
            reason,
            receiptId,
            printUrl,
            openKey,
        });

        const popup = window.open(printUrl, '_blank', 'noopener');
        if (popup === null) {
            logWorkflowTrace('receipt-open:blocked-popup', {
                reason,
                receiptId,
            });
            return false;
        }

        logWorkflowTrace('receipt-open:opened', {
            reason,
            receiptId,
            openKey,
        });
        return true;
    };

    const hasOpenableCustomerReceipt = () => {
        const receiptId = Number.parseInt(String(paymentPrintReceiptButton?.dataset.paymentLatestReceiptId || 0), 10) || 0;
        const printUrl = String(paymentPrintReceiptButton?.dataset.paymentPrintUrl || '').trim();
        return receiptId > 0 && printUrl !== '';
    };

    const openBookingSummaryReceiptWindow = () => {
        const bookingId = currentBookingId();
        if (bookingId <= 0) {
            return false;
        }

        const printUrl = buildWorkspacePathUrl(`workspace/output?booking_id=${bookingId}&doc=booking_summary_receipt`);
        const popup = window.open(printUrl, '_blank', 'noopener');

        return popup !== null;
    };

    let printReceiptInFlight = false;

    const currentSavedPaymentApplies = () => savedPaymentState.saved && savedPaymentState.receiptId > 0 && savedPaymentState.bookingId === currentBookingId();

    const logPaymentInputRuntime = (stage, extra = {}) => {
        if (typeof sendWorkspaceClientError !== 'function') {
            return;
        }

        sendWorkspaceClientError({
            type: 'payment_input_runtime',
            message: `Amount Receiving runtime: ${stage}`,
            extra: {
                stage,
                currentBookingId: currentBookingId(),
                savedPaymentApplies: currentSavedPaymentApplies(),
                savedPaymentState: { ...savedPaymentState },
                receivedDisabled: receivedNowInput instanceof HTMLInputElement ? receivedNowInput.disabled : null,
                receivedReadOnly: receivedNowInput instanceof HTMLInputElement ? receivedNowInput.readOnly : null,
                receivedValue: receivedNowInput instanceof HTMLInputElement ? receivedNowInput.value : null,
                paymentCurrency: paymentCurrencySelect instanceof HTMLSelectElement ? paymentCurrencySelect.value : null,
                invoiceCurrency: paymentCurrentInvoiceInput instanceof HTMLInputElement
                    ? String(paymentCurrentInvoiceInput.dataset.paymentCurrency || '')
                    : null,
                ...extra,
            },
        });
    };

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
            paymentExchangeSettlementButton.disabled = false;
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
            quickReceiveInput.value = '0';
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
            receivedNowInput.value = '0';
        }
        if (quickReceiveInput) {
            quickReceiveInput.value = '0';
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

        logPaymentInputRuntime('saved-payment-edit-attempt');

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
        const target = selectedExchangeSettlementTarget();
        if (!target) {
            return null;
        }

        const paymentCurrency = paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
        const paymentAmountSource = receivedNowInput?.value || 0;
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
        if (paymentExchangeRateDateDisplay) {
            paymentExchangeRateDateDisplay.value = rateDate;
        }

        if (targetCurrency === paymentCurrency) {
            if (paymentExchangeRateRow) {
                paymentExchangeRateRow.hidden = true;
            }
            if (paymentExchangePaymentAmountRow) {
                paymentExchangePaymentAmountRow.hidden = true;
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
            if (paymentExchangePaymentAmountRow) {
                paymentExchangePaymentAmountRow.hidden = false;
            }
            if (paymentExchangeRateLabel) {
                paymentExchangeRateLabel.textContent = `Exchange Rate (1 ${quote.rateFromCurrency} = ? ${quote.rateToCurrency})`;
            }
            if (paymentExchangePaymentAmountLabel) {
                paymentExchangePaymentAmountLabel.textContent = `${paymentCurrency} to Pay`;
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
        if (paymentExchangePaymentAmountInput) {
            paymentExchangePaymentAmountInput.value = paymentRequiredToFullyClearTarget > 0.005
                ? formatCurrencyAmount(paymentCurrency, paymentRequiredToFullyClearTarget)
                : formatCurrencyAmount(paymentCurrency, 0);
        }
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

        const preview = {
            target,
            quote: workingQuote,
            paymentAmount,
            targetBalance,
            targetSettled,
            paymentRequiredToFullyClearTarget,
            paymentConsumed,
            remainingTargetBalance,
            remainingTargetPaymentAmount: roundToTwo(convertTargetAmountToPaymentAmount(remainingTargetBalance, workingQuote)),
            remainingPaymentAmount,
            autoAppliedToSameCurrency,
            returnOrCredit,
            rateDate,
        };

        updateExchangeConfirmAvailability(preview);
        focusExchangeConfirmWhenReady(preview);

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

    const selectedPaymentAdvanceOption = () => {
        if (!paymentAdvanceSelect || paymentAdvanceSelect.value === '') {
            return null;
        }

        return paymentAdvanceOptions.find((advance) => String(advance.id || '') === String(paymentAdvanceSelect.value)) || null;
    };

    const uniqueCustomerAdvanceRows = (rows) => {
        const seen = new Set();
        return (Array.isArray(rows) ? rows : []).filter((advance) => {
            const id = Number.parseInt(String(advance?.id || 0), 10) || 0;
            const amount = Math.max(toNumber(advance?.unallocated_amount || 0), 0);
            if (id <= 0 || amount <= 0.005) {
                return false;
            }

            const key = id > 0
                ? `id:${id}`
                : [
                    String(advance?.receipt_no || '').trim().toUpperCase(),
                    String(advance?.currency || '').trim().toUpperCase(),
                    formatMoney(amount),
                ].join('|');
            if (seen.has(key)) {
                return false;
            }
            seen.add(key);
            return true;
        });
    };

    const currentPaymentAdvanceContext = () => ({
        branchId: Number.parseInt(String(bookingBranchField?.value || 0), 10) || 0,
        travelerId: Number.parseInt(String(bookingSelectedTravelerIdField?.value || 0), 10) || 0,
        currency: String(paymentCurrencySelect?.value || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase(),
        invoiceCurrency: String(currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase(),
    });

    const setPaymentAdvanceVisible = (visible) => {
        if (paymentAdvanceRow) {
            paymentAdvanceRow.hidden = false;
        }
        if (paymentAdvanceSelect) {
            paymentAdvanceSelect.disabled = !visible;
        }
        if (paymentAdvanceAmountRow) {
            paymentAdvanceAmountRow.hidden = !visible;
        }
        if (!visible) {
            if (paymentAdvanceSelect) {
                paymentAdvanceSelect.value = '';
            }
            if (paymentAdvanceAmountInput) {
                paymentAdvanceAmountInput.value = '0';
            }
        }
    };

    const syncPaymentAdvanceAmountCap = (currentDueOverride = null) => {
        if (!paymentAdvanceAmountInput) {
            return 0;
        }

        const selectedAdvance = selectedPaymentAdvanceOption();
        if (!selectedAdvance) {
            paymentAdvanceAmountInput.value = '0';
            return 0;
        }

        const invoiceCurrency = paymentCurrentInvoiceInput?.dataset.paymentCurrency || currentInvoiceSnapshot().invoiceCurrency || 'PKR';
        const paymentCurrency = paymentCurrencySelect?.value || invoiceCurrency;
        if (String(invoiceCurrency).toUpperCase() !== String(paymentCurrency).toUpperCase()) {
            paymentAdvanceAmountInput.value = '0';
            setPaymentAdvanceVisible(false);
            return 0;
        }

        const available = Math.max(toNumber(selectedAdvance.unallocated_amount || 0), 0);
        const fallbackDue = paymentTotalOutstandingInput?.dataset.paymentTotalDueNow
            || paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance
            || paymentCurrentBalanceInput?.value
            || 0;
        const currentDue = Math.max(toNumber(currentDueOverride ?? fallbackDue), 0);
        const cashNow = Math.max(toNumber(receivedNowInput?.value || 0), 0);
        const maxAdvanceUse = Math.max(Math.min(available, Math.max(currentDue - cashNow, 0)), 0);
        let requested = Math.max(toNumber(paymentAdvanceAmountInput.value || 0), 0);

        if (requested <= 0.005 && maxAdvanceUse > 0.005 && paymentAdvanceSelect?.value) {
            requested = maxAdvanceUse;
        }
        if (requested > maxAdvanceUse) {
            requested = maxAdvanceUse;
        }

        paymentAdvanceAmountInput.value = formatNumberInputValue(requested);
        return requested;
    };

    const refreshPaymentAdvanceControls = async () => {
        if (!paymentAdvanceSelect || !paymentAdvanceAmountInput || !customerAdvanceAvailableUrl) {
            return;
        }

        const context = currentPaymentAdvanceContext();
        if (context.branchId <= 0 || context.travelerId <= 0 || context.currency !== context.invoiceCurrency) {
            paymentAdvanceOptions = [];
            paymentAdvanceSelect.innerHTML = '<option value="">No advance available</option>';
            setPaymentAdvanceVisible(false);
            return;
        }

        if (paymentAdvanceLoadController) {
            paymentAdvanceLoadController.abort();
        }
        paymentAdvanceLoadController = new AbortController();

        try {
            const url = new URL(customerAdvanceAvailableUrl, window.location.href);
            url.searchParams.set('branch_id', String(context.branchId));
            url.searchParams.set('traveler_id', String(context.travelerId));
            url.searchParams.set('currency', context.currency);

            const response = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: paymentAdvanceLoadController.signal,
            });
            const payload = await response.json().catch(() => ({ ok: false, advances: [] }));
            paymentAdvanceOptions = uniqueCustomerAdvanceRows(payload.advances);
            const previousValue = paymentAdvanceSelect.value;

            paymentAdvanceSelect.innerHTML = '<option value="">No advance available</option>';
            paymentAdvanceOptions.forEach((advance) => {
                const amount = Math.max(toNumber(advance.unallocated_amount || 0), 0);
                const option = document.createElement('option');
                option.value = String(advance.id || '');
                option.dataset.amount = String(amount);
                option.textContent = `${advance.receipt_no || 'Advance'} / ${context.currency} ${formatNumberInputValue(amount)}`;
                paymentAdvanceSelect.appendChild(option);
            });

            if (paymentAdvanceSelect.options.length > 1) {
                paymentAdvanceSelect.options[0].textContent = 'Select customer advance';
            }
            if (previousValue && Array.from(paymentAdvanceSelect.options).some((option) => option.value === previousValue)) {
                paymentAdvanceSelect.value = previousValue;
            }

            setPaymentAdvanceVisible(paymentAdvanceOptions.length > 0);
            syncPaymentAdvanceAmountCap();
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
            paymentAdvanceOptions = [];
            setPaymentAdvanceVisible(false);
            logWorkflowTrace('customer-advance:payment-load-failed', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
    };

    const refreshPaymentPreview = () => {
        if (!receivedNowInput || !paymentCurrentInvoiceInput || !paymentCurrentBalanceInput || !paymentTotalOutstandingInput) {
            return;
        }

        syncReceiptScopeTargets();

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
        const currentInvoiceDueBeforeReceipt = paymentInvoiceBalanceIsLocked()
            ? persistedInvoiceBalanceAmount()
            : (hasPersistedInvoiceState
                ? Math.max(currentInvoiceAmount - persistedAlreadyReceived, 0)
                : Math.max(currentInvoiceAmount, 0));
        const currentBalance = Math.max(currentInvoiceDueBeforeReceipt, 0);
        const openBalanceDetails = syncOpenBalanceDisplay(invoiceCurrency, currentBalance);
        let balanceInPaymentCurrency = paymentCurrency === invoiceCurrency
            ? Math.max(currentBalance, 0)
            : 0;
        let returnAmount = Math.max(receivedNow - balanceInPaymentCurrency, 0);
        let currentInvoiceDueInPaymentCurrency = paymentCurrency === invoiceCurrency
            ? currentBalance
            : 0;
        let advanceUsedNow = 0;
        if (paymentCurrency === invoiceCurrency) {
            advanceUsedNow = syncPaymentAdvanceAmountCap(currentInvoiceDueInPaymentCurrency);
        }
        let remainingCurrentInvoiceDueInPaymentCurrency = Math.max(currentInvoiceDueInPaymentCurrency - receivedNow - advanceUsedNow, 0);
        let hasRemainingOutstanding = remainingCurrentInvoiceDueInPaymentCurrency > 0.005;
        const crossCurrencyCurrentInvoice = paymentCurrency !== invoiceCurrency && currentBalance > 0.005;
        const crossCurrencyPreview = crossCurrencyCurrentInvoice ? exchangeSettlementPreview() : null;

        if (
            crossCurrencyPreview
            && (
                crossCurrencyPreview.target.currency === crossCurrencyPreview.quote.paymentCurrency
                || toNumber(crossCurrencyPreview.quote.exchangeRate || 0) > 0.005
            )
        ) {
            balanceInPaymentCurrency = Math.max(toNumber(crossCurrencyPreview.paymentRequiredToFullyClearTarget || 0), 0);
            returnAmount = Math.max(toNumber(crossCurrencyPreview.returnOrCredit || 0), 0);
            currentInvoiceDueInPaymentCurrency = Math.max(toNumber(crossCurrencyPreview.paymentRequiredToFullyClearTarget || 0), 0);
            remainingCurrentInvoiceDueInPaymentCurrency = Math.max(toNumber(crossCurrencyPreview.remainingPaymentAmount || 0), 0);
            advanceUsedNow = 0;
            hasRemainingOutstanding = receivedNow > 0.005
                ? Math.max(toNumber(crossCurrencyPreview.remainingTargetBalance || 0), 0) > 0.005
                : remainingCurrentInvoiceDueInPaymentCurrency > 0.005;
        }
        const hasCurrentInvoiceAmount = currentInvoiceAmount > 0.005
            || currentBalance > 0.005
            || persistedAlreadyReceived > 0.005
            || persistedCurrentInvoiceBalance > 0.005
            || hasSavedServiceRows();

        syncPaymentCurrencyLabels(paymentCurrency);
        if (paymentCurrentInvoiceInput instanceof HTMLInputElement) {
            paymentCurrentInvoiceInput.value = formatNumberInputValue(currentInvoiceAmount);
        }
        if (paymentAlreadyReceivedInput) {
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(invoiceCurrency, persistedAlreadyReceived);
        }
        paymentCurrentBalanceInput.value = formatCurrencyAmount(invoiceCurrency, currentBalance);
        paymentTotalOutstandingInput.value = formatCurrencyAmount(paymentCurrency, balanceInPaymentCurrency);
        paymentTotalOutstandingInput.dataset.paymentTotalDueNow = String(currentInvoiceDueInPaymentCurrency);
        syncCurrentBalancePkrEquivalent(currentBalance, invoiceCurrency);
        syncActivePassengerSummaryDisplay();

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

        if (!realInvoiceExists && currentInvoiceDueInPaymentCurrency <= 0.005) {
            computedState = 'Draft';
        } else if (hasRemainingOutstanding && receivedNow <= 0.005) {
            if (persistedState.label === 'Overdue') {
                computedState = 'Overdue';
                computedHelper = persistedState.helper || '';
            } else {
                computedState = 'Unpaid';
            }
        } else if (hasRemainingOutstanding && receivedNow > 0.005) {
            computedState = 'Partially Paid';
        } else if (remainingCurrentInvoiceDueInPaymentCurrency <= 0.005 && realInvoiceExists) {
            computedState = 'Paid';
        }

        setPaymentState(computedState, computedHelper);

        updateWorkflowState();
    };

    const markManualPaymentCurrencySelection = (paymentCurrency, invoiceCurrency = null) => {
        if (!paymentCurrencySelect) {
            return;
        }

        const normalizedInvoiceCurrency = String(invoiceCurrency || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();
        const bookingKey = `${currentBookingId()}|${normalizedInvoiceCurrency}`;
        paymentCurrencySelect.dataset.paymentManualSelection = '1';
        paymentCurrencySelect.dataset.paymentManualContext = bookingKey;
        paymentCurrencySelect.dataset.paymentDefaultContext = bookingKey;
        paymentCurrencySelect.value = String(paymentCurrency || normalizedInvoiceCurrency).toUpperCase();
    };

    const syncDefaultPaymentCurrency = (invoiceCurrency) => {
        if (!paymentCurrencySelect) {
            return;
        }

        const bookingKey = `${currentBookingId()}|${String(invoiceCurrency || 'PKR').toUpperCase()}`;
        const previousKey = String(paymentCurrencySelect.dataset.paymentDefaultContext || '');
        const hasManualSelection = paymentCurrencySelect.dataset.paymentManualSelection === '1'
            && String(paymentCurrencySelect.dataset.paymentManualContext || '') === bookingKey;
        const shouldReset = paymentCurrencySelect.value.trim() === '' || (!hasManualSelection && previousKey !== bookingKey);

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

        if (station.dataset.suppressExchangeAutoOpen === '1') {
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
        receivedNowInput.addEventListener('focus', () => {
            logPaymentInputRuntime('focus');
        });
        receivedNowInput.addEventListener('input', refreshPaymentPreview);
        syncInvoiceDueDateMirrors();
        refreshPaymentPreview();
    }

    if (paymentCurrentInvoiceInput instanceof HTMLInputElement) {
        paymentCurrentInvoiceInput.addEventListener('input', () => {
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            applyManualInvoiceAmountOverride(paymentCurrentInvoiceInput.value);
            refreshPaymentPreview();
        });
        paymentCurrentInvoiceInput.addEventListener('change', () => {
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            applyManualInvoiceAmountOverride(paymentCurrentInvoiceInput.value);
            refreshPaymentPreview();
        });
    }

    if (paymentReceiptScopeSelect instanceof HTMLSelectElement) {
        paymentReceiptScopeSelect.addEventListener('change', () => {
            syncReceiptScopeTargets();
            syncPaymentAdvanceAmountCap();
            refreshPaymentPreview();
        });
    }

    if (paymentTargetReceivableSelect instanceof HTMLSelectElement) {
        paymentTargetReceivableSelect.addEventListener('change', () => {
            syncPaymentAdvanceAmountCap();
            refreshPaymentPreview();
        });
    }

    if (paymentAdvanceSelect instanceof HTMLSelectElement) {
        paymentAdvanceSelect.addEventListener('change', () => {
            syncPaymentAdvanceAmountCap();
            refreshPaymentPreview();
        });
    }

    if (paymentAdvanceAmountInput instanceof HTMLInputElement) {
        paymentAdvanceAmountInput.addEventListener('input', refreshPaymentPreview);
        paymentAdvanceAmountInput.addEventListener('change', refreshPaymentPreview);
    }

    if (paymentCurrencySelect) {
        paymentCurrencySelect.addEventListener('change', async () => {
            markManualPaymentCurrencySelection(paymentCurrencySelect.value, currentInvoiceSnapshot().invoiceCurrency || 'PKR');
            if (noteSavedPaymentEditAttempt()) {
                syncPaymentTreasurySelector();
                syncPaymentCurrencyLabels(paymentCurrencySelect.value);
                await refreshPaymentAdvanceControls();
                refreshPaymentPreview();
                return;
            }

            syncPaymentTreasurySelector();
            syncPaymentCurrencyLabels(paymentCurrencySelect.value);
            await refreshPaymentAdvanceControls();
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

    bookingSelectedTravelerIdField?.addEventListener('change', () => {
        refreshPaymentAdvanceControls();
    });
    bookingBranchField?.addEventListener('change', () => {
        refreshPaymentAdvanceControls();
    });
    refreshPaymentAdvanceControls();

    if (paymentExchangeSettlementButton) {
        paymentExchangeSettlementButton.addEventListener('click', async () => {
            await openExchangeSettlementModal({ allowManualRatePreview: true });
        });
    }

    if (paymentPrintReceiptButton) {
        paymentPrintReceiptButton.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            const clickId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
            const clickGuard = window.__travelReceiptClickGuard || { clickedAt: 0, clickId: '' };
            const clickAgeMs = Date.now() - Number(clickGuard.clickedAt || 0);
            logWorkflowTrace('print-receipt:click-handler-entered', {
                clickId,
                previousClickId: String(clickGuard.clickId || ''),
                previousClickAgeMs: clickAgeMs,
                eventDetail: Number(event.detail || 0),
                eventIsTrusted: event.isTrusted === true,
                printReceiptInFlight,
            });

            if (clickAgeMs < 1200) {
                logWorkflowTrace('print-receipt:blocked-duplicate-click', {
                    clickId,
                    previousClickId: String(clickGuard.clickId || ''),
                    previousClickAgeMs: clickAgeMs,
                });
                return;
            }

            window.__travelReceiptClickGuard = {
                clickedAt: Date.now(),
                clickId,
                scriptInstanceId: workspaceScriptInstanceId,
            };

            if (printReceiptInFlight) {
                logWorkflowTrace('print-receipt:ignored-in-flight', {
                    clickId,
                });
                return;
            }

            printReceiptInFlight = true;
            try {
                const hasSavedReceipt = hasOpenableCustomerReceipt();
                const savedPaymentStillApplies = currentSavedPaymentApplies();
                const enteredPaymentAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);
                const shouldSaveBeforePrint = enteredPaymentAmount > 0.005
                    || !hasSavedReceipt
                    || (!savedPaymentStillApplies && currentServiceDraftNeedsPersistForWorkflow());

                logWorkflowTrace('print-receipt:clicked', {
                    clickId,
                    shouldSaveBeforePrint,
                    hasSavedReceipt,
                    savedPaymentStillApplies,
                    enteredPaymentAmount,
                });

                if (!shouldSaveBeforePrint && hasSavedReceipt && openCustomerReceiptWindowOnce('print-existing')) {
                    logWorkflowTrace('print-receipt:opened-existing');
                    return;
                }

                if (shouldSaveBeforePrint) {
                    logWorkflowTrace('print-receipt:save-before-open:start');
                    const payload = await performSameCurrencyPaymentSave({
                        autoOpenReceipt: false,
                    });
                    logWorkflowTrace('print-receipt:save-before-open:payload-returned', {
                        clickId,
                        payloadOk: Boolean(payload),
                        receiptId: Number.parseInt(String(payload?.receipt_id || 0), 10) || 0,
                        bookingId: Number.parseInt(String(payload?.booking_id || 0), 10) || 0,
                    });
                    if (payload && openCustomerReceiptWindowOnce('print-after-save')) {
                        logWorkflowTrace('print-receipt:save-before-open:opened-after-save', {
                            receiptId: Number.parseInt(String(payload.receipt_id || 0), 10) || 0,
                        });
                        return;
                    }
                    logWorkflowTrace('print-receipt:save-before-open:no-open-after-save', {
                        payloadOk: Boolean(payload),
                    });
                }

                if (hasOpenableCustomerReceipt() && openCustomerReceiptWindowOnce('print-fallback-existing')) {
                    logWorkflowTrace('print-receipt:opened-fallback-existing');
                    return;
                }

                logWorkflowTrace('print-receipt:open-failed');
                showFeedback('Receipt could not be opened yet. Please review the payment and try again.');
            } finally {
                printReceiptInFlight = false;
            }
        }, true);
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

    if (paymentExchangePaymentCurrencyInput instanceof HTMLSelectElement) {
        paymentExchangePaymentCurrencyInput.addEventListener('change', () => {
            const nextCurrency = String(paymentExchangePaymentCurrencyInput.value || '').trim().toUpperCase();
            if (nextCurrency === '') {
                return;
            }

            markManualPaymentCurrencySelection(nextCurrency, currentInvoiceSnapshot().invoiceCurrency || 'PKR');
            paymentExchangePaymentCurrencyInput.value = nextCurrency;

            syncPaymentTreasurySelector();
            syncPaymentCurrencyLabels(nextCurrency);
            refreshPaymentPreview();
            exchangeSettlementPreview();
        });
    }

    if (paymentExchangeRateInput) {
        paymentExchangeRateInput.addEventListener('input', () => {
            paymentExchangeConfirmFocusDone = false;
            exchangeSettlementPreview();
        });
        paymentExchangeRateInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            void confirmExchangeSettlement();
        });
    }

    if (receivedNowInput) {
        receivedNowInput.addEventListener('input', async () => {
            logPaymentInputRuntime('input', {
                enteredValue: receivedNowInput.value,
            });
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                exchangeSettlementPreview();
            }
        });
        receivedNowInput.addEventListener('change', async () => {
            logPaymentInputRuntime('change', {
                changedValue: receivedNowInput.value,
            });
            if (noteSavedPaymentEditAttempt()) {
                refreshPaymentPreview();
                return;
            }

            if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                exchangeSettlementPreview();
            }
        });
    }

    const syncSettlementRateFieldsFromPreview = (preview) => {
        if (!preview) {
            return;
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
    };

    const applyExchangeSettlementPayloadFromPreview = (preview, options = {}) => {
        const { rateOnly = false } = options;
        if (!preview) {
            return;
        }

        if (paymentSettlementModeInput) {
            paymentSettlementModeInput.value = rateOnly ? 'normal' : 'exchange';
        }
        if (paymentSettlementTargetIdInput) {
            paymentSettlementTargetIdInput.value = rateOnly ? '' : String(preview.target.id || 0);
        }
        if (paymentSettlementTargetCurrencyInput) {
            paymentSettlementTargetCurrencyInput.value = rateOnly ? '' : String(preview.target.currency || 'PKR');
        }
        if (paymentSettlementTargetReceivableAmountInput) {
            paymentSettlementTargetReceivableAmountInput.value = rateOnly ? '' : formatNumberInputValue(preview.targetSettled);
        }
        if (paymentSettlementTargetPaymentAmountInput) {
            paymentSettlementTargetPaymentAmountInput.value = rateOnly ? '' : formatNumberInputValue(preview.paymentConsumed);
        }
        syncSettlementRateFieldsFromPreview(preview);
    };

    const saveExchangeSettlementRateOnly = async (preview) => {
        if (!(paymentForm instanceof HTMLFormElement)) {
            throw new Error('Payment form is not available.');
        }

        syncSettlementRateFieldsFromPreview(preview);
        const formData = new FormData();
        const csrfToken = paymentForm.elements.namedItem('_token');
        if (csrfToken instanceof HTMLInputElement) {
            formData.append('_token', csrfToken.value);
        }

        [
            'booking_id',
            'branch_id',
            'settlement_rate_from_currency',
            'settlement_rate_to_currency',
            'settlement_exchange_rate',
            'settlement_exchange_rate_effective_date',
        ].forEach((name) => {
            const field = paymentForm.elements.namedItem(name);
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
                formData.append(name, field.value);
            }
        });

        const response = await fetch(paymentExchangeRateSaveUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({
            ok: false,
            message: 'The server returned an invalid exchange-rate response.',
        }));

        if (!response.ok || payload.ok === false) {
            throw new Error(String(payload.message || 'Today\'s exchange rate could not be saved.'));
        }

        const rate = payload.rate || {};
        const fromCurrency = String(rate.from_currency || preview.quote.rateFromCurrency || '');
        const toCurrency = String(rate.to_currency || preview.quote.rateToCurrency || '');
        const exchangeRate = toNumber(rate.exchange_rate || preview.quote.exchangeRate || 0);
        const effectiveDate = String(rate.effective_date || preview.rateDate || '');
        if (fromCurrency !== '' && toCurrency !== '' && exchangeRate > 0.005) {
            dailySettlementRates[`${fromCurrency}->${toCurrency}`] = {
                fromCurrency,
                toCurrency,
                exchangeRate,
                effectiveDate,
                isDerived: false,
            };
        }

        return payload;
    };

    const saveDailyPricingExchangeRate = async (fromCurrency, toCurrency, exchangeRate, effectiveDate) => {
        if (!(paymentForm instanceof HTMLFormElement)) {
            throw new Error('Payment form is not available.');
        }

        const normalizedFrom = String(fromCurrency || '').trim().toUpperCase();
        const normalizedTo = String(toCurrency || '').trim().toUpperCase();
        const normalizedDate = normalizeLooseDate(effectiveDate || '')
            || normalizeLooseDate(paymentReceiptDateInput?.value || '')
            || new Date().toISOString().slice(0, 10);
        const normalizedRate = roundExchangeRate(exchangeRate);

        if (normalizedFrom === '' || normalizedTo === '' || normalizedFrom === normalizedTo) {
            return null;
        }
        if (normalizedRate <= 0.005) {
            throw new Error('A valid daily exchange rate is required.');
        }

        const formData = new FormData();
        const csrfToken = paymentForm.elements.namedItem('_token');
        if (csrfToken instanceof HTMLInputElement) {
            formData.append('_token', csrfToken.value);
        }

        const bookingIdField = paymentForm.elements.namedItem('booking_id');
        const branchIdField = paymentForm.elements.namedItem('branch_id');
        const fallbackBranchId = String(
            bookingBranchField?.value
            || serviceForm?.elements?.namedItem('auto_branch_id')?.value
            || '0'
        );
        formData.append('booking_id', bookingIdField instanceof HTMLInputElement ? bookingIdField.value : String(currentBookingId() || 0));
        formData.append('branch_id', branchIdField instanceof HTMLInputElement ? branchIdField.value : fallbackBranchId);
        formData.append('settlement_rate_from_currency', normalizedFrom);
        formData.append('settlement_rate_to_currency', normalizedTo);
        formData.append('settlement_exchange_rate', String(normalizedRate));
        formData.append('settlement_exchange_rate_effective_date', normalizedDate);

        const response = await fetch(paymentExchangeRateSaveUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({
            ok: false,
            message: 'The server returned an invalid exchange-rate response.',
        }));

        if (!response.ok || payload.ok === false) {
            throw new Error(String(payload.message || 'Today\'s exchange rate could not be saved.'));
        }

        const rate = payload.rate || {};
        const savedFrom = String(rate.from_currency || normalizedFrom).trim().toUpperCase();
        const savedTo = String(rate.to_currency || normalizedTo).trim().toUpperCase();
        const savedRate = roundExchangeRate(rate.exchange_rate || normalizedRate);
        const savedDate = String(rate.effective_date || normalizedDate);
        dailySettlementRates[`${savedFrom}->${savedTo}`] = {
            fromCurrency: savedFrom,
            toCurrency: savedTo,
            exchangeRate: savedRate,
            effectiveDate: savedDate,
            isDerived: false,
        };
        if (dailySettlementRatesDataNode) {
            dailySettlementRatesDataNode.textContent = JSON.stringify(dailySettlementRates);
        }

        return payload;
    };

    const ensurePricingExchangeRateReady = async (options = {}) => {
        const { reason = 'pricing', forcePrompt = false } = options;
        const invoiceCurrency = currentInvoiceCurrencyCode();
        const costCurrency = currentCostCurrencyCode();
        const effectiveDate = currentPricingRateEffectiveDate()
            || normalizeLooseDate(paymentReceiptDateInput?.value || '')
            || new Date().toISOString().slice(0, 10);

        if (invoiceCurrency === costCurrency) {
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = '1';
            }
            return true;
        }

        const existingRate = resolvePricingExchangeRateFromMap(costCurrency, invoiceCurrency, effectiveDate);
        if (!forcePrompt && existingRate > 0.005) {
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = String(existingRate);
            }
            return true;
        }

        logCommercialCalculator('pricing-rate-required', reason, {
            costCurrency,
            invoiceCurrency,
            effectiveDate,
            existingRate,
        });

        const promptFromCurrency = costCurrency === 'PKR' && invoiceCurrency !== 'PKR'
            ? invoiceCurrency
            : costCurrency;
        const promptToCurrency = costCurrency === 'PKR' && invoiceCurrency !== 'PKR'
            ? costCurrency
            : invoiceCurrency;
        const promptExistingRate = resolvePricingExchangeRateFromMap(promptFromCurrency, promptToCurrency, effectiveDate);
        const rawRate = window.prompt(
            `Enter today's exchange rate for this invoice.\n\n1 ${promptFromCurrency} = ? ${promptToCurrency}\nDate: ${effectiveDate}`,
            promptExistingRate > 0.005 ? String(promptExistingRate) : ''
        );
        if (rawRate === null) {
            showFeedback(`Daily rate is required to convert ${costCurrency} cost into ${invoiceCurrency} invoice amount.`);
            return false;
        }

        const enteredRate = roundExchangeRate(rawRate);
        if (enteredRate <= 0.005) {
            showFeedback('Enter a valid exchange rate greater than zero.');
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = '';
            }
            return false;
        }

        try {
            await saveDailyPricingExchangeRate(promptFromCurrency, promptToCurrency, enteredRate, effectiveDate);
            const resolvedPricingRate = resolvePricingExchangeRateFromMap(costCurrency, invoiceCurrency, effectiveDate);
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = String(resolvedPricingRate > 0.005 ? resolvedPricingRate : enteredRate);
            }
            if (serviceFields.pricingRateEffectiveDate instanceof HTMLInputElement) {
                serviceFields.pricingRateEffectiveDate.value = effectiveDate;
            }
            logCommercialCalculator('pricing-rate-saved', reason, {
                costCurrency,
                invoiceCurrency,
                promptFromCurrency,
                promptToCurrency,
                effectiveDate,
                enteredRate,
                resolvedPricingRate,
            });
            showFeedback(`Daily rate saved: 1 ${promptFromCurrency} = ${enteredRate} ${promptToCurrency}.`);
            refreshProfit(`pricing-rate-saved:${reason}`);
            return true;
        } catch (error) {
            showFeedback(error.message || 'Today\'s exchange rate could not be saved.');
            logCommercialCalculator('pricing-rate-save-failed', reason, {
                costCurrency,
                invoiceCurrency,
                effectiveDate,
                enteredRate,
                message: error.message || String(error),
            });
            return false;
        }
    };

    const confirmExchangeSettlement = async () => {
        if (paymentExchangeConfirmInFlight) {
            return;
        }

        clearExchangeFeedback();
        const preview = exchangeSettlementPreview();
        if (!preview) {
            showExchangeFeedback('Choose a settlement target first.');
            return;
        }

        const isRateOnlySave = preview.paymentAmount <= 0.005;

        if (currentSavedPaymentApplies() && !isRateOnlySave) {
            showExchangeFeedback(savedPaymentLockedMessage());
            syncSavedPaymentUiState();
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

        if (!isRateOnlySave && (Number.parseInt(String(preview.target?.id || 0), 10) || 0) <= 0) {
            showExchangeFeedback('Save the current invoice first before applying exchange settlement to the payment.');
            return;
        }
        if (!isRateOnlySave && (preview.targetSettled <= 0.005 || preview.paymentConsumed <= 0.005)) {
            showExchangeFeedback('The entered payment does not settle any amount on the selected target.');
            return;
        }

        applyExchangeSettlementPayloadFromPreview(preview, { rateOnly: isRateOnlySave });

        if (!(paymentForm instanceof HTMLFormElement)) {
            showExchangeFeedback('Payment form is not available.');
            return;
        }

        if (!isRateOnlySave && !ensurePaymentDetailReadyForSave()) {
            showExchangeFeedback(`Enter ${currentPaymentMethodLabel()} details before saving payment.`);
            return;
        }

        if (!isRateOnlySave && receivedNowInput) {
            receivedNowInput.value = formatNumberInputValue(preview.paymentAmount);
        }

        paymentExchangeConfirmInFlight = true;
        paymentExchangeConfirmButton.disabled = true;
        paymentExchangeConfirmButton.textContent = isRateOnlySave ? 'Saving Rate...' : 'Saving...';

        try {
            const payload = isRateOnlySave
                ? await saveExchangeSettlementRateOnly(preview)
                : await (async () => {
                    const response = await fetch(paymentForm.action, {
                        method: 'POST',
                        body: new FormData(paymentForm),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });

                    const receiptPayload = await response.json().catch(() => ({
                        ok: false,
                        message: 'The server returned an invalid exchange settlement response.',
                    }));

                    if (!response.ok || receiptPayload.ok === false) {
                        throw new Error(String(receiptPayload.message || 'Exchange settlement could not be saved.'));
                    }

                    return receiptPayload;
                })();

            if (isRateOnlySave) {
                exchangeSettlementPreview();
                triggerExchangeSettlementClose();
                window.setTimeout(() => {
                    syncPaymentCurrencyLabels(paymentCurrencySelect?.value || preview.quote.paymentCurrency || 'PKR');
                    receivedNowInput?.focus();
                }, 20);
                showFeedback(String(payload.message || 'Today\'s exchange rate saved.'));
                return;
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
            showFeedback(successMessage);
            window.setTimeout(() => {
                triggerExchangeSettlementClose();
                const returnFocusTarget = paymentPrintReceiptButton && !paymentPrintReceiptButton.disabled
                    ? paymentPrintReceiptButton
                    : paymentPrimarySaveButton;
                returnFocusTarget?.focus({ preventScroll: true });
            }, 20);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Exchange settlement could not be saved.';
            showExchangeFeedback(message);
        } finally {
            paymentExchangeConfirmInFlight = false;
            syncSavedPaymentUiState();
        }
    };

    if (paymentExchangeConfirmButton) {
        paymentExchangeConfirmButton.addEventListener('click', () => {
            void confirmExchangeSettlement();
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
            if (noteSavedPaymentEditAttempt()) {
                return;
            }

            syncPaymentTreasurySelector();
            if (promptTreasuryAccountSetupIfMissing(paymentMethodSelect.value)) {
                return;
            }

            if (isNonCashPaymentMethod(paymentMethodSelect.value)) {
                openPaymentDetailModal();
                return;
            }

            clearPaymentDetailHiddenFields();
            closePaymentDetailModal();
        });
        paymentMethodSelect.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            if (noteSavedPaymentEditAttempt()) {
                return;
            }

            syncPaymentTreasurySelector();
            if (promptTreasuryAccountSetupIfMissing(paymentMethodSelect.value)) {
                return;
            }

            if (isDirectSupplierPaymentMethod(paymentMethodSelect.value) && directSupplierObligationSelect && !directSupplierObligationSelect.disabled) {
                directSupplierObligationSelect.focus();
                return;
            }

            if (
                paymentTreasuryAccountSelect
                && paymentTreasuryAccountRow
                && !paymentTreasuryAccountRow.hidden
                && !paymentTreasuryAccountSelect.disabled
            ) {
                paymentTreasuryAccountSelect.focus();
                return;
            }

            if (paymentPrimarySaveButton) {
                paymentPrimarySaveButton.focus();
            }
        });
    }
    if (directSupplierObligationSelect) {
        directSupplierObligationSelect.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            event.preventDefault();
            if (paymentPrimarySaveButton instanceof HTMLButtonElement) {
                paymentPrimarySaveButton.focus();
            }
        });
    }

    paymentDetailCloseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            closePaymentDetailModal();
            if (paymentDetailCloseFocusMode === 'after-save') {
                paymentDetailCloseFocusMode = 'method';
                focusAfterPaymentDetailModalClose();
                return;
            }

            paymentDetailCloseFocusMode = 'method';
            paymentMethodSelect?.focus();
        });
    });

    if (paymentDetailApplyButton) {
        paymentDetailApplyButton.addEventListener('click', () => {
            paymentDetailCloseFocusMode = 'after-save';
            applyPaymentDetailModalValues({ close: false });
            triggerPaymentDetailCloseButton();
        });
    }

    const paymentDetailFields = [
        paymentDetailReferenceInput,
        paymentDetailBankCardInput,
        paymentDetailChargesInput,
        paymentDetailRemarksInput,
    ].filter((field) => field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement);

    const triggerPaymentDetailCloseButton = () => {
        const preferredCloseButton = paymentDetailCloseButtons.find((button) =>
            button instanceof HTMLButtonElement
            && button.closest('[data-payment-detail-modal]') === paymentDetailModal
        );

        if (preferredCloseButton instanceof HTMLButtonElement) {
            preferredCloseButton.click();
            return;
        }

        closePaymentDetailModal();
    };

    paymentDetailFields.forEach((field, index) => {
        field.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const nextField = paymentDetailFields[index + 1];
            if (nextField) {
                nextField.focus();
                if (typeof nextField.select === 'function') {
                    nextField.select();
                }
                return;
            }

            if (paymentDetailApplyButton instanceof HTMLButtonElement) {
                paymentDetailApplyButton.click();
            } else {
                paymentDetailCloseFocusMode = 'after-save';
                applyPaymentDetailModalValues({ close: false });
                triggerPaymentDetailCloseButton();
            }
        });
    });

    syncPaymentTreasurySelector();

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

    const performSameCurrencyPaymentSave = async (options = {}) => {
        const {
            autoOpenReceipt = false,
            suppressReceiptCreation = false,
            workflowOrigin = 'payment',
        } = options;

        logWorkflowTrace('save-payment:start', {
            autoOpenReceipt,
            suppressReceiptCreation,
            workflowOrigin,
        });

        if (!(paymentForm instanceof HTMLFormElement)) {
            logWorkflowTrace('save-payment:aborted-no-form');
            return null;
        }

        if (paymentSubmitValidationInFlight) {
            logWorkflowTrace('save-payment:aborted-validation-in-flight');
            return null;
        }

        paymentSubmitValidationInFlight = true;
        paymentSubmitDebug.lastBackendError = null;
        paymentSubmitDebug.lastBackendResponse = null;

        try {
            if (currentSavedPaymentApplies()) {
                logWorkflowTrace('save-payment:aborted-already-saved');
                showFeedback(savedPaymentLockedMessage());
                syncSavedPaymentUiState();
                return null;
            }

            const isExchangeSettlement = paymentSettlementModeInput?.value === 'exchange';
            const selectedPaymentCurrency = paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR';
            const receivedAmount = Math.max(toNumber(receivedNowInput?.value || 0), 0);
            const selectedAdvanceUsed = paymentAdvanceSelect?.value
                ? Math.max(toNumber(paymentAdvanceAmountInput?.value || 0), 0)
                : 0;
            const usesCustomerAdvance = selectedAdvanceUsed > 0.005;
            let sameCurrencyDueNow = Math.max(
                toNumber(paymentTotalOutstandingInput?.dataset.paymentTotalDueNow || paymentTotalOutstandingInput?.value || 0),
                0
            );
            let invoiceSnapshot = currentInvoiceSnapshot();
            syncPaymentCurrencyLabels(selectedPaymentCurrency);

            const commitDraftForPayment = async () => {
                const draftNeedsPersist = currentServiceDraftRequiresPersistBeforePayment();
                if (!draftNeedsPersist && currentBookingId() > 0 && (currentPersistedServiceId() > 0 || hasValidSavedServiceForPayment())) {
                    logWorkflowTrace('save-payment:commit-draft-skip-existing', {
                        draftNeedsPersist,
                    });
                    window.workspaceDebugEnterFlow('payment-save-commit-draft-skip-existing', {
                        currentBookingId: currentBookingId(),
                        currentPersistedServiceId: currentPersistedServiceId(),
                        activeServiceId: activeServiceId(),
                        hasValidSavedServiceForPayment: hasValidSavedServiceForPayment(),
                        draftNeedsPersist,
                    });
                    return true;
                }

                if (typeof serviceAutosaveReady !== 'function' || !serviceAutosaveReady()) {
                    logWorkflowTrace('save-payment:commit-draft-not-ready');
                    showFeedback('Complete customer, invoice date, passenger, service type, and service amount before Save Payment.');
                    if (currentBookingId() <= 0 && typeof autosaveBookingReady === 'function' && !autosaveBookingReady()) {
                        focusTarget('input[name="lead_traveler_name"]');
                    } else if (servicePassengerNameField instanceof HTMLInputElement && servicePassengerNameField.value.trim() === '') {
                        servicePassengerNameField.focus();
                    }
                    return false;
                }

                logWorkflowTrace('save-payment:commit-draft-start', {
                    draftNeedsPersist,
                });
                window.workspaceDebugEnterFlow('payment-save-commit-draft-start', {
                    currentBookingId: currentBookingId(),
                    currentPersistedServiceId: currentPersistedServiceId(),
                    activeServiceId: activeServiceId(),
                    draftNeedsPersist,
                    selectedPaymentCurrency,
                    receivedAmount,
                    selectedAdvanceUsed,
                });

                const servicePayload = await persistServiceAutosave({ allowCreate: true });
                if (!servicePayload || currentBookingId() <= 0 || currentPersistedServiceId() <= 0) {
                    logWorkflowTrace('save-payment:commit-draft-failed', {
                        servicePayloadOk: Boolean(servicePayload),
                    });
                    showFeedback('The invoice/service could not be saved. Please review required fields before Save Payment.');
                    window.workspaceDebugEnterFlow('payment-save-commit-draft-failed', {
                        currentBookingId: currentBookingId(),
                        currentPersistedServiceId: currentPersistedServiceId(),
                        servicePayloadOk: Boolean(servicePayload),
                    });
                    return false;
                }

                refreshSettlementDataFromPayload(servicePayload);
                refreshPaymentHistoryFromPayload(servicePayload);
                applyAutosavePaymentFoundation(servicePayload, { syncCommercialEditor: false });
                invoiceSnapshot = currentInvoiceSnapshot();
                sameCurrencyDueNow = Math.max(
                    toNumber(paymentTotalOutstandingInput?.dataset.paymentTotalDueNow || paymentTotalOutstandingInput?.value || 0),
                    0
                );

                logWorkflowTrace('save-payment:commit-draft-done', {
                    savedServiceId: currentPersistedServiceId(),
                });
                window.workspaceDebugEnterFlow('payment-save-commit-draft-done', {
                    currentBookingId: currentBookingId(),
                    currentPersistedServiceId: currentPersistedServiceId(),
                    sameCurrencyDueNow,
                    invoiceCurrency: invoiceSnapshot.invoiceCurrency,
                });

                return true;
            };

            if (!await commitDraftForPayment()) {
                logWorkflowTrace('save-payment:aborted-commit-draft-false');
                return null;
            }

            if (receivedAmount <= 0.005) {
                logWorkflowTrace('save-payment:zero-amount-branch');
                const zeroAmountReceiptAction = suppressReceiptCreation ? 'no_receipt' : 'save';
                if (receivedNowInput) {
                    receivedNowInput.value = '0';
                }
                if (quickReceiveInput) {
                    quickReceiveInput.value = '0';
                }

                paymentSubmitDebug.currentBookingId = currentBookingId();
                paymentSubmitDebug.parsedAmountReceiving = 0;
                paymentSubmitDebug.paymentCurrency = selectedPaymentCurrency;
                paymentSubmitDebug.invoiceCurrency = invoiceSnapshot.invoiceCurrency;
                paymentSubmitDebug.dueDate = paymentDueDateInput?.value || '';
                paymentSubmitDebug.lastAttemptedPayload = {
                    booking_id: currentBookingId(),
                    received_amount: '0',
                    receipt_action: suppressReceiptCreation ? 'no_receipt' : 'save',
                };

                if (paymentPrimarySaveButton) {
                    paymentPrimarySaveButton.disabled = true;
                    paymentPrimarySaveButton.textContent = 'Saving...';
                }

                try {
                    if (typeof autosaveBookingReady === 'function' && autosaveBookingReady()) {
                        const invoicePayload = await persistInvoiceAutosave({ force: true });
                        if (invoicePayload) {
                            refreshSettlementDataFromPayload(invoicePayload);
                            refreshPaymentHistoryFromPayload(invoicePayload);
                            applyAutosavePaymentFoundation(invoicePayload, { syncCommercialEditor: false });
                        }
                    }
                } catch (zeroPaymentSaveError) {
                    throw new Error(zeroPaymentSaveError instanceof Error ? zeroPaymentSaveError.message : 'Booking could not be updated without payment.');
                }

                if (suppressReceiptCreation && !usesCustomerAdvance) {
                    const payload = {
                        ok: true,
                        booking_id: currentBookingId(),
                        receipt_id: 0,
                        received_amount: 0,
                        receipt_action: zeroAmountReceiptAction,
                        workflow_origin: workflowOrigin,
                    };

                    showFeedback('Service saved. Add the next passenger/service.');
                    logWorkflowTrace('save-payment:zero-amount-no-receipt', {
                        workflowOrigin,
                        bookingId: payload.booking_id,
                    });
                    station.dispatchEvent(new CustomEvent('workspace:service-saved-without-receipt', {
                        bubbles: true,
                        detail: payload,
                    }));

                    return payload;
                }

                const csrfField = paymentForm.elements.namedItem('_token');
                const csrfToken = csrfField instanceof HTMLInputElement ? csrfField.value.trim() : '';
                const formData = new FormData(paymentForm);
                formData.set('receipt_action', zeroAmountReceiptAction);

                paymentSubmitDebug.csrfFound = csrfToken !== '';
                paymentSubmitDebug.routeUrl = paymentForm.action;
                paymentSubmitDebug.lastAttemptedPayload = Object.fromEntries(formData.entries());

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
                    logWorkflowTrace('save-payment:zero-amount-backend-failed', {
                        payloadMessage: String(payload.message || ''),
                    });
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

                if (autoOpenReceipt) {
                    showFeedback('Invoice saved without payment. Use Print Receipt after a payment is recorded.');
                }

                logWorkflowTrace('save-payment:zero-amount-saved', {
                    receiptId: Number.parseInt(String(payload.receipt_id || 0), 10) || 0,
                    advanceApplied: payload.advance_applied || null,
                    autoOpenReceipt,
                    suppressReceiptCreation: true,
                    workflowOrigin,
                });

                station.dispatchEvent(new CustomEvent('workspace:payment-zero-saved', {
                    bubbles: true,
                    detail: {
                        booking_id: Number.parseInt(String(payload.booking_id || currentBookingId()), 10) || currentBookingId(),
                        receipt_id: Number.parseInt(String(payload.receipt_id || 0), 10) || 0,
                        received_amount: 0,
                    },
                }));

                return payload;
            }

            if (selectedPaymentCurrency !== invoiceSnapshot.invoiceCurrency) {
                logWorkflowTrace('save-payment:cross-currency-branch', {
                    selectedPaymentCurrency,
                    invoiceCurrency: invoiceSnapshot.invoiceCurrency,
                });
                if (invoiceSnapshot.invoiceBalance > 0.005) {
                    if (receivedAmount <= 0.005) {
                        showFeedback('Enter the receiving amount first, or use Exchange Settlement only to save today\'s rate.');
                        return null;
                    }

                    const crossCurrencyPreview = exchangeSettlementPreview();
                    if (!crossCurrencyPreview) {
                        showFeedback('Open Exchange Settlement first after changing payment currency, then save the payment or receipt.');
                        return null;
                    }
                    if (crossCurrencyPreview.target.currency !== crossCurrencyPreview.quote.paymentCurrency && toNumber(crossCurrencyPreview.quote.exchangeRate || 0) <= 0.005) {
                        showFeedback('Open Exchange Settlement first after changing payment currency, then save the payment or receipt.');
                        return null;
                    }
                    if (crossCurrencyPreview.targetSettled <= 0.005 || crossCurrencyPreview.paymentConsumed <= 0.005) {
                        showFeedback('The entered payment does not settle any amount on the current invoice.');
                        return null;
                    }

                    applyExchangeSettlementPayloadFromPreview(crossCurrencyPreview, { rateOnly: false });
                } else {
                    showFeedback('Cross-currency settlement is under final testing. Please use same-currency payment for now.');
                    return null;
                }
            }

            if (isExchangeSettlement) {
                clearExchangeSettlementFields();
            }

            if (typeof serviceAutosaveReady === 'function' && serviceAutosaveReady()) {
                window.workspaceDebugEnterFlow('payment-save-force-service-persist-start', {
                    currentBookingId: currentBookingId(),
                    currentPersistedServiceId: currentPersistedServiceId(),
                    selectedPaymentCurrency,
                    receivedAmount,
                });

                const servicePayload = await persistServiceAutosave({ allowCreate: true });
                if (servicePayload) {
                    logWorkflowTrace('save-payment:force-service-persist-done', {
                        savedServiceId: Number.parseInt(String(servicePayload.service_id || 0), 10) || currentPersistedServiceId(),
                    });
                    refreshSettlementDataFromPayload(servicePayload);
                    refreshPaymentHistoryFromPayload(servicePayload);
                    applyAutosavePaymentFoundation(servicePayload, { syncCommercialEditor: false });
                    invoiceSnapshot = currentInvoiceSnapshot();
                    sameCurrencyDueNow = Math.max(
                        toNumber(paymentTotalOutstandingInput?.dataset.paymentTotalDueNow || paymentTotalOutstandingInput?.value || 0),
                        0
                    );
                }

                window.workspaceDebugEnterFlow('payment-save-force-service-persist-done', {
                    currentBookingId: currentBookingId(),
                    currentPersistedServiceId: currentPersistedServiceId(),
                    sameCurrencyDueNow,
                    invoiceCurrency: invoiceSnapshot.invoiceCurrency,
                });
            }

            if (sameCurrencyDueNow <= 0.005) {
                showFeedback('No outstanding balance exists in the selected payment currency.');
                return null;
            }

            const currentServiceId = currentPersistedServiceId();
            const paymentReady = currentServiceId > 0
                && (
                    autosavedPaymentEligible
                    || autosavedReceivableAmount > 0.005
                    || hasValidSavedServiceForPayment()
                );

            if (!paymentReady && sameCurrencyDueNow <= 0.005) {
                showFeedback('No outstanding balance exists in the selected payment currency.');
                return null;
            }

            if (selectedPaymentCurrency === invoiceSnapshot.invoiceCurrency) {
                clearExchangeSettlementFields();
            }

            if (promptTreasuryAccountSetupIfMissing(paymentMethodSelect?.value || '')) {
                return null;
            }
            if (promptDirectSupplierPayableIfMissing()) {
                return null;
            }

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
                        return null;
                    }

                    paymentDueDateInput.value = normalizedDate;
                    if (paymentDueDatePicker) {
                        paymentDueDatePicker.value = normalizedDate;
                    }
                    syncInvoiceDueDateMirrors();
                }
            }

            if (!ensurePaymentDetailReadyForSave()) {
                return null;
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
                logWorkflowTrace('save-payment:backend-failed', {
                    payloadMessage: String(payload.message || ''),
                });
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
            if (autoOpenReceipt && !openCustomerReceiptWindowOnce('save-payment')) {
                showFeedback('Payment saved. Use Print Receipt if the receipt window did not open automatically.');
            }
            logWorkflowTrace('save-payment:saved', {
                receiptId: Number.parseInt(String(payload.receipt_id || 0), 10) || 0,
                receiptNo: receiptSummary.receiptNo,
                autoOpenReceipt,
            });
            station.dispatchEvent(new CustomEvent('workspace:payment-saved', { bubbles: true, detail: payload }));
            return payload;
        } catch (error) {
            paymentSubmitDebug.lastBackendError = error instanceof Error ? error.message : 'Customer receipt could not be saved.';
            logWorkflowTrace('save-payment:exception', {
                error: paymentSubmitDebug.lastBackendError,
            });
            showFeedback(paymentSubmitDebug.lastBackendError);
            return null;
        } finally {
            logWorkflowTrace('save-payment:finally');
            paymentSubmitValidationInFlight = false;
            syncSavedPaymentUiState();
        }
    };

    if (paymentPrimarySaveButton) {
        paymentSubmitDebug.saveButtonFound = true;
        paymentPrimarySaveButton.addEventListener('click', async () => {
            await performSameCurrencyPaymentSave({
                autoOpenReceipt: true,
            });
        });
        paymentPrimarySaveButton.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            event.preventDefault();
            paymentPrimarySaveButton.click();
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

    if (customerAdvanceOpenButton) {
        customerAdvanceOpenButton.addEventListener('click', () => openCustomerAdvanceModal());
    }

    if (customerAdvanceSearchInput) {
        let customerAdvanceSearchTimer = 0;
        customerAdvanceSearchInput.addEventListener('input', () => {
            window.clearTimeout(customerAdvanceSearchTimer);
            customerAdvanceSearchTimer = window.setTimeout(() => {
                renderCustomerAdvanceSearchResults();
            }, 120);
        });
        customerAdvanceSearchInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            const firstResult = customerAdvanceResults?.querySelector('[data-customer-advance-select]');
            if (firstResult instanceof HTMLElement) {
                event.preventDefault();
                firstResult.click();
            }
        });
    }

    if (customerAdvanceResults) {
        customerAdvanceResults.addEventListener('click', (event) => {
            const row = event.target instanceof HTMLElement ? event.target.closest('[data-customer-advance-select]') : null;
            if (!(row instanceof HTMLElement)) {
                return;
            }

            const customerId = Number.parseInt(String(row.dataset.customerAdvanceSelect || '0'), 10) || 0;
            const customer = findCustomerDirectoryEntryById(customerId);
            if (!customer) {
                setCustomerAdvanceFeedback('Customer could not be selected. Search again.', true);
                return;
            }

            selectCustomerForAdvance(customer);
        });
        customerAdvanceResults.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
                return;
            }

            const row = event.target instanceof HTMLElement ? event.target.closest('[data-customer-advance-select]') : null;
            if (!(row instanceof HTMLElement)) {
                return;
            }

            event.preventDefault();
            row.click();
        });
    }

    if (customerAdvanceNewCustomerButton) {
        customerAdvanceNewCustomerButton.addEventListener('click', () => {
            customerAdvanceNewCustomerMode = true;
            if (customerAdvanceModal instanceof HTMLElement) {
                customerAdvanceModal.hidden = true;
                customerAdvanceModal.setAttribute('aria-hidden', 'true');
            }
            openNewCustomerModal();
        });
    }

    customerAdvanceCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeCustomerAdvanceModal());
    });

    [customerAdvanceBranch, customerAdvanceCurrency, customerAdvanceMethod].forEach((field) => {
        if (field instanceof HTMLSelectElement) {
            field.addEventListener('change', () => {
                if (field === customerAdvanceBranch && customerAdvanceRefundBranch instanceof HTMLSelectElement) {
                    customerAdvanceRefundBranch.value = customerAdvanceBranch.value;
                }
                if (field === customerAdvanceCurrency && customerAdvanceRefundCurrency instanceof HTMLSelectElement) {
                    customerAdvanceRefundCurrency.value = customerAdvanceCurrency.value;
                }
                syncCustomerAdvanceTreasurySelector();
                syncCustomerAdvanceBankDetailVisibility();
                syncCustomerAdvanceRefundTreasurySelector();
                loadCustomerAdvanceRefundOptions();
            });
        }
    });

    [customerAdvanceRefundBranch, customerAdvanceRefundCurrency, customerAdvanceRefundMethod].forEach((field) => {
        if (field instanceof HTMLSelectElement) {
            field.addEventListener('change', () => {
                syncCustomerAdvanceRefundTreasurySelector();
                loadCustomerAdvanceRefundOptions();
            });
        }
    });

    if (customerAdvanceRefundReceipt instanceof HTMLSelectElement) {
        customerAdvanceRefundReceipt.addEventListener('change', () => syncCustomerAdvanceRefundAmountFromSelection());
    }

    if (customerAdvanceForm instanceof HTMLFormElement) {
        customerAdvanceForm.addEventListener('submit', (event) => {
            if (customerAdvanceForm.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            const travelerId = Number.parseInt(String(customerAdvanceTravelerId?.value || '0'), 10) || 0;
            const amount = toNumber(customerAdvanceAmount?.value || 0);
            const requiresTreasury = paymentMethodRequiresTreasurySelection(customerAdvanceMethod?.value || '');
            const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
            const wantsPrint = submitter?.hasAttribute('data-customer-advance-save-print') === true;
            if (customerAdvancePrintAfterSave instanceof HTMLInputElement) {
                customerAdvancePrintAfterSave.value = wantsPrint ? '1' : '0';
            }
            customerAdvanceForm.target = wantsPrint ? '_blank' : '';
            if (customerAdvanceReturnTo instanceof HTMLInputElement) {
                customerAdvanceReturnTo.value = `${window.location.pathname}${window.location.search}${window.location.hash}`;
            }
            if (travelerId <= 0) {
                event.preventDefault();
                setCustomerAdvanceFeedback('Select or add a customer before saving the advance.');
                customerAdvanceSearchInput?.focus();
                return;
            }
            if (amount <= 0) {
                event.preventDefault();
                setCustomerAdvanceFeedback('Enter the advance amount before saving.');
                customerAdvanceAmount?.focus();
                return;
            }
            if (requiresTreasury && String(customerAdvanceTreasuryAccount?.value || '').trim() === '') {
                event.preventDefault();
                setCustomerAdvanceFeedback('Select the deposit account before saving.');
                customerAdvanceTreasuryAccount?.focus();
                return;
            }

            customerAdvanceForm.dataset.submitting = '1';
            window.setTimeout(() => {
                if (customerAdvanceForm instanceof HTMLFormElement) {
                    customerAdvanceForm.dataset.submitting = '0';
                }
            }, 4000);
        });
    }

    if (customerAdvanceRefundForm instanceof HTMLFormElement) {
        customerAdvanceRefundForm.addEventListener('submit', (event) => {
            const travelerId = Number.parseInt(String(customerAdvanceRefundTravelerId?.value || '0'), 10) || 0;
            const amount = toNumber(customerAdvanceRefundAmount?.value || 0);
            const requiresTreasury = paymentMethodRequiresTreasurySelection(customerAdvanceRefundMethod?.value || '');
            if (customerAdvanceRefundReturnTo instanceof HTMLInputElement) {
                customerAdvanceRefundReturnTo.value = `${window.location.pathname}${window.location.search}${window.location.hash}`;
            }
            if (travelerId <= 0) {
                event.preventDefault();
                setCustomerAdvanceRefundFeedback('Select or add a customer before returning advance.');
                customerAdvanceSearchInput?.focus();
                return;
            }
            if (String(customerAdvanceRefundReceipt?.value || '').trim() === '') {
                event.preventDefault();
                setCustomerAdvanceRefundFeedback('Select the advance receipt to return.');
                customerAdvanceRefundReceipt?.focus();
                return;
            }
            if (amount <= 0) {
                event.preventDefault();
                setCustomerAdvanceRefundFeedback('Enter the return amount.');
                customerAdvanceRefundAmount?.focus();
                return;
            }
            if (requiresTreasury && String(customerAdvanceRefundTreasuryAccount?.value || '').trim() === '') {
                event.preventDefault();
                setCustomerAdvanceRefundFeedback('Select the account paid from.');
                customerAdvanceRefundTreasuryAccount?.focus();
                return;
            }
            const reasonField = customerAdvanceRefundForm.querySelector('[name="advance_refund_reason"]');
            if (reasonField instanceof HTMLInputElement && String(reasonField.value || '').trim() === '') {
                event.preventDefault();
                setCustomerAdvanceRefundFeedback('Enter the reason for returning the advance.');
                reasonField.focus();
            }
        });
    }

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

    if (documentFileInput instanceof HTMLInputElement) {
        documentFileInput.addEventListener('change', validateDocumentFileSelection);
    }

    if (documentUploadForm instanceof HTMLFormElement) {
        documentUploadForm.addEventListener('submit', (event) => {
            if (!validateDocumentFileSelection()) {
                event.preventDefault();
                documentFileInput?.focus();
            }
        });
    }

    const fillValue = (field, value) => {
        if (field) {
            if (field instanceof HTMLInputElement && field.type === 'number') {
                field.value = formatNumberInputValue(value ?? 0);
                return;
            }

            field.value = value ?? '';
        }
    };

    const normalizeNumberFieldDisplay = (field) => {
        if (!(field instanceof HTMLInputElement) || field.type !== 'number') {
            return;
        }

        const rawValue = String(field.value ?? '').trim();
        if (rawValue === '') {
            return;
        }

        field.value = formatNumberInputValue(rawValue);
    };

    const bindWholeNumberInputs = (root = station) => {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        root.querySelectorAll('input[type="number"]').forEach((field) => {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            normalizeNumberFieldDisplay(field);
            field.addEventListener('blur', () => normalizeNumberFieldDisplay(field));
        });
    };
    bindWholeNumberInputs();

    const syncServiceCurrencyMirror = () => {
        if (!serviceFields.currencyMirror || !serviceFields.currency) {
            return;
        }

        serviceFields.currencyMirror.value = serviceFields.currency.value || 'PKR';
    };

    function currentBookingId() {
        const candidates = [
            invoiceForm?.elements?.namedItem('booking_id'),
            serviceForm?.elements?.namedItem('booking_id'),
            paymentForm?.elements?.namedItem('booking_id'),
        ];
        const field = candidates.find((candidate) => candidate instanceof HTMLInputElement);
        return field instanceof HTMLInputElement ? Number.parseInt(field.value || '0', 10) : 0;
    }

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
        renderOfflineStatus();
        station.dispatchEvent(new CustomEvent('workspace:booking-synced', {
            bubbles: true,
            detail: {
                booking_id: normalizedBookingId,
            },
        }));
    };

    const currentPersistedServiceId = () => {
        const hiddenServiceId = Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10);
        if (hiddenServiceId > 0) {
            return hiddenServiceId;
        }

        if (autosavedPersistedServiceId > 0) {
            return autosavedPersistedServiceId;
        }

        return 0;
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

    const postAutosave = async (url, form, extraFields = {}) => {
        if (!(form instanceof HTMLFormElement) || url === '') {
            throw new Error('Autosave is not available for this form.');
        }

        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timeoutId = controller
            ? window.setTimeout(() => controller.abort(), 20000)
            : 0;
        let response;

        try {
            const formData = new FormData(form);
            Object.entries(extraFields || {}).forEach(([key, value]) => {
                formData.set(key, String(value));
            });
            response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined,
            });
        } catch (error) {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }

            if (error && typeof error === 'object' && error.name === 'AbortError') {
                throw new Error('Autosave timed out on this connection. Your current edits are still in the form.');
            }

            throw new Error('Autosave could not reach the server. Your current edits are still in the form.');
        }

        if (timeoutId) {
            window.clearTimeout(timeoutId);
        }

        const rawResponse = await response.text();
        let payload = null;
        try {
            payload = JSON.parse(rawResponse);
        } catch (error) {
            const genericMessage = response.status >= 500
                ? 'The server hit an internal error while autosaving. Your current edits are still in the form.'
                : 'The server returned an invalid autosave response.';
            payload = {
                ok: false,
                message: genericMessage,
            };
        }

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
    customerOpenReceivables = parseJsonDataNode(customerOpenReceivablesDataNode, 'customerOpenReceivables');
    dailySettlementRatesDataNode = station.querySelector('#workspace-daily-settlement-rates-data');
    dailySettlementRates = parseBalanceMap(dailySettlementRatesDataNode?.textContent || '{}');
    const paymentReceiptsDataNode = station.querySelector('#workspace-payment-receipts-data');
    let paymentReceipts = parseJsonDataNode(paymentReceiptsDataNode, 'paymentReceipts');
    const paymentAllocationsDataNode = station.querySelector('#workspace-payment-allocations-data');
    let paymentAllocations = parseJsonDataNode(paymentAllocationsDataNode, 'paymentAllocations');
    const customerDuesFinderUrl = String(customerDuesModal?.dataset.customerDuesUrl || '').trim();
    const supplierHistoryFinderUrl = String(supplierHistoryModal?.dataset.supplierHistoryUrl || '').trim();
    let supplierHistoryFinderState = {
        query: '',
        results: [],
        requestToken: 0,
    };
    const normalizeCustomerDuesQuery = (value) => String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    function findCustomerDirectoryEntryById(customerId) {
        const normalizedId = Number.parseInt(String(customerId || '0'), 10) || 0;
        if (normalizedId <= 0) {
            return null;
        }

        return customerDirectory.find((entry) => Number.parseInt(String(entry?.id || '0'), 10) === normalizedId) || null;
    }
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
    function syncCustomerDuesFinderWithWorkspaceCustomer() {
        const selectedTravelerId = Number.parseInt(String(bookingSelectedTravelerIdField?.value || '0'), 10) || 0;
        if (selectedTravelerId <= 0) {
            resetCustomerDuesSelection();
            return {
                travelerId: 0,
                customer: null,
                query: customerDuesSearchInput?.value || customerDuesFinderState.query || '',
            };
        }

        const activeCustomer = findCustomerDirectoryEntryById(selectedTravelerId);
        const preferredQuery = String(
            activeCustomer?.full_name
            || resolveBookingLeadField()?.value
            || customerDuesSearchInput?.value
            || customerDuesFinderState.query
            || ''
        ).trim();

        customerDuesFinderState = {
            ...customerDuesFinderState,
            selectedTravelerId,
            selectedCustomer: activeCustomer,
            query: preferredQuery,
            openInvoices: [],
        };

        if (customerDuesSearchInput instanceof HTMLInputElement) {
            customerDuesSearchInput.value = preferredQuery;
        }

        return {
            travelerId: selectedTravelerId,
            customer: activeCustomer,
            query: preferredQuery,
        };
    }
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
    const serviceRefundForm = station.querySelector('form[data-service-event-bar="refund"]');
    const serviceRefundMethodSelect = serviceRefundForm?.elements?.namedItem('refund_payment_method') instanceof HTMLSelectElement
        ? serviceRefundForm.elements.namedItem('refund_payment_method')
        : null;
    const serviceRefundTreasuryRow = station.querySelector('[data-service-refund-treasury-row]');
    const serviceRefundTreasurySelect = serviceRefundForm?.elements?.namedItem('refund_treasury_account_id') instanceof HTMLSelectElement
        ? serviceRefundForm.elements.namedItem('refund_treasury_account_id')
        : null;
    const serviceSupplierRefundMethodSelect = serviceRefundForm?.elements?.namedItem('supplier_refund_payment_method') instanceof HTMLSelectElement
        ? serviceRefundForm.elements.namedItem('supplier_refund_payment_method')
        : null;
    const serviceSupplierRefundTreasuryRow = station.querySelector('[data-service-supplier-refund-treasury-row]');
    const serviceSupplierRefundTreasurySelect = serviceRefundForm?.elements?.namedItem('supplier_refund_treasury_account_id') instanceof HTMLSelectElement
        ? serviceRefundForm.elements.namedItem('supplier_refund_treasury_account_id')
        : null;
    const serviceRefundBranchIdField = station.querySelector('[data-service-refund-branch-id]');
    const serviceRefundCurrencyField = station.querySelector('[data-service-refund-currency]');
    const serviceRefundDestinationRows = Array.from(station.querySelectorAll('[data-service-refund-destination-row]'));
    const serviceCorrectionSettlementForm = station.querySelector('form[data-service-correction-bar="settlement"]');
    const serviceCorrectionSettlementSummaryNote = serviceCorrectionSettlementForm?.querySelector('[data-service-correction-settlement-summary]') || null;
    const serviceCorrectionRefundForm = station.querySelector('form[data-service-correction-refund-form], form[data-service-correction-bar="refund"]');
    const serviceCorrectionRefundMethodSelect = serviceCorrectionRefundForm?.elements?.namedItem('refund_payment_method') instanceof HTMLSelectElement
        ? serviceCorrectionRefundForm.elements.namedItem('refund_payment_method')
        : null;
    const serviceCorrectionRefundTreasuryRow = serviceCorrectionRefundForm?.querySelector('[data-service-correction-refund-treasury-row]') || null;
    const serviceCorrectionRefundTreasurySelect = serviceCorrectionRefundForm?.elements?.namedItem('refund_treasury_account_id') instanceof HTMLSelectElement
        ? serviceCorrectionRefundForm.elements.namedItem('refund_treasury_account_id')
        : null;
    const serviceCorrectionSupplierRefundMethodSelect = serviceCorrectionRefundForm?.elements?.namedItem('supplier_refund_payment_method') instanceof HTMLSelectElement
        ? serviceCorrectionRefundForm.elements.namedItem('supplier_refund_payment_method')
        : null;
    const serviceCorrectionSupplierRefundTreasuryRow = serviceCorrectionRefundForm?.querySelector('[data-service-correction-supplier-refund-treasury-row]') || null;
    const serviceCorrectionSupplierRefundTreasurySelect = serviceCorrectionRefundForm?.elements?.namedItem('supplier_refund_treasury_account_id') instanceof HTMLSelectElement
        ? serviceCorrectionRefundForm.elements.namedItem('supplier_refund_treasury_account_id')
        : null;
    const serviceCorrectionRefundBranchIdField = serviceCorrectionRefundForm?.querySelector('[data-service-correction-refund-branch-id]') || null;
    const serviceCorrectionRefundCurrencyField = serviceCorrectionRefundForm?.querySelector('[data-service-correction-refund-currency]') || null;
    const serviceCorrectionRefundDestinationRows = serviceCorrectionRefundForm
        ? Array.from(serviceCorrectionRefundForm.querySelectorAll('[data-service-correction-refund-destination-row]'))
        : [];
    const serviceCorrectionBars = {
        settlement: serviceCorrectionSettlementForm,
        settlementReverse: station.querySelector('form[data-service-correction-bar="settlement-reverse"]'),
        refund: station.querySelector('form[data-service-correction-bar="refund"]'),
        refundReverse: station.querySelector('form[data-service-correction-bar="refund-reverse"]'),
    };
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
    const settlementInitiallyVisible = serviceEventBars.settlement instanceof HTMLElement && !serviceEventBars.settlement.hidden;
    const setServiceEventBarControlsEnabled = (bar, enabled) => {
        if (!(bar instanceof HTMLElement)) {
            return;
        }

        const forceEnabled = bar.dataset.serviceEventBar === 'settlement' && !bar.hidden;
        bar.querySelectorAll('input, select, textarea, button').forEach((control) => {
            if (
                control instanceof HTMLInputElement
                || control instanceof HTMLSelectElement
                || control instanceof HTMLTextAreaElement
                || control instanceof HTMLButtonElement
            ) {
                if (enabled || forceEnabled) {
                    control.dataset.workflowOriginalDisabled = '0';
                }
                control.disabled = !(enabled || forceEnabled);
            }
        });
    };
    const unlockVisibleSettlementBar = () => {
        const bar = serviceEventBars.settlement;
        if (!(bar instanceof HTMLElement) || bar.hidden) {
            return;
        }

        bar.style.pointerEvents = 'auto';
        bar.style.position = 'relative';
        bar.style.zIndex = '8';
        bar.style.transform = 'translateZ(0)';
        bar.querySelectorAll('input, select, textarea, button').forEach((control) => {
            if (
                control instanceof HTMLInputElement
                || control instanceof HTMLSelectElement
                || control instanceof HTMLTextAreaElement
                || control instanceof HTMLButtonElement
            ) {
                control.dataset.workflowOriginalDisabled = '0';
                control.disabled = false;
            }
        });
        void bar.offsetHeight;
        bar.style.transform = 'translateZ(0) scale(1)';
    };
    if (serviceEventBars.settlement instanceof HTMLElement) {
        ['pointerenter', 'mousedown', 'click', 'focusin'].forEach((eventName) => {
            serviceEventBars.settlement.addEventListener(eventName, unlockVisibleSettlementBar, true);
        });
    }
    window.workspaceHandleSettlementSubmit = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return true;
        }

        unlockVisibleSettlementBar();
        const reasonField = form.elements.namedItem('settlement_reason');
        if (reasonField instanceof HTMLInputElement) {
            const existingReason = String(reasonField.value || '').trim();
            if (existingReason === '') {
                const promptedReason = window.prompt('Enter cancellation settlement reason:');
                if (promptedReason === null) {
                    return false;
                }

                const normalizedReason = promptedReason.trim();
                if (normalizedReason.length < 5) {
                    alert('Cancellation settlement reason must be at least 5 characters.');
                    return false;
                }

                reasonField.value = normalizedReason;
            }
        }

        return true;
    };
    if (serviceEventBars.settlement instanceof HTMLFormElement) {
        serviceEventBars.settlement.addEventListener('submit', (event) => {
            if (!window.workspaceHandleSettlementSubmit(serviceEventBars.settlement)) {
                event.preventDefault();
            }
        });
    }
    const settlementReasonField = serviceEventBars.settlement instanceof HTMLFormElement
        ? serviceEventBars.settlement.elements.namedItem('settlement_reason')
        : null;
    if (settlementReasonField instanceof HTMLInputElement) {
        settlementReasonField.addEventListener('dblclick', () => {
            const promptedReason = window.prompt('Enter cancellation settlement reason:', settlementReasonField.value || '');
            if (promptedReason !== null) {
                settlementReasonField.value = promptedReason.trim();
            }
        });
    }
    const lossAmountPanel = station.querySelector('[data-loss-amount-panel]');
    const lossReasonPanel = station.querySelector('[data-loss-reason-panel]');
    const chips = Array.from(station.querySelectorAll('.service-type-chip'));
    const serviceFields = {
        serviceId: station.querySelector('[data-service-field="serviceId"]'),
        lineNumber: station.querySelector('[data-service-field="lineNumber"]'),
        supplier: station.querySelector('[data-service-field="supplier"]'),
        currency: station.querySelector('[data-service-field="currency"]'),
        costCurrency: station.querySelector('[data-service-field="cost_currency"]'),
        currencyMirror: station.querySelector('[data-service-field="currency-mirror"]'),
        pricingExchangeRate: station.querySelector('[data-service-field="pricing_exchange_rate"]'),
        pricingRateEffectiveDate: station.querySelector('[data-service-field="pricing_rate_effective_date"]'),
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
    const normalizeServicePassengerValue = (value) => normalizePassengerName(String(value || ''));
    const servicePassengerManualOverride = () => servicePassengerNameField?.dataset.passengerManualOverride === '1';
    const setServicePassengerTracking = (value, options = {}) => {
        if (!servicePassengerNameField) {
            return;
        }

        const normalizedValue = normalizeServicePassengerValue(value);
        const travelerId = serviceFields.travelerId ? String(serviceFields.travelerId.value || '0') : '0';
        const traveler = travelers.find((item) => String(item.travelerId || '0') === travelerId);
        const matchesTraveler = traveler && normalizeServicePassengerValue(traveler.fullName) === normalizedValue;
        const inferredManualOverride = options.manual === true
            || (
                options.manual !== false
                && normalizedValue !== ''
                && !matchesTraveler
            );

        servicePassengerNameField.dataset.passengerAutoValue = options.autoValue ?? (matchesTraveler ? String(value || '') : '');
        servicePassengerNameField.dataset.passengerManualOverride = inferredManualOverride ? '1' : '0';
    };
    const syncServicePassengerName = (fallbackName = '', options = {}) => {
        if (!servicePassengerNameField) {
            return;
        }

        const travelerId = serviceFields.travelerId ? String(serviceFields.travelerId.value || '0') : '0';
        const traveler = travelers.find((item) => String(item.travelerId || '0') === travelerId);
        const resolvedName = traveler && traveler.fullName
            ? traveler.fullName
            : fallbackName;

        const currentValue = String(servicePassengerNameField.value || '');
        const normalizedCurrent = normalizeServicePassengerValue(currentValue);
        const normalizedResolved = normalizeServicePassengerValue(resolvedName);
        const normalizedAuto = normalizeServicePassengerValue(servicePassengerNameField.dataset.passengerAutoValue || '');
        const shouldPreserveManualValue = options.force !== true
            && servicePassengerManualOverride()
            && normalizedCurrent !== ''
            && normalizedCurrent !== normalizedAuto
            && normalizedCurrent !== normalizedResolved;

        if (shouldPreserveManualValue) {
            return;
        }

        servicePassengerNameField.value = resolvedName;
        setServicePassengerTracking(resolvedName, {
            manual: typeof options.manual === 'boolean'
                ? options.manual
                : (!traveler && normalizedResolved !== ''),
            autoValue: traveler && traveler.fullName ? traveler.fullName : '',
        });
    };
    const syncServiceTravelerIdFromName = () => {
        if (!servicePassengerNameField || !serviceFields.travelerId) {
            return;
        }

        const traveler = findTravelerByName(servicePassengerNameField.value);
        serviceFields.travelerId.value = traveler && Number.parseInt(String(traveler.travelerId || 0), 10) > 0
            ? String(traveler.travelerId)
            : '0';
        const currentName = String(servicePassengerNameField.value || '');
        const normalizedCurrent = normalizeServicePassengerValue(currentName);
        const normalizedTraveler = normalizeServicePassengerValue(traveler?.fullName || '');

        setServicePassengerTracking(currentName, {
            manual: normalizedCurrent !== '' && normalizedCurrent !== normalizedTraveler,
            autoValue: traveler?.fullName || '',
        });
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
        const currentPassenger = normalizeServicePassengerValue(servicePassengerNameField.value);
        const currentAutoPassenger = normalizeServicePassengerValue(servicePassengerNameField.dataset.passengerAutoValue || '');
        const customerPassenger = normalizeServicePassengerValue(customer.full_name || '');
        const hasMeaningfulManualPassenger = servicePassengerManualOverride()
            && currentPassenger !== ''
            && currentPassenger !== currentAutoPassenger
            && currentPassenger !== customerPassenger;
        const shouldDefaultPassenger = serviceId <= 0 && !hasMeaningfulManualPassenger;

        if (shouldDefaultPassenger) {
            fillValue(serviceFields.travelerId, customer.id);
            fillValue(servicePassengerNameField, customer.full_name || '');
            setServicePassengerTracking(customer.full_name || '', {
                manual: false,
                autoValue: customer.full_name || '',
            });
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
    const servicePercentInputs = {
        serviceCharge: commercialLookup('commercial-service-charge-percent', '[data-service-percent="service_charge"]'),
        discount: commercialLookup('commercial-discount-percent', '[data-service-percent="discount"]'),
        vat: commercialLookup('commercial-vat-percent', '[data-service-percent="vat"]'),
    };
    const serviceDiscountInput = commercialLookup('commercial-discount-amount', '[data-service-discount]');
    const manualSaleAdjustmentInput = commercialLookup('commercial-sale-adjustment', '[data-service-sale-adjustment]');
    const bottomTotalFields = {
        fare: station.querySelector('#commercial-bottom-fare'),
        taxes: station.querySelector('#commercial-bottom-taxes'),
        other: station.querySelector('#commercial-bottom-other'),
        sale: station.querySelector('#commercial-bottom-sp'),
        receivable: station.querySelector('#commercial-bottom-receivable'),
        payable: station.querySelector('#commercial-bottom-payable'),
        profit: station.querySelector('#commercial-bottom-profit'),
    };
    let commercialDiagnosticCount = 0;
    let commercialDiagnosticLastPayload = '';
    const logCommercialCalculator = (stage, source = '', extra = {}) => {
        if (commercialDiagnosticCount >= 80) {
            return;
        }

        const snapshot = {
            stage,
            source,
            serviceType: String(serviceTypeField?.value || ''),
            invoiceCurrency: String(serviceFields.currency?.value || ''),
            costCurrency: String(serviceFields.costCurrency?.value || ''),
            pricingExchangeRate: String(serviceFields.pricingExchangeRate?.value || ''),
            mktFareRaw: String(serviceMetricInputs.sale?.value ?? ''),
            mktFareNumber: toNumber(serviceMetricInputs.sale?.value || 0),
            serviceAmountRaw: String(serviceMetricInputs.serviceCharge?.value ?? ''),
            serviceAmountNumber: toNumber(serviceMetricInputs.serviceCharge?.value || 0),
            discountRaw: String(serviceDiscountInput?.value ?? ''),
            discountNumber: toNumber(serviceDiscountInput?.value || 0),
            vatOutputRaw: String(serviceMetricInputs.vat?.value ?? ''),
            vatOutputNumber: toNumber(serviceMetricInputs.vat?.value || 0),
            purchaseCostRaw: String(serviceMetricInputs.cost?.value ?? ''),
            purchaseCostNumber: toNumber(serviceMetricInputs.cost?.value || 0),
            frtxRaw: String(airlinePayableField?.value ?? airlinePayableField?.textContent ?? ''),
            finalSaleRaw: String(finalSalePriceInput?.value ?? ''),
            saleAdjustmentRaw: String(manualSaleAdjustmentInput?.value ?? ''),
            paymentInvoiceRaw: String(paymentCurrentInvoiceInput?.value ?? ''),
            paymentInvoiceData: String(paymentCurrentInvoiceInput?.dataset.paymentCurrentInvoice ?? ''),
            paymentBalanceRaw: String(paymentCurrentBalanceInput?.value ?? ''),
            paymentBalanceData: String(paymentCurrentBalanceInput?.dataset.paymentPersistedInvoiceBalance ?? ''),
            selectorCounts: commercialTrace.selectorCounts,
            activeElement: describeTarget(document.activeElement),
            ...extra,
        };
        const payloadKey = JSON.stringify({
            stage: snapshot.stage,
            source: snapshot.source,
            mktFareRaw: snapshot.mktFareRaw,
            serviceAmountRaw: snapshot.serviceAmountRaw,
            finalSaleRaw: snapshot.finalSaleRaw,
            paymentInvoiceRaw: snapshot.paymentInvoiceRaw,
            guard: snapshot.guard || '',
        });

        if (payloadKey === commercialDiagnosticLastPayload && stage !== 'guard-failed') {
            return;
        }

        commercialDiagnosticLastPayload = payloadKey;
        commercialDiagnosticCount += 1;
        pushWorkspaceClientError('commercial_calculator', {
            message: `Commercial calculator ${stage}`,
            extra: snapshot,
        });
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
        ticketType: station.querySelector('[data-ticket-field="ticket_type"]'),
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
    const financialCorrectionForm = station.querySelector('[data-service-event-bar="financial-correction"]');
    const financialCorrectionCostInput = financialCorrectionForm?.elements?.namedItem('corrected_cost_basis') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_cost_basis')
        : null;
    const financialCorrectionServiceAmountInput = financialCorrectionForm?.elements?.namedItem('corrected_service_charge') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_service_charge')
        : null;
    const financialCorrectionDiscountInput = financialCorrectionForm?.elements?.namedItem('corrected_discount_amount') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_discount_amount')
        : null;
    const financialCorrectionCustomerTotalInput = financialCorrectionForm?.elements?.namedItem('corrected_final_sale_price') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_final_sale_price')
        : null;
    const financialCorrectionInvoiceCurrencySelect = financialCorrectionForm?.elements?.namedItem('corrected_invoice_currency') instanceof HTMLSelectElement
        ? financialCorrectionForm.elements.namedItem('corrected_invoice_currency')
        : null;
    const financialCorrectionCostCurrencySelect = financialCorrectionForm?.elements?.namedItem('corrected_cost_currency') instanceof HTMLSelectElement
        ? financialCorrectionForm.elements.namedItem('corrected_cost_currency')
        : null;
    const financialCorrectionRateInput = financialCorrectionForm?.elements?.namedItem('corrected_pricing_exchange_rate') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_pricing_exchange_rate')
        : null;
    const financialCorrectionRateDateInput = financialCorrectionForm?.elements?.namedItem('corrected_pricing_rate_effective_date') instanceof HTMLInputElement
        ? financialCorrectionForm.elements.namedItem('corrected_pricing_rate_effective_date')
        : null;
    const financialCorrectionLossInput = financialCorrectionForm?.querySelector('[data-financial-correction-loss]');
    const financialCorrectionLossNote = financialCorrectionForm?.querySelector('[data-financial-correction-loss-note]');
    const ticketRouteMask = '---/---/---';
    const ticketRouteEditablePositions = [0, 1, 2, 4, 5, 6, 8, 9, 10];
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

    const sanitizeTicketRouteCharacters = (value) => String(value || '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, ticketRouteEditablePositions.length);

    const formatTicketRouteMask = (value) => {
        const routeCharacters = sanitizeTicketRouteCharacters(value).split('');
        const maskedCharacters = ticketRouteMask.split('');
        ticketRouteEditablePositions.forEach((position, index) => {
            maskedCharacters[position] = routeCharacters[index] || '-';
        });
        return maskedCharacters.join('');
    };

    const ticketRouteCharactersFromMaskedValue = (value) => sanitizeTicketRouteCharacters(value);

    const ticketRouteCharacterArrayFromMaskedValue = (value) => {
        const characters = ticketRouteCharactersFromMaskedValue(value).split('');
        while (characters.length < ticketRouteEditablePositions.length) {
            characters.push('');
        }
        return characters;
    };

    const ticketRouteDisplayValueFromSegments = (from, to) => formatTicketRouteMask(`${from || ''}${to || ''}`);

    const nearestTicketRoutePosition = (position) => {
        if (!Number.isFinite(position)) {
            return ticketRouteEditablePositions[0];
        }

        for (let index = 0; index < ticketRouteEditablePositions.length; index += 1) {
            if (ticketRouteEditablePositions[index] >= position) {
                return ticketRouteEditablePositions[index];
            }
        }

        return ticketRouteEditablePositions[ticketRouteEditablePositions.length - 1];
    };

    const previousTicketRoutePosition = (position) => {
        for (let index = ticketRouteEditablePositions.length - 1; index >= 0; index -= 1) {
            if (ticketRouteEditablePositions[index] < position) {
                return ticketRouteEditablePositions[index];
            }
        }

        return ticketRouteEditablePositions[0];
    };

    const nextTicketRouteCaretPosition = (editableIndex) => {
        const nextPosition = ticketRouteEditablePositions[editableIndex + 1];
        return typeof nextPosition === 'number' ? nextPosition : ticketRouteEditablePositions[ticketRouteEditablePositions.length - 1] + 1;
    };

    const setTicketRouteCaret = (position) => {
        if (!(ticketRouteDisplay instanceof HTMLInputElement)) {
            return;
        }

        const safePosition = Math.max(0, Math.min(ticketRouteMask.length, position));
        window.requestAnimationFrame(() => {
            ticketRouteDisplay.setSelectionRange(safePosition, safePosition);
        });
    };

    const syncTicketRouteStorage = (maskedValue) => {
        const routeCharacters = ticketRouteCharactersFromMaskedValue(maskedValue);
        const fromSegment = routeCharacters.slice(0, 3);
        const middleSegment = routeCharacters.slice(3, 6);
        const lastSegment = routeCharacters.slice(6, 9);
        const toSegmentParts = [];

        if (middleSegment !== '') {
            toSegmentParts.push(middleSegment);
        }
        if (lastSegment !== '') {
            toSegmentParts.push(lastSegment);
        }

        fillValue(ticketFields.sectorFrom, fromSegment);
        fillValue(ticketFields.sectorTo, toSegmentParts.join('/'));
    };

    const syncTicketRouteDisplay = (from, to) => {
        if (!(ticketRouteDisplay instanceof HTMLInputElement)) {
            return;
        }

        const maskedValue = ticketRouteDisplayValueFromSegments(from, to);
        fillValue(ticketRouteDisplay, maskedValue);
        syncTicketRouteStorage(maskedValue);
    };

    const normalizeTicketRouteDisplayInteraction = () => {
        if (!(ticketRouteDisplay instanceof HTMLInputElement)) {
            return;
        }

        const maskedValue = formatTicketRouteMask(ticketRouteDisplay.value);
        fillValue(ticketRouteDisplay, maskedValue);
        syncTicketRouteStorage(maskedValue);
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
        servicePercentInputs.serviceCharge,
        servicePercentInputs.discount,
        servicePercentInputs.vat,
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

    if (ticketRouteDisplay instanceof HTMLInputElement) {
        normalizeTicketRouteDisplayInteraction();

        ticketRouteDisplay.addEventListener('focus', () => {
            normalizeTicketRouteDisplayInteraction();
            const routeCharacters = ticketRouteCharactersFromMaskedValue(ticketRouteDisplay.value);
            const targetPosition = routeCharacters.length < ticketRouteEditablePositions.length
                ? ticketRouteEditablePositions[routeCharacters.length]
                : ticketRouteEditablePositions[ticketRouteEditablePositions.length - 1] + 1;
            setTicketRouteCaret(targetPosition);
        });

        ticketRouteDisplay.addEventListener('click', () => {
            const selectionStart = typeof ticketRouteDisplay.selectionStart === 'number'
                ? ticketRouteDisplay.selectionStart
                : 0;
            setTicketRouteCaret(nearestTicketRoutePosition(selectionStart));
        });

        ticketRouteDisplay.addEventListener('keydown', (event) => {
            const selectionStart = typeof ticketRouteDisplay.selectionStart === 'number'
                ? ticketRouteDisplay.selectionStart
                : 0;
            const selectionEnd = typeof ticketRouteDisplay.selectionEnd === 'number'
                ? ticketRouteDisplay.selectionEnd
                : selectionStart;
            const routeCharacters = ticketRouteCharacterArrayFromMaskedValue(ticketRouteDisplay.value);
            const normalizedKey = String(event.key || '');
            const isCharacterKey = /^[a-z0-9]$/i.test(normalizedKey);

            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (normalizedKey === 'Tab') {
                return;
            }

            if (normalizedKey === 'ArrowLeft') {
                event.preventDefault();
                setTicketRouteCaret(previousTicketRoutePosition(selectionStart));
                return;
            }

            if (normalizedKey === 'ArrowRight') {
                event.preventDefault();
                setTicketRouteCaret(nearestTicketRoutePosition(selectionStart + 1));
                return;
            }

            if (normalizedKey === 'Home') {
                event.preventDefault();
                setTicketRouteCaret(ticketRouteEditablePositions[0]);
                return;
            }

            if (normalizedKey === 'End') {
                event.preventDefault();
                setTicketRouteCaret(ticketRouteEditablePositions[ticketRouteEditablePositions.length - 1] + 1);
                return;
            }

            if (normalizedKey === 'Backspace') {
                event.preventDefault();
                const targetPosition = selectionStart !== selectionEnd
                    ? nearestTicketRoutePosition(selectionStart)
                    : previousTicketRoutePosition(selectionStart);
                const editableIndex = ticketRouteEditablePositions.indexOf(targetPosition);
                if (editableIndex >= 0) {
                    routeCharacters[editableIndex] = '';
                    const maskedValue = formatTicketRouteMask(routeCharacters.join(''));
                    fillValue(ticketRouteDisplay, maskedValue);
                    syncTicketRouteStorage(maskedValue);
                    setTicketRouteCaret(targetPosition);
                }
                return;
            }

            if (normalizedKey === 'Delete') {
                event.preventDefault();
                const targetPosition = nearestTicketRoutePosition(selectionStart);
                const editableIndex = ticketRouteEditablePositions.indexOf(targetPosition);
                if (editableIndex >= 0) {
                    routeCharacters[editableIndex] = '';
                    const maskedValue = formatTicketRouteMask(routeCharacters.join(''));
                    fillValue(ticketRouteDisplay, maskedValue);
                    syncTicketRouteStorage(maskedValue);
                    setTicketRouteCaret(targetPosition);
                }
                return;
            }

            if (!isCharacterKey) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            const targetPosition = nearestTicketRoutePosition(selectionStart);
            const editableIndex = ticketRouteEditablePositions.indexOf(targetPosition);
            if (editableIndex < 0) {
                return;
            }

            routeCharacters[editableIndex] = normalizedKey.toUpperCase();
            const maskedValue = formatTicketRouteMask(routeCharacters.join(''));
            fillValue(ticketRouteDisplay, maskedValue);
            syncTicketRouteStorage(maskedValue);
            setTicketRouteCaret(nextTicketRouteCaretPosition(editableIndex));
        });

        ticketRouteDisplay.addEventListener('paste', (event) => {
            event.preventDefault();
            const pastedText = event.clipboardData ? event.clipboardData.getData('text') : '';
            const pastedCharacters = sanitizeTicketRouteCharacters(pastedText).split('');
            if (!pastedCharacters.length) {
                return;
            }

            const routeCharacters = ticketRouteCharacterArrayFromMaskedValue(ticketRouteDisplay.value);
            let editableIndex = ticketRouteEditablePositions.indexOf(nearestTicketRoutePosition(
                typeof ticketRouteDisplay.selectionStart === 'number' ? ticketRouteDisplay.selectionStart : 0
            ));
            if (editableIndex < 0) {
                editableIndex = 0;
            }

            pastedCharacters.forEach((character) => {
                if (editableIndex >= routeCharacters.length) {
                    return;
                }
                routeCharacters[editableIndex] = character;
                editableIndex += 1;
            });

            const maskedValue = formatTicketRouteMask(routeCharacters.join(''));
            fillValue(ticketRouteDisplay, maskedValue);
            syncTicketRouteStorage(maskedValue);
            setTicketRouteCaret(nextTicketRouteCaretPosition(Math.min(editableIndex - 1, ticketRouteEditablePositions.length - 1)));
        });

        ticketRouteDisplay.addEventListener('input', () => {
            normalizeTicketRouteDisplayInteraction();
        });
    }

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
        costCurrency: serviceFields.costCurrency?.value || serviceFields.currency?.value || 'PKR',
        pricingExchangeRate: toNumber(serviceFields.pricingExchangeRate?.value || 1),
        pricingRateEffectiveDate: serviceFields.pricingRateEffectiveDate?.value || '',
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
        ticketType: ticketFields.ticketType?.value || 'international',
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

    let supplierAdvanceFxCandidate = null;

    const clearSupplierAdvanceFxFields = () => {
        if (supplierAdvanceFxUse instanceof HTMLInputElement) supplierAdvanceFxUse.value = '';
        if (supplierAdvanceFxAdvanceId instanceof HTMLInputElement) supplierAdvanceFxAdvanceId.value = '';
        if (supplierAdvanceFxRate instanceof HTMLInputElement) supplierAdvanceFxRate.value = '';
        if (supplierAdvanceFxRateDate instanceof HTMLInputElement && supplierAdvanceFxRateDate.value === '') {
            supplierAdvanceFxRateDate.value = new Date().toISOString().slice(0, 10);
        }
    };

    const hideSupplierAdvanceNote = () => {
        supplierAdvanceFxCandidate = null;
        clearSupplierAdvanceFxFields();
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

    const prepareSupplierAdvanceFxUse = () => {
        if (!supplierAdvanceFxCandidate) {
            clearSupplierAdvanceFxFields();
            return true;
        }

        if (supplierAdvanceFxUse instanceof HTMLInputElement && supplierAdvanceFxUse.value === '1') {
            return true;
        }

        const advanceCurrency = String(supplierAdvanceFxCandidate.currency || '').toUpperCase();
        const payableCurrency = String(serviceFields.costCurrency?.value || serviceFields.currency?.value || '').trim().toUpperCase();
        const amount = Math.max(toNumber(serviceMetricInputs.cost?.value || serviceFields.purchaseCost?.value || 0), 0);
        if (advanceCurrency === '' || payableCurrency === '' || amount <= 0) {
            clearSupplierAdvanceFxFields();
            return true;
        }

        const useAdvance = window.confirm(`A ${advanceCurrency} prepaid supplier balance is available, but this payable is ${payableCurrency}. Use it with an exchange rate now?`);
        if (!useAdvance) {
            clearSupplierAdvanceFxFields();
            return true;
        }

        const rateText = window.prompt(`Enter exchange rate: 1 ${advanceCurrency} = how many ${payableCurrency}?`, '');
        const rateValue = Number.parseFloat(String(rateText || '').trim());
        if (!Number.isFinite(rateValue) || rateValue <= 0) {
            showFeedback('Different-currency supplier advance was not applied because exchange rate was not confirmed.');
            clearSupplierAdvanceFxFields();
            return true;
        }

        if (supplierAdvanceFxUse instanceof HTMLInputElement) supplierAdvanceFxUse.value = '1';
        if (supplierAdvanceFxAdvanceId instanceof HTMLInputElement) supplierAdvanceFxAdvanceId.value = String(supplierAdvanceFxCandidate.id || '');
        if (supplierAdvanceFxRate instanceof HTMLInputElement) supplierAdvanceFxRate.value = String(rateValue);
        if (supplierAdvanceFxRateDate instanceof HTMLInputElement && supplierAdvanceFxRateDate.value === '') {
            supplierAdvanceFxRateDate.value = new Date().toISOString().slice(0, 10);
        }
        showFeedback(`Different-currency supplier advance confirmed at 1 ${advanceCurrency} = ${rateValue} ${payableCurrency}.`);
        return true;
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
        const currency = String(serviceFields.costCurrency?.value || serviceFields.currency?.value || '').trim().toUpperCase();
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
                Number(payload.available_amount || 0) > 0
                    ? 'Same-currency advance will be used automatically against Mkt. Fare.'
                    : 'Different-currency advance requires exchange-rate confirmation before it is used.'
            );
            supplierAdvanceFxCandidate = null;
            if (Number(payload.available_amount || 0) <= 0) {
                const candidates = Array.isArray(payload.advance_candidates) ? payload.advance_candidates : [];
                supplierAdvanceFxCandidate = candidates.find((row) => String(row.currency || '').toUpperCase() !== currency) || null;
                if (!supplierAdvanceFxCandidate) {
                    clearSupplierAdvanceFxFields();
                }
            } else {
                clearSupplierAdvanceFxFields();
            }
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
            return [serviceLine.sectorFrom || '', serviceLine.sectorTo || ''].filter(Boolean).join('/') || serviceLine.remarks || '';
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
            : roundToTwo(toNumber(serviceMetricInputs.sale?.value));
    };

    const currentInvoiceCurrencyCode = () => String(serviceFields.currency?.value || 'PKR').trim().toUpperCase() || 'PKR';

    const currentCostCurrencyCode = () => String(serviceFields.costCurrency?.value || currentInvoiceCurrencyCode()).trim().toUpperCase() || currentInvoiceCurrencyCode();

    const currentPricingRateEffectiveDate = () => {
        const explicitDate = String(serviceFields.pricingRateEffectiveDate?.value || '').trim();
        if (explicitDate !== '') {
            return explicitDate;
        }

        const dueDate = String(serviceFields.dueDate?.value || '').trim();
        if (dueDate !== '') {
            return dueDate;
        }

        const bookingDateField = invoiceForm?.elements?.namedItem('booking_date');
        if (bookingDateField instanceof HTMLInputElement && bookingDateField.value.trim() !== '') {
            return bookingDateField.value.trim();
        }

        return '';
    };

    const resolvePricingExchangeRateFromMap = (fromCurrency, toCurrency, effectiveDate = '') => {
        const normalizedFrom = String(fromCurrency || '').trim().toUpperCase();
        const normalizedTo = String(toCurrency || '').trim().toUpperCase();
        if (normalizedFrom === '' || normalizedTo === '') {
            return 0;
        }
        if (normalizedFrom === normalizedTo) {
            return 1;
        }

        const directKey = `${normalizedFrom}->${normalizedTo}`;
        const reverseKey = `${normalizedTo}->${normalizedFrom}`;
        const directRate = toNumber(dailySettlementRates?.[directKey]?.exchangeRate || 0);
        if (directRate > 0) {
            return directRate;
        }

        const reverseRate = toNumber(dailySettlementRates?.[reverseKey]?.exchangeRate || 0);
        if (reverseRate > 0) {
            return roundExchangeRate(1 / reverseRate);
        }

        const preferredQuote = preferredSettlementQuote(normalizedTo, normalizedFrom, dailySettlementRates || {}, effectiveDate);
        const preferredRate = toNumber(preferredQuote?.exchangeRate || 0);
        if (preferredRate <= 0) {
            return 0;
        }

        if (String(preferredQuote?.rateFromCurrency || '').toUpperCase() === normalizedFrom
            && String(preferredQuote?.rateToCurrency || '').toUpperCase() === normalizedTo) {
            return preferredRate;
        }

        if (String(preferredQuote?.rateFromCurrency || '').toUpperCase() === normalizedTo
            && String(preferredQuote?.rateToCurrency || '').toUpperCase() === normalizedFrom) {
            return roundExchangeRate(1 / preferredRate);
        }

        return 0;
    };

    const currentPricingExchangeRate = (options = {}) => {
        const invoiceCurrency = options.invoiceCurrency || currentInvoiceCurrencyCode();
        const costCurrency = options.costCurrency || currentCostCurrencyCode();
        if (invoiceCurrency === costCurrency) {
            return 1;
        }

        const postedRate = toNumber(serviceFields.pricingExchangeRate?.value || 0);
        if (postedRate > 0) {
            return postedRate;
        }

        return resolvePricingExchangeRateFromMap(costCurrency, invoiceCurrency, options.effectiveDate || currentPricingRateEffectiveDate());
    };

    const refreshPricingExchangeRateSnapshot = () => {
        if (!(serviceFields.pricingExchangeRate instanceof HTMLInputElement)) {
            return currentPricingExchangeRate();
        }

        const invoiceCurrency = currentInvoiceCurrencyCode();
        const costCurrency = currentCostCurrencyCode();
        if (invoiceCurrency === costCurrency) {
            serviceFields.pricingExchangeRate.value = '1';
            return 1;
        }

        const defaultRate = resolvePricingExchangeRateFromMap(costCurrency, invoiceCurrency, currentPricingRateEffectiveDate());
        serviceFields.pricingExchangeRate.value = defaultRate > 0 ? String(defaultRate) : '';
        return defaultRate;
    };

    const currentConvertedPayableAmount = () => {
        const purchaseCost = currentServicePayableAmount();
        return convertCostAmountToInvoiceCurrency(purchaseCost);
    };

    const convertCostAmountToInvoiceCurrency = (amount) => {
        const invoiceCurrency = currentInvoiceCurrencyCode();
        const costCurrency = currentCostCurrencyCode();
        const rate = currentPricingExchangeRate({ invoiceCurrency, costCurrency });
        if (invoiceCurrency !== costCurrency && rate <= 0.005) {
            return 0;
        }
        return roundToTwo(toNumber(amount) * (invoiceCurrency === costCurrency ? 1 : rate));
    };

    const currentReceivableAmountInCostCurrency = () => roundToTwo(
        currentServicePayableAmount()
        + toNumber(serviceMetricInputs.serviceCharge?.value)
        + toNumber(serviceMetricInputs.vat?.value)
        - toNumber(serviceDiscountInput?.value)
    );

    const currentConvertedReceivableAmount = () => convertCostAmountToInvoiceCurrency(
        currentReceivableAmountInCostCurrency()
    );

    const syncPricingSnapshotFields = () => {
        if (serviceFields.pricingRateEffectiveDate instanceof HTMLInputElement) {
            serviceFields.pricingRateEffectiveDate.value = currentPricingRateEffectiveDate();
        }

        if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
            refreshPricingExchangeRateSnapshot();
        }
    };

    const defaultFinalSalePrice = (receivableBaseOverride = null) => {
        if (isAirTicketServiceType()) {
            return currentConvertedReceivableAmount();
        }

        const receivableBase = receivableBaseOverride !== null && Number.isFinite(receivableBaseOverride)
            ? receivableBaseOverride
            : currentConvertedPayableAmount();

        return receivableBase
            + toNumber(serviceMetricInputs.serviceCharge?.value)
            + toNumber(serviceMetricInputs.vat?.value)
            - toNumber(serviceDiscountInput?.value);
    };

    let commercialPercentSyncing = false;

    const percentBaseForCommercialField = (fieldKey) => {
        const payable = currentServicePayableAmount();
        if (fieldKey === 'serviceCharge') {
            return payable;
        }

        if (fieldKey === 'discount') {
            return payable + toNumber(serviceMetricInputs.serviceCharge?.value);
        }

        if (fieldKey === 'vat') {
            return Math.max(
                toNumber(serviceMetricInputs.serviceCharge?.value) - toNumber(serviceDiscountInput?.value),
                0
            );
        }

        return 0;
    };

    const commercialAmountInputForPercent = (fieldKey) => {
        if (fieldKey === 'serviceCharge') {
            return serviceMetricInputs.serviceCharge;
        }

        if (fieldKey === 'discount') {
            return serviceDiscountInput;
        }

        if (fieldKey === 'vat') {
            return serviceMetricInputs.vat;
        }

        return null;
    };

    const commercialPercentInputForAmount = (fieldKey) => {
        if (fieldKey === 'serviceCharge') {
            return servicePercentInputs.serviceCharge;
        }

        if (fieldKey === 'discount') {
            return servicePercentInputs.discount;
        }

        if (fieldKey === 'vat') {
            return servicePercentInputs.vat;
        }

        return null;
    };

    const applyCommercialPercentToAmount = (fieldKey) => {
        const percentInput = commercialPercentInputForAmount(fieldKey);
        const amountInput = commercialAmountInputForPercent(fieldKey);
        if (!(percentInput instanceof HTMLInputElement) || !(amountInput instanceof HTMLInputElement)) {
            return;
        }

        const base = percentBaseForCommercialField(fieldKey);
        const percentage = Math.max(toNumber(percentInput.value), 0);
        const amount = roundToTwo(base * percentage / 100);

        percentInput.dataset.percentActive = percentage > 0.005 ? '1' : '0';
        commercialPercentSyncing = true;
        amountInput.value = formatNumberInputValue(amount);
        commercialPercentSyncing = false;
        logCommercialCalculator('amount-from-percent', fieldKey, {
            fieldKey,
            base,
            percentage,
            amount,
            amountValue: amountInput.value,
        });

        if (finalSalePriceInput instanceof HTMLInputElement) {
            finalSalePriceInput.dataset.manualOverride = '0';
        }

        refreshProfit(`percent:${fieldKey}`);
        syncTicketCommercialMirrors();
    };

    const syncCommercialPercentFromAmount = (fieldKey, options = {}) => {
        if (commercialPercentSyncing) {
            return;
        }

        const { markManualAmount = false } = options;

        const percentInput = commercialPercentInputForAmount(fieldKey);
        const amountInput = commercialAmountInputForPercent(fieldKey);
        if (!(percentInput instanceof HTMLInputElement) || !(amountInput instanceof HTMLInputElement)) {
            return;
        }

        const base = percentBaseForCommercialField(fieldKey);
        const amount = toNumber(amountInput.value);
        percentInput.value = base > 0.005 ? formatNumberInputValue(roundToTwo(amount / base * 100)) : '0';
        logCommercialCalculator('percent-from-amount', fieldKey, {
            fieldKey,
            base,
            amount,
            percentValue: percentInput.value,
            markManualAmount,
        });
        if (markManualAmount) {
            percentInput.dataset.percentActive = '0';
        }
    };

    const syncAllCommercialPercentsFromAmounts = () => {
        ['serviceCharge', 'discount', 'vat'].forEach(syncCommercialPercentFromAmount);
    };

    const reapplyActiveCommercialPercents = (fieldKeys = ['serviceCharge', 'discount', 'vat']) => {
        fieldKeys.forEach((fieldKey) => {
            const percentInput = commercialPercentInputForAmount(fieldKey);
            if (percentInput instanceof HTMLInputElement && percentInput.dataset.percentActive === '1') {
                applyCommercialPercentToAmount(fieldKey);
            }
        });
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

    const refreshFinancialCorrectionLossPreview = () => {
        if (!financialCorrectionForm || !(financialCorrectionLossInput instanceof HTMLInputElement) || !(financialCorrectionCustomerTotalInput instanceof HTMLInputElement)) {
            return;
        }

        const serviceType = String(financialCorrectionForm.dataset.financialCorrectionServiceType || 'air ticket').trim().toLowerCase();
        const correctionCurrency = String(financialCorrectionInvoiceCurrencySelect?.value || financialCorrectionForm.dataset.financialCorrectionCurrency || serviceFields.currency?.value || 'PKR').trim().toUpperCase() || 'PKR';
        const correctionCostCurrency = String(financialCorrectionCostCurrencySelect?.value || financialCorrectionForm.dataset.financialCorrectionCostCurrency || correctionCurrency).trim().toUpperCase() || correctionCurrency;
        const correctionRate = Math.max(toNumber(financialCorrectionRateInput?.value || financialCorrectionForm.dataset.financialCorrectionRate || 1), 0);
        const correctionTaxTotal = toNumber(financialCorrectionForm.dataset.financialCorrectionTaxTotal || 0);
        const correctionVatAmount = toNumber(financialCorrectionForm.dataset.financialCorrectionVat || 0);
        const correctedCostBasis = toNumber(financialCorrectionCostInput?.value || 0);
        const correctedServiceAmount = toNumber(financialCorrectionServiceAmountInput?.value || 0);
        const correctedDiscountAmount = toNumber(financialCorrectionDiscountInput?.value || 0);
        const correctedPayableInCostCurrency = serviceType === 'air ticket'
            ? roundToTwo(correctedCostBasis + correctionTaxTotal)
            : roundToTwo(correctedCostBasis);
        const correctedPayableAmount = roundToTwo(
            correctedPayableInCostCurrency * (correctionCurrency === correctionCostCurrency ? 1 : (correctionRate || 1))
        );
        const correctedCustomerTotal = roundToTwo(
            correctedPayableAmount
            + correctedServiceAmount
            + correctionVatAmount
            - correctedDiscountAmount
        );
        const lossAmount = Math.max(roundToTwo(correctedPayableAmount - correctedCustomerTotal), 0);
        const hasLoss = lossAmount > 0.005;

        financialCorrectionCustomerTotalInput.value = formatNumberInputValue(correctedCustomerTotal);
        financialCorrectionLossInput.value = formatNumberInputValue(lossAmount);

        if (financialCorrectionLossNote instanceof HTMLElement) {
            financialCorrectionLossNote.hidden = !hasLoss;
            if (hasLoss) {
                financialCorrectionLossNote.innerHTML = `Loss sale preview: <strong>${formatCurrencyAmount(correctionCurrency, lossAmount)}</strong>. Customer Total is below supplier payable, so this edit will record a loss instead of reducing Mkt. Fare automatically.`;
            }
        }
    };

    const syncFinancialCorrectionEditor = (serviceLine, canEdit) => {
        if (!(financialCorrectionForm instanceof HTMLFormElement) || !serviceLine) {
            return;
        }

        const serviceId = Number.parseInt(String(serviceLine.serviceId || 0), 10) || 0;
        const boundServiceId = Number.parseInt(String(financialCorrectionForm.dataset.financialCorrectionBoundServiceId || 0), 10) || 0;
        const serviceSelectionChanged = boundServiceId !== serviceId;
        const serviceType = String(serviceLine.type || 'air ticket').trim().toLowerCase();
        const currency = String(serviceLine.currency || serviceFields.currency?.value || 'PKR').trim().toUpperCase() || 'PKR';
        const costCurrency = String(serviceLine.costCurrency || serviceFields.costCurrency?.value || currency).trim().toUpperCase() || currency;
        const pricingExchangeRate = toNumber(serviceLine.pricingExchangeRate || 1) || 1;
        const pricingRateEffectiveDate = String(serviceLine.pricingRateEffectiveDate || serviceFields.pricingRateEffectiveDate?.value || '').trim();
        const correctedCostBasis = serviceType === 'air ticket'
            ? toNumber(serviceLine.salePrice)
            : toNumber(serviceLine.purchaseCost);
        const correctedServiceCharge = toNumber(serviceLine.serviceCharge);
        const correctedDiscount = toNumber(serviceLine.discountAmount);
        const correctedVat = toNumber(serviceLine.vat);
        const correctionTaxTotal = roundToTwo(
            toNumber(serviceLine.spyiAmount)
            + toNumber(serviceLine.aqYrPkAmount)
            + toNumber(serviceLine.yqAmount)
            + toNumber(serviceLine.othAmount)
            + toNumber(serviceLine.vatInput)
            + toNumber(serviceLine.taxes)
        );
        const correctedPayable = serviceType === 'air ticket'
            ? roundToTwo(correctedCostBasis + correctionTaxTotal)
            : roundToTwo(correctedCostBasis);
        const correctedPayableInInvoiceCurrency = roundToTwo(
            correctedPayable * (currency === costCurrency ? 1 : pricingExchangeRate)
        );
        const correctedCustomerTotal = toNumber(serviceLine.finalSalePrice);
        const lossAmount = Math.max(roundToTwo(correctedPayableInInvoiceCurrency - correctedCustomerTotal), 0);

        const serviceIdField = financialCorrectionForm.elements.namedItem('service_id');
        if (serviceIdField instanceof HTMLInputElement) {
            serviceIdField.value = canEdit ? String(serviceId) : '';
        }

        financialCorrectionForm.dataset.financialCorrectionServiceType = serviceType;
        financialCorrectionForm.dataset.financialCorrectionTaxTotal = String(correctionTaxTotal);
        financialCorrectionForm.dataset.financialCorrectionVat = String(correctedVat);
        financialCorrectionForm.dataset.financialCorrectionCurrency = currency;
        financialCorrectionForm.dataset.financialCorrectionCostCurrency = costCurrency;
        financialCorrectionForm.dataset.financialCorrectionRate = String(pricingExchangeRate);
        financialCorrectionForm.dataset.financialCorrectionBoundServiceId = String(serviceId);
        financialCorrectionForm.hidden = !canEdit;
        setServiceEventBarControlsEnabled(financialCorrectionForm, canEdit);

        const costLabel = financialCorrectionCostInput?.closest('label')?.querySelector('span');
        if (costLabel instanceof HTMLElement) {
            costLabel.textContent = serviceType === 'air ticket' ? 'Mkt. Fare' : 'Cost';
        }
        if (serviceSelectionChanged) {
            fillValue(financialCorrectionCostInput, formatNumberInputValue(correctedCostBasis));
            fillValue(financialCorrectionServiceAmountInput, formatNumberInputValue(correctedServiceCharge));
            fillValue(financialCorrectionDiscountInput, formatNumberInputValue(correctedDiscount));
            fillValue(financialCorrectionCustomerTotalInput, formatNumberInputValue(correctedCustomerTotal));
            fillValue(financialCorrectionLossInput, formatNumberInputValue(lossAmount));
            fillValue(financialCorrectionInvoiceCurrencySelect, currency);
            fillValue(financialCorrectionCostCurrencySelect, costCurrency);
            fillValue(financialCorrectionRateInput, String(pricingExchangeRate));
            fillValue(financialCorrectionRateDateInput, pricingRateEffectiveDate);

            const reasonField = financialCorrectionForm.elements.namedItem('financial_correction_reason');
            const noteField = financialCorrectionForm.elements.namedItem('financial_correction_note');
            fillValue(reasonField, '');
            fillValue(noteField, '');
        }

        if (financialCorrectionLossNote instanceof HTMLElement) {
            financialCorrectionLossNote.hidden = lossAmount <= 0.005;
            if (lossAmount > 0.005) {
                financialCorrectionLossNote.innerHTML = `Loss sale preview: <strong>${formatCurrencyAmount(currency, lossAmount)}</strong>. Customer Total is below supplier payable, so this edit will record a loss instead of reducing ${serviceType === 'air ticket' ? 'Mkt. Fare' : 'Cost'} automatically.`;
            }
        }
    };

    [
        financialCorrectionCostInput,
        financialCorrectionServiceAmountInput,
        financialCorrectionDiscountInput,
        financialCorrectionInvoiceCurrencySelect,
        financialCorrectionCostCurrencySelect,
        financialCorrectionRateInput,
    ].forEach((input) => {
        if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement)) {
            return;
        }

        input.addEventListener('input', refreshFinancialCorrectionLossPreview);
        input.addEventListener('change', refreshFinancialCorrectionLossPreview);
    });

    financialCorrectionForm?.addEventListener('submit', () => {
        const activeRow = serviceRows.find((row) => row.classList.contains('is-active'));
        const activeIndex = activeRow instanceof HTMLElement
            ? Number.parseInt(String(activeRow.dataset.serviceIndex || '-1'), 10)
            : -1;
        const activeLine = activeIndex >= 0 ? serviceLines[activeIndex] : null;
        const activeServiceId = Number.parseInt(String(activeLine?.serviceId || 0), 10) || 0;
        const serviceIdField = financialCorrectionForm.elements.namedItem('service_id');
        if (serviceIdField instanceof HTMLInputElement && activeServiceId > 0) {
            serviceIdField.value = String(activeServiceId);
        }
    });

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

    const serviceLinePayableAmount = (serviceLine) => {
        const serviceType = String(serviceLine?.type || 'air ticket');
        const purchaseCost = toNumber(serviceLine?.purchaseCost);
        const invoiceCurrency = String(serviceLine?.currency || 'PKR').trim().toUpperCase();
        const costCurrency = String(serviceLine?.costCurrency || invoiceCurrency).trim().toUpperCase();
        const pricingExchangeRate = toNumber(serviceLine?.pricingExchangeRate || 1);
        const convertedPurchaseCost = roundToTwo(
            purchaseCost * (invoiceCurrency === costCurrency ? 1 : pricingExchangeRate)
        );

        if (serviceType === 'air ticket') {
            return convertedPurchaseCost;
        }

        return purchaseCost > 0.005 ? convertedPurchaseCost : toNumber(serviceLine?.salePrice);
    };

    const serviceReceivableAmount = (serviceLine) => {
        const savedFinalSale = toNumber(serviceLine.finalSalePrice);
        if (Math.abs(savedFinalSale) > 0.005) {
            return savedFinalSale;
        }

        if (String(serviceLine.type || 'air ticket') === 'air ticket') {
            const invoiceCurrency = String(serviceLine?.currency || 'PKR').trim().toUpperCase();
            const costCurrency = String(serviceLine?.costCurrency || invoiceCurrency).trim().toUpperCase();
            const pricingExchangeRate = toNumber(serviceLine?.pricingExchangeRate || 1);
            const costCurrencyReceivable = roundToTwo(
                toNumber(serviceLine.purchaseCost)
                + toNumber(serviceLine.serviceCharge)
                + toNumber(serviceLine.vat)
                - toNumber(serviceLine.discountAmount)
            );

            return roundToTwo(costCurrencyReceivable * (invoiceCurrency === costCurrency ? 1 : pricingExchangeRate));
        }

        const receivableBase = toNumber(serviceLine.salePrice);

        return roundToTwo(
            receivableBase
            + toNumber(serviceLine.serviceCharge)
            + toNumber(serviceLine.vat)
            - toNumber(serviceLine.discountAmount)
        );
    };

    const hasManualFinalSaleOverride = (serviceLine) => {
        const savedFinalSale = toNumber(serviceLine.finalSalePrice);
        const suggestedFinalSale = serviceReceivableAmount({
            ...serviceLine,
            finalSalePrice: 0,
        });

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
        const alreadyReceived = Math.max(toNumber(paymentAlreadyReceivedInput?.dataset.paymentPersistedReceived || paymentAlreadyReceivedInput?.value || 0), 0);
        const provisionalBalance = roundToTwo(Math.max(provisionalTotal - alreadyReceived, 0));
        const paymentCurrency = paymentCurrencySelect?.value || invoiceCurrency;

        paymentCurrentInvoiceInput.dataset.paymentCurrency = invoiceCurrency;
        paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = String(provisionalTotal);
        paymentCurrentInvoiceInput.value = formatNumberInputValue(provisionalTotal);
        if (paymentCurrentBalanceInput) {
            paymentCurrentBalanceInput.dataset.paymentPersistedInvoiceBalance = String(provisionalBalance);
            paymentCurrentBalanceInput.value = formatCurrencyAmount(invoiceCurrency, provisionalBalance);
        }
        station.querySelectorAll('[data-payment-current-invoice-row], [data-payment-current-balance-row]').forEach((row) => {
            if (row instanceof HTMLElement) {
                row.hidden = provisionalTotal <= 0.005 && provisionalBalance <= 0.005;
            }
        });
        if (paymentTotalOutstandingInput) {
            paymentTotalOutstandingInput.dataset.paymentTotalOutstanding = String(provisionalBalance);
            paymentTotalOutstandingInput.dataset.paymentTotalDueNow = paymentCurrency === invoiceCurrency
                ? String(provisionalBalance)
                : '0';
            paymentTotalOutstandingInput.value = formatCurrencyAmount(
                paymentCurrency,
                paymentCurrency === invoiceCurrency ? provisionalBalance : 0
            );
        }
        updateCommercialTrace({
            lastEventFired: 'syncPaymentInvoiceTotals',
            lastFieldWritten: `payment current invoice=${provisionalTotal.toFixed(2)}, balance=${provisionalBalance.toFixed(2)}`,
            lastOverwriteSource: 'syncPaymentInvoiceTotals',
        });
        logCommercialCalculator('payment-totals-synced', 'syncPaymentInvoiceTotals', {
            activeReceivable,
            activeCurrency,
            invoiceCurrency,
            paymentCurrency,
            activeLineId,
            persistedTotal,
            activeInvoiceTotal,
            provisionalTotal,
            alreadyReceived,
            provisionalBalance,
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
            logCommercialCalculator('guard-failed', source, {
                missingRequirements,
                hasServiceProfit: Boolean(serviceProfit),
                hasSale: Boolean(serviceMetricInputs.sale),
                hasCost: Boolean(serviceMetricInputs.cost),
                hasTax: Boolean(serviceMetricInputs.tax),
                hasVat: Boolean(serviceMetricInputs.vat),
                hasCommission: Boolean(serviceMetricInputs.commission),
                hasServiceCharge: Boolean(serviceMetricInputs.serviceCharge),
                hasFinalSale: Boolean(finalSalePriceInput),
            });
            return;
        }

        refreshAirlineCommissionTotal();
        syncPricingSnapshotFields();
        const isAirTicket = isAirTicketServiceType();
        const taxTotal = currentServiceTaxTotal();
        const supplierPayable = currentServicePayableAmount();
        const convertedPayable = isAirTicket ? currentConvertedPayableAmount() : supplierPayable;
        const invoiceCurrency = currentInvoiceCurrencyCode();
        const costCurrency = currentCostCurrencyCode();
        const pricingRate = currentPricingExchangeRate({ invoiceCurrency, costCurrency });
        const missingPricingRate = invoiceCurrency !== costCurrency && pricingRate <= 0.005;
        const airlinePayable = isAirTicket ? supplierPayable : 0;
        const otherPayable = isAirTicket ? 0 : supplierPayable;
        const totalPayable = convertedPayable;
        const currentManualOverride = finalSalePriceInput.dataset.manualOverride === '1';
        const suggestedFinalSalePrice = defaultFinalSalePrice(totalPayable);
        updateCommercialTrace({
            lastMktFareRead: toNumber(serviceMetricInputs.sale?.value).toFixed(2),
            lastServAmountRead: toNumber(serviceMetricInputs.serviceCharge?.value).toFixed(2),
            lastFrTxComputed: airlinePayable.toFixed(2),
            lastFinalSaleComputed: suggestedFinalSalePrice.toFixed(2),
        });
        logCommercialCalculator('refresh-start', source, {
            isAirTicket,
            taxTotal,
            supplierPayable,
            convertedPayable,
            airlinePayable,
            otherPayable,
            totalPayable,
            pricingRate,
            missingPricingRate,
            currentManualOverride,
            suggestedFinalSalePrice,
        });
        const existingFinalSalePrice = toNumber(finalSalePriceInput.value);
        const staleZeroManualOverride = currentManualOverride
            && existingFinalSalePrice <= 0.005
            && suggestedFinalSalePrice > 0.005;
        if (!currentManualOverride || finalSalePriceInput.value === '' || staleZeroManualOverride) {
            finalSalePriceInput.value = formatNumberInputValue(suggestedFinalSalePrice);
            finalSalePriceInput.dataset.manualOverride = staleZeroManualOverride ? '0' : '0';
            updateCommercialTrace({
                lastFieldWritten: `final sale=${finalSalePriceInput.value}`,
                lastOverwriteSource: source,
            });
        }
        const finalSalePrice = toNumber(finalSalePriceInput.value);
        const receivable = finalSalePrice;
        const manualSaleAdjustment = currentManualOverride
            ? roundToTwo(finalSalePrice - suggestedFinalSalePrice)
            : 0;
        const profit = receivable - totalPayable;
        const lossAmount = Math.max(totalPayable - finalSalePrice, 0);
        const hasLoss = lossAmount > 0.005;

        if (serviceMetricInputs.cost) {
            serviceMetricInputs.cost.value = formatNumberInputValue(supplierPayable);
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
            bottomTotalFields.payable.value = formatMoney(totalPayable);
        }
        if (bottomTotalFields.profit) {
            bottomTotalFields.profit.value = formatMoney(profit);
        }
        if (manualSaleAdjustmentInput) {
            manualSaleAdjustmentInput.value = formatNumberInputValue(manualSaleAdjustment);
            manualSaleAdjustmentInput.classList.toggle('is-positive', manualSaleAdjustment > 0.005);
            manualSaleAdjustmentInput.classList.toggle('is-negative', manualSaleAdjustment < -0.005);
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
            lossAmountInput.value = formatNumberInputValue(lossAmount);
        }
        if (serviceFields.lossReason) {
            serviceFields.lossReason.required = hasLoss;
            serviceFields.lossReason.disabled = !hasLoss;
            if (!hasLoss) {
                serviceFields.lossReason.value = '';
            }
        }

        refreshFinancialCorrectionLossPreview();

        if (commercialDebug) {
            updateCommercialTrace({
                bootStatus: 'refresh-complete',
                lastMktFareRead: toNumber(serviceMetricInputs.sale?.value).toFixed(2),
                lastServAmountRead: toNumber(serviceMetricInputs.serviceCharge?.value).toFixed(2),
                lastFrTxComputed: airlinePayable.toFixed(2),
                lastFinalSaleComputed: finalSalePrice.toFixed(2),
            }, { log: false });
        }
        logCommercialCalculator('refresh-complete', source, {
            finalSalePrice,
            receivable,
            manualSaleAdjustment,
            profit,
            lossAmount,
            hasLoss,
            paymentInvoiceValue: paymentCurrentInvoiceInput?.value || '',
            paymentBalanceValue: paymentCurrentBalanceInput?.value || '',
            serviceProfitText: serviceProfit.textContent || '',
        });

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
        const canShowServiceEventActions = workspaceOpenedExistingBookingAtLoad && isPersisted;
        const status = String(serviceLine.status || serviceLine.displayStatus || '').trim().toLowerCase().replace(/\s+/g, '_');
        const type = String(serviceLine.type || '').trim().toLowerCase();
        const hasCancellationEvent = Number.parseInt(String(serviceLine.latestCancelEventId || 0), 10) > 0
            || serviceLine.hasCancellationEvent === true
            || String(serviceLine.hasCancellationEvent || '') === '1';
        const isCancelled = status === 'cancelled' || hasCancellationEvent;
        const settlementFinanciallySettled = serviceLine.latestCancelFinanciallySettled === true
            || String(serviceLine.latestCancelFinanciallySettled || '') === '1';
        const customerRefundFollowUp = toNumber(serviceLine.customerRefundableCreditAmount || 0);
        const supplierRefundFollowUp = toNumber(serviceLine.supplierRefundableCreditAmount || 0);
        const releasedCustomerCredit = toNumber(serviceLine.latestCancelReleasedCustomerCreditAmount || 0);
        const releasedSupplierCredit = toNumber(serviceLine.latestCancelReleasedSupplierCreditAmount || 0);
        const latestCustomerRefundAmountOnly = toNumber(serviceLine.latestCustomerRefundAmountOnly || 0);
        const latestSupplierRefundAmountOnly = toNumber(serviceLine.latestSupplierRefundAmountOnly || 0);
        const latestCustomerRefundEventId = Number.parseInt(String(serviceLine.latestCustomerRefundEventId || 0), 10);
        const latestSupplierRefundEventId = Number.parseInt(String(serviceLine.latestSupplierRefundEventId || 0), 10);
        const hasRefundEvent = Number.parseInt(String(serviceLine.latestRefundEventId || 0), 10) > 0;
        const hasRefundReverseOptions = latestCustomerRefundEventId > 0 || latestSupplierRefundEventId > 0;
        const hasPostedRefundActivity = latestCustomerRefundAmountOnly > 0.005 || latestSupplierRefundAmountOnly > 0.005;
        const refundFollowUpComplete = isCancelled
            && settlementFinanciallySettled
            && customerRefundFollowUp <= 0.005
            && supplierRefundFollowUp <= 0.005
            && hasPostedRefundActivity;
        const refundFollowUpOpen = !refundFollowUpComplete && (
            !isCancelled
            || settlementFinanciallySettled
            || customerRefundFollowUp > 0.005
            || supplierRefundFollowUp > 0.005
            || hasRefundReverseOptions
            || (!settlementFinanciallySettled && (releasedCustomerCredit > 0.005 || releasedSupplierCredit > 0.005))
            || (!hasRefundEvent && (releasedCustomerCredit > 0.005 || releasedSupplierCredit > 0.005))
        );
        const canSettleCancellation = canShowServiceEventActions
            && (isCancelled || settlementInitiallyVisible)
            && !settlementFinanciallySettled;
        const canRefundService = canShowServiceEventActions && refundFollowUpOpen;

        if (isPersisted) {
            hideSupplierAdvanceNote();
        }

        const cancelledNote = station.querySelector('[data-service-cancelled-note]');
        const canOpenBookingEditor = canShowServiceEventActions;
        const canOpenPenaltyRefundEditor = canShowServiceEventActions || isCancelled;
        if (cancelledNote instanceof HTMLElement) {
            cancelledNote.hidden = !canShowServiceEventActions || !isCancelled;
        }

        serviceEditBookingOpenButtons.forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.disabled = !canOpenBookingEditor;
            }
        });

        servicePenaltyRefundOpenButtons.forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.disabled = !canOpenPenaltyRefundEditor;
            }
        });

        if (serviceEventBars.cancel) {
            serviceEventBars.cancel.hidden = !canShowServiceEventActions || isCancelled;
            setServiceEventBarControlsEnabled(serviceEventBars.cancel, canShowServiceEventActions && !isCancelled);
        }

        if (serviceEventBars.refund) {
            serviceEventBars.refund.hidden = !canRefundService;
            setServiceEventBarControlsEnabled(serviceEventBars.refund, canRefundService);
        }

        if (serviceEventBars.settlement) {
            serviceEventBars.settlement.hidden = !canSettleCancellation;
            setServiceEventBarControlsEnabled(serviceEventBars.settlement, canSettleCancellation);
            unlockVisibleSettlementBar();
        }

        if (serviceEventBars.reissue) {
            serviceEventBars.reissue.hidden = !canShowServiceEventActions || type !== 'air ticket';
            setServiceEventBarControlsEnabled(serviceEventBars.reissue, canShowServiceEventActions && type === 'air ticket');
        }

        if (serviceCorrectionBars.settlement instanceof HTMLElement) {
            serviceCorrectionBars.settlement.hidden = !(canShowServiceEventActions && settlementFinanciallySettled);
        }

        if (serviceCorrectionBars.settlementReverse instanceof HTMLElement) {
            serviceCorrectionBars.settlementReverse.hidden = !(canShowServiceEventActions && settlementFinanciallySettled);
            serviceCorrectionBars.settlementReverse.querySelectorAll('button').forEach((button) => {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = hasRefundReverseOptions;
                }
            });
        }

        if (serviceCorrectionBars.refund instanceof HTMLElement) {
            serviceCorrectionBars.refund.hidden = !(canShowServiceEventActions && hasRefundReverseOptions);
        }

        if (serviceCorrectionBars.refundReverse instanceof HTMLElement) {
            serviceCorrectionBars.refundReverse.hidden = !(canShowServiceEventActions && hasRefundReverseOptions);
        }

        syncFinancialCorrectionEditor(serviceLine, canShowServiceEventActions);

        if (serviceSubmitButton) {
            serviceSubmitButton.textContent = 'Update Service';
            serviceSubmitButton.hidden = !isPersisted;
            serviceSubmitButton.setAttribute('aria-hidden', isPersisted ? 'false' : 'true');
            serviceSubmitButton.tabIndex = isPersisted ? 0 : -1;
        }

        if (activeServiceMode) {
            activeServiceMode.textContent = isPersisted ? 'Editing Service Line' : (hasPersistedServices() ? 'New Service Line' : 'First Service Line');
        }

        lockSavedServiceFinancialEditor();

        if (serviceDeactivateId) {
            serviceDeactivateId.value = isPersisted ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceDeactivateButton) {
            serviceDeactivateButton.disabled = !isPersisted;
        }

        if (serviceCancelId) {
            serviceCancelId.value = canShowServiceEventActions ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceCancelButton) {
            serviceCancelButton.disabled = !canShowServiceEventActions || isCancelled;
        }

        if (serviceRefundId) {
            serviceRefundId.value = canShowServiceEventActions ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceRefundCurrencyField instanceof HTMLInputElement) {
            serviceRefundCurrencyField.value = String(serviceLine.currency || serviceRefundCurrencyField.value || 'PKR').trim().toUpperCase() || 'PKR';
        }

        if (serviceRefundButton) {
            serviceRefundButton.disabled = !canRefundService;
        }

        if (serviceSettlementId) {
            serviceSettlementId.value = canSettleCancellation ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceSettlementButton) {
            serviceSettlementButton.disabled = !canSettleCancellation && serviceEventBars.settlement?.hidden !== false;
        }

        syncSettlementFormFromServiceLine(serviceLine);
        syncSettlementCorrectionFormFromServiceLine(serviceLine);
        syncRefundCorrectionFormFromServiceLine(serviceLine);

        if (serviceReissueId) {
            serviceReissueId.value = canShowServiceEventActions && type === 'air ticket' ? String(serviceLine.serviceId || '') : '';
        }

        if (serviceReissueButton) {
            serviceReissueButton.disabled = !canShowServiceEventActions || type !== 'air ticket';
        }

        syncRefundTreasurySelector();
        syncSupplierRefundTreasurySelector();
        syncCorrectionRefundTreasurySelector();
        syncCorrectionSupplierRefundTreasurySelector();
    };
    reapplyActiveServiceActionState = () => {
        const activeServiceRow = serviceRows.find((row) => row.classList.contains('is-active'));
        const activeServiceIndex = activeServiceRow instanceof HTMLElement
            ? Number.parseInt(activeServiceRow.dataset.serviceIndex || '-1', 10)
            : -1;
        if (activeServiceIndex >= 0 && serviceLines[activeServiceIndex]) {
            updateServiceActionState(serviceLines[activeServiceIndex]);
        }
    };

    const eligibleRefundTreasuryAccounts = () => {
        if (!serviceRefundMethodSelect) {
            return [];
        }

        const method = String(serviceRefundMethodSelect.value || '').trim();
        const currency = serviceRefundCurrencyField instanceof HTMLInputElement
            ? String(serviceRefundCurrencyField.value || '').trim().toUpperCase()
            : '';
        const branchId = serviceRefundBranchIdField instanceof HTMLInputElement
            ? Number.parseInt(String(serviceRefundBranchIdField.value || '0'), 10) || 0
            : 0;
        const compatibleTypes = paymentTreasuryTypesForMethod(method);

        return paymentTreasuryAccounts.filter((account) => {
            return (branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === currency;
        });
    };

    const eligibleRefundTreasuryAccountsFor = (method, branchField, currencyField) => {
        const normalizedMethod = String(method || '').trim();
        const currency = currencyField instanceof HTMLInputElement || currencyField instanceof HTMLSelectElement
            ? String(currencyField.value || '').trim().toUpperCase()
            : '';
        const branchId = branchField instanceof HTMLInputElement || branchField instanceof HTMLSelectElement
            ? Number.parseInt(String(branchField.value || '0'), 10) || 0
            : 0;
        const compatibleTypes = paymentTreasuryTypesForMethod(normalizedMethod);

        return paymentTreasuryAccounts.filter((account) => {
            return (branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === currency;
        });
    };

    const syncRefundTreasurySelectorFor = ({
        form,
        methodSelect,
        treasuryRow,
        treasurySelect,
        branchField,
        currencyField,
        destinationRows,
        toggleFormClass = true,
    }) => {
        if (!methodSelect || !treasurySelect || !treasuryRow) {
            return;
        }

        const method = String(methodSelect.value || '').trim();
        const requiresTreasury = paymentMethodRequiresTreasurySelection(method);
        const eligibleAccounts = requiresTreasury
            ? eligibleRefundTreasuryAccountsFor(method, branchField, currencyField)
            : [];
        const selectedBefore = String(treasurySelect.value || '').trim();
        const preferredAccount = defaultPaymentTreasuryAccount(eligibleAccounts);

        treasurySelect.innerHTML = '';

        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = eligibleAccounts.length > 0
            ? (method === 'cash' ? 'Select refund cash account' : 'Select refund bank account')
            : 'No eligible refund account configured';
        treasurySelect.appendChild(promptOption);

        eligibleAccounts.forEach((account) => {
            const option = document.createElement('option');
            option.value = String(account.id || '');
            option.textContent = buildPaymentTreasuryLabel(account);
            treasurySelect.appendChild(option);
        });

        let nextValue = '';
        if (selectedBefore !== '' && eligibleAccounts.some((account) => String(account.id || '') === selectedBefore)) {
            nextValue = selectedBefore;
        } else if (preferredAccount) {
            nextValue = String(preferredAccount.id || '');
        }
        treasurySelect.value = nextValue;

        treasuryRow.hidden = !requiresTreasury;
        treasurySelect.disabled = !requiresTreasury;

        const showBankDestination = method === 'bank_transfer';
        if (toggleFormClass && form instanceof HTMLFormElement) {
            form.classList.toggle('legacy-service-event-bar--refund-bank', showBankDestination);
            form.classList.toggle('legacy-service-event-bar--refund-cash', !showBankDestination);
        }
        destinationRows.forEach((row) => {
            row.hidden = !showBankDestination;
            const field = row.querySelector('input, select, textarea');
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                field.disabled = !showBankDestination;
            }
        });
    };

    const syncRefundTreasurySelector = () => {
        if (!serviceRefundMethodSelect || !serviceRefundTreasurySelect || !serviceRefundTreasuryRow) {
            return;
        }

        syncRefundTreasurySelectorFor({
            form: serviceRefundForm,
            methodSelect: serviceRefundMethodSelect,
            treasuryRow: serviceRefundTreasuryRow,
            treasurySelect: serviceRefundTreasurySelect,
            branchField: serviceRefundBranchIdField,
            currencyField: serviceRefundCurrencyField,
            destinationRows: serviceRefundDestinationRows,
        });
    };
    if (serviceRefundMethodSelect) {
        serviceRefundMethodSelect.addEventListener('change', syncRefundTreasurySelector);
    }

    const syncSupplierRefundTreasurySelector = () => {
        if (!serviceSupplierRefundMethodSelect || !serviceSupplierRefundTreasurySelect || !serviceSupplierRefundTreasuryRow) {
            return;
        }

        syncRefundTreasurySelectorFor({
            form: serviceRefundForm,
            methodSelect: serviceSupplierRefundMethodSelect,
            treasuryRow: serviceSupplierRefundTreasuryRow,
            treasurySelect: serviceSupplierRefundTreasurySelect,
            branchField: serviceRefundBranchIdField,
            currencyField: serviceRefundCurrencyField,
            destinationRows: [],
            toggleFormClass: false,
        });
    };
    if (serviceSupplierRefundMethodSelect) {
        serviceSupplierRefundMethodSelect.addEventListener('change', syncSupplierRefundTreasurySelector);
    }

    const syncCorrectionRefundTreasurySelector = () => {
        if (!serviceCorrectionRefundMethodSelect || !serviceCorrectionRefundTreasurySelect || !serviceCorrectionRefundTreasuryRow) {
            return;
        }

        syncRefundTreasurySelectorFor({
            form: serviceCorrectionRefundForm,
            methodSelect: serviceCorrectionRefundMethodSelect,
            treasuryRow: serviceCorrectionRefundTreasuryRow,
            treasurySelect: serviceCorrectionRefundTreasurySelect,
            branchField: serviceCorrectionRefundBranchIdField,
            currencyField: serviceCorrectionRefundCurrencyField,
            destinationRows: serviceCorrectionRefundDestinationRows,
        });
    };
    if (serviceCorrectionRefundMethodSelect) {
        serviceCorrectionRefundMethodSelect.addEventListener('change', syncCorrectionRefundTreasurySelector);
    }

    const syncCorrectionSupplierRefundTreasurySelector = () => {
        if (!serviceCorrectionSupplierRefundMethodSelect || !serviceCorrectionSupplierRefundTreasurySelect || !serviceCorrectionSupplierRefundTreasuryRow) {
            return;
        }

        syncRefundTreasurySelectorFor({
            form: serviceCorrectionRefundForm,
            methodSelect: serviceCorrectionSupplierRefundMethodSelect,
            treasuryRow: serviceCorrectionSupplierRefundTreasuryRow,
            treasurySelect: serviceCorrectionSupplierRefundTreasurySelect,
            branchField: serviceCorrectionRefundBranchIdField,
            currencyField: serviceCorrectionRefundCurrencyField,
            destinationRows: [],
            toggleFormClass: false,
        });
    };
    if (serviceCorrectionSupplierRefundMethodSelect) {
        serviceCorrectionSupplierRefundMethodSelect.addEventListener('change', syncCorrectionSupplierRefundTreasurySelector);
    }

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
        fillValue(serviceFields.costCurrency, serviceLine.costCurrency || serviceLine.currency || 'PKR');
        fillValue(serviceFields.pricingExchangeRate, serviceLine.pricingExchangeRate || 1);
        fillValue(serviceFields.pricingRateEffectiveDate, serviceLine.pricingRateEffectiveDate || '');
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
        fillValue(ticketFields.ticketType, serviceLine.ticketType || 'international');
        fillValue(ticketFields.class, serviceLine.class || 'economy');
        fillValue(ticketFields.sectorFrom, serviceLine.sectorFrom || '');
        fillValue(ticketFields.sectorTo, serviceLine.sectorTo || '');
        syncTicketRouteDisplay(serviceLine.sectorFrom || '', serviceLine.sectorTo || '');
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
        syncAllCommercialPercentsFromAmounts();
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
        fillValue(serviceFields.costCurrency, paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR');
        fillValue(serviceFields.pricingExchangeRate, 1);
        fillValue(serviceFields.pricingRateEffectiveDate, '');
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
        fillValue(servicePercentInputs.serviceCharge, 0);
        fillValue(servicePercentInputs.discount, 0);
        fillValue(servicePercentInputs.vat, 0);
        fillValue(finalSalePriceInput, 0);
        if (finalSalePriceInput) {
            finalSalePriceInput.dataset.manualOverride = '0';
        }
        fillValue(airlineCommissionExtra, 0);
        fillValue(airlineCommissionAdjustment, 0);
        fillValue(ticketFields.pnr, '');
        fillValue(ticketFields.ticketNumber, '');
        fillValue(ticketFields.airline, '');
        fillValue(ticketFields.ticketType, 'international');
        fillValue(ticketFields.class, 'economy');
        fillValue(ticketFields.sectorFrom, '');
        fillValue(ticketFields.sectorTo, '');
        syncTicketRouteDisplay('', '');
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

        syncSettlementFormFromServiceLine({
            currency: paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR',
            latestCancelCustomerPenaltyAmount: 0,
            latestCancelSupplierPenaltyAmount: 0,
            latestCancelExpectedSupplierRefundAmount: 0,
            latestCancelReleasedCustomerCreditAmount: 0,
            latestCancelReleasedSupplierCreditAmount: 0,
            latestCancelFinanciallySettled: false,
            latestCancelReason: '',
            latestCancelEventDate: '',
        });
        syncSettlementCorrectionFormFromServiceLine({
            currency: paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR',
            latestCancelCustomerPenaltyAmount: 0,
            latestCancelSupplierPenaltyAmount: 0,
            latestCancelExpectedSupplierRefundAmount: 0,
            latestCancelReleasedCustomerCreditAmount: 0,
            latestCancelReleasedSupplierCreditAmount: 0,
            latestCancelFinanciallySettled: false,
            latestCancelReason: '',
            latestCancelEventDate: '',
        });
        syncRefundCorrectionFormFromServiceLine({
            latestCustomerRefundAmountOnly: 0,
            latestSupplierRefundAmountOnly: 0,
        });
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
            const lineSector = serviceLineDetailLabel(serviceLine);
            const lineReceivable = Math.abs(toNumber(serviceLine.rowReceivable)) > 0.005
                ? toNumber(serviceLine.rowReceivable)
                : serviceReceivableAmount(serviceLine);
            const lineCurrency = String(serviceLine.currency || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR').toUpperCase();
            const lineReference = String(serviceLine.lineNumber || '').trim();
            const linePaid = Math.min(Math.max(paymentAllocations.reduce((total, allocation) => {
                const receiptStatus = String(allocation?.receiptStatusRaw || allocation?.receiptStatus || '').trim().toLowerCase();
                const allocationType = String(allocation?.allocationType || '').trim().toLowerCase();
                const allocationReference = String(allocation?.serviceLineReference || '').trim();
                const allocationCurrency = String(allocation?.receivableCurrency || allocation?.currency || '').trim().toUpperCase();
                if (receiptStatus === 'void'
                    || allocationType !== 'current invoice'
                    || allocationReference !== lineReference
                    || allocationCurrency !== lineCurrency) {
                    return total;
                }

                return total + Math.max(toNumber(allocation?.receivableAmountAllocated ?? allocation?.allocatedAmount ?? 0), 0);
            }, 0), 0), lineReceivable);
            const lineOutstanding = Math.max(lineReceivable - linePaid, 0);
            const linePnr = String(serviceLine.pnr || '').trim() || 'N/A';
            const linePassengerName = String(serviceLine.passengerName || '').trim() || 'Passenger';
            const lineRelation = serviceIndex === 0 ? 'Self' : 'Passenger';
            const lineRemarks = serviceIndex === 0 ? 'Lead Traveler' : '';
            const isActive = activeServiceId > 0
                ? Number.parseInt(String(serviceLine.serviceId || 0), 10) === activeServiceId
                : serviceIndex === 0;

            row.className = isActive ? 'is-active' : '';
            row.dataset.serviceRow = 'true';
            row.dataset.serviceIndex = String(serviceIndex);
            row.innerHTML = `
                <td>${serviceIndex + 1}</td>
                <td>${escapeHtml(linePassengerName)}</td>
                <td>${escapeHtml(lineRelation)}</td>
                <td>${escapeHtml(linePnr)}</td>
                <td>${escapeHtml(lineSector || 'N/A')}</td>
                <td data-passenger-summary-invoice>${escapeHtml(formatCurrencyAmount(lineCurrency, lineReceivable))}</td>
                <td data-passenger-summary-paid>${escapeHtml(formatCurrencyAmount(lineCurrency, linePaid))}</td>
                <td data-passenger-summary-outstanding>${escapeHtml(formatCurrencyAmount(lineCurrency, lineOutstanding))}</td>
                <td>${escapeHtml(lineRemarks)}</td>
            `;
            serviceTableBody.appendChild(row);
        });

        serviceRows = Array.from(station.querySelectorAll('[data-service-row]'));
    };

    const isActiveServiceEditorElement = (element) => {
        if (!(element instanceof HTMLElement)) {
            return false;
        }

        if (element.closest('[data-payment-exchange-modal], [data-payment-detail-modal]')) {
            return false;
        }

        if (serviceForm && serviceForm.contains(element)) {
            return true;
        }

        const formAttribute = element.getAttribute('form');
        return formAttribute === 'legacy-service-form';
    };

    const isActivelyEditingServiceForm = () => {
        const activeElement = document.activeElement;
        if (!isActiveServiceEditorElement(activeElement)) {
            return false;
        }

        return activeElement instanceof HTMLInputElement
            || activeElement instanceof HTMLSelectElement
            || activeElement instanceof HTMLTextAreaElement;
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

    const upsertAutosavedServiceLine = (serviceLine, options = {}) => {
        if (!serviceLine) {
            return;
        }

        const reloadEditor = options.reloadEditor !== false;

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
        if (reloadEditor && activeIndex >= 0) {
            loadServiceLine(activeIndex);
            return;
        }

        if (activeIndex >= 0) {
            serviceRows.forEach((item, rowIndex) => {
                item.classList.toggle('is-active', rowIndex === activeIndex);
            });
        }

        if (activeServiceReference) {
            activeServiceReference.textContent = serviceLine.lineNumber || '';
        }

        updateServiceActionState(serviceLine);
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
        mergedLine.costCurrency = mergedLine.costCurrency || draftSnapshot.costCurrency || mergedLine.currency || 'PKR';
        mergedLine.pricingExchangeRate = toNumber(mergedLine.pricingExchangeRate || draftSnapshot.pricingExchangeRate || 1) || 1;
        mergedLine.pricingRateEffectiveDate = mergedLine.pricingRateEffectiveDate || draftSnapshot.pricingRateEffectiveDate || '';
        mergedLine.status = mergedLine.status || 'Open';
        mergedLine.displayStatus = mergedLine.displayStatus || mergedLine.status || 'Open';
        mergedLine.passengerName = mergedLine.passengerName || draftSnapshot.passengerName || '';
        mergedLine.rowReceivable = Math.abs(toNumber(mergedLine.rowReceivable)) > 0.005
            ? toNumber(mergedLine.rowReceivable)
            : serviceReceivableAmount(mergedLine);
        mergedLine.rowPayable = Math.abs(toNumber(mergedLine.rowPayable)) > 0.005
            ? toNumber(mergedLine.rowPayable)
            : serviceLinePayableAmount(mergedLine);
        mergedLine.rowSpTotal = Math.abs(toNumber(mergedLine.rowSpTotal)) > 0.005
            ? toNumber(mergedLine.rowSpTotal)
            : mergedLine.rowReceivable;
        mergedLine.rowProfit = Math.abs(toNumber(mergedLine.rowProfit)) > 0.005
            ? toNumber(mergedLine.rowProfit)
            : (mergedLine.rowReceivable - mergedLine.rowPayable);
        const hasAllocatedAmount = Object.prototype.hasOwnProperty.call(mergedLine, 'allocatedAmount');
        const hasOutstandingAmount = Object.prototype.hasOwnProperty.call(mergedLine, 'outstandingAmount');
        mergedLine.allocatedAmount = hasAllocatedAmount
            ? Math.max(Math.min(toNumber(mergedLine.allocatedAmount), mergedLine.rowReceivable), 0)
            : 0;
        mergedLine.outstandingAmount = hasOutstandingAmount
            ? Math.max(toNumber(mergedLine.outstandingAmount), 0)
            : Math.max(mergedLine.rowReceivable - mergedLine.allocatedAmount, 0);
        mergedLine.persisted = mergedLine.serviceId > 0;
        mergedLine.saved = mergedLine.serviceId > 0;
        mergedLine.paymentEligible = payload.payment_eligible === true
            || mergedLine.paymentEligible === true
            || mergedLine.rowReceivable > 0.005
            || toNumber(mergedLine.finalSalePrice) > 0.005;

        return mergedLine;
    };

    const applySavedServiceUiState = (payload, options = {}) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const preserveLiveEditorState = options.preserveLiveEditorState === true;

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
            upsertAutosavedServiceLine(normalizedServiceLine, {
                reloadEditor: preserveLiveEditorState ? false : !isActivelyEditingServiceForm(),
            });
            updateServiceActionState(normalizedServiceLine);
        }

        station.dataset.hasSavedService = autosavedHasSavedService ? '1' : '0';
        setInvoiceNumber(payload.invoice_no || 'Draft');
        applyAutosavePaymentFoundation(payload, {
            syncCommercialEditor: !preserveLiveEditorState,
            preserveLiveInvoicePreview: preserveLiveEditorState,
        });

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
                if (finalSalePriceInput instanceof HTMLInputElement) {
                    finalSalePriceInput.dataset.manualOverride = '0';
                }
                updateCommercialTrace({
                    listenerAttached: true,
                    lastEventFired: `input:${input.name || input.id || 'metric'}`,
                });
                logCommercialCalculator('metric-input', input.name || input.id || 'metric', {
                    targetId: input.id || '',
                    targetName: input.name || '',
                    targetValue: input.value,
                });
                refreshProfit(`input:${input.name || input.id || 'metric'}`);
                syncTicketCommercialMirrors();
                if (input === serviceMetricInputs.sale
                    || input === serviceMetricInputs.tax
                    || input === serviceMetricInputs.otherFare
                    || input === serviceMetricInputs.sotoFare
                    || input === serviceMetricInputs.spyiAmount
                    || input === serviceMetricInputs.aqYrPkAmount
                    || input === serviceMetricInputs.yqAmount
                    || input === serviceMetricInputs.othAmount
                    || input === serviceMetricInputs.vatInput) {
                    reapplyActiveCommercialPercents();
                }
            });
        }
    });
    updateCommercialTrace({
        listenerAttached: Object.values(serviceMetricInputs).some((input) => Boolean(input)),
    });
    logCommercialCalculator('boot-bindings', 'workspace-init', {
        listenerAttached: Object.values(serviceMetricInputs).some((input) => Boolean(input)),
        hasCommercialEditor: commercialEditor instanceof HTMLElement,
        hasPaymentCurrentInvoiceInput: paymentCurrentInvoiceInput instanceof HTMLInputElement,
        hasPaymentCurrentBalanceInput: paymentCurrentBalanceInput instanceof HTMLInputElement,
    });

    if (commercialEditor instanceof HTMLElement) {
        commercialEditor.addEventListener('input', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            if (
                target.matches('[data-service-metric], [data-service-discount], [data-service-percent], [data-service-final-sale]')
            ) {
                logCommercialCalculator('delegated-input', target.name || target.id || 'field', {
                    targetId: target.id || '',
                    targetName: target.name || '',
                    targetValue: target.value,
                    matchesMetric: target.matches('[data-service-metric]'),
                    matchesDiscount: target.matches('[data-service-discount]'),
                    matchesPercent: target.matches('[data-service-percent]'),
                    matchesFinalSale: target.matches('[data-service-final-sale]'),
                });
                refreshProfit(`commercial-delegate:${target.name || target.id || 'field'}`);
                syncTicketCommercialMirrors();
            }
        });
        commercialEditor.addEventListener('change', async (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) {
                return;
            }

            if (
                target.matches('[data-service-metric], [data-service-discount], [data-service-percent], [data-service-final-sale], [data-service-field="currency"], [data-service-field="cost_currency"]')
            ) {
                if (
                    finalSalePriceInput instanceof HTMLInputElement
                    && target.matches('[data-service-field="currency"], [data-service-field="cost_currency"]')
                ) {
                    finalSalePriceInput.dataset.manualOverride = '0';
                }
                const invoiceCurrencyChanged = target.matches('[data-service-field="currency"]');
                const costCurrencyChanged = target.matches('[data-service-field="cost_currency"]');
                if (invoiceCurrencyChanged && paymentCurrencySelect instanceof HTMLSelectElement) {
                    const normalizedInvoiceCurrency = String(target.value || 'PKR').trim().toUpperCase() || 'PKR';
                    paymentCurrencySelect.dataset.paymentManualSelection = '0';
                    paymentCurrencySelect.dataset.paymentManualContext = '';
                    paymentCurrencySelect.value = normalizedInvoiceCurrency;
                    syncPaymentTreasurySelector();
                    syncPaymentCurrencyLabels(normalizedInvoiceCurrency);
                    if (paymentExchangeModal && !paymentExchangeModal.hidden) {
                        closeExchangeSettlementModal();
                    }
                }
                logCommercialCalculator('delegated-change', target.name || target.id || 'field', {
                    targetId: target.id || '',
                    targetName: target.name || '',
                    targetValue: target.value,
                    matchesCurrency: invoiceCurrencyChanged,
                    matchesCostCurrency: costCurrencyChanged,
                });
                refreshProfit(`commercial-delegate-change:${target.name || target.id || 'field'}`);
                syncTicketCommercialMirrors();
                if (invoiceCurrencyChanged || costCurrencyChanged) {
                    await ensurePricingExchangeRateReady({
                        reason: invoiceCurrencyChanged ? 'invoice-currency-change' : 'cost-currency-change',
                    });
                    refreshPaymentPreview();
                }
            }
        });
    }

    Object.entries(servicePercentInputs).forEach(([fieldKey, input]) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.addEventListener('input', () => {
            updateCommercialTrace({
                listenerAttached: true,
                lastEventFired: `input:${input.id || fieldKey}`,
            });
            applyCommercialPercentToAmount(fieldKey);
        });
    });

    [
        ['serviceCharge', serviceMetricInputs.serviceCharge],
        ['vat', serviceMetricInputs.vat],
    ].forEach(([fieldKey, input]) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.addEventListener('input', () => {
            syncCommercialPercentFromAmount(fieldKey, { markManualAmount: true });
        });
    });

    if (serviceDiscountInput) {
        serviceDiscountInput.addEventListener('input', () => {
            if (finalSalePriceInput instanceof HTMLInputElement) {
                finalSalePriceInput.dataset.manualOverride = '0';
            }
            updateCommercialTrace({
                listenerAttached: true,
                lastEventFired: `input:${serviceDiscountInput.name || serviceDiscountInput.id || 'discount'}`,
            });
            syncCommercialPercentFromAmount('discount', { markManualAmount: true });
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

    [financialCorrectionCostInput, financialCorrectionCustomerTotalInput].forEach((field) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        field.addEventListener('input', refreshFinancialCorrectionLossPreview);
        field.addEventListener('change', refreshFinancialCorrectionLossPreview);
    });

    [airlineCommissionExtra, airlineCommissionAdjustment].forEach((input) => {
        if (input) {
            input.addEventListener('input', refreshAirlineCommissionTotal);
        }
    });

    if (serviceTypeField) {
        serviceTypeField.addEventListener('change', () => {
            refreshSubtypeVisibility();
            refreshProfit('change:service_type');
            if (fieldAutosaveEnabled) {
                scheduleServiceAutosave();
            }
        });
    }

    if (serviceFields.currency) {
        serviceFields.currency.addEventListener('change', () => {
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = '';
            }
            refreshProfit(`change:${serviceFields.currency.name || 'currency'}`);
            syncTicketCommercialMirrors();
            syncServiceCurrencyMirror();
            refreshPaymentPreview();
        });
    }

    if (serviceFields.costCurrency) {
        serviceFields.costCurrency.addEventListener('change', () => {
            if (serviceFields.pricingExchangeRate instanceof HTMLInputElement) {
                serviceFields.pricingExchangeRate.value = '';
            }
            refreshProfit(`change:${serviceFields.costCurrency.name || 'cost_currency'}`);
            scheduleSupplierAdvanceBalanceRefresh(0);
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

    const syncWorkspaceBranchContext = (options = {}) => {
        if (!(bookingBranchField instanceof HTMLSelectElement)) {
            return;
        }

        const branchId = String(bookingBranchField.value || '').trim();
        const branchCurrency = workspaceBranchBaseCurrency(branchId);
        const forceCurrency = options.forceCurrency === true;

        syncAutoBookingFields();

        const paymentBranchField = paymentForm?.elements?.namedItem('branch_id');
        if (paymentBranchField instanceof HTMLInputElement || paymentBranchField instanceof HTMLSelectElement) {
            paymentBranchField.value = branchId;
        }

        if (serviceRefundBranchIdField instanceof HTMLInputElement) {
            serviceRefundBranchIdField.value = branchId;
        }

        if (supplierAddBranch instanceof HTMLSelectElement && branchId !== '') {
            supplierAddBranch.value = branchId;
        }

        if (globalPrepaidBranchField instanceof HTMLSelectElement && branchId !== '') {
            globalPrepaidBranchField.value = branchId;
        }

        if (globalPrepaidCurrencyField instanceof HTMLSelectElement && branchCurrency !== '') {
            globalPrepaidCurrencyField.value = branchCurrency;
        }

        if (supplierAddCurrency instanceof HTMLSelectElement && branchCurrency !== '') {
            supplierAddCurrency.value = branchCurrency;
        }

        const canResetServiceCurrency = forceCurrency || currentPersistedServiceId() <= 0;
        if (canResetServiceCurrency && serviceFields.currency instanceof HTMLSelectElement && branchCurrency !== '') {
            serviceFields.currency.value = branchCurrency;
        }
        if (canResetServiceCurrency && serviceFields.costCurrency instanceof HTMLSelectElement && branchCurrency !== '') {
            serviceFields.costCurrency.value = branchCurrency;
        }

        if (paymentCurrencySelect instanceof HTMLSelectElement && branchCurrency !== '') {
            delete paymentCurrencySelect.dataset.paymentManualSelection;
            delete paymentCurrencySelect.dataset.paymentManualContext;
            delete paymentCurrencySelect.dataset.paymentDefaultContext;
            paymentCurrencySelect.value = branchCurrency;
        }

        if (typeof syncServiceCurrencyMirror === 'function') {
            syncServiceCurrencyMirror();
        }
        if (typeof syncDefaultPaymentCurrency === 'function') {
            syncDefaultPaymentCurrency(branchCurrency);
        }
        if (typeof syncPaymentCurrencyLabels === 'function') {
            syncPaymentCurrencyLabels(branchCurrency);
        }
        if (typeof syncPaymentInvoiceTotals === 'function') {
            syncPaymentInvoiceTotals(toNumber(clientReceivableField?.value || 0));
        }
        if (typeof clearExchangeSettlementFields === 'function') {
            clearExchangeSettlementFields();
        }
        if (typeof closeExchangeSettlementModal === 'function') {
            closeExchangeSettlementModal();
        }
        refreshTreasurySelectors();
        refreshPaymentPreview();
        updateWorkflowState();
    };

    bookingBranchField?.addEventListener('change', () => {
        syncWorkspaceBranchContext({ forceCurrency: true });
        showFeedback('Branch context updated. Currency, receipt branch, and treasury account defaults were refreshed.');
    });

    if (invoiceForm) {
        invoiceForm.addEventListener('submit', (event) => {
            event.preventDefault();
            showFeedback('Use Save Payment to save the invoice, service, and receipt together.');
            focusTarget('[data-payment-submit-action="save"]');
        });
    }

    if (serviceForm) {
        serviceForm.addEventListener('submit', (event) => {
            window.workspaceDebugEnterFlow('service-form-submit', {
                activeElement: document.activeElement instanceof HTMLElement
                    ? `${document.activeElement.tagName.toLowerCase()}${document.activeElement.id ? `#${document.activeElement.id}` : ''}${document.activeElement.getAttribute('name') ? `[name="${document.activeElement.getAttribute('name')}"]` : ''}`
                    : 'none',
                autosaveServiceUrl,
                suppressAutosave,
                persistedServiceId: currentPersistedServiceId(),
                bookingId: serviceForm.elements.namedItem('booking_id') instanceof HTMLInputElement
                    ? Number.parseInt(serviceForm.elements.namedItem('booking_id').value || '0', 10)
                    : 0,
            });
            syncAutoBookingFields();
            syncServiceTravelerIdFromName();

            if (!hasRequiredLossReason({ focus: true, announce: true })) {
                event.preventDefault();
                return;
            }
            if (!prepareSupplierAdvanceFxUse()) {
                event.preventDefault();
                return;
            }

            const bookingIdField = serviceForm.elements.namedItem('booking_id');
            const bookingId = bookingIdField instanceof HTMLInputElement ? Number.parseInt(bookingIdField.value || '0', 10) : 0;
            if (bookingId > 0 && currentPersistedServiceId() > 0) {
                return;
            }

            event.preventDefault();
            showFeedback('Use Save Payment to save the invoice, service, and receipt together.');
            focusTarget('[data-payment-submit-action="save"]');
        });

    const serviceAutosaveFields = new Set([
        'service_type',
        'currency',
        'cost_currency',
        'service_traveler_id',
            'service_passenger_name',
            'ticket_number',
            'ticket_pnr',
            'ticket_airline',
            'ticket_sector_from',
            'ticket_sector_to',
            'ticket_departure_date',
            'ticket_return_date',
            'ticket_type',
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
            'pricing_exchange_rate',
            'pricing_rate_effective_date',
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
    const protectedSavedServiceFinancialFields = new Set([
        'cost_currency',
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
        'commission',
        'service_charge',
        'discount_amount',
        'vat',
        'final_sale_price',
        'pricing_exchange_rate',
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

    const isProtectedSavedServiceFinancialField = (target) => {
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) {
            return false;
        }

        if (currentPersistedServiceId() <= 0) {
            return false;
        }

        return protectedSavedServiceFinancialFields.has(String(target.name || '').trim());
    };

    station.addEventListener('change', (event) => {
        if (!fieldAutosaveEnabled || suppressAutosave || !isServiceAutosaveTarget(event.target)) {
            return;
        }

        if (isProtectedSavedServiceFinancialField(event.target)) {
            window.workspaceDebugEnterFlow('service-autosave-change-skip-protected', {
                target: String(event.target.name || ''),
                currentPersistedServiceId: currentPersistedServiceId(),
                value: String(event.target.value || ''),
            });
            return;
        }

        window.workspaceDebugEnterFlow('service-autosave-change-schedule', {
            target: String(event.target.name || ''),
            currentPersistedServiceId: currentPersistedServiceId(),
            value: String(event.target.value || ''),
        });
        scheduleServiceAutosave();
    }, true);

    station.addEventListener('focusout', (event) => {
        if (!fieldAutosaveEnabled || suppressAutosave || !isServiceAutosaveTarget(event.target)) {
            return;
        }

        if (isProtectedSavedServiceFinancialField(event.target)) {
            window.workspaceDebugEnterFlow('service-autosave-focusout-skip-protected', {
                target: String(event.target.name || ''),
                currentPersistedServiceId: currentPersistedServiceId(),
                value: String(event.target.value || ''),
            });
            return;
        }

        window.workspaceDebugEnterFlow('service-autosave-focusout-schedule', {
            target: String(event.target.name || ''),
            currentPersistedServiceId: currentPersistedServiceId(),
            value: String(event.target.value || ''),
        });
        scheduleServiceAutosave();
    }, true);
    }

    if (invoiceForm) {
        const invoiceAutosaveFields = new Set(['branch_id', 'booking_date', 'booking_status', 'party_label', 'remarks']);
        invoiceForm.addEventListener('change', (event) => {
            if (!fieldAutosaveEnabled || suppressAutosave || !(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement)) {
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
            if (!fieldAutosaveEnabled || suppressAutosave || !(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement)) {
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
    const formatCustomerColorTag = (colorTag) => {
        return colorTag
            ? String(colorTag).replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase())
            : '-';
    };
    const updateCustomerSummary = (customer = null) => {
        const previousBalanceTotals = customer?.previous_balance_totals && typeof customer.previous_balance_totals === 'object'
            ? customer.previous_balance_totals
            : {};
        const fullOutstandingTotals = customer?.full_outstanding_totals && typeof customer.full_outstanding_totals === 'object'
            ? customer.full_outstanding_totals
            : previousBalanceTotals;
        fillValue(customerSummaryClient, customer?.id ? String(customer.id) : '-');
        fillValue(customerSummaryMobile, customer?.mobile || '-');
        fillValue(customerSummaryFamily, customer?.family_id || '-');
        fillValue(customerSummaryColor, formatCustomerColorTag(customer?.color_tag || ''));
        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = JSON.stringify(previousBalanceTotals);
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = JSON.stringify(fullOutstandingTotals);
            paymentPreviousBalanceInput.dataset.paymentPreviousBalance = '0';
            const invoiceSnapshot = currentInvoiceSnapshot();
            const outstandingRefresh = syncOpenBalanceDisplay(
                invoiceSnapshot.invoiceCurrency,
                invoiceSnapshot.invoiceBalance
            );
            sendWorkspaceClientError({
                type: 'customer_outstanding_refresh',
                message: 'Customer outstanding balance refreshed after customer selection.',
                customerId: Number.parseInt(String(customer?.id || 0), 10) || 0,
                customerName: String(customer?.full_name || ''),
                previousBalanceTotals,
                fullOutstandingTotals,
                invoiceCurrency: invoiceSnapshot.invoiceCurrency,
                invoiceBalance: invoiceSnapshot.invoiceBalance,
                visibleOutstandingMap: outstandingRefresh.openBalanceMap,
            });
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
            if (String(serviceLine.status || serviceLine.displayStatus || '').trim().toLowerCase() === 'cancelled') {
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

    const currentServiceDraftRequiresPersistBeforePayment = () => {
        if (currentBookingId() <= 0) {
            return false;
        }

        if (activeServiceId() > 0 || currentPersistedServiceId() > 0) {
            return false;
        }

        return serviceAutosaveReady() && serviceHasMeaningfulDraftData();
    };

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
            preserveLiveInvoicePreview = false,
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
        const customerCreditMap = totals.customer_credit_map && typeof totals.customer_credit_map === 'object'
            ? totals.customer_credit_map
            : null;
        const otherCurrencyPreviousBalanceMap = totals.other_currency_previous_balance_map && typeof totals.other_currency_previous_balance_map === 'object'
            ? totals.other_currency_previous_balance_map
            : null;
        const airlinePayable = toNumber(totals.airline_payable || 0);
        const otherPayable = toNumber(totals.other_payable || 0);

        if (syncCommercialEditor) {
            [airlinePayableField, airlinePayableFinancialField, airlinePayableSummary, ticketValueField].forEach((node) => {
                if (!node) {
                    return;
                }

                if ('value' in node) {
                    node.value = formatMoney(airlinePayable);
                } else {
                    node.textContent = formatMoney(airlinePayable);
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
                otherPayableSummary.textContent = formatMoney(otherPayable);
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
        if (paymentCurrentInvoiceInput && !preserveLiveInvoicePreview) {
            paymentCurrentInvoiceInput.dataset.paymentCurrency = currentInvoiceCurrency;
            paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = String(receivable);
            paymentCurrentInvoiceInput.value = formatNumberInputValue(receivable);
        }
        if (paymentAlreadyReceivedInput && !preserveLiveInvoicePreview) {
            paymentAlreadyReceivedInput.dataset.paymentPersistedReceived = String(totalReceived);
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(
                currentInvoiceCurrency,
                totalReceived
            );
        }
        if (paymentCurrentBalanceInput && !preserveLiveInvoicePreview) {
            paymentCurrentBalanceInput.dataset.paymentPersistedInvoiceBalance = String(currentBalance);
            paymentCurrentBalanceInput.dataset.paymentSavedInvoiceBalance = String(currentBalance);
        }
        if (paymentTotalOutstandingInput && !preserveLiveInvoicePreview) {
            paymentTotalOutstandingInput.dataset.paymentTotalOutstanding = String(currentBalance);
            paymentTotalOutstandingInput.dataset.paymentTotalDueNow = String(currentBalance);
        }
        if (paymentCurrentBalancePkrInput && !preserveLiveInvoicePreview) {
            paymentCurrentBalancePkrInput.dataset.paymentPkrRate = String(currentBalancePkrRate);
            if (currentInvoiceCurrency !== 'PKR' && currentBalancePkrRate > 0.005) {
                paymentCurrentBalancePkrInput.value = formatCurrencyAmount('PKR', currentBalancePkrEquivalent);
            } else {
                paymentCurrentBalancePkrInput.value = 'PKR 0';
            }
        }
        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = JSON.stringify(previousBalanceMap || {});
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = JSON.stringify(fullCustomerOutstandingMap || previousBalanceMap || {});
        }

        syncCustomerCreditDisplay(currentInvoiceCurrency, customerCreditMap || undefined);

        if (serviceFields.currency && currentBookingId() <= 0 && Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10) <= 0) {
            serviceFields.currency.value = currentInvoiceCurrency;
            syncServiceCurrencyMirror();
        }
        syncDefaultPaymentCurrency(currentInvoiceCurrency);

        syncPaymentCurrencyLabels(paymentCurrencySelect?.value || currentInvoiceCurrency);
        if (!preserveLiveInvoicePreview) {
            syncCurrentBalancePkrEquivalent(currentBalance, currentInvoiceCurrency);
        }

        if (preserveLiveInvoicePreview) {
            refreshPaymentPreview();
            return;
        }

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
        syncDirectSupplierObligationOptions(payload);
        updateTotalsFromAutosave(payload.totals || {}, options);
        renderServiceRows(activeServiceId());
        bindServiceRowClicks();
    };

    const persistInvoiceAutosave = (options = {}) => {
        if (!(invoiceForm instanceof HTMLFormElement) || autosaveInvoiceUrl === '') {
            return Promise.resolve(null);
        }

        if (browserIsOffline()) {
            setAutosaveStatus('dirty', 'Offline draft pending');
            return Promise.resolve(null);
        }

        const { force = false, allowCreate = false } = options;
        if (!autosaveBookingReady()) {
            return Promise.resolve(null);
        }

        if (currentBookingId() <= 0 && !allowCreate) {
            window.workspaceDebugEnterFlow('invoice-autosave-skip-draft-create-disabled', {
                force,
                allowCreate,
                selectedCustomerId: Number.parseInt(String(bookingSelectedTravelerIdField?.value || '0'), 10) || 0,
                leadName: String(bookingLeadField?.value || '').trim(),
            });
            return Promise.resolve(null);
        }

        const payloadKey = serializeForm(invoiceForm);
        if (!force && payloadKey === lastInvoiceAutosaveKey && currentBookingId() > 0) {
            return Promise.resolve(null);
        }

        if (invoiceAutosaveInFlight) {
            pendingInvoiceAutosave = true;
            pendingInvoiceAutosaveForce = pendingInvoiceAutosaveForce || force;
            pendingInvoiceAutosaveAllowCreate = pendingInvoiceAutosaveAllowCreate || allowCreate;
            return invoiceAutosavePromise;
        }

        invoiceAutosaveInFlight = true;
        invoiceAutosavePromise = (async () => {
            setAutosaveStatus('saving', 'Saving...');
            if (currentBookingId() <= 0) {
                setInvoiceNumber('Draft');
            }

            const payload = await postAutosave(
                autosaveInvoiceUrl,
                invoiceForm,
                allowCreate ? { commit_intent: 'payment_save' } : {}
            );
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
                const queuedAllowCreate = pendingInvoiceAutosaveAllowCreate;
                pendingInvoiceAutosave = false;
                pendingInvoiceAutosaveForce = false;
                pendingInvoiceAutosaveAllowCreate = false;
                window.setTimeout(() => {
                    void persistInvoiceAutosave({ force: queuedForce, allowCreate: queuedAllowCreate });
                }, 0);
                return;
            }

            pendingInvoiceAutosaveForce = false;
            pendingInvoiceAutosaveAllowCreate = false;
        });

        return invoiceAutosavePromise;
    };

    const persistServiceAutosave = (options = {}) => {
        if (!(serviceForm instanceof HTMLFormElement) || autosaveServiceUrl === '') {
            return Promise.resolve(null);
        }
        const { allowCreate = false } = options;

        window.workspaceDebugEnterFlow('persist-service-start', {
            suppressAutosave,
            currentBookingId: currentBookingId(),
            currentPersistedServiceId: currentPersistedServiceId(),
            serviceAutosaveReady: serviceAutosaveReady(),
            allowCreate,
        });

        if (browserIsOffline()) {
            setAutosaveStatus('dirty', 'Offline draft pending');
            return Promise.resolve(null);
        }

        if (!serviceAutosaveReady()) {
            window.workspaceDebugEnterFlow('persist-service-skip-not-ready', {
                currentBookingId: currentBookingId(),
                currentPersistedServiceId: currentPersistedServiceId(),
                serviceType: String(serviceTypeField?.value || ''),
                salePrice: toNumber(serviceMetricInputs.sale?.value || 0),
                serviceCharge: toNumber(serviceMetricInputs.serviceCharge?.value || 0),
            });
            return Promise.resolve(null);
        }

        if (!hasRequiredLossReason({ focus: true, announce: true })) {
            window.workspaceDebugEnterFlow('persist-service-skip-loss-reason', {
                currentBookingId: currentBookingId(),
                currentPersistedServiceId: currentPersistedServiceId(),
            });
            return Promise.resolve(null);
        }

        if (serviceAutosaveInFlight) {
            pendingServiceAutosave = true;
            pendingServiceAutosaveAllowCreate = pendingServiceAutosaveAllowCreate || allowCreate;
            return serviceAutosavePromise;
        }

        serviceAutosaveInFlight = true;
        serviceAutosavePromise = (async () => {
            syncAutoBookingFields();
            syncServiceTravelerIdFromName();
            prepareSupplierAdvanceFxUse();

            if (currentBookingId() <= 0) {
                if (!allowCreate) {
                    window.workspaceDebugEnterFlow('persist-service-skip-draft-create-disabled', {
                        currentBookingId: currentBookingId(),
                        currentPersistedServiceId: currentPersistedServiceId(),
                    });
                    return null;
                }

                const invoicePayload = await persistInvoiceAutosave({ force: true, allowCreate: true });
                if (!invoicePayload || Number.parseInt(String(invoicePayload.booking_id || '0'), 10) <= 0) {
                    return null;
                }
            }

            const payloadKey = serializeForm(serviceForm);
            if (payloadKey === lastServiceAutosaveKey && Number.parseInt(String(serviceFields.serviceId?.value || '0'), 10) > 0) {
                return null;
            }

            setAutosaveStatus('saving', 'Saving...');
            const payload = await postAutosave(autosaveServiceUrl, serviceForm, { commit_intent: 'payment_save' });
            const currentPayloadKey = serializeForm(serviceForm);
            const responseIsStale = currentPayloadKey !== payloadKey;
            lastInvoiceAutosaveKey = serializeForm(invoiceForm);
            lastServiceAutosaveKey = serializeForm(serviceForm);
            applySavedServiceUiState(payload, {
                preserveLiveEditorState: true,
            });
            if (responseIsStale) {
                refreshProfit('autosave-stale-response');
            }
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
                const queuedAllowCreate = pendingServiceAutosaveAllowCreate;
                pendingServiceAutosave = false;
                pendingServiceAutosaveAllowCreate = false;
                window.setTimeout(() => {
                    void persistServiceAutosave({ allowCreate: queuedAllowCreate });
                }, 0);
                return;
            }

            pendingServiceAutosaveAllowCreate = false;
        });

        return serviceAutosavePromise;
    };

    const scheduleInvoiceAutosave = () => {
        window.clearTimeout(invoiceAutosaveTimerId);
        setAutosaveStatus('dirty', 'Unsaved changes');
        if (browserIsOffline()) {
            setAutosaveStatus('dirty', 'Offline draft pending');
            return;
        }
        invoiceAutosaveTimerId = window.setTimeout(() => {
            void persistInvoiceAutosave();
        }, 700);
    };

    const scheduleServiceAutosave = () => {
        window.clearTimeout(serviceAutosaveTimerId);
        setAutosaveStatus('dirty', 'Unsaved changes');
        if (browserIsOffline()) {
            setAutosaveStatus('dirty', 'Offline draft pending');
            return;
        }
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

    const forceElementsEnabled = (elements) => {
        elements.forEach((element) => {
            if (element instanceof HTMLAnchorElement) {
                element.classList.remove('is-disabled');
                element.setAttribute('aria-disabled', 'false');
                element.removeAttribute('tabindex');
                return;
            }

            if (element instanceof HTMLButtonElement
                || element instanceof HTMLInputElement
                || element instanceof HTMLSelectElement
                || element instanceof HTMLTextAreaElement) {
                element.dataset.workflowOriginalDisabled = '0';
                element.disabled = false;
            }
        });
    };
    updateWorkflowState = () => {
        const customerReady = hasSelectedCustomer();
        const hasPersistedService = currentPersistedServiceId() > 0
            || serviceLines.some((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) > 0);
        const serviceReady = customerReady || hasPersistedService;
        const postServiceReady = autosavedHasSavedService || hasPersistedService || hasSavedServiceRows();
        const addServiceReady = postServiceReady || serviceAutosaveReady();

        setGateState(workflowGates.customerStage, customerReady);
        setGateState(workflowGates.serviceEntry, serviceReady);
        setGateState(workflowGates.postService, postServiceReady);
        setGateState(workflowGates.paymentStage, true);
        setElementsEnabled(addServiceButtons, addServiceReady);
        if (addServiceReady) {
            forceElementsEnabled(addServiceButtons);
        }
        setElementsEnabled(paymentHistoryButtons, true);
        setElementsEnabled(paymentSubmitButtons, true);
    };

    if (serviceForm instanceof HTMLFormElement) {
        serviceForm.addEventListener('input', () => updateWorkflowState());
        serviceForm.addEventListener('change', () => updateWorkflowState());
    }

    if (invoiceForm instanceof HTMLFormElement) {
        invoiceForm.addEventListener('input', () => updateWorkflowState());
        invoiceForm.addEventListener('change', () => updateWorkflowState());
    }

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
    let customerAdvanceNewCustomerMode = false;
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
        syncNewCustomerSaveMode();
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

    const searchableCustomerDirectory = () => {
        if (browserIsOffline()) {
            return customerDirectory.slice();
        }

        return customerDirectory.filter((customer) => !isSnapshotRecord(customer));
    };

    const resetCustomerFinancialState = () => {
        replaceSettlementData([], {});
        replacePaymentHistoryData([], []);
        renderPaymentHistoryModal();

        if (paymentPreviousBalanceInput) {
            paymentPreviousBalanceInput.dataset.paymentPreviousBalanceMap = '{}';
            paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = '{}';
            paymentPreviousBalanceInput.dataset.paymentPreviousBalance = '0';
        }
        if (paymentCurrentInvoiceInput) {
            const currency = String(paymentCurrentInvoiceInput.dataset.paymentCurrency || 'PKR');
            paymentCurrentInvoiceInput.dataset.paymentCurrentInvoice = '0';
            paymentCurrentInvoiceInput.value = formatNumberInputValue(0);
        }
        if (paymentAlreadyReceivedInput) {
            const currency = String(paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR');
            paymentAlreadyReceivedInput.dataset.paymentPersistedReceived = '0';
            paymentAlreadyReceivedInput.value = formatCurrencyAmount(currency, 0);
        }
        if (paymentCurrentBalanceInput) {
            paymentCurrentBalanceInput.dataset.paymentPersistedInvoiceBalance = '0';
            paymentCurrentBalanceInput.dataset.paymentSavedInvoiceBalance = '0';
            paymentCurrentBalanceInput.value = formatCurrencyAmount(
                String(paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR'),
                0
            );
        }
        if (paymentCustomerCreditInput) {
            paymentCustomerCreditInput.dataset.paymentCustomerCredit = '0';
            paymentCustomerCreditInput.dataset.paymentCustomerCreditMap = '{}';
            paymentCustomerCreditInput.value = formatCurrencyAmount(
                String(paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR'),
                0
            );
        }
        if (paymentCustomerCreditRow) {
            paymentCustomerCreditRow.hidden = true;
        }
        if (paymentTotalOutstandingInput) {
            paymentTotalOutstandingInput.dataset.paymentTotalOutstanding = '0';
            paymentTotalOutstandingInput.dataset.paymentTotalDueNow = '0';
            paymentTotalOutstandingInput.value = formatCurrencyAmount(
                String(paymentCurrencySelect?.value || paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR'),
                0
            );
        }
    };

    const applyCustomerToForms = (customer) => {
        if (!customer) {
            return;
        }

        suppressCustomerAutocompleteInput = true;
        resetCustomerFinancialState();
        station.dataset.hasSelectedCustomer = '1';
        fillValue(bookingSelectedTravelerIdField, customer.id || '');
        fillValue(resolveBookingLeadField(), customer.full_name || '');
        fillValue(bookingMobileField, customer.mobile || '');
        fillValue(bookingPassportField, customer.passport_number || '');
        updateCustomerSummary(customer);
        if (bookingBranchField && customer.branch_id) {
            bookingBranchField.value = String(customer.branch_id);
            syncWorkspaceBranchContext({ forceCurrency: true });
        }
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
            syncWorkspaceBranchContext({ forceCurrency: true });
        }

        if (activeTravelerReference) {
            activeTravelerReference.textContent = customer.id ? `TRV-${String(customer.id).padStart(3, '0')}` : 'TRV-DRAFT';
        }

        useCustomerAsDraftServicePassenger(customer);
        refreshPaymentPreview();
        updateWorkflowState();
        hideCustomerAutocomplete();
        closeCustomerPicker();
        station.dispatchEvent(new CustomEvent('workspace:customer-selected', {
            bubbles: true,
            detail: customer || {},
        }));
        if (fieldAutosaveEnabled && autosaveInvoiceUrl !== '') {
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

    const startFreshWorkspaceForCustomer = (customer) => {
        if (!customer) {
            return;
        }

        const newBookingUrl = station.dataset.newBookingUrl || '/workspace?new=1';
        try {
            const normalizedUrl = new URL(newBookingUrl, window.location.origin);
            normalizedUrl.searchParams.delete('focus');
            window.sessionStorage.setItem(pendingFreshCustomerKey, JSON.stringify(customer));
            window.location.href = `${normalizedUrl.pathname}${normalizedUrl.search}`;
        } catch (error) {
            applyCustomerToForms(customer);
        }
    };

    window.workspaceLoadCustomerIntoFreshInvoice = startFreshWorkspaceForCustomer;

    const workspaceNeedsFreshInvoiceBeforeCustomerAction = () => {
        const bookingId = currentBookingId();
        const selectedCustomerId = Number.parseInt(String(bookingSelectedTravelerIdField?.value || '0'), 10) || 0;
        const leadName = String(resolveBookingLeadField()?.value || '').trim();
        const mobile = String(bookingMobileField?.value || '').trim();
        const passport = String(bookingPassportField?.value || '').trim();

        return bookingId > 0
            || selectedCustomerId > 0
            || leadName !== ''
            || mobile !== ''
            || passport !== ''
            || station.dataset.hasSelectedCustomer === '1';
    };

    const startFreshWorkspaceForCustomerAction = (actionName) => {
        const action = actionName === 'new-customer' ? 'new-customer' : 'find-customer';
        if (!workspaceNeedsFreshInvoiceBeforeCustomerAction()) {
            return false;
        }

        const newBookingUrl = station.dataset.newBookingUrl || '/workspace?new=1';
        try {
            const normalizedUrl = new URL(newBookingUrl, window.location.origin);
            normalizedUrl.searchParams.delete('focus');
            window.sessionStorage.setItem(pendingFreshWorkspaceActionKey, action);
            window.location.href = `${normalizedUrl.pathname}${normalizedUrl.search}`;
            return true;
        } catch (error) {
            return false;
        }
    };

    window.workspaceStartFreshInvoiceForCustomerAction = startFreshWorkspaceForCustomerAction;

    const restorePendingFreshWorkspaceCustomer = () => {
        let raw = '';
        try {
            raw = window.sessionStorage.getItem(pendingFreshCustomerKey) || '';
        } catch (error) {
            return;
        }

        if (raw.trim() === '') {
            return;
        }

        try {
            const customer = JSON.parse(raw);
            window.sessionStorage.removeItem(pendingFreshCustomerKey);
            if (!customer || Number.parseInt(String(customer.id || 0), 10) <= 0) {
                return;
            }
            applyCustomerToForms(customer);
            window.setTimeout(() => {
                focusTarget('.legacy-invoice-header__remarks input[name="remarks"]');
            }, 120);
        } catch (error) {
            window.sessionStorage.removeItem(pendingFreshCustomerKey);
        }
    };

    const restorePendingFreshWorkspaceAction = () => {
        let action = '';
        try {
            action = window.sessionStorage.getItem(pendingFreshWorkspaceActionKey) || '';
            window.sessionStorage.removeItem(pendingFreshWorkspaceActionKey);
        } catch (error) {
            return;
        }

        if (action === 'new-customer') {
            openNewCustomerModal();
            return;
        }

        if (action === 'find-customer') {
            openCustomerPicker();
        }
    };

    const renderInlineCustomerLabel = (customer) => {
        const idLabel = customer.id ? `#${customer.id}` : '-';
        const phoneLabel = customer.mobile || '-';
        const passportLabel = customer.passport_number || '-';
        const familyLabel = customer.family_id || idLabel;
        const addressLabel = customer.village || customer.district || customer.current_residence || customer.address || customer.notes || '-';
        const colorLabel = customer.color_tag ? String(customer.color_tag).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()) : '-';

        return `
            <span class="customer-inline-picker__cell customer-inline-picker__cell--name">${customer.full_name || ''} ${renderSnapshotBadge(customer)}</span>
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
        const availableCustomers = searchableCustomerDirectory();
        filteredAutocompleteCustomers = query === ''
            ? availableCustomers.slice(0, 12)
            : availableCustomers.filter((customer) => customerLabel(customer).includes(query)).slice(0, 12);
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
            const customerEditDisabled = browserIsOffline() ? ' disabled title="Reconnect to edit this customer."' : '';
            row.innerHTML = `
                <td>${customer.full_name || ''} ${renderSnapshotBadge(customer)}</td>
                <td>${customer.family_id || ''}</td>
                <td>${customer.passport_number || ''}</td>
                <td>${customer.mobile || ''}</td>
                <td>${customer.village || customer.district || customer.current_residence || customer.address || ''}</td>
                <td>${customer.color_tag ? String(customer.color_tag).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase()) : ''}</td>
                <td><button class="btn btn-sm" type="button" data-customer-edit="${customer.id || ''}"${customerEditDisabled}>Edit</button></td>
            `;
            row.addEventListener('click', () => {
                if (browserIsOffline()) {
                    showFeedback('Offline mode allows customer search only. Reconnect before loading a customer into a booking.');
                    return;
                }
                startFreshWorkspaceForCustomer(customer);
            });
            row.addEventListener('dblclick', () => {
                if (browserIsOffline()) {
                    showFeedback('Offline mode allows customer search only. Reconnect before loading a customer into a booking.');
                    return;
                }
                startFreshWorkspaceForCustomer(customer);
            });
            customerPickerResults.appendChild(row);

            const editButton = row.querySelector('[data-customer-edit]');
            if (editButton) {
                editButton.addEventListener('click', (event) => {
                    event.stopPropagation();
                    if (browserIsOffline()) {
                        showFeedback('Reconnect before editing an existing customer.');
                        return;
                    }
                    openNewCustomerModal(customer);
                });
            }
        });
    };

    const filterCustomers = () => {
        const query = (customerPickerInput?.value || '').trim().toLowerCase();
        const availableCustomers = searchableCustomerDirectory();
        filteredCustomers = query === ''
            ? availableCustomers.slice()
            : availableCustomers.filter((customer) => customerLabel(customer).includes(query));
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
        void refreshWorkspaceConnectivity();
        syncNewCustomerSaveMode();
        window.setTimeout(() => focusTarget('[data-new-customer-focus]'), 60);
    }

    function closeNewCustomerModal() {
        if (!newCustomerModal) {
            return;
        }

        newCustomerModal.hidden = true;
        newCustomerModal.setAttribute('aria-hidden', 'true');
        resetNewCustomerForm();
        if (customerAdvanceNewCustomerMode && customerAdvanceModal instanceof HTMLElement) {
            customerAdvanceNewCustomerMode = false;
            customerAdvanceModal.hidden = false;
            customerAdvanceModal.setAttribute('aria-hidden', 'false');
            window.setTimeout(() => customerAdvanceSearchInput?.focus(), 40);
        }
    }

    const consumeRequestedCustomerEdit = () => {
        if (requestedCustomerEditId <= 0) {
            return;
        }

        const customer = customerDirectory.find((entry) => Number.parseInt(String(entry?.id || '0'), 10) === requestedCustomerEditId);
        if (!customer) {
            return;
        }

        openNewCustomerModal(customer);

        try {
            const cleanedUrl = new URL(window.location.href);
            cleanedUrl.searchParams.delete('customer_edit');
            cleanedUrl.searchParams.delete('customer_id');
            cleanedUrl.searchParams.delete('traveler_id');
            window.history.replaceState({}, '', `${cleanedUrl.pathname}${cleanedUrl.search}${cleanedUrl.hash}`);
        } catch (error) {
            // Ignore URL cleanup errors.
        }
    };

    newCustomerForm?.addEventListener('submit', async (event) => {
        if (!(newCustomerForm instanceof HTMLFormElement)) {
            return;
        }

        event.preventDefault();
        if (browserIsOffline()) {
            syncNewCustomerSaveMode();
            showFeedback('You are offline. Use Save Customer Offline to queue this customer.');
            return;
        }

        if (newCustomerSubmit) {
            newCustomerSubmit.disabled = true;
        }

        try {
            const response = await fetch(newCustomerForm.action, {
                method: 'POST',
                body: new FormData(newCustomerForm),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'Customer could not be saved.');
            }

            const customer = payload.traveler || null;
            if (customer) {
                const existingIndex = customerDirectory.findIndex((entry) => Number(entry.id || 0) === Number(customer.id || 0));
                if (existingIndex >= 0) {
                    customerDirectory[existingIndex] = customer;
                } else {
                    customerDirectory.push(customer);
                }
                filteredCustomers = searchableCustomerDirectory();
                filteredAutocompleteCustomers = searchableCustomerDirectory().slice(0, 12);
                if (customerAdvanceNewCustomerMode) {
                    customerAdvanceNewCustomerMode = false;
                    closeNewCustomerModal();
                    if (customerAdvanceModal instanceof HTMLElement) {
                        customerAdvanceModal.hidden = false;
                        customerAdvanceModal.setAttribute('aria-hidden', 'false');
                    }
                    selectCustomerForAdvance(customer);
                    setCustomerAdvanceFeedback(payload.message || 'Customer selected for advance.', true);
                    window.setTimeout(() => {
                        customerAdvanceAmount?.focus();
                        customerAdvanceAmount?.select();
                    }, 60);
                    return;
                }
                startFreshWorkspaceForCustomer(customer);
            }

            closeNewCustomerModal();
            showFeedback(payload.message || 'Customer saved.');
        } catch (error) {
            setWorkspaceConnectivityState('offline');
            showFeedback(error.message || 'Customer could not be saved.');
        } finally {
            if (newCustomerSubmit) {
                newCustomerSubmit.disabled = false;
            }
        }
    });

    const collectOfflineTravelerDraftPayload = () => {
        if (!(newCustomerForm instanceof HTMLFormElement)) {
            throw new Error('Customer form is unavailable.');
        }

        if (Number.parseInt(formValue(newCustomerForm, 'traveler_id') || '0', 10) > 0) {
            throw new Error('Offline v1 supports new customer drafts only. Open a fresh customer form to save offline.');
        }

        const payload = {
            traveler_branch_id: formValue(newCustomerForm, 'traveler_branch_id'),
            first_name: formValue(newCustomerForm, 'first_name'),
            last_name: formValue(newCustomerForm, 'last_name'),
            family_id: formValue(newCustomerForm, 'family_id'),
            gender: formValue(newCustomerForm, 'gender') || 'unspecified',
            date_of_birth: formValue(newCustomerForm, 'date_of_birth'),
            passport_number: formValue(newCustomerForm, 'passport_number'),
            passport_expiry: formValue(newCustomerForm, 'passport_expiry'),
            mobile: formValue(newCustomerForm, 'mobile'),
            occupation: formValue(newCustomerForm, 'occupation'),
            current_residence: formValue(newCustomerForm, 'current_residence'),
            permanent_residence: formValue(newCustomerForm, 'permanent_residence'),
            village: formValue(newCustomerForm, 'village'),
            district: formValue(newCustomerForm, 'district'),
            color_tag: formValue(newCustomerForm, 'color_tag') || 'none',
            nationality: formValue(newCustomerForm, 'nationality'),
            address: formValue(newCustomerForm, 'address'),
            notes: formValue(newCustomerForm, 'notes'),
        };

        if (payload.traveler_branch_id === '' || payload.first_name === '') {
            throw new Error('Branch and first name are required before saving an offline customer draft.');
        }

        return payload;
    };

    const saveTravelerDraftOffline = () => {
        const payload = collectOfflineTravelerDraftPayload();
        const label = [payload.first_name, payload.last_name].filter(Boolean).join(' ');
        queueOfflineDraft('traveler.create', payload, label || 'Customer draft');
        showFeedback('Customer draft saved into the offline queue.');
    };

    const fetchOfflineSnapshot = async () => {
        if (offlineSnapshotUrl === '') {
            throw new Error('Offline snapshot route is unavailable.');
        }

        const response = await fetch(offlineSnapshotUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || 'Offline snapshot could not be downloaded.');
        }

        if (payload.csrf_token) {
            setOfflineToken(payload.csrf_token);
        }

        offlineSnapshotCache = payload.snapshot || null;
        offlineMeta.last_snapshot_at = payload.snapshot?.generated_at || new Date().toISOString();
        integrateOfflineSnapshot(offlineSnapshotCache);
        persistOfflineState();
        renderOfflineStatus();

        const fileStamp = String((payload.snapshot?.generated_at || new Date().toISOString())).replace(/[:.]/g, '-');
        downloadJsonFile(`travel-ops-offline-snapshot-${fileStamp}.json`, payload);
        showFeedback('Offline snapshot downloaded and cached on this device.');
    };

    const syncOfflineQueue = async () => {
        if (offlineSyncUrl === '') {
            throw new Error('Offline sync route is unavailable.');
        }

        if (!Array.isArray(offlineQueue) || offlineQueue.length === 0) {
            showFeedback('Offline queue is already empty.');
            return;
        }

        const token = getCsrfToken();
        if (token === '') {
            throw new Error('CSRF token is missing for offline sync.');
        }

        const response = await fetch(offlineSyncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                _token: token,
                source_device: offlineSourceDevice(),
                drafts: offlineQueue.map((draft) => ({
                    client_draft_id: draft.client_draft_id,
                    type: draft.type,
                    payload: draft.payload,
                })),
            }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || 'Offline drafts could not be synced.');
        }

        const results = Array.isArray(payload.results) ? payload.results : [];
        const processedDraftIds = new Set(results.map((row) => String(row?.client_draft_id || '')).filter(Boolean));
        const syncedCount = results.filter((row) => String(row?.status || '') === 'synced').length;
        const rejectedCount = results.filter((row) => String(row?.status || '') === 'rejected').length;

        offlineQueue = offlineQueue.filter((draft) => !processedDraftIds.has(String(draft.client_draft_id || '')));
        offlineMeta.last_sync_at = new Date().toISOString();
        persistOfflineState();
        renderOfflineStatus();

        const messageParts = [];
        if (syncedCount > 0) {
            messageParts.push(`${syncedCount} draft${syncedCount === 1 ? '' : 's'} synced`);
        }
        if (rejectedCount > 0) {
            messageParts.push(`${rejectedCount} draft${rejectedCount === 1 ? '' : 's'} rejected`);
        }
        if (messageParts.length === 0) {
            messageParts.push('Offline sync completed');
        }

        showFeedback(messageParts.join(' • ') + '.');
    };

    offlineSnapshotButton?.addEventListener('click', async () => {
        offlineSnapshotButton.disabled = true;
        try {
            await fetchOfflineSnapshot();
        } catch (error) {
            showFeedback(error.message || 'Offline snapshot could not be downloaded.');
        } finally {
            offlineSnapshotButton.disabled = false;
        }
    });

    offlineSaveCustomerButtons.forEach((button) => button.addEventListener('click', () => {
        try {
            saveTravelerDraftOffline();
        } catch (error) {
            showFeedback(error.message || 'Offline customer draft could not be saved.');
        }
    }));

    offlineStatus?.addEventListener('click', () => {
        toggleOfflineQueuePreview();
    });

    offlineSyncButton?.addEventListener('click', async () => {
        offlineSyncButton.disabled = true;
        try {
            await syncOfflineQueue();
        } catch (error) {
            showFeedback(error.message || 'Offline drafts could not be synced.');
        } finally {
            offlineSyncButton.disabled = false;
        }
    });

    window.addEventListener('online', () => {
        showFeedback('Connection restored. Sync the offline queue when you are ready.');
        void refreshWorkspaceConnectivity();
        syncNewCustomerSaveMode();
        syncOfflineEditLockMode();
        renderOfflineStatus();
    });

    window.addEventListener('offline', () => {
        showFeedback('Connection lost. New customers can be saved offline.');
        setWorkspaceConnectivityState('offline');
        syncNewCustomerSaveMode();
        syncOfflineEditLockMode();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void refreshWorkspaceConnectivity();
        }
    });

    window.addEventListener('focus', () => {
        void refreshWorkspaceConnectivity();
    });

    window.setInterval(() => {
        if (document.visibilityState === 'visible') {
            void refreshWorkspaceConnectivity();
        }
    }, 8000);

    if (offlineSnapshotCache) {
        integrateOfflineSnapshot(offlineSnapshotCache);
    }

    void refreshWorkspaceConnectivity();
    syncNewCustomerSaveMode();
    syncOfflineEditLockMode();
    renderOfflineStatus();

    function openServiceEditBookingModal(options = {}) {
        if (!(serviceEditBookingModal instanceof HTMLElement)) {
            return;
        }

        try {
            window.sessionStorage.setItem(pendingServiceEditBookingModalKey, '1');
            window.sessionStorage.removeItem(pendingPenaltyRefundModalKey);
        } catch (error) {
            // ignore storage failures
        }

        serviceEditBookingModal.hidden = false;
        serviceEditBookingModal.setAttribute('aria-hidden', 'false');

        window.setTimeout(() => {
            const preferredField = serviceEditBookingModal.querySelector(
                '[data-service-event-bar="cancel"]:not([hidden]) input[name="cancel_reason"], '
                + '[data-service-event-bar="settlement"]:not([hidden]) input[name="customer_penalty_amount"], '
                + '[data-service-event-bar="refund"]:not([hidden]) input[name="customer_refund_amount"], '
                + '[data-service-event-bar="reissue"]:not([hidden]) input[name="new_ticket_number"], '
                + '[data-service-event-bar="financial-correction"]:not([hidden]) input[name="corrected_cost_basis"]'
            );
            if (preferredField instanceof HTMLElement) {
                preferredField.focus();
                if (preferredField instanceof HTMLInputElement) {
                    preferredField.select();
                }
            }
        }, 40);

        if (options.silent !== true) {
            showFeedback('Booking edit opened.');
        }
    }
    window.workspaceOpenServiceEditBookingModal = openServiceEditBookingModal;

    function closeServiceEditBookingModal() {
        if (!(serviceEditBookingModal instanceof HTMLElement)) {
            return;
        }

        try {
            window.sessionStorage.removeItem(pendingServiceEditBookingModalKey);
        } catch (error) {
            // ignore storage failures
        }

        serviceEditBookingModal.hidden = true;
        serviceEditBookingModal.setAttribute('aria-hidden', 'true');
    }
    window.workspaceCloseServiceEditBookingModal = closeServiceEditBookingModal;

    function openServicePenaltyRefundModal(options = {}) {
        if (!(servicePenaltyRefundModal instanceof HTMLElement)) {
            return;
        }

        try {
            window.sessionStorage.removeItem(pendingServiceEditBookingModalKey);
            window.sessionStorage.setItem(pendingPenaltyRefundModalKey, '1');
        } catch (error) {
            // ignore storage failures
        }

        servicePenaltyRefundModal.hidden = false;
        servicePenaltyRefundModal.setAttribute('aria-hidden', 'false');

        window.setTimeout(() => {
            const preferredField = servicePenaltyRefundModal.querySelector(
                '[data-service-correction-bar="settlement"]:not([hidden]) input[name="customer_penalty_amount"], '
                + '[data-service-correction-bar="refund"]:not([hidden]) input[name="customer_refund_amount"], '
                + '[data-service-correction-bar="settlement-reverse"]:not([hidden]) input[name="settlement_reverse_reason"], '
                + '[data-service-correction-bar="refund-reverse"]:not([hidden]) input[name="refund_reverse_reason"]'
            );
            if (preferredField instanceof HTMLElement) {
                preferredField.focus();
                if (preferredField instanceof HTMLInputElement) {
                    preferredField.select();
                }
            }
        }, 40);

        if (options.silent !== true) {
            showFeedback('Penalty / refund editor opened.');
        }
    }
    window.workspaceOpenServicePenaltyRefundModal = openServicePenaltyRefundModal;

    function closeServicePenaltyRefundModal() {
        if (!(servicePenaltyRefundModal instanceof HTMLElement)) {
            return;
        }

        try {
            window.sessionStorage.removeItem(pendingPenaltyRefundModalKey);
        } catch (error) {
            // ignore storage failures
        }

        servicePenaltyRefundModal.hidden = true;
        servicePenaltyRefundModal.setAttribute('aria-hidden', 'true');
    }
    window.workspaceCloseServicePenaltyRefundModal = closeServicePenaltyRefundModal;

    const restorePenaltyRefundModalState = () => {
        let shouldRestore = '';
        try {
            shouldRestore = window.sessionStorage.getItem(pendingPenaltyRefundModalKey) || '';
        } catch (error) {
            return;
        }

        if (shouldRestore !== '1') {
            return;
        }

        if (!(servicePenaltyRefundModal instanceof HTMLElement)) {
            return;
        }

        window.setTimeout(() => {
            openServicePenaltyRefundModal({ silent: true });
        }, 80);
    };

    const restoreServiceEditBookingModalState = () => {
        let shouldRestore = '';
        try {
            shouldRestore = window.sessionStorage.getItem(pendingServiceEditBookingModalKey) || '';
        } catch (error) {
            return;
        }

        if (shouldRestore !== '1') {
            return;
        }

        if (!(serviceEditBookingModal instanceof HTMLElement)) {
            return;
        }

        window.setTimeout(() => {
            openServiceEditBookingModal({ silent: true });
        }, 80);
    };

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

        if (!Array.isArray(supplierHistoryFinderState.results) || supplierHistoryFinderState.results.length === 0) {
            supplierHistoryResultsBody.innerHTML = normalizeCustomerDuesQuery(supplierHistoryFinderState.query) === ''
                ? '<tr><td colspan="13" class="empty-cell">No recent supplier payment history is available.</td></tr>'
                : '<tr><td colspan="13" class="empty-cell">No supplier payment history matched this search.</td></tr>';
            return;
        }

        supplierHistoryResultsBody.innerHTML = supplierHistoryFinderState.results.map((row) => `<tr>
            <td>${escapeHtml(String(row?.supplier_name || ''))}</td>
            <td>${Number.parseInt(String(row?.booking_id || 0), 10) > 0 && String(row?.booking_url || '').trim() !== ''
                ? `<a class="report-booking-link" href="${escapeHtml(String(row?.booking_url || '#'))}">${escapeHtml(String(row?.booking_reference || ''))}</a>`
                : escapeHtml(String(row?.booking_reference || ''))}</td>
            <td>${escapeHtml(String(row?.passenger_name || ''))}</td>
            <td>${escapeHtml(String(row?.route || ''))}</td>
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

        setSupplierHistoryFeedback('Loading supplier payment history…');

        try {
            const url = new URL(supplierHistoryFinderUrl, window.location.origin);
            if (normalizeCustomerDuesQuery(supplierHistoryFinderState.query) !== '') {
                url.searchParams.set('q', supplierHistoryFinderState.query.trim());
            }

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
    window.workspaceLoadSupplierHistoryFinder = (options = {}) => loadSupplierHistoryFinder(options);

    function renderCustomerDuesFinder() {
        if (customerDuesSelectedSummary instanceof HTMLElement) {
            const selectedCustomer = customerDuesFinderState.selectedCustomer;
            const balanceLabel = selectedCustomer && selectedCustomer.open_balance_totals
                ? formatCurrencyTotalsInline(selectedCustomer.open_balance_totals)
                : 'No outstanding balance.';
            customerDuesSelectedSummary.textContent = selectedCustomer
                ? `${selectedCustomer.full_name || 'Customer'} | Outstanding Balance: ${balanceLabel}`
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
    }

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
    window.workspaceLoadCustomerDuesFinder = (options = {}) => loadCustomerDuesFinder(options);

    function openCustomerDuesModal() {
        if (!customerDuesModal) {
            return;
        }

        clearSavedPaymentState({
            clearAmount: true,
            closeExchange: true,
        });
        logPaymentInputRuntime('open-customer-dues-modal');

        customerDuesModal.hidden = false;
        customerDuesModal.setAttribute('aria-hidden', 'false');
        const workspaceCustomerContext = syncCustomerDuesFinderWithWorkspaceCustomer();
        renderCustomerDuesFinder();
        void loadCustomerDuesFinder({
            query: workspaceCustomerContext.query,
            travelerId: workspaceCustomerContext.travelerId,
            currency: customerDuesCurrencyFilter?.value || customerDuesFinderState.currency,
        });
        window.setTimeout(() => {
            customerDuesSearchInput?.focus();
            customerDuesSearchInput?.select();
        }, 40);
    }
    window.workspaceOpenCustomerDuesModal = openCustomerDuesModal;

    function closeCustomerDuesModal() {
        if (!customerDuesModal) {
            return;
        }

        customerDuesModal.hidden = true;
        customerDuesModal.setAttribute('aria-hidden', 'true');
    }
    window.workspaceCloseCustomerDuesModal = closeCustomerDuesModal;

    const setCustomerAdvanceFeedback = (message, visible = true) => {
        if (!(customerAdvanceFeedback instanceof HTMLElement)) {
            return;
        }

        customerAdvanceFeedback.textContent = message || '';
        customerAdvanceFeedback.hidden = !visible || String(message || '').trim() === '';
    };

    const setCustomerAdvanceRefundFeedback = (message, visible = true) => {
        if (!(customerAdvanceRefundFeedback instanceof HTMLElement)) {
            return;
        }

        customerAdvanceRefundFeedback.textContent = message || '';
        customerAdvanceRefundFeedback.hidden = !visible || String(message || '').trim() === '';
    };

    const selectedCustomerForAdvance = () => {
        if (customerDuesFinderState.selectedCustomer && typeof customerDuesFinderState.selectedCustomer === 'object') {
            return customerDuesFinderState.selectedCustomer;
        }

        const selectedAdvanceTravelerId = Number.parseInt(String(customerAdvanceTravelerId?.value || 0), 10) || 0;
        if (selectedAdvanceTravelerId > 0) {
            const selectedAdvanceCustomer = findCustomerDirectoryEntryById(selectedAdvanceTravelerId);
            if (selectedAdvanceCustomer) {
                return selectedAdvanceCustomer;
            }
        }

        const workspaceTravelerId = Number.parseInt(String(bookingSelectedTravelerIdField?.value || 0), 10) || 0;
        if (workspaceTravelerId > 0) {
            const workspaceCustomer = findCustomerDirectoryEntryById(workspaceTravelerId);
            if (workspaceCustomer) {
                return workspaceCustomer;
            }
        }

        const selectedTravelerId = Number.parseInt(String(customerDuesFinderState.selectedTravelerId || 0), 10) || 0;
        if (selectedTravelerId <= 0 || !Array.isArray(customerDuesFinderState.customers)) {
            return null;
        }

        return customerDuesFinderState.customers.find((customer) => {
            return Number.parseInt(String(customer?.id || 0), 10) === selectedTravelerId;
        }) || null;
    };

    function customerAdvanceSearchMatches(customer, query) {
        const normalizedQuery = String(query || '').trim().toLowerCase();
        if (normalizedQuery === '') {
            return true;
        }

        return customerLabel(customer).includes(normalizedQuery);
    }

    function renderCustomerAdvanceSearchResults() {
        if (!(customerAdvanceResults instanceof HTMLElement)) {
            return;
        }

        const query = String(customerAdvanceSearchInput?.value || '').trim();
        const results = searchableCustomerDirectory()
            .filter((customer) => customerAdvanceSearchMatches(customer, query))
            .slice(0, 8);

        customerAdvanceResults.innerHTML = '';
        if (results.length === 0) {
            customerAdvanceResults.hidden = false;
            customerAdvanceResults.innerHTML = '<div class="customer-advance-results__empty">No customer found. Use New Customer if needed.</div>';
            return;
        }

        const table = document.createElement('table');
        table.className = 'legacy-table customer-advance-results__table';
        table.innerHTML = '<thead><tr><th>Customer</th><th>Mobile</th><th>Passport</th></tr></thead><tbody></tbody>';
        const body = table.querySelector('tbody');
        results.forEach((customer) => {
            const row = document.createElement('tr');
            row.dataset.customerAdvanceSelect = String(customer.id || '');
            row.tabIndex = 0;
            row.classList.add('customer-advance-results__row');
            row.innerHTML = `
                <td>${escapeHtml(String(customer.full_name || ''))}</td>
                <td>${escapeHtml(String(customer.mobile || '-'))}</td>
                <td>${escapeHtml(String(customer.passport_number || '-'))}</td>
            `;
            body?.appendChild(row);
        });
        customerAdvanceResults.appendChild(table);
        customerAdvanceResults.hidden = false;
    }

    function selectCustomerForAdvance(customer) {
        if (!customer) {
            return;
        }

        const travelerId = Number.parseInt(String(customer.id || 0), 10) || 0;
        if (travelerId <= 0) {
            return;
        }

        if (customerAdvanceTravelerId instanceof HTMLInputElement) {
            customerAdvanceTravelerId.value = String(travelerId);
        }
        if (customerAdvanceCustomerName instanceof HTMLInputElement) {
            customerAdvanceCustomerName.value = String(customer.full_name || 'Customer');
        }
        if (customerAdvanceSearchInput instanceof HTMLInputElement) {
            customerAdvanceSearchInput.value = String(customer.full_name || '');
        }
        if (customerAdvanceBranch instanceof HTMLSelectElement && Number.parseInt(String(customer.branch_id || 0), 10) > 0) {
            customerAdvanceBranch.value = String(customer.branch_id);
        }
        if (customerAdvanceCurrency instanceof HTMLSelectElement) {
            customerAdvanceCurrency.value = preferredCustomerAdvanceCurrency(customer);
        }
        if (customerAdvanceRefundTravelerId instanceof HTMLInputElement) {
            customerAdvanceRefundTravelerId.value = String(travelerId);
        }
        if (customerAdvanceRefundBranch instanceof HTMLSelectElement && Number.parseInt(String(customer.branch_id || 0), 10) > 0) {
            customerAdvanceRefundBranch.value = String(customer.branch_id);
        }
        if (customerAdvanceRefundCurrency instanceof HTMLSelectElement) {
            customerAdvanceRefundCurrency.value = preferredCustomerAdvanceCurrency(customer);
        }
        if (customerAdvanceResults instanceof HTMLElement) {
            customerAdvanceResults.hidden = true;
            customerAdvanceResults.innerHTML = '';
        }

        setCustomerAdvanceFeedback('', false);
        setCustomerAdvanceRefundFeedback('', false);
        syncCustomerAdvanceTreasurySelector();
        syncCustomerAdvanceRefundTreasurySelector();
        loadCustomerAdvanceRefundOptions();
    }

    const preferredCustomerAdvanceCurrency = (customer) => {
        const filterCurrency = String(customerDuesCurrencyFilter?.value || customerDuesFinderState.currency || '').trim().toUpperCase();
        if (['PKR', 'AED', 'USD'].includes(filterCurrency)) {
            return filterCurrency;
        }

        const totals = customer && typeof customer.open_balance_totals === 'object' && customer.open_balance_totals !== null
            ? customer.open_balance_totals
            : {};
        const currency = Object.keys(totals).find((key) => ['PKR', 'AED', 'USD'].includes(String(key || '').toUpperCase()));

        return String(currency || currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();
    };

    const eligibleCustomerAdvanceTreasuryAccounts = () => {
        const method = String(customerAdvanceMethod?.value || '').trim();
        const currency = String(customerAdvanceCurrency?.value || '').trim().toUpperCase();
        const branchId = Number.parseInt(String(customerAdvanceBranch?.value || '0'), 10) || 0;
        const compatibleTypes = paymentTreasuryTypesForMethod(method);

        return paymentTreasuryAccounts.filter((account) => {
            return (branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === currency;
        });
    };

    const syncCustomerAdvanceTreasurySelector = () => {
        if (!(customerAdvanceTreasuryAccount instanceof HTMLSelectElement)) {
            return;
        }

        const method = String(customerAdvanceMethod?.value || '').trim();
        const requiresTreasury = paymentMethodRequiresTreasurySelection(method);
        const eligibleAccounts = requiresTreasury ? eligibleCustomerAdvanceTreasuryAccounts() : [];
        const selectedBefore = String(customerAdvanceTreasuryAccount.value || '').trim();
        const preferredAccount = defaultPaymentTreasuryAccount(eligibleAccounts);

        customerAdvanceTreasuryAccount.innerHTML = '';

        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = eligibleAccounts.length > 0
            ? (method === 'cash' ? 'Select cash account' : 'Select bank account')
            : 'No eligible account configured';
        customerAdvanceTreasuryAccount.appendChild(promptOption);

        eligibleAccounts.forEach((account) => {
            const option = document.createElement('option');
            option.value = String(account.id || '');
            option.textContent = String(account.accountName || account.label || '').trim();
            customerAdvanceTreasuryAccount.appendChild(option);
        });

        if (selectedBefore !== '' && eligibleAccounts.some((account) => String(account.id || '') === selectedBefore)) {
            customerAdvanceTreasuryAccount.value = selectedBefore;
        } else if (preferredAccount) {
            customerAdvanceTreasuryAccount.value = String(preferredAccount.id || '');
        } else {
            customerAdvanceTreasuryAccount.value = '';
        }

        customerAdvanceTreasuryAccount.disabled = !requiresTreasury;
    };

    const syncCustomerAdvanceBankDetailVisibility = () => {
        if (!(customerAdvanceBankDetailField instanceof HTMLElement)) {
            return;
        }

        const method = String(customerAdvanceMethod?.value || '').trim();
        customerAdvanceBankDetailField.hidden = method === '' || method === 'cash';
    };

    const eligibleCustomerAdvanceRefundTreasuryAccounts = () => {
        const method = String(customerAdvanceRefundMethod?.value || '').trim();
        const currency = String(customerAdvanceRefundCurrency?.value || '').trim().toUpperCase();
        const branchId = Number.parseInt(String(customerAdvanceRefundBranch?.value || '0'), 10) || 0;
        const compatibleTypes = paymentTreasuryTypesForMethod(method);

        return paymentTreasuryAccounts.filter((account) => {
            return (branchId <= 0 || Number.parseInt(String(account?.branchId || 0), 10) === branchId)
                && compatibleTypes.includes(String(account?.accountType || '').trim())
                && String(account?.currency || '').trim().toUpperCase() === currency;
        });
    };

    const syncCustomerAdvanceRefundTreasurySelector = () => {
        if (!(customerAdvanceRefundTreasuryAccount instanceof HTMLSelectElement)) {
            return;
        }

        const method = String(customerAdvanceRefundMethod?.value || '').trim();
        const requiresTreasury = paymentMethodRequiresTreasurySelection(method);
        const eligibleAccounts = requiresTreasury ? eligibleCustomerAdvanceRefundTreasuryAccounts() : [];
        const selectedBefore = String(customerAdvanceRefundTreasuryAccount.value || '').trim();
        const preferredAccount = defaultPaymentTreasuryAccount(eligibleAccounts);

        customerAdvanceRefundTreasuryAccount.innerHTML = '';

        const promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = requiresTreasury
            ? 'Select account'
            : 'Not required';
        customerAdvanceRefundTreasuryAccount.appendChild(promptOption);

        eligibleAccounts.forEach((account) => {
            const option = document.createElement('option');
            option.value = String(account.id || '');
            option.textContent = String(account.accountName || account.label || '').trim();
            customerAdvanceRefundTreasuryAccount.appendChild(option);
        });

        if (selectedBefore !== '' && eligibleAccounts.some((account) => String(account.id || '') === selectedBefore)) {
            customerAdvanceRefundTreasuryAccount.value = selectedBefore;
        } else if (preferredAccount) {
            customerAdvanceRefundTreasuryAccount.value = String(preferredAccount.id || '');
        } else {
            customerAdvanceRefundTreasuryAccount.value = '';
        }

        customerAdvanceRefundTreasuryAccount.disabled = !requiresTreasury;
    };

    const syncCustomerAdvanceRefundAmountFromSelection = () => {
        if (!(customerAdvanceRefundReceipt instanceof HTMLSelectElement) || !(customerAdvanceRefundAmount instanceof HTMLInputElement)) {
            return;
        }

        const option = customerAdvanceRefundReceipt.selectedOptions?.[0] || null;
        const amount = option instanceof HTMLOptionElement ? toNumber(option.dataset.availableAmount || 0) : 0;
        if (amount > 0) {
            customerAdvanceRefundAmount.value = formatMoney(amount);
        }
    };

    async function loadCustomerAdvanceRefundOptions() {
        if (!(customerAdvanceRefundReceipt instanceof HTMLSelectElement)) {
            return;
        }

        const travelerId = Number.parseInt(String(customerAdvanceRefundTravelerId?.value || customerAdvanceTravelerId?.value || '0'), 10) || 0;
        const branchId = Number.parseInt(String(customerAdvanceRefundBranch?.value || customerAdvanceBranch?.value || '0'), 10) || 0;
        const currency = String(customerAdvanceRefundCurrency?.value || customerAdvanceCurrency?.value || 'PKR').trim().toUpperCase();

        customerAdvanceRefundReceipt.innerHTML = '<option value="">Select customer advance</option>';
        if (travelerId <= 0 || branchId <= 0 || customerAdvanceAvailableUrl === '') {
            return;
        }

        const params = new URLSearchParams({
            branch_id: String(branchId),
            traveler_id: String(travelerId),
            currency,
        });

        try {
            const response = await fetch(`${customerAdvanceAvailableUrl}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            const advances = uniqueCustomerAdvanceRows(payload.advances);
            advances.forEach((advance) => {
                const availableAmount = toNumber(advance.unallocated_amount || 0);
                const option = document.createElement('option');
                option.value = String(advance.id || '');
                option.dataset.availableAmount = String(availableAmount);
                option.textContent = `${advance.receipt_no || 'Advance'} / ${currency} ${formatMoney(availableAmount)}`;
                customerAdvanceRefundReceipt.appendChild(option);
            });
        } catch (error) {
            setCustomerAdvanceRefundFeedback('Available advances could not be loaded.');
        }
    }

    function openCustomerAdvanceModal() {
        if (!customerAdvanceModal) {
            return;
        }

        const selectedCustomer = selectedCustomerForAdvance();
        if (selectedCustomer) {
            selectCustomerForAdvance(selectedCustomer);
        } else {
            if (customerAdvanceTravelerId instanceof HTMLInputElement) {
                customerAdvanceTravelerId.value = '';
            }
            if (customerAdvanceCustomerName instanceof HTMLInputElement) {
                customerAdvanceCustomerName.value = '';
            }
            if (customerAdvanceSearchInput instanceof HTMLInputElement) {
                customerAdvanceSearchInput.value = '';
            }
            if (customerAdvanceResults instanceof HTMLElement) {
                customerAdvanceResults.hidden = true;
                customerAdvanceResults.innerHTML = '';
            }
            if (customerAdvanceCurrency instanceof HTMLSelectElement) {
                customerAdvanceCurrency.value = String(currentInvoiceSnapshot().invoiceCurrency || 'PKR').toUpperCase();
            }
            if (customerAdvanceRefundTravelerId instanceof HTMLInputElement) {
                customerAdvanceRefundTravelerId.value = '';
            }
        }
        if (customerAdvanceAmount instanceof HTMLInputElement) {
            customerAdvanceAmount.value = '0';
        }

        setCustomerAdvanceFeedback('', false);
        setCustomerAdvanceRefundFeedback('', false);
        syncCustomerAdvanceTreasurySelector();
        syncCustomerAdvanceBankDetailVisibility();
        syncCustomerAdvanceRefundTreasurySelector();
        loadCustomerAdvanceRefundOptions();
        customerAdvanceModal.hidden = false;
        customerAdvanceModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            if (selectedCustomer) {
                customerAdvanceAmount?.focus();
                customerAdvanceAmount?.select();
            } else {
                customerAdvanceSearchInput?.focus();
            }
        }, 40);
    }
    window.workspaceOpenCustomerAdvanceModal = openCustomerAdvanceModal;

    function closeCustomerAdvanceModal() {
        if (!customerAdvanceModal) {
            return;
        }

        customerAdvanceNewCustomerMode = false;
        customerAdvanceModal.hidden = true;
        customerAdvanceModal.setAttribute('aria-hidden', 'true');
        refreshPaymentAdvanceControls();
    }
    window.workspaceCloseCustomerAdvanceModal = closeCustomerAdvanceModal;

    window.addEventListener('focus', () => {
        refreshPaymentAdvanceControls();
        if (customerAdvanceModal instanceof HTMLElement && !customerAdvanceModal.hidden) {
            loadCustomerAdvanceRefundOptions();
        }
    });

    window.addEventListener('pageshow', () => {
        refreshPaymentAdvanceControls();
    });

    station.addEventListener('workspace:customer-selected', () => {
        syncCustomerDuesFinderWithWorkspaceCustomer();
        refreshPaymentAdvanceControls();
        if (!customerDuesModal?.hidden) {
            renderCustomerDuesFinder();
        }
    });

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
    window.workspaceOpenSupplierHistoryModal = openSupplierHistoryModal;

    function closeSupplierHistoryModal() {
        if (!supplierHistoryModal) {
            return;
        }

        supplierHistoryModal.hidden = true;
        supplierHistoryModal.setAttribute('aria-hidden', 'true');
    }
    window.workspaceCloseSupplierHistoryModal = closeSupplierHistoryModal;

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
        syncSupplierTreasurySelectors();

        if (simplePostpaidTotal) {
            simplePostpaidTotal.textContent = selectedCurrencies.length === 1
                ? `${selectedCurrencies[0]} ${formatNumberInputValue(selectedTotal)}`
                : formatNumberInputValue(selectedTotal);
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

        if (simplePostpaidSelectAll instanceof HTMLInputElement) {
            simplePostpaidSelectAll.checked = availableRows > 0 && selectedRows.length === availableRows;
            simplePostpaidSelectAll.indeterminate = selectedRows.length > 0 && selectedRows.length < availableRows;
        }
    }

    function openGlobalPrepaidSupplierModal(options = {}) {
        if (!globalPrepaidSupplierModal) {
            return;
        }

        if (globalPrepaidSupplierField && options.supplierName) {
            const supplierName = String(options.supplierName);
            addSupplierToSelect(globalPrepaidSupplierField, supplierName);
            globalPrepaidSupplierField.value = supplierName;
        }
        if (globalPrepaidBranchField && options.branchId) {
            globalPrepaidBranchField.value = String(options.branchId);
        }
        if (globalPrepaidCurrencyField && options.currency) {
            globalPrepaidCurrencyField.value = String(options.currency);
        }
        if (globalPrepaidAmountField && options.focusAmount !== false) {
            globalPrepaidAmountField.value = globalPrepaidAmountField.value || '0';
        }
        globalPrepaidSupplierModal.dataset.returnToTicketType = options.returnToTicketType ? '1' : '0';
        globalPrepaidSupplierModal.hidden = false;
        globalPrepaidSupplierModal.setAttribute('aria-hidden', 'false');
        showFeedback('Prepaid supplier payment opened.');
        window.setTimeout(() => {
            if (globalPrepaidAmountField) {
                globalPrepaidAmountField.focus();
                globalPrepaidAmountField.select();
            } else {
                globalPrepaidSupplierField?.focus();
            }
        }, 70);
    }
    window.workspaceOpenGlobalPrepaidSupplierModal = openGlobalPrepaidSupplierModal;

    function closeGlobalPrepaidSupplierModal() {
        if (!globalPrepaidSupplierModal) {
            return;
        }

        const shouldReturnToTicketType = globalPrepaidSupplierModal.dataset.returnToTicketType === '1';
        globalPrepaidSupplierModal.hidden = true;
        globalPrepaidSupplierModal.setAttribute('aria-hidden', 'true');
        globalPrepaidSupplierModal.dataset.returnToTicketType = '0';
        if (shouldReturnToTicketType) {
            focusTicketTypeAfterSupplier();
        }
    }

    async function openExchangeSettlementModal(options = {}) {
        if (!paymentExchangeModal || !paymentExchangeTargetSelect) {
            return;
        }

        const { allowManualRatePreview = false } = options;
        if (!allowManualRatePreview) {
            await ensureExchangeSettlementTargetsReady();
        }
        const target = currentInvoiceExchangeTarget() || buildManualExchangeSettlementTarget({
            allowZeroBalance: allowManualRatePreview,
        });
        if (!target) {
            const message = currentInvoiceNeedsSettlementTarget()
                ? 'Save the current invoice first so it becomes available for exchange settlement.'
                : 'No current invoice receivable is available for exchange settlement.';
            showFeedback(message);
            return;
        }

        paymentExchangeManualTarget = target.isManualPreviewOnly ? target : null;
        paymentExchangeTargetSelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = String(target.id || 0);
        option.textContent = settlementTargetLabel(target);
        option.selected = true;
        paymentExchangeTargetSelect.appendChild(option);

        clearExchangeSettlementFields();
        paymentExchangeConfirmFocusDone = false;
        paymentExchangeModal.style.removeProperty('display');
        paymentExchangeModal.hidden = false;
        paymentExchangeModal.setAttribute('aria-hidden', 'false');
        exchangeSettlementPreview();
        window.setTimeout(() => {
            if (paymentExchangeRateInput && !paymentExchangeRateRow?.hidden && toNumber(paymentExchangeRateInput.value || 0) <= 0.005) {
                paymentExchangeRateInput.focus();
                return;
            }
            if (Math.max(toNumber(receivedNowInput?.value || 0), 0) <= 0.005) {
                paymentExchangeRateInput?.focus();
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
        paymentExchangeModal.style.display = 'none';
        paymentExchangeModal.hidden = true;
        paymentExchangeModal.setAttribute('aria-hidden', 'true');
        paymentExchangeManualTarget = null;
        paymentExchangeConfirmFocusDone = false;
        if (clear) {
            clearExchangeSettlementFields();
        }
    }

    const triggerExchangeSettlementClose = () => {
        const preferredCloseButton = paymentExchangeCloseButtons.find((button) =>
            button instanceof HTMLButtonElement
            && button.closest('[data-payment-exchange-modal]') === paymentExchangeModal
        );

        if (preferredCloseButton) {
            preferredCloseButton.click();
            return;
        }

        closeExchangeSettlementModal();
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

    serviceEditBookingOpenButtons.forEach((button) => {
        button.addEventListener('click', () => openServiceEditBookingModal());
    });

    serviceEditBookingCloseButtons.forEach((button) => {
        button.addEventListener('click', closeServiceEditBookingModal);
    });

    if (serviceEditBookingModal instanceof HTMLElement) {
        serviceEditBookingModal.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                try {
                    window.sessionStorage.setItem(pendingServiceEditBookingModalKey, '1');
                    window.sessionStorage.removeItem(pendingPenaltyRefundModalKey);
                } catch (error) {
                    // ignore storage failures
                }
            });
        });
    }

    servicePenaltyRefundOpenButtons.forEach((button) => {
        button.addEventListener('click', () => openServicePenaltyRefundModal());
    });

    servicePenaltyRefundCloseButtons.forEach((button) => {
        button.addEventListener('click', closeServicePenaltyRefundModal);
    });

    if (servicePenaltyRefundModal instanceof HTMLElement) {
        servicePenaltyRefundModal.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                try {
                    window.sessionStorage.removeItem(pendingServiceEditBookingModalKey);
                    window.sessionStorage.setItem(pendingPenaltyRefundModalKey, '1');
                } catch (error) {
                    // ignore storage failures
                }
            });
        });
    }

    supplierSettlementCloseButtons.forEach((button) => {
        button.addEventListener('click', closeSupplierSettlementModal);
    });

    if (simplePostpaidSelectAll instanceof HTMLInputElement) {
        simplePostpaidSelectAll.addEventListener('change', () => {
            simplePostpaidSelectors.forEach((input) => {
                input.checked = simplePostpaidSelectAll.checked;
            });
            updateSimplePostpaidSupplierForm();
        });
    }

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
                return;
            }

            if (!ensureSupplierTreasuryReady(simplePostpaidForm)) {
                event.preventDefault();
            }
        });
        updateSimplePostpaidSupplierForm();
    }

    supplierTreasurySelects.forEach((select) => {
        const form = select.form;
        const methodField = form?.elements?.namedItem('supplier_payment_method');
        const currencyField = form?.elements?.namedItem('supplier_payment_currency');
        if (methodField instanceof HTMLSelectElement || methodField instanceof HTMLInputElement) {
            methodField.addEventListener('change', syncSupplierTreasurySelectors);
        }
        if (currencyField instanceof HTMLSelectElement || currencyField instanceof HTMLInputElement) {
            currencyField.addEventListener('change', syncSupplierTreasurySelectors);
            currencyField.addEventListener('input', syncSupplierTreasurySelectors);
        }
        form?.addEventListener('submit', (event) => {
            if (!ensureSupplierTreasuryReady(form)) {
                event.preventDefault();
            }
        });
    });
    syncSupplierTreasurySelectors();

    globalPrepaidSupplierOpenButtons.forEach((button) => {
        button.addEventListener('click', openGlobalPrepaidSupplierModal);
    });

    globalPrepaidSupplierCloseButtons.forEach((button) => {
        button.addEventListener('click', closeGlobalPrepaidSupplierModal);
    });

    const showGlobalPrepaidSupplierFeedback = (message) => {
        const text = String(message || '').trim();

        if (globalPrepaidSupplierFeedback) {
            globalPrepaidSupplierFeedback.textContent = text;
            globalPrepaidSupplierFeedback.hidden = text === '';
            return;
        }

        if (text !== '') {
            showFeedback(text);
        }
    };

    const submitGlobalPrepaidSupplierForm = async (event) => {
        event.preventDefault();

        if (!(globalPrepaidSupplierForm instanceof HTMLFormElement)) {
            return;
        }

        const supplierName = String(globalPrepaidSupplierField?.value || '').trim();
        const advanceAmount = Math.max(toNumber(globalPrepaidAmountField?.value || 0), 0);

        if (supplierName === addSupplierOptionValue) {
            openSupplierAddModal('', 'global-prepaid');
            return;
        }

        if (supplierName === '') {
            showGlobalPrepaidSupplierFeedback('Enter supplier name.');
            globalPrepaidSupplierField?.focus();
            return;
        }

        if (advanceAmount <= 0.005) {
            showGlobalPrepaidSupplierFeedback('Enter a valid prepaid supplier amount.');
            globalPrepaidAmountField?.focus();
            globalPrepaidAmountField?.select();
            return;
        }

        showGlobalPrepaidSupplierFeedback('');

        if (globalPrepaidSupplierSubmit) {
            globalPrepaidSupplierSubmit.disabled = true;
            globalPrepaidSupplierSubmit.textContent = 'Saving...';
        }

        try {
            const response = await fetch(globalPrepaidSupplierForm.action, {
                method: 'POST',
                body: new FormData(globalPrepaidSupplierForm),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({
                ok: false,
                message: 'The server returned an invalid prepaid supplier response.',
            }));

            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'Prepaid supplier payment could not be saved.');
            }

            showFeedback(payload.message || 'Prepaid supplier payment recorded successfully.');
            closeGlobalPrepaidSupplierModal();
            scheduleSupplierAdvanceBalanceRefresh(0);
        } catch (error) {
            showGlobalPrepaidSupplierFeedback(error instanceof Error ? error.message : 'Prepaid supplier payment could not be saved.');
        } finally {
            if (globalPrepaidSupplierSubmit) {
                globalPrepaidSupplierSubmit.disabled = false;
                globalPrepaidSupplierSubmit.textContent = 'Save Prepaid Supplier Payment';
            }
        }
    };

    if (globalPrepaidSupplierForm) {
        globalPrepaidSupplierForm.addEventListener('submit', submitGlobalPrepaidSupplierForm);
    }

    const focusGlobalPrepaidSupplierField = (field) => {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.focus();
        if (field instanceof HTMLInputElement) {
            field.select();
        }
    };

    const submitGlobalPrepaidSupplierFormFromKeyboard = () => {
        if (!(globalPrepaidSupplierForm instanceof HTMLFormElement) || globalPrepaidSupplierSubmit?.disabled) {
            return;
        }

        if (typeof globalPrepaidSupplierForm.requestSubmit === 'function') {
            if (globalPrepaidSupplierSubmit instanceof HTMLElement) {
                globalPrepaidSupplierForm.requestSubmit(globalPrepaidSupplierSubmit);
            } else {
                globalPrepaidSupplierForm.requestSubmit();
            }
            return;
        }

        globalPrepaidSupplierSubmit?.click();
    };

    const handleGlobalPrepaidSupplierFormEnter = (event) => {
        if (event.key !== 'Enter' && event.code !== 'NumpadEnter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();


        const fields = Array.from(globalPrepaidSupplierForm?.elements || [])
            .filter((field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)
            .filter((field) => !(field instanceof HTMLInputElement && field.type === 'hidden'))
            .filter((field) => !field.disabled);

        const currentIndex = fields.indexOf(target);
        const nextField = fields[currentIndex + 1];

        if (nextField instanceof HTMLElement) {
            focusGlobalPrepaidSupplierField(nextField);
            return;
        }

        submitGlobalPrepaidSupplierFormFromKeyboard();
    };

    if (globalPrepaidSupplierForm) {
        globalPrepaidSupplierForm.addEventListener('keydown', handleGlobalPrepaidSupplierFormEnter, true);
    }
    if (serviceFields.supplier instanceof HTMLInputElement) {
        serviceFields.supplier.addEventListener('input', () => {
            scheduleSupplierAdvanceBalanceRefresh(220);
        });
        serviceFields.supplier.addEventListener('change', () => {
            scheduleSupplierAdvanceBalanceRefresh(0);
        });
    } else if (serviceFields.supplier instanceof HTMLSelectElement) {
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
        button.addEventListener('click', () => {
            closeExchangeSettlementModal();
        });
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
        replaceSettlementData([], {});
        replacePaymentHistoryData([], []);
        renderPaymentHistoryModal();
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
            paymentCurrentBalancePkrInput.value = 'PKR 0';
        }
        if (paymentCurrentBalancePkrRow) {
            paymentCurrentBalancePkrRow.hidden = true;
        }
        if (paymentCustomerCreditInput) {
            paymentCustomerCreditInput.dataset.paymentCustomerCredit = '0';
            paymentCustomerCreditInput.dataset.paymentCustomerCreditMap = '{}';
            paymentCustomerCreditInput.value = formatCurrencyAmount(
                paymentCurrentInvoiceInput?.dataset.paymentCurrency || 'PKR',
                0
            );
        }
        if (paymentCustomerCreditRow) {
            paymentCustomerCreditRow.hidden = true;
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

            if (fieldAutosaveEnabled && autosaveBookingReady()) {
                scheduleInvoiceAutosave();
            }
        });
        bookingLeadField.addEventListener('blur', () => {
            if (suppressCustomerAutocompleteInput) {
                return;
            }

            if (fieldAutosaveEnabled && !customerAutocompletePanel?.contains(document.activeElement) && autosaveBookingReady()) {
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
                startFreshWorkspaceForCustomer(filteredCustomers[customerPickerSelectionIndex] || null);
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

    const requestedServiceId = (() => {
        try {
            return Number.parseInt(new URLSearchParams(window.location.search).get('service_id') || '0', 10) || 0;
        } catch (error) {
            return 0;
        }
    })();
    const serviceWorkflowState = (() => {
        try {
            const params = new URLSearchParams(window.location.search);
            return {
                step: String(params.get('service_workflow') || '').trim().toLowerCase(),
                autoPrintRefund: params.get('auto_print_refund') === '1',
            };
        } catch (error) {
            return {
                step: '',
                autoPrintRefund: false,
            };
        }
    })();

    const clearServiceWorkflowStateFromUrl = () => {
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('service_workflow');
            url.searchParams.delete('auto_print_refund');
            window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`);
        } catch (error) {
            // Ignore URL cleanup issues; workflow restoration is still complete.
        }
    };

    const ensureServiceWorkflowModalForSelector = (selector) => {
        const normalizedSelector = String(selector || '');
        if (normalizedSelector.includes('[data-service-event-bar="financial-correction"]')
            || normalizedSelector.includes('[data-service-event-bar="reissue"]')) {
            openServiceEditBookingModal({ silent: true });
            return;
        }

        if (
            normalizedSelector.includes('[data-service-event-bar="cancel"]')
            || normalizedSelector.includes('[data-service-event-bar="settlement"]')
            || normalizedSelector.includes('[data-service-event-bar="refund"]')
            || normalizedSelector.includes('[data-service-event-bar="cancel-reopen"]')
        ) {
            openServiceEditBookingModal({ silent: true });
        }
    };

    const focusServiceWorkflowField = (selector, message = '') => {
        const tryFocus = () => {
            const field = station.querySelector(selector);
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            const form = field.closest('form');
            if (form instanceof HTMLElement && form.hidden) {
                return false;
            }

            field.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            window.setTimeout(() => focusTarget(selector), 80);
            if (message !== '') {
                showFeedback(message);
            }

            return true;
        };

        if (tryFocus()) {
            return;
        }

        ensureServiceWorkflowModalForSelector(selector);

        [120, 320, 700].forEach((delay) => {
            window.setTimeout(tryFocus, delay);
        });
    };

    const openLatestRefundReceipt = () => {
        const printLink = station.querySelector('[data-service-event-bar="refund"] a[href*="doc=service_refund_receipt"]');
        if (!(printLink instanceof HTMLAnchorElement) || printLink.href === '') {
            return false;
        }

        const popup = window.open(printLink.href, '_blank', 'noopener');
        return popup !== null;
    };

    if (serviceLines.length > 0) {
        const requestedServiceIndex = requestedServiceId > 0
            ? serviceLines.findIndex((serviceLine) => Number.parseInt(String(serviceLine.serviceId || 0), 10) === requestedServiceId)
            : -1;
        const initialServiceIndex = requestedServiceIndex >= 0 ? requestedServiceIndex : 0;
        serviceRows.forEach((item, rowIndex) => {
            item.classList.toggle('is-active', rowIndex === initialServiceIndex);
        });
        loadServiceLine(initialServiceIndex);
    } else {
        updateCommercialTrace({
            lastEventFired: 'boot-no-service-lines',
            lastOverwriteSource: 'boot-no-service-lines',
        });
        refreshProfit('boot-no-service-lines');
        syncAllCommercialPercentsFromAmounts();
        refreshSubtypeVisibility();
    }

    window.setTimeout(() => {
        refreshProfit('boot-deferred-commercial-refresh');
        syncTicketCommercialMirrors();
    }, 0);

    if (travelers.length > 0) {
        loadTraveler(0);
    }

    setAutosaveStatus('idle', currentBookingId() > 0 ? 'Saved' : 'Draft');
    updateWhatsappLedgerActionState();

    if (paymentWhatsappLedgerButton instanceof HTMLButtonElement) {
        paymentWhatsappLedgerButton.addEventListener('click', (event) => {
            event.preventDefault();
            openWhatsappLedgerFlow();
        });
    }

    [customerAutocompleteInput, bookingMobileField, bookingBranchField, businessSourceInput].forEach((field) => {
        if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLSelectElement)) {
            return;
        }

        field.addEventListener('input', () => updateWhatsappLedgerActionState());
        field.addEventListener('change', () => updateWhatsappLedgerActionState());
    });

    updateWorkflowState();
    reapplyActiveServiceActionState();
    window.requestAnimationFrame(() => reapplyActiveServiceActionState());
    window.setTimeout(() => reapplyActiveServiceActionState(), 150);
    window.setTimeout(() => reapplyActiveServiceActionState(), 700);
    window.addEventListener('focus', reapplyActiveServiceActionState);
    window.addEventListener('resize', reapplyActiveServiceActionState);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            reapplyActiveServiceActionState();
        }
    });

    const requestedPanelId = window.location.hash.replace('#', '');
    const requestedPanel = requestedPanelId !== '' ? station.querySelector(`#${requestedPanelId}`) : null;
    if (requestedPanel && requestedPanel.dataset.dockPanel) {
        activateDock(requestedPanel.dataset.dockPanel);
    } else {
        activateDock('overview');
    }

    if (requestedPanelId === 'dock-panel-reminders') {
        revealWorkspaceSection('dock-panel-reminders', {
            focusSelector: '[data-reminder-focus="task"]',
        });
        const pageToast = document.querySelector('.alert-toast');
        const toastMessage = pageToast instanceof HTMLElement ? String(pageToast.textContent || '').trim() : '';
        if (toastMessage !== '') {
            showFeedback(toastMessage);
        }
    }

    if (requestedPanelId === 'dock-panel-documents') {
        const pageToast = document.querySelector('.alert-toast');
        const toastMessage = pageToast instanceof HTMLElement ? String(pageToast.textContent || '').trim() : '';
        if (toastMessage !== '') {
            showDocumentUploadFeedback(toastMessage);
            showFeedback(toastMessage);
        }
    }

    if (serviceWorkflowState.step !== '') {
        const workflowStep = serviceWorkflowState.step;
        window.setTimeout(() => {
            reapplyActiveServiceActionState();
            unlockVisibleSettlementBar();

            if (workflowStep === 'cancelled') {
                focusServiceWorkflowField(
                    '[data-service-event-bar="settlement"] input[name="customer_penalty_amount"]',
                    'Cancellation recorded. Continue with settlement if needed.'
                );
            } else if (workflowStep === 'settled') {
                focusServiceWorkflowField(
                    '[data-service-event-bar="refund"] input[name="customer_refund_amount"]',
                    'Settlement saved. Refund is ready if money must be returned to the customer.'
                );
            } else if (workflowStep === 'refunded') {
                focusServiceWorkflowField(
                    '[data-service-event-bar="refund"] input[name="customer_refund_amount"]',
                    'Refund posted. Opening the refund receipt.'
                );

                if (serviceWorkflowState.autoPrintRefund) {
                    window.setTimeout(() => {
                        const opened = openLatestRefundReceipt();
                        if (!opened) {
                            showFeedback('Refund posted. Use Print Refund if the receipt window did not open automatically.');
                        }
                    }, 220);
                }
            } else if (workflowStep === 'penalty_refund_corrected') {
                closeServicePenaltyRefundModal();
                showFeedback('Penalty / refund correction saved.');
            }

            clearServiceWorkflowStateFromUrl();
        }, 120);
    }

    if (reminderToggleButtons.length > 0) {
        const reminderSection = station.querySelector('#dock-panel-reminders');
        const expandedState = reminderSection instanceof HTMLElement && !reminderSection.hidden ? 'true' : 'false';
        reminderToggleButtons.forEach((button) => {
            if (button instanceof HTMLButtonElement) {
                button.setAttribute('aria-expanded', expandedState);
            }
        });
    }

    const receivableAlertsPanel = station.querySelector('[data-receivable-alerts]');
    if (receivableAlertsPanel instanceof HTMLElement) {
        const scopeButtons = Array.from(receivableAlertsPanel.querySelectorAll('[data-receivable-alert-scope-toggle]'));
        const totalCountNode = station.querySelector('[data-receivable-alert-count-total]');
        const tableBody = receivableAlertsPanel.querySelector('[data-receivable-alert-table] tbody');
        let activeReceivableAlertScope = ['customer', 'booking'].includes(String(receivableAlertsPanel.dataset.defaultScope || ''))
            ? String(receivableAlertsPanel.dataset.defaultScope)
            : 'customer';

        const todayIso = () => {
            const now = new Date();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            return `${now.getFullYear()}-${month}-${day}`;
        };

        const diffDays = (fromIso, toIso) => {
            if (!fromIso || !toIso) {
                return 0;
            }

            const fromDate = new Date(`${fromIso}T00:00:00`);
            const toDate = new Date(`${toIso}T00:00:00`);
            if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
                return 0;
            }

            return Math.round((toDate.getTime() - fromDate.getTime()) / 86400000);
        };

        const currentReceivableBookingReference = () => {
            const bookingId = currentBookingId();
            if (bookingId <= 0) {
                return '';
            }

            return Array.from(customerOpenReceivables).find((row) => Number.parseInt(String(row?.bookingId || 0), 10) === bookingId)?.bookingReference || '';
        };

        const normalizeReceivableAlertRows = () => {
            const bookingId = currentBookingId();
            const bookingReference = currentReceivableBookingReference();

            return Array.from(customerOpenReceivables)
                .filter((row) => toNumber(row?.outstandingAmount || 0) > 0.005)
                .map((row) => {
                    const rowBookingId = Number.parseInt(String(row?.bookingId || 0), 10) || 0;
                    const rowBookingReference = String(row?.bookingReference || '').trim();
                    const isCurrentBooking = Boolean(row?.isCurrentBooking)
                        || (bookingId > 0 && rowBookingId === bookingId)
                        || (bookingReference !== '' && rowBookingReference === bookingReference);

                    return {
                        ...row,
                        bookingId: rowBookingId,
                        bookingReference: rowBookingReference,
                        isCurrentBooking,
                    };
                })
                .sort((left, right) => {
                    if (left.isCurrentBooking && !right.isCurrentBooking) {
                        return -1;
                    }
                    if (!left.isCurrentBooking && right.isCurrentBooking) {
                        return 1;
                    }

                    const leftDue = String(left.nextDueDate || '');
                    const rightDue = String(right.nextDueDate || '');
                    if (leftDue !== rightDue) {
                        return leftDue.localeCompare(rightDue);
                    }

                    return Number.parseInt(String(left.id || 0), 10) - Number.parseInt(String(right.id || 0), 10);
                });
        };

        const categorizeReceivableAlertRow = (row) => {
            const dueDate = String(row?.nextDueDate || '').trim();
            if (dueDate === '') {
                return {
                    category: 'Missing Due Date',
                    daysLabel: '',
                };
            }

            const today = todayIso();
            const days = diffDays(today, dueDate);
            if (days < 0) {
                return {
                    category: 'Overdue',
                    daysLabel: `Overdue ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'}`,
                };
            }

            if (days === 0) {
                return {
                    category: 'Due Today',
                    daysLabel: 'Due in 0 days',
                };
            }

            return {
                category: 'Upcoming',
                daysLabel: `Due in ${days} day${days === 1 ? '' : 's'}`,
            };
        };

        const buildReceivableAlertScopes = () => {
            const rows = normalizeReceivableAlertRows();
            const customerRows = rows;
            const bookingRows = rows.filter((row) => row.isCurrentBooking);

            return {
                customer: customerRows,
                booking: bookingRows,
            };
        };

        const countReceivableAlertRows = (rows = []) => {
            const counts = {
                overdue: 0,
                dueToday: 0,
                pending: 0,
                missingDueDate: 0,
                total: rows.length,
            };

            rows.forEach((row) => {
                const category = categorizeReceivableAlertRow(row).category;
                if (category === 'Overdue') {
                    counts.overdue += 1;
                } else if (category === 'Due Today') {
                    counts.dueToday += 1;
                } else if (category === 'Missing Due Date') {
                    counts.missingDueDate += 1;
                } else {
                    counts.pending += 1;
                }
            });

            return counts;
        };

        const receivableAlertRowHtml = (row) => {
            const dueMeta = categorizeReceivableAlertRow(row);
            const bookingId = Number.parseInt(String(row?.bookingId || 0), 10) || 0;
            const openUrl = bookingId > 0 ? buildWorkspacePathUrl(`workspace?booking_id=${bookingId}`) : '#';
            const customerName = String(row?.passengerName || bookingLeadField?.value || '').trim();
            const branchName = String(row?.branchName || '').trim();
            const serviceType = String(row?.serviceType || 'Service').trim() || 'Service';
            const serviceLineReference = String(row?.serviceLineReference || '').trim();
            const serviceSummary = serviceLineReference !== ''
                ? `${serviceType} / ${serviceLineReference}`
                : serviceType;
            const rowClass = row.isCurrentBooking ? ' class="legacy-reminders-table__row--current-booking"' : '';
            const currentBookingFlag = row.isCurrentBooking
                ? '<br><small class="legacy-reminders-table__row-flag">Current Booking</small>'
                : '';
            const openAction = bookingId > 0
                ? `<a class="btn btn-sm" href="${escapeHtml(openUrl)}">Open</a>`
                : '<span class="muted-text">Draft</span>';

            return `<tr data-receivable-alert-row${rowClass}>
                <td>${escapeHtml(dueMeta.category)}<br><small>${escapeHtml(dueMeta.daysLabel)}</small></td>
                <td>${escapeHtml(String(row?.bookingReference || ''))}${currentBookingFlag}</td>
                <td>${escapeHtml(customerName !== '' ? customerName : '-')}</td>
                <td>${escapeHtml(branchName !== '' ? branchName : '-')}</td>
                <td>${escapeHtml(formatCurrencyAmount(String(row?.currency || 'PKR'), toNumber(row?.outstandingAmount || 0)))}</td>
                <td>${escapeHtml(String(row?.nextDueDate || '-'))}</td>
                <td>${escapeHtml(serviceSummary)}</td>
                <td>${openAction}</td>
            </tr>`;
        };

        const applyReceivableAlertScope = (scopeKey, options = {}) => {
            const normalizedScope = scopeKey === 'booking' ? 'booking' : 'customer';
            activeReceivableAlertScope = normalizedScope;
            const scopes = buildReceivableAlertScopes();
            const visibleRows = scopes[normalizedScope] || [];
            const counts = countReceivableAlertRows(visibleRows);

            if (tableBody instanceof HTMLElement) {
                tableBody.innerHTML = visibleRows.length > 0
                    ? visibleRows.map((row) => receivableAlertRowHtml(row)).join('')
                    : '<tr data-receivable-alert-empty-row><td colspan="8" class="empty-cell">No receivable alerts right now.</td></tr>';
            }

            scopeButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                const isActive = button.dataset.scope === normalizedScope;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            if (totalCountNode instanceof HTMLElement) {
                totalCountNode.textContent = String(counts.total);
            }

            if (options.updateDefault !== false) {
                receivableAlertsPanel.dataset.defaultScope = normalizedScope;
            }
        };

        renderReceivableAlerts = () => {
            applyReceivableAlertScope(activeReceivableAlertScope, {
                updateDefault: false,
            });
        };

        scopeButtons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.addEventListener('click', () => {
                applyReceivableAlertScope(String(button.dataset.scope || 'customer'));
            });
        });

        renderReceivableAlerts();
    }

    if (requestedPanelId === 'dock-panel-suppliers') {
        openSupplierSettlementModal();
    }

    if (!hasPendingTreasuryWorkspaceStateForCurrentRoute()) {
        let navigationType = '';
        try {
            const navigationEntry = performance.getEntriesByType('navigation')[0];
            navigationType = String(navigationEntry?.type || '').trim().toLowerCase();
        } catch (error) {
            navigationType = '';
        }

        const focusParams = new URLSearchParams(window.location.search);
        const focusMode = focusParams.get('focus');
        const shouldHonorCustomerFocus = focusMode === 'customer' && navigationType !== 'reload';

        if (focusMode === 'payment-save' && paymentPrimarySaveButton) {
            window.setTimeout(() => {
                paymentPrimarySaveButton.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                paymentPrimarySaveButton.focus();
            }, 120);
        } else if (focusMode === 'refund-customer') {
            window.setTimeout(() => {
                const refundAmountField = station.querySelector('[data-service-event-bar="refund"] input[name="customer_refund_amount"]');
                if (refundAmountField instanceof HTMLElement) {
                    refundAmountField.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                    refundAmountField.focus();
                    if (refundAmountField instanceof HTMLInputElement) {
                        refundAmountField.select();
                    }
                } else if (paymentPrimarySaveButton) {
                    paymentPrimarySaveButton.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                    paymentPrimarySaveButton.focus();
                }
            }, 120);
        } else if (focusMode === 'customer' && bookingLeadField) {
            if (shouldHonorCustomerFocus) {
                window.setTimeout(() => {
                    bookingLeadField.focus();
                    bookingLeadField.select();
                    filterAutocompleteCustomers();
                }, 120);
            }
        } else if (quickSearch) {
            window.setTimeout(() => {
                quickSearch.focus();
                quickSearch.select();
            }, 120);
        }

        if (focusMode !== null) {
            try {
                const cleanedUrl = new URL(window.location.href);
                cleanedUrl.searchParams.delete('focus');
                window.history.replaceState({}, '', `${cleanedUrl.pathname}${cleanedUrl.search}${cleanedUrl.hash}`);
            } catch (error) {
                // Ignore URL cleanup errors.
            }
        }
    }

    restorePendingFreshWorkspaceCustomer();
    restorePendingFreshWorkspaceAction();
    restoreServiceEditBookingModalState();
    restorePenaltyRefundModalState();
    consumeRequestedCustomerEdit();
    restoreWorkspaceStateAfterTreasuryReturn();
    window.addEventListener('focus', handleTreasurySetupReturn);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            handleTreasurySetupReturn();
        }
    });
});
