<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];
$warnings = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$warn = static function (string $label, bool $passed, string $details = '') use (&$warnings): void {
    echo ($passed ? '[PASS] ' : '[WARN] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $warnings[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         LIMIT 1'
    );
    $statement->execute(['table_name' => $table]);

    return $statement->fetchColumn() !== false;
};

$columnExists = static function (string $table, string $column) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $statement->fetchColumn() !== false;
};

$indexExists = static function (string $table, string $indexName) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    return $statement->fetchColumn() !== false;
};

$foreignKeyExists = static function (string $table, string $foreignKeyName) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = :table_name
           AND constraint_name = :constraint_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'constraint_name' => $foreignKeyName,
    ]);

    return $statement->fetchColumn() !== false;
};

$containsAll = static function (string $path, array $needles): bool {
    if (! is_file($path)) {
        return false;
    }

    $source = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (! str_contains($source, $needle)) {
            return false;
        }
    }

    return true;
};

echo 'Workspace and payment readiness audit' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$workspaceView = BASE_PATH . '/app/Views/workspace/partials/station.php';
$workspaceJs = BASE_PATH . '/public/assets/js/workspace.js';
$appCss = BASE_PATH . '/public/assets/css/app.css';
$receiptService = BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php';
$customerPaymentRepository = BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php';
$treasuryRepository = BASE_PATH . '/app/Repositories/TreasuryRepository.php';
$bookingRepository = BASE_PATH . '/app/Repositories/BookingRepository.php';
$publicIndex = BASE_PATH . '/public/index.php';
$receiptOutputView = BASE_PATH . '/app/Views/workspace/output.php';
$nobleRouteSignature = BASE_PATH . '/public/assets/images/receipt-branches/noble-route-signature.png';

foreach ([
    'Workspace view exists' => $workspaceView,
    'Workspace script exists' => $workspaceJs,
    'Application stylesheet exists' => $appCss,
    'Customer receipt workspace service exists' => $receiptService,
    'Customer payment repository exists' => $customerPaymentRepository,
    'Treasury repository exists' => $treasuryRepository,
    'Booking repository exists' => $bookingRepository,
    'Public router exists' => $publicIndex,
] as $label => $path) {
    $check($label, is_file($path), $path);
}

$check(
    'Workspace quick search markup is present',
    $containsAll($workspaceView, [
        'id="workspace-search-form"',
        'id="workspace-search"',
        'data-workspace-search-submit',
    ])
);
$check(
    'Operating branch selector is prominent and connected to the booking branch authority',
    $containsAll($workspaceView, [
        'data-operating-branch-control',
        'data-operating-branch-radio',
        'data-operating-branch-status',
        'id="legacy-invoice-header-form"',
        'name="branch_id"',
    ])
);
$check(
    'Operating branch selector synchronizes financial defaults and confirms existing-booking moves',
    $containsAll($workspaceJs, [
        'syncOperatingBranchControl',
        'bookingBranchField.dispatchEvent(new Event(\'change\'',
        'Move ${bookingReference} from ${previousName} to ${nextName}?',
        'A conflicting cash/bank transaction will block the save.',
        'currentBookingId() <= 0',
    ])
);
$check(
    'Branch currency changes preserve manual selections and reject raw amount relabelling',
    $containsAll($workspaceJs, [
        'markBranchCurrencyDefault',
        'markBranchCurrencyManual',
        'initializeBranchCurrencyModes',
        'validateBranchCurrencyTransition',
        'transitionBranchCurrencies',
        "mode: 'branch-currency-transition'",
        'remained identical or suspiciously close',
        'Default currencies and entered amounts were converted safely; manually selected currencies were retained.',
    ])
        && $containsAll($workspaceView, [
            'data-pricing-exchange-title',
            'data-pricing-exchange-subtitle',
            'data-pricing-exchange-currency-label',
        ])
);
$check(
    'Operating branch selector has compact active and branch-specific visual states',
    $containsAll($appCss, [
        '.workspace-operating-branch {',
        '.workspace-operating-branch__option--noble',
        '.workspace-operating-branch__option.is-active',
        '.legacy-workspace.has-operating-branch-control .legacy-invoice-header__branch',
    ])
);
$check(
    'Workspace payment actions markup is present',
    $containsAll($workspaceView, [
        'data-payment-treasury-select',
        'data-payment-submit-action="save"',
        'data-payment-action="print-receipt"',
    ])
);
$check(
    'Workspace payment scope controls support whole-invoice and passenger-specific receipt targeting',
    $containsAll($workspaceView, [
        'name="receipt_scope"',
        'data-payment-receipt-scope',
        'name="target_receivable_item_id"',
        'data-payment-target-receivable',
    ])
        && $containsAll($workspaceJs, [
            'const paymentReceiptScopeSelect =',
            'const paymentTargetReceivableSelect =',
            'const currentBookingReceivableTargets = (currency = \'\') => {',
            'const syncReceiptScopeTargets = () => {',
        ])
);
$check(
    'Booking supplier payable table supports select all',
    $containsAll($workspaceView, [
        'data-simple-postpaid-select-all',
        'data-simple-postpaid-select',
    ])
        && $containsAll($workspaceJs, [
            'const simplePostpaidSelectAll =',
            'simplePostpaidSelectAll.indeterminate',
            'simplePostpaidSelectors.forEach((input) => {',
        ])
);
$check(
    'Add supplier popup is wide enough for one-row entry',
    $containsAll($workspaceView, [
        'data-service-supplier-add-modal',
        'max-width:1100px',
    ])
        && $containsAll($appCss, [
            'customer-picker-modal[data-service-supplier-add-modal] .customer-picker-modal__dialog',
            'width: min(1080px, calc(100vw - 44px))',
        ])
);
$check(
    'Saved supplier changes autosave without a redundant action button',
    ! str_contains((string) file_get_contents($workspaceView), 'data-service-supplier-save')
        && $containsAll($workspaceJs, [
            'const hasSupplierSettlement =',
            'if (hasSupplierSettlement) {',
            'await persistServiceAutosave();',
            'Supplier updated to ${newSupplier}. Payables and financial reports were synchronized.',
        ])
);
$check(
    'Workspace quick search script binds to the real form',
    $containsAll($workspaceJs, [
        "const quickSearchForm = station.querySelector('#workspace-search-form');",
        "quickSearchForm?.querySelector('input[name=\"q\"]')",
        "quickSearchForm?.querySelector('[data-workspace-search-submit]')",
    ])
);
$check(
    'Workspace payment treasury selector script is present',
    $containsAll($workspaceJs, [
        "namedItem('treasury_account_id')",
        'const paymentTreasuryAccountSelect =',
        'const paymentTreasuryAccountRow =',
    ])
);
$check(
    'Workspace payment save persists a new draft service before reusing existing booking payment state',
    $containsAll($workspaceJs, [
        'const currentServiceDraftRequiresPersistBeforePayment = () => {',
        'const draftNeedsPersist = currentServiceDraftRequiresPersistBeforePayment();',
        "window.workspaceDebugEnterFlow('payment-save-commit-draft-skip-existing'",
    ])
);
$check(
    'Exchange settlement modal flow is present',
    $containsAll($workspaceJs, [
        'async function openExchangeSettlementModal(options = {})',
        'allowManualRatePreview',
        'settlement_mode',
    ])
);
$check(
    'Service pricing supports independent supplier, agency, and invoice currencies',
    $containsAll($workspaceView, [
        'name="cost_currency"',
        'name="service_charge_currency"',
        'name="currency"',
        'data-pricing-exchange-modal',
        'data-pricing-exchange-confirm',
        'Confirm Rates &amp; Continue',
    ])
        && $containsAll($workspaceJs, [
            'currentPricingExchangeRequirements',
            'currentServiceChargeCurrencyCode',
            'currentServiceChargeExchangeRate',
            'initializePricingCurrencyTracking',
            'convertEnteredAmountsForCurrencyChange',
            'invoiceCurrencyChanged || costCurrencyChanged',
            'Changing Cost',
            '[airlinePayableFinancialField, ticketValueField]',
            'formatMoney(convertedPayable)',
            'currentFinancialSummaryCurrencyCode',
            'data-financial-summary-currency',
            'financialSummaryAmount',
            'ensureFinancialSummaryExchangeRateReady',
            'Confirm Financial Summary Exchange Rate',
            'const resolvedRate = resolvePricingExchangeRateFromMap(',
            'currentPricingRateEffectiveDate()',
            'const pricingExchangeConfirmedPairs = new Set()',
            'sourceToInvoiceConfirmedRate',
            'pricingExchangeConfirmedPairs.add(pricingExchangePairKey(requirement))',
            "event.key === 'Enter'",
            "event.code === 'NumpadEnter'",
            'void confirmPricingExchangeRates({ restoreOnFailure: true })',
            'closePricingExchangeModal(true)',
            'setPricingExchangeModalOpen(false)',
            'restoreOnFailure: true',
            "pricingExchangeModal?.addEventListener('keydown', confirmPricingExchangeOnEnter, true)",
            'key: `amount-currency:${role}:${previousCurrency}->${selectedCurrency}`',
            'roundToTwo(originalAmount * sourceToSelectedRate)',
            "mode: 'pricing-amount-currency-transition'",
            'A blank amount does not prove that two different currencies have a',
            'supplier-cost-amount-entered',
            'agency-amount-entered',
            "reason: 'service-save'",
            'allowPrompt: false',
            'pricing-rate-missing-without-prompt',
        ])
        && $columnExists('booking_services', 'service_charge_currency')
        && $columnExists('booking_services', 'service_charge_exchange_rate')
        && $columnExists('booking_services', 'service_charge_rate_effective_date')
);
$check(
    'Changing supplier cost currency preserves the nominal Mkt.Fare and refreshes the service-currency summary',
    $containsAll($workspaceJs, [
        'serviceChargeCurrencyChanged',
        'invoiceCurrencyChanged || costCurrencyChanged',
        'Mkt.Fare is the supplier\'s nominal amount',
        'const currentFinancialSummaryCurrencyCode = () => currentServiceChargeCurrencyCode();',
        'costCurrencyChanged || serviceChargeCurrencyChanged || supplierCostAmountChanged',
        'formatMoney(convertedPayable)',
        "refreshProfit('branch-context-updated')",
        'syncFinancialSummaryCurrencyLabel();',
    ])
        && !str_contains(
            $workspaceJs,
            '(costCurrencyChanged || serviceChargeCurrencyChanged)\n                    && previousPricingCurrency !== selectedPricingCurrency'
        )
);
$check(
    'Workspace restore flow is present',
    $containsAll($workspaceJs, [
        'const restoreFormState = (form, entries = [], options = {}) => {',
        'workspace:customer-selected',
    ])
);
$check(
    'Customer receipt service enforces treasury resolution',
    $containsAll($receiptService, [
        'public function saveReceipt(array $input, int $actorUserId, array $accessibleBranchIds): array',
        'private function saveExchangeSettlement(array $input, int $actorUserId, array $accessibleBranchIds): array',
        'private function resolveReceiptTreasuryAccountId(array $input, array $payload, int $branchId): ?int',
        'private const ALLOWED_RECEIPT_SCOPES = [\'whole_invoice\', \'passenger_specific\'];',
        'private function shouldPostReceiptAccounting(array $payload): bool',
        'resolveReceiptAllocationTargetId(',
        'Please configure/select a cash or bank account for this payment.',
    ])
);
$check(
    'Zero-value receipt saves skip empty accounting posts',
    $containsAll($receiptService, [
        'if ($this->shouldPostReceiptAccounting($payload)) {',
        'postCustomerReceiptRecorded([',
    ])
);
$check(
    'Customer payment repository persists treasury account links',
    $containsAll($customerPaymentRepository, [
        'treasury_account_id',
        'LEFT JOIN treasury_accounts ta ON ta.id = customer_receipts.treasury_account_id',
    ])
);
$check(
    'Quick search backend includes receipt search',
    $containsAll($bookingRepository, [
        'latest_receipt_no',
        'crx.receipt_no LIKE ?',
    ])
);
$check(
    'Workspace receipt-save route is registered',
    $containsAll($publicIndex, [
        '/workspace/payments/receipts/save',
        'saveReceipt',
    ])
);
$check(
    'Add Service saves the current service without creating zero-value receipt rows',
    $containsAll($workspaceJs, [
        "workflowOrigin: 'add_service'",
        'suppressReceiptCreation: enteredPaymentAmount <= 0.005',
        "receipt_action: suppressReceiptCreation ? 'no_receipt' : 'save'",
    ])
);
$check(
    'Receipt printing avoids blank reservations and is guarded against duplicate opens',
    $containsAll($workspaceJs, [
        'const openCustomerReceiptWindowOnce =',
        'window.__travelReceiptOpenGuard',
        'window.__travelReceiptClickGuard',
        'receipt-open:calling-window-open',
        'print-receipt:click-handler-entered',
        'print-receipt:blocked-duplicate-click',
        'event.stopImmediatePropagation();',
        "receipt-open:blocked-duplicate",
        'Printing an existing receipt is strictly read-only.',
        "openCustomerReceiptWindowOnce('print-existing')",
        "openCustomerReceiptWindowOnce('print-after-save')",
        'print-receipt:save-before-open:cancelled-or-failed',
        "typeof window.travelLauncher.config === 'function'",
        'receipt-open:launcher-child-managed',
        'window.location.assign(normalizedUrl)',
        'autoOpenReceipt: true',
    ])
        && ! str_contains($workspaceJs, "window.open('about:blank', '_blank')")
);
$check(
    'Noble Route receipt signature is embedded and loaded before automatic printing',
    is_file($nobleRouteSignature)
        && filesize($nobleRouteSignature) > 0
        && $containsAll($receiptOutputView, [
            "str_contains(\$primaryBranchIdentity, 'noble route')",
            "'data:image/png;base64,' . base64_encode",
            'Array.from(document.images)',
            'document.fonts.ready',
            'window.print();',
        ])
);

$check('Treasury accounts table exists', $tableExists('treasury_accounts'));
$check('Treasury transactions table exists', $tableExists('treasury_transactions'));
$check('Customer receipts table exists', $tableExists('customer_receipts'));
$check(
    'Customer receipts treasury link column exists',
    $tableExists('customer_receipts') && $columnExists('customer_receipts', 'treasury_account_id')
);
$check(
    'Customer receipts treasury link index exists',
    $tableExists('customer_receipts') && $indexExists('customer_receipts', 'idx_customer_receipts_treasury_account')
);
$check(
    'Customer receipts treasury link foreign key exists',
    $tableExists('customer_receipts') && $foreignKeyExists('customer_receipts', 'fk_customer_receipts_treasury_account')
);

if ($tableExists('payment_methods')) {
    $requiredPaymentMethods = ['cash', 'bank_transfer', 'debit_card', 'credit_card'];
    $statement = $db->prepare(
        'SELECT code
         FROM payment_methods
         WHERE code IN (' . implode(',', array_fill(0, count($requiredPaymentMethods), '?')) . ')'
    );
    $statement->execute($requiredPaymentMethods);
    $foundMethods = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $missingMethods = array_values(array_diff($requiredPaymentMethods, $foundMethods));
    $check(
        'Required customer payment methods exist',
        $missingMethods === [],
        $missingMethods !== [] ? implode(', ', $missingMethods) : ''
    );
}

if (
    $tableExists('customer_receipts')
    && $columnExists('customer_receipts', 'treasury_account_id')
    && $tableExists('treasury_accounts')
) {
    $brokenTreasuryLinks = $db->query(
        'SELECT
            cr.id,
            cr.receipt_no,
            cr.payment_method,
            cr.branch_id,
            cr.currency,
            cr.treasury_account_id,
            ta.branch_id AS treasury_branch_id,
            ta.currency AS treasury_currency,
            ta.is_active AS treasury_active
         FROM customer_receipts cr
         LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
         WHERE cr.status <> "void"
           AND cr.payment_method IN ("cash", "bank_transfer")
           AND cr.treasury_account_id IS NOT NULL
           AND (
                ta.id IS NULL
                OR ta.is_active <> 1
                OR ta.branch_id <> cr.branch_id
                OR ta.currency <> cr.currency
           )
         LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $check(
        'Treasury-linked customer receipts point to valid active branch/currency-matching accounts',
        $brokenTreasuryLinks === [],
        $brokenTreasuryLinks !== [] ? json_encode($brokenTreasuryLinks, JSON_UNESCAPED_SLASHES) : ''
    );

    $missingTreasuryLinks = $db->query(
        'SELECT COUNT(*)
         FROM customer_receipts
         WHERE status <> "void"
           AND payment_method IN ("cash", "bank_transfer")
           AND treasury_account_id IS NULL'
    )->fetchColumn();

    $warn(
        'Cash and bank-transfer receipts are treasury-linked',
        (int) $missingTreasuryLinks === 0,
        (int) $missingTreasuryLinks . ' receipts missing treasury account link'
    );
}

if ($warnings !== []) {
    echo PHP_EOL . 'Warnings:' . PHP_EOL;
    foreach ($warnings as $warning) {
        echo ' - ' . $warning . PHP_EOL;
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Workspace/payment readiness checks failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Workspace/payment readiness checks passed.' . PHP_EOL;
return 0;
