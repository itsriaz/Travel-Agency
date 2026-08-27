document.addEventListener('DOMContentLoaded', () => {
    const treasuryPage = document.querySelector('.treasury-page');
    const treasuryClientLogUrl = String(treasuryPage?.dataset?.treasuryClientLogUrl || '').trim();
    const treasuryCsrfToken = String(treasuryPage?.dataset?.treasuryCsrfToken || '').trim();
    const treasuryTabTrace = (type, extra = {}) => {
        const entry = {
            type,
            timestamp: new Date().toISOString(),
            page: 'treasury_accounts',
            ...extra,
        };

        if (typeof console !== 'undefined' && typeof console.log === 'function') {
            console.log('[treasury-tab-trace]', entry);
        }

        if (treasuryClientLogUrl === '') {
            return;
        }

        const payload = JSON.stringify({
            type: 'treasury_tab_trace',
            message: String(extra.message || type || 'Treasury tab trace'),
            timestamp: entry.timestamp,
            extra: entry,
        });

        fetch(treasuryClientLogUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': treasuryCsrfToken,
            },
            body: payload,
            keepalive: true,
        }).catch(() => {
            // Ignore logging transport failures.
        });
    };

    window.addEventListener('error', (event) => {
        treasuryTabTrace('script_error', {
            message: String(event.message || 'Treasury page script error'),
            file: String(event.filename || ''),
            line: Number(event.lineno || 0),
            column: Number(event.colno || 0),
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason instanceof Error
            ? `${event.reason.name}: ${event.reason.message}`
            : String(event.reason || 'Unhandled rejection');
        treasuryTabTrace('script_unhandled_rejection', {
            message: reason,
        });
    });

    const treasuryTabButtons = Array.from(document.querySelectorAll('[data-treasury-tab]'));
    const treasuryTabPanels = Array.from(document.querySelectorAll('[data-treasury-tab-panel]'));
    const treasuryForm = document.querySelector('[data-treasury-account-form]');
    const treasuryAccountType = document.querySelector('[data-treasury-account-type]');
    const treasurySaveButton = document.querySelector('[data-treasury-save-button]');
    const bankDetailFields = Array.from(document.querySelectorAll('[data-bank-detail-field]'));
    const transferForm = document.querySelector('[data-treasury-transfer-form]');
    const transferSourceBranch = document.querySelector('[data-transfer-source-branch]');
    const transferDestinationBranch = document.querySelector('[data-transfer-destination-branch]');
    const transferType = document.querySelector('[data-transfer-type]');
    const transferSourceCurrency = document.querySelector('[data-transfer-source-currency]');
    const transferDestinationCurrency = document.querySelector('[data-transfer-destination-currency]');
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferFromLabel = document.querySelector('[data-transfer-from-label]');
    const transferToLabel = document.querySelector('[data-transfer-to-label]');
    const transferSourceSummary = document.querySelector('[data-transfer-source-summary]');
    const transferDestinationSummary = document.querySelector('[data-transfer-destination-summary]');
    const transferSourceAmount = document.querySelector('[data-transfer-source-amount]');
    const transferDestinationAmount = document.querySelector('[data-transfer-destination-amount]');
    const transferExchangeRate = document.querySelector('[data-transfer-exchange-rate]');
    const transferRateDate = document.querySelector('[data-transfer-rate-date]');
    const transferSaveButton = document.querySelector('[data-treasury-transfer-save-button]');
    const treasuryTransferFxModal = document.querySelector('[data-treasury-transfer-fx-modal]');
    const treasuryTransferFxCancelButtons = Array.from(document.querySelectorAll('[data-treasury-transfer-fx-cancel]'));
    const treasuryTransferFxSourceSummary = document.querySelector('[data-treasury-transfer-fx-source-summary]');
    const treasuryTransferFxDestinationSummary = document.querySelector('[data-treasury-transfer-fx-destination-summary]');
    const treasuryTransferFxSourceAmount = document.querySelector('[data-treasury-transfer-fx-source-amount]');
    const treasuryTransferFxRateLabel = document.querySelector('[data-treasury-transfer-fx-rate-label]');
    const treasuryTransferFxRateInput = document.querySelector('[data-treasury-transfer-fx-rate-input]');
    const treasuryTransferFxRateDate = document.querySelector('[data-treasury-transfer-fx-rate-date]');
    const treasuryTransferFxPreview = document.querySelector('[data-treasury-transfer-fx-preview]');
    const treasuryTransferFxFeedback = document.querySelector('[data-treasury-transfer-fx-feedback]');
    const treasuryTransferFxKeepButton = document.querySelector('[data-treasury-transfer-fx-keep]');
    const treasuryTransferFxConvertButton = document.querySelector('[data-treasury-transfer-fx-convert]');
    const transferAccounts = <?= json_encode(array_map(static function (array $account): array {
        return [
            'id' => (int) ($account['id'] ?? 0),
            'branch_id' => (int) ($account['branch_id'] ?? 0),
            'branch_name' => (string) ($account['branch_name'] ?? ''),
            'account_type' => (string) ($account['account_type'] ?? ''),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'account_code' => (string) ($account['account_code'] ?? ''),
            'currency' => (string) ($account['currency'] ?? 'PKR'),
            'is_default' => (int) ($account['is_default'] ?? 0),
            'current_balance' => number_format((float) ($account['current_balance'] ?? 0), 2, '.', ''),
        ];
    }, $transferAccounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const directForm = document.querySelector('[data-treasury-direct-form]');
    const directBranch = document.querySelector('[data-direct-branch]');
    const directType = document.querySelector('[data-direct-type]');
    const directCurrency = document.querySelector('[data-direct-currency]');
    const directAccount = document.querySelector('[data-direct-account]');
    const directCounterpartyLabel = document.querySelector('[data-direct-counterparty-label]');
    const directSaveButton = directForm?.querySelector('button[type="submit"]') || null;
    let treasuryTransferFxState = null;
    const treasuryDefaultTab = <?= json_encode($editAccount ? 'account' : 'transfer', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    treasuryTabTrace('init', {
        message: 'Treasury tab script initialized.',
        tab_button_count: treasuryTabButtons.length,
        tab_panel_count: treasuryTabPanels.length,
        default_tab: treasuryDefaultTab,
        has_transfer_form: transferForm instanceof HTMLFormElement,
        has_direct_form: directForm instanceof HTMLFormElement,
        has_account_form: treasuryForm instanceof HTMLFormElement,
    });

    const activateTreasuryTab = (tabName, persist = true) => {
        const nextTab = treasuryTabButtons.some((button) => button.dataset.treasuryTab === tabName)
            ? tabName
            : treasuryDefaultTab;

        treasuryTabButtons.forEach((button) => {
            const active = button.dataset.treasuryTab === nextTab;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });

        treasuryTabPanels.forEach((panel) => {
            const active = panel.dataset.treasuryTabPanel === nextTab;
            panel.classList.toggle('is-active', active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        treasuryTabTrace('activate', {
            message: 'Treasury tab activation executed.',
            requested_tab: tabName,
            active_tab: nextTab,
            persist,
            visible_panels: treasuryTabPanels
                .filter((panel) => panel.classList.contains('is-active'))
                .map((panel) => String(panel.dataset.treasuryTabPanel || '')),
        });

        if (persist) {
            try {
                window.localStorage.setItem('treasury.accounts.activeTab', nextTab);
            } catch (error) {
                treasuryTabTrace('local_storage_failed', {
                    message: error instanceof Error ? error.message : 'Treasury localStorage write failed.',
                });
                // Ignore storage failures and keep the tab active for this view only.
            }
        }
    };

    treasuryTabButtons.forEach((button, index) => {
        button.addEventListener('click', () => {
            treasuryTabTrace('click', {
                message: 'Treasury tab clicked.',
                clicked_tab: String(button.dataset.treasuryTab || ''),
                index,
            });
            activateTreasuryTab(String(button.dataset.treasuryTab || treasuryDefaultTab));
        });

        button.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') {
                return;
            }

            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % treasuryTabButtons.length;
            } elseif (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + treasuryTabButtons.length) % treasuryTabButtons.length;
            } elseif (event.key === 'Home') {
                nextIndex = 0;
            } elseif (event.key === 'End') {
                nextIndex = treasuryTabButtons.length - 1;
            }

            const nextButton = treasuryTabButtons[nextIndex] || null;
            if (nextButton instanceof HTMLButtonElement) {
                activateTreasuryTab(String(nextButton.dataset.treasuryTab || treasuryDefaultTab));
                nextButton.focus();
            }
        });
    });

    try {
        const rememberedTab = window.localStorage.getItem('treasury.accounts.activeTab') || treasuryDefaultTab;
        treasuryTabTrace('restore', {
            message: 'Restoring treasury tab selection.',
            remembered_tab: rememberedTab,
        });
        activateTreasuryTab(rememberedTab, false);
    } catch (error) {
        treasuryTabTrace('restore_failed', {
            message: error instanceof Error ? error.message : 'Treasury tab restore failed.',
        });
        activateTreasuryTab(treasuryDefaultTab, false);
    }

    const syncBankDetailsVisibility = () => {
        const selectedType = String(treasuryAccountType?.value || '');
        const showBankDetails = selectedType === 'bank' || selectedType === 'wallet';

        bankDetailFields.forEach((field) => {
            field.style.display = showBankDetails ? '' : 'none';
            field.hidden = !showBankDetails;
            field.dataset.bankDetailVisible = showBankDetails ? '1' : '0';
            field.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !showBankDetails;
            });
        });
    };

    treasuryAccountType?.addEventListener('change', syncBankDetailsVisibility);
    syncBankDetailsVisibility();

    const visibleTreasuryFields = () => {
        if (!(treasuryForm instanceof HTMLFormElement)) {
            return [];
        }

        const fields = Array.from(treasuryForm.querySelectorAll('input, select, textarea, button[type="submit"]'));
        return fields.filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            if (field.closest('[hidden]') !== null) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });
    };

    const focusTreasuryField = (field) => {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.focus();
        if ((field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) && typeof field.select === 'function') {
            field.select();
        }
    };

    treasuryForm?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement || target instanceof HTMLButtonElement)) {
            return;
        }

        if (target instanceof HTMLTextAreaElement && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
        } else if (!(target instanceof HTMLButtonElement)) {
            event.preventDefault();
        }

        const fields = visibleTreasuryFields();
        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;

        if (nextField) {
            focusTreasuryField(nextField);
            return;
        }

        if (target instanceof HTMLButtonElement && target.type === 'submit') {
            target.click();
            return;
        }

        if (treasurySaveButton instanceof HTMLButtonElement) {
            treasurySaveButton.focus();
            treasurySaveButton.click();
        }
    }, true);

    const transferOperationConfig = () => {
        const operation = String(transferType?.value || 'cash_deposit_to_bank');
        if (operation === 'bank_withdrawal_to_cash') {
            return {
                fromType: 'bank',
                toType: 'cash',
                fromLabel: 'From Bank Account',
                toLabel: 'To Cash Account',
            };
        }

        if (operation === 'bank_to_bank_transfer') {
            return {
                fromType: 'bank',
                toType: 'bank',
                fromLabel: 'From Bank Account',
                toLabel: 'To Bank Account',
            };
        }

        if (operation === 'cash_to_cash_transfer') {
            return {
                fromType: 'cash',
                toType: 'cash',
                fromLabel: 'From Cash Account',
                toLabel: 'To Cash Account',
            };
        }

        return {
            fromType: 'cash',
            toType: 'bank',
            fromLabel: 'From Cash Account',
            toLabel: 'To Bank Account',
        };
    };

    const syncTransferAccounts = () => {
        if (!(transferFromAccount instanceof HTMLSelectElement) || !(transferToAccount instanceof HTMLSelectElement)) {
            return;
        }

        const config = transferOperationConfig();
        const previousFrom = transferFromAccount.value;
        const previousTo = transferToAccount.value;

        if (transferFromLabel) {
            transferFromLabel.textContent = config.fromLabel;
        }
        if (transferToLabel) {
            transferToLabel.textContent = config.toLabel;
        }

        const buildOptions = (accountType, placeholder) => {
            const matches = transferAccounts.filter((account) =>
                String(account.account_type || '') === accountType
            );

            return {
                options: matches,
                html: ['<option value="">' + placeholder + '</option>'].concat(matches.map((account) => {
                    const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
                    return '<option value="' + String(account.id) + '">' +
                        String(account.account_name) + ' | ' + String(account.branch_name) + ' | ' + String(account.currency) + ' - Bal ' + String(account.current_balance) + suffix +
                        '</option>';
                })).join(''),
            };
        };

        const fromData = buildOptions(config.fromType, 'Select source account');
        const toData = buildOptions(config.toType, 'Select destination account');
        transferFromAccount.innerHTML = fromData.html;
        transferToAccount.innerHTML = toData.html;

        if (fromData.options.some((account) => String(account.id) === previousFrom)) {
            transferFromAccount.value = previousFrom;
        } else if (fromData.options.length === 1) {
            transferFromAccount.value = String(fromData.options[0].id);
        }

        if (toData.options.some((account) => String(account.id) === previousTo)) {
            transferToAccount.value = previousTo;
        } else if (toData.options.length === 1) {
            transferToAccount.value = String(toData.options[0].id);
        }
    };

    const syncTransferDerivedFields = () => {
        const sourceAccountId = Number(transferFromAccount?.value || 0);
        const destinationAccountId = Number(transferToAccount?.value || 0);
        const sourceAccount = transferAccounts.find((account) => Number(account.id || 0) === sourceAccountId) || null;
        const destinationAccount = transferAccounts.find((account) => Number(account.id || 0) === destinationAccountId) || null;

        if (transferSourceBranch instanceof HTMLInputElement) {
            transferSourceBranch.value = sourceAccount ? String(sourceAccount.branch_id || '') : '';
        }
        if (transferDestinationBranch instanceof HTMLInputElement) {
            transferDestinationBranch.value = destinationAccount ? String(destinationAccount.branch_id || '') : '';
        }
        if (transferSourceCurrency instanceof HTMLInputElement) {
            transferSourceCurrency.value = sourceAccount ? String(sourceAccount.currency || '') : '';
        }
        if (transferDestinationCurrency instanceof HTMLInputElement) {
            transferDestinationCurrency.value = destinationAccount ? String(destinationAccount.currency || '') : '';
        }
        if (transferSourceSummary instanceof HTMLInputElement) {
            transferSourceSummary.value = sourceAccount
                ? String(sourceAccount.currency || '')
                : '';
        }
        if (transferDestinationSummary instanceof HTMLInputElement) {
            transferDestinationSummary.value = destinationAccount
                ? String(destinationAccount.currency || '')
                : '';
        }

        if (sourceAccount && destinationAccount && sourceAccount.currency === destinationAccount.currency) {
            if (transferExchangeRate instanceof HTMLInputElement) {
                transferExchangeRate.value = '1';
            }
            if (transferRateDate instanceof HTMLInputElement && !transferRateDate.value) {
                transferRateDate.value = new Date().toISOString().slice(0, 10);
            }
        } else if (transferExchangeRate instanceof HTMLInputElement) {
            transferExchangeRate.value = '';
        }
    };

    const showTreasuryTransferFxFeedback = (message) => {
        if (!(treasuryTransferFxFeedback instanceof HTMLElement)) {
            return;
        }
        treasuryTransferFxFeedback.textContent = message;
        treasuryTransferFxFeedback.hidden = message === '';
    };

    const closeTreasuryTransferFxModal = () => {
        if (!(treasuryTransferFxModal instanceof HTMLElement)) {
            return;
        }
        treasuryTransferFxModal.hidden = true;
        treasuryTransferFxModal.setAttribute('aria-hidden', 'true');
        showTreasuryTransferFxFeedback('');
    };

    const treasuryTransferFxContext = () => {
        const sourceAccountId = Number(transferFromAccount?.value || 0);
        const destinationAccountId = Number(transferToAccount?.value || 0);
        const sourceAccount = transferAccounts.find((account) => Number(account.id || 0) === sourceAccountId) || null;
        const destinationAccount = transferAccounts.find((account) => Number(account.id || 0) === destinationAccountId) || null;
        if (!sourceAccount || !destinationAccount) {
            return null;
        }

        const sourceCurrency = String(sourceAccount.currency || '').toUpperCase();
        const destinationCurrency = String(destinationAccount.currency || '').toUpperCase();
        const sourceAmount = Math.max(Number(transferSourceAmount?.value || 0), 0);

        return {
            sourceAccount,
            destinationAccount,
            sourceCurrency,
            destinationCurrency,
            sourceAmount,
        };
    };

    const treasuryTransferFxRateMeta = (sourceCurrency, destinationCurrency) => {
        if ((sourceCurrency === 'AED' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'AED')) {
            return {
                baseCurrency: 'AED',
                quoteCurrency: 'PKR',
            };
        }
        if ((sourceCurrency === 'USD' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'USD')) {
            return {
                baseCurrency: 'USD',
                quoteCurrency: 'PKR',
            };
        }

        return {
            baseCurrency: sourceCurrency,
            quoteCurrency: destinationCurrency,
        };
    };

    const computeTreasuryTransferConvertedAmount = (context, rate) => {
        const meta = treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
        const sourceAmount = context.sourceAmount;
        if (sourceAmount <= 0 || rate <= 0) {
            return 0;
        }

        if (context.sourceCurrency === meta.baseCurrency && context.destinationCurrency === meta.quoteCurrency) {
            return Math.round(sourceAmount * rate);
        }
        if (context.sourceCurrency === meta.quoteCurrency && context.destinationCurrency === meta.baseCurrency) {
            return Math.round(sourceAmount / rate);
        }

        return Math.round(sourceAmount * rate);
    };

    const refreshTreasuryTransferFxModal = () => {
        if (!(treasuryTransferFxState && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxPreview instanceof HTMLElement)) {
            return;
        }

        const rate = Math.max(Number(treasuryTransferFxRateInput.value || 0), 0);
        const convertedAmount = computeTreasuryTransferConvertedAmount(treasuryTransferFxState, rate);
        const sourceCurrency = treasuryTransferFxState.sourceCurrency;
        const destinationCurrency = treasuryTransferFxState.destinationCurrency;

        if (rate <= 0) {
            treasuryTransferFxPreview.textContent = 'Enter the exchange rate, then choose whether to keep the same amount or convert it.';
            return;
        }

        treasuryTransferFxPreview.textContent = 'Converted amount: '
            + convertedAmount.toLocaleString()
            + ' '
            + destinationCurrency
            + ' from '
            + treasuryTransferFxState.sourceAmount.toLocaleString()
            + ' '
            + sourceCurrency
            + '.';
    };

    const openTreasuryTransferFxModal = () => {
        const context = treasuryTransferFxContext();
        if (!context || context.sourceCurrency === context.destinationCurrency || context.sourceAmount <= 0) {
            return;
        }
        if (!(treasuryTransferFxModal instanceof HTMLElement && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }

        treasuryTransferFxState = context;
        const rateMeta = treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
        if (treasuryTransferFxSourceSummary instanceof HTMLElement) {
            treasuryTransferFxSourceSummary.textContent = String(context.sourceAccount.account_name || '') + ' / ' + String(context.sourceAccount.branch_name || '') + ' / ' + context.sourceCurrency;
        }
        if (treasuryTransferFxDestinationSummary instanceof HTMLElement) {
            treasuryTransferFxDestinationSummary.textContent = String(context.destinationAccount.account_name || '') + ' / ' + String(context.destinationAccount.branch_name || '') + ' / ' + context.destinationCurrency;
        }
        if (treasuryTransferFxSourceAmount instanceof HTMLElement) {
            treasuryTransferFxSourceAmount.textContent = context.sourceAmount.toLocaleString() + ' ' + context.sourceCurrency;
        }
        if (treasuryTransferFxRateLabel instanceof HTMLElement) {
            treasuryTransferFxRateLabel.textContent = 'Exchange Rate (1 ' + rateMeta.baseCurrency + ' = ? ' + rateMeta.quoteCurrency + ')';
        }

        treasuryTransferFxRateInput.value = transferExchangeRate instanceof HTMLInputElement ? String(transferExchangeRate.value || '') : '';
        treasuryTransferFxRateDate.value = transferRateDate instanceof HTMLInputElement && transferRateDate.value
            ? transferRateDate.value
            : new Date().toISOString().slice(0, 10);
        refreshTreasuryTransferFxModal();
        showTreasuryTransferFxFeedback('');
        treasuryTransferFxModal.hidden = false;
        treasuryTransferFxModal.setAttribute('aria-hidden', 'false');
        treasuryTransferFxRateInput.focus();
    };

    const syncTransferAmounts = () => {
        if (!(transferSourceAmount instanceof HTMLInputElement) || !(transferDestinationAmount instanceof HTMLInputElement) || !(transferExchangeRate instanceof HTMLInputElement)) {
            return;
        }

        const sourceCurrency = String((transferSourceCurrency instanceof HTMLInputElement ? transferSourceCurrency.value : '') || 'PKR').trim().toUpperCase();
        const destinationCurrency = String((transferDestinationCurrency instanceof HTMLInputElement ? transferDestinationCurrency.value : '') || 'PKR').trim().toUpperCase();
        const sourceAmountValue = transferSourceAmount.value;
        const sourceAmountNumber = Number(sourceAmountValue || 0);
        const exchangeRateNumber = Number(transferExchangeRate.value || 0);
        const storedAmount = transferForm?.querySelector('input[name="amount"]');

        if (sourceCurrency === destinationCurrency) {
            transferExchangeRate.value = sourceAmountValue !== '' ? '1' : transferExchangeRate.value;
            transferDestinationAmount.value = sourceAmountValue;
        } else {
            if (sourceAmountNumber > 0 && exchangeRateNumber > 0) {
                const context = treasuryTransferFxContext();
                if (context) {
                    transferDestinationAmount.value = String(computeTreasuryTransferConvertedAmount(context, exchangeRateNumber));
                }
            }
        }

        if (storedAmount instanceof HTMLInputElement) {
            storedAmount.value = sourceAmountValue;
        }
    };

    transferType?.addEventListener('change', syncTransferAccounts);
    transferFromAccount?.addEventListener('change', () => {
        syncTransferDerivedFields();
        syncTransferAmounts();
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    transferToAccount?.addEventListener('change', () => {
        syncTransferDerivedFields();
        syncTransferAmounts();
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    transferSourceAmount?.addEventListener('input', syncTransferAmounts);
    transferSourceAmount?.addEventListener('blur', () => {
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    treasuryTransferFxCancelButtons.forEach((button) => button.addEventListener('click', closeTreasuryTransferFxModal));
    treasuryTransferFxRateInput?.addEventListener('input', refreshTreasuryTransferFxModal);
    treasuryTransferFxKeepButton?.addEventListener('click', () => {
        const context = treasuryTransferFxContext();
        if (!(context && transferDestinationAmount instanceof HTMLInputElement && transferExchangeRate instanceof HTMLInputElement && transferRateDate instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }
        transferDestinationAmount.value = String(Math.round(context.sourceAmount));
        transferExchangeRate.value = '';
        transferRateDate.value = treasuryTransferFxRateDate.value || transferRateDate.value;
        closeTreasuryTransferFxModal();
    });
    treasuryTransferFxConvertButton?.addEventListener('click', () => {
        const context = treasuryTransferFxContext();
        if (!(context && transferDestinationAmount instanceof HTMLInputElement && transferExchangeRate instanceof HTMLInputElement && transferRateDate instanceof HTMLInputElement && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }
        const rate = Math.max(Number(treasuryTransferFxRateInput.value || 0), 0);
        if (rate <= 0) {
            showTreasuryTransferFxFeedback('Enter a valid exchange rate first.');
            treasuryTransferFxRateInput.focus();
            return;
        }
        transferDestinationAmount.value = String(computeTreasuryTransferConvertedAmount(context, rate));
        transferExchangeRate.value = String(rate.toFixed(2));
        transferRateDate.value = treasuryTransferFxRateDate.value || transferRateDate.value;
        closeTreasuryTransferFxModal();
    });
    transferForm?.addEventListener('submit', (event) => {
        const context = treasuryTransferFxContext();
        if (!context || context.sourceCurrency === context.destinationCurrency) {
            return;
        }
        const rate = Math.max(Number((transferExchangeRate instanceof HTMLInputElement ? transferExchangeRate.value : '') || 0), 0);
        const destinationAmount = Math.max(Number((transferDestinationAmount instanceof HTMLInputElement ? transferDestinationAmount.value : '') || 0), 0);
        if (rate <= 0 || destinationAmount <= 0) {
            event.preventDefault();
            openTreasuryTransferFxModal();
        }
    });
    syncTransferAccounts();
    syncTransferDerivedFields();
    syncTransferAmounts();

    const syncDirectAccounts = () => {
        if (!(directAccount instanceof HTMLSelectElement)) {
            return;
        }

        const branchId = Number(directBranch?.value || 0);
        const currency = String(directCurrency?.value || 'PKR').trim().toUpperCase();
        const previousValue = directAccount.value;

        const matches = transferAccounts.filter((account) =>
            Number(account.branch_id || 0) === branchId
            && String(account.currency || '').toUpperCase() === currency
        );

        directAccount.innerHTML = '<option value="">Select treasury account</option>'
            + matches.map((account) => {
                const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
                return '<option value="' + String(account.id) + '">' +
                    String(account.account_name) + ' - Bal ' + String(account.current_balance) + suffix +
                    '</option>';
            }).join('');

        if (matches.some((account) => String(account.id) === previousValue)) {
            directAccount.value = previousValue;
        } else if (matches.length === 1) {
            directAccount.value = String(matches[0].id);
        }
    };

    const syncDirectLabels = () => {
        if (directCounterpartyLabel) {
            directCounterpartyLabel.textContent = String(directType?.value || '') === 'adjustment_decrease' ? 'Paid To' : 'Received From';
        }
    };

    [directBranch, directCurrency].forEach((field) => field?.addEventListener('change', syncDirectAccounts));
    directType?.addEventListener('change', syncDirectLabels);
    syncDirectAccounts();
    syncDirectLabels();

    transferForm?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement || target instanceof HTMLButtonElement)) {
            return;
        }

        if (target instanceof HTMLTextAreaElement && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
        } else if (!(target instanceof HTMLButtonElement)) {
            event.preventDefault();
        }

        const fields = Array.from(transferForm.querySelectorAll('input, select, textarea, button[type="submit"]')).filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });

        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;
        if (nextField instanceof HTMLElement) {
            nextField.focus();
            if ((nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) && typeof nextField.select === 'function') {
                nextField.select();
            }
            return;
        }

        if (transferSaveButton instanceof HTMLButtonElement) {
            transferSaveButton.focus();
            transferSaveButton.click();
        }
    }, true);

    directForm?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement || target instanceof HTMLButtonElement)) {
            return;
        }

        if (target instanceof HTMLTextAreaElement && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
        } else if (!(target instanceof HTMLButtonElement)) {
            event.preventDefault();
        }

        const fields = Array.from(directForm.querySelectorAll('input, select, textarea, button[type="submit"]')).filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });

        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;
        if (nextField instanceof HTMLElement) {
            nextField.focus();
            if ((nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) && typeof nextField.select === 'function') {
                nextField.select();
            }
            return;
        }

        if (directSaveButton instanceof HTMLButtonElement) {
            directSaveButton.focus();
            directSaveButton.click();
        }
    }, true);
});
