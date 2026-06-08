<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
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
    'Workspace payment actions markup is present',
    $containsAll($workspaceView, [
        'data-payment-treasury-select',
        'data-payment-submit-action="save"',
        'data-payment-action="print-receipt"',
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
    'Exchange settlement modal flow is present',
    $containsAll($workspaceJs, [
        'async function openExchangeSettlementModal(options = {})',
        'allowManualRatePreview',
        'settlement_mode',
    ])
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
        'Please configure/select a cash or bank account for this payment.',
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
    exit(1);
}

echo PHP_EOL . 'Workspace/payment readiness checks passed.' . PHP_EOL;
