<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

echo 'Automatic linked-party settlement regression' . PHP_EOL;
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$actorId = (int) $db->query('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin") ORDER BY (r.code = "super_admin") DESC, u.id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $actorId <= 0) {
    throw new RuntimeException('An active branch and financial administrator are required.');
}

$beforeTreasury = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')
        ->execute(['code' => 'QA-AUTO-' . $suffix, 'name' => 'QA Auto Account ' . $suffix]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active) VALUES (:branch, :code, :name, "normal_payable", "PKR", 1)')
        ->execute(['branch' => $branchId, 'code' => 'QA-AUTO-S-' . $suffix, 'name' => 'QA Auto Supplier ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id) VALUES (:business, :supplier, :user)')
        ->execute(['business' => $businessSourceId, 'supplier' => $supplierId, 'user' => $actorId]);

    $saleBooking = 'QA-AUTO-SALE-' . $suffix;
    $purchaseBooking = 'QA-AUTO-BUY-' . $suffix;
    foreach ([[$saleBooking, $businessSourceId], [$purchaseBooking, $businessSourceId]] as [$reference, $accountId]) {
        $db->prepare('INSERT INTO bookings (booking_reference, branch_id, business_source_id, booking_status, booking_date, created_by_user_id, updated_by_user_id) VALUES (:reference, :branch, :business, "open", CURDATE(), :created_user, :updated_user)')
            ->execute(['reference' => $reference, 'branch' => $branchId, 'business' => $accountId, 'created_user' => $actorId, 'updated_user' => $actorId]);
    }
    $db->prepare('INSERT INTO customer_receivable_items (branch_id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status, created_by_user_id) VALUES (:branch, :reference, "SV-001", "PKR", 45000, 15000, 30000, "partially_paid", :user)')
        ->execute(['branch' => $branchId, 'reference' => $saleBooking, 'user' => $actorId]);
    $receivableId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id) VALUES (:supplier, :branch, :reference, "SV-001", "PKR", 100000, 0, 100000, "open", :user)')
        ->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $purchaseBooking, 'user' => $actorId]);
    $obligationId = (int) $db->lastInsertId();

    $service = new \App\Services\CounterpartyOffsetService($app);
    $settlements = $service->autoSettleForFinancialContext(
        $businessSourceId,
        0,
        $branchId,
        ['PKR'],
        date('Y-m-d'),
        $actorId
    );
    $receivable = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetchColumn();
    $payable = $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn();

    $check('Matching balances are not adjusted automatically', $settlements === []);
    $check('Customer receipt does not reduce the independent account-holder sale balance', abs((float) $receivable - 30000) <= 0.005);
    $check('Supplier payable remains PKR 100,000', abs((float) $payable - 100000) <= 0.005);
    $linkRepository = new \App\Repositories\CounterpartyLinkRepository($app);
    $positions = $linkRepository->openLedgerPositions($businessSourceId, $supplierId, [$branchId], 'PKR');
    $check(
        'Linked ledgers show separate PKR 30,000 receivable and PKR 100,000 payable',
        count($positions) === 1
            && abs((float) ($positions[0]['customer_receivable'] ?? 0) - 30000) <= 0.005
            && abs((float) ($positions[0]['supplier_payable'] ?? 0) - 100000) <= 0.005
    );
    $check(
        'Linked ledger closing balances respect currency filters without converting amounts',
        $linkRepository->openLedgerPositions($businessSourceId, $supplierId, [$branchId], 'AED') === []
    );
    $advanceReference = 'QA-LINK-ADV-' . $suffix;
    $db->prepare('INSERT INTO supplier_advances (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, received_at)
                  VALUES (:supplier, :branch, "PKR", 500, 500, :reference, CURDATE())')
        ->execute(['supplier' => $supplierId, 'branch' => $branchId, 'reference' => $advanceReference]);
    $supplierLedger = (new \App\Repositories\ReportRepository($app))->supplierLedger(
        [$branchId], null, null, 'PKR', '', 0, $businessSourceId, ''
    );
    $linkedAdvanceRows = array_values(array_filter(
        $supplierLedger,
        static fn (array $row): bool => (string) ($row['source_reference'] ?? '') === $advanceReference
    ));
    $check(
        'Supplier ledger Account filter includes the linked supplier without a posted offset',
        count($linkedAdvanceRows) === 1
            && (int) ($linkedAdvanceRows[0]['supplier_id'] ?? 0) === $supplierId
    );
    $reportService = new \App\Services\ReportService($app);
    $linkedStatement = (new \App\Services\LinkedPartyLedgerService($app))->buildReport(
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'entry_date_raw' => date('Y-m-d'),
            'currency' => 'PKR',
            'ledger_entry' => 'Customer invoice',
            'is_financial_position_only' => true,
            'raw_debit_amount' => 800,
            'raw_credit_amount' => 0,
        ]],
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'ledger_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'entry_type' => 'Supplier invoice',
            'raw_debit_amount' => 1000,
            'raw_credit_amount' => 0,
        ]],
        null,
        null
    );
    $check(
        'One account statement shows customer sale and supplier purchase with PKR 200 payable',
        count($linkedStatement['rows']) === 2
            && count($linkedStatement['footerRows']) === 1
            && (float) $linkedStatement['footerRows'][0]['balance_amount'] === -200.0
    );
    $linkedSupplierStatement = (new \App\Services\LinkedPartyLedgerService($app))->buildSupplierReport(
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'entry_date_raw' => date('Y-m-d'),
            'currency' => 'PKR',
            'ledger_entry' => 'Customer invoice',
            'is_financial_position_only' => true,
            'raw_debit_amount' => 800,
            'raw_credit_amount' => 0,
        ]],
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'ledger_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'entry_type' => 'Supplier invoice',
            'raw_debit_amount' => 1000,
            'raw_credit_amount' => 0,
        ]],
        null,
        null,
        '',
        'Linked Supplier'
    );
    $check(
        'Linked Supplier Ledger shows PKR 1,000 purchased and PKR 800 sold as PKR 200 payable',
        count($linkedSupplierStatement['rows']) === 2
            && (float) str_replace(',', '', $linkedSupplierStatement['footerRows'][0]['debit_amount']) === 1000.0
            && (float) str_replace(',', '', $linkedSupplierStatement['footerRows'][0]['credit_amount']) === 800.0
            && (float) str_replace(',', '', $linkedSupplierStatement['footerRows'][0]['balance_amount']) === 200.0
    );
    $linkedSupplierReceivable = (new \App\Services\LinkedPartyLedgerService($app))->buildSupplierReport(
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'entry_date_raw' => date('Y-m-d'),
            'currency' => 'PKR',
            'ledger_entry' => 'Customer invoice',
            'is_financial_position_only' => true,
            'raw_debit_amount' => 1200,
            'raw_credit_amount' => 0,
        ]],
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'ledger_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'entry_type' => 'Supplier invoice',
            'raw_debit_amount' => 1000,
            'raw_credit_amount' => 0,
        ]],
        null,
        null,
        '',
        'Linked Supplier'
    );
    $check(
        'Linked Supplier Ledger calls a negative PKR 200 position receivable rather than supplier advance',
        (float) str_replace(',', '', $linkedSupplierReceivable['footerRows'][0]['balance_amount']) === -200.0
            && str_contains((string) $linkedSupplierReceivable['summaryCards'][2]['value'], 'Receivable')
            && ! str_contains((string) $linkedSupplierReceivable['summaryCards'][2]['value'], 'Advance')
    );
    $paymentStatement = (new \App\Services\LinkedPartyLedgerService($app))->buildReport(
        [[
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'entry_date_raw' => '2026-01-01',
            'currency' => 'PKR', 'ledger_entry' => 'Customer invoice', 'is_financial_position_only' => true,
            'raw_debit_amount' => 800, 'raw_credit_amount' => 0,
        ], [
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'entry_date_raw' => '2026-01-03',
            'currency' => 'PKR', 'ledger_entry' => 'Customer payment received',
            'raw_debit_amount' => 200, 'raw_credit_amount' => 0,
        ]],
        [[
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'ledger_date' => '2026-01-02',
            'currency' => 'PKR', 'entry_type' => 'Supplier invoice recorded',
            'raw_debit_amount' => 1000, 'raw_credit_amount' => 0,
        ], [
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'ledger_date' => '2026-01-04',
            'currency' => 'PKR', 'entry_type' => 'Supplier payment made',
            'raw_debit_amount' => 0, 'raw_credit_amount' => 100,
        ]],
        null,
        null
    );
    $check(
        'Customer and supplier payments appear once in the same running statement',
        array_column($paymentStatement['rows'], 'ledger_entry') === [
            'Customer invoice', 'Supplier invoice recorded', 'Customer payment received', 'Supplier payment made',
        ]
            && (float) $paymentStatement['footerRows'][0]['balance_amount'] === -300.0
    );
    $advanceStatement = (new \App\Services\LinkedPartyLedgerService($app))->buildReport(
        [[
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'entry_date_raw' => '2026-01-01',
            'currency' => 'PKR', 'ledger_entry' => 'Customer invoice', 'is_financial_position_only' => true,
            'raw_debit_amount' => 400, 'raw_credit_amount' => 0,
        ], [
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'entry_date_raw' => '2026-01-02',
            'currency' => 'PKR', 'ledger_entry' => 'Customer advance received',
            'raw_debit_amount' => 400, 'raw_credit_amount' => 0,
        ], [
            'branch_id' => $branchId, 'branch_name' => 'Test Branch', 'entry_date_raw' => '2026-01-03',
            'currency' => 'PKR', 'ledger_entry' => 'Customer advance applied to invoice',
            'is_internal_transfer' => true, 'raw_debit_amount' => 0, 'raw_credit_amount' => 400,
        ]],
        [],
        null,
        null
    );
    $check(
        'Applying an already received advance does not count a second payment',
        count($advanceStatement['rows']) === 2
            && (float) $advanceStatement['footerRows'][0]['balance_amount'] === 0.0
    );
    $openingOnly = (new \App\Services\LinkedPartyLedgerService($app))->buildReport(
        [[
            'branch_id' => $branchId,
            'branch_name' => 'Test Branch',
            'entry_date_raw' => '2026-01-01',
            'currency' => 'PKR',
            'ledger_entry' => 'Customer invoice',
            'is_financial_position_only' => true,
            'raw_debit_amount' => 800,
            'raw_credit_amount' => 0,
        ]],
        [],
        '2026-02-01',
        '2026-02-28'
    );
    $check(
        'Date window with only earlier activity retains an opening row and closing balance',
        count($openingOnly['rows']) === 1
            && $openingOnly['rows'][0]['ledger_entry'] === 'Opening balance'
            && (float) $openingOnly['footerRows'][0]['balance_amount'] === 800.0
    );
    $customerState = $reportService->reportState([
        'report' => 'customer_ledger',
        'business_source_id' => $businessSourceId,
    ], [$branchId], $actorId, 'print');
    $check('Linked account uses the single-statement view', ($customerState['isLinkedPartyLedger'] ?? false) === true);
    $check(
        'Linked account print uses the ordinary Account Ledger columns',
        in_array('transaction_reference', array_column($customerState['columns'], 'key'), true)
            && in_array('lead_traveler_name', array_column($customerState['columns'], 'key'), true)
            && array_reduce($customerState['rows'], static fn (bool $valid, array $row): bool =>
                $valid
                    && array_key_exists('transaction_reference', $row)
                    && array_key_exists('lead_traveler_name', $row), true)
    );
    $linkedScreenState = $reportService->reportState([
        'report' => 'customer_ledger',
        'business_source_id' => $businessSourceId,
    ], [$branchId], $actorId, 'screen');
    ob_start();
    (static function (array $state): void {
        extract($state);
        require BASE_PATH . '/app/Views/reports/index.php';
    })($linkedScreenState);
    $linkedScreenHtml = (string) ob_get_clean();
    preg_match_all('/class="report-total-row"/', $linkedScreenHtml, $linkedFooterMatches);
    $check(
        'Linked account screen renders only its currency-aware footer rows',
        count($linkedFooterMatches[0]) === count($linkedScreenState['footerRows'])
    );
    $ordinaryLinkedAccountSummary = (new \App\Services\AccountLedgerService($app))->report(
        $linkedScreenState['filters'],
        [$branchId]
    );
    $check(
        'Linked account preserves the ordinary Account Ledger summary data',
        ($linkedScreenState['summaryCards'] ?? []) === ($ordinaryLinkedAccountSummary['summaryCards'] ?? [])
            && ($linkedScreenState['customerOutstandingSummaryRows'] ?? []) === ($ordinaryLinkedAccountSummary['summaryRows'] ?? [])
            && str_contains($linkedScreenHtml, '<section class="stat-grid report-stat-grid')
    );
    $standardCustomerState = $reportService->reportState([
        'report' => 'customer_ledger',
    ], [$branchId], $actorId, 'print');
    $check(
        'Ordinary account ledger remains unchanged and matches linked ledger columns',
        ($standardCustomerState['isLinkedPartyLedger'] ?? false) === false
            && array_column($standardCustomerState['columns'], 'key') === array_column($customerState['columns'], 'key')
            && in_array('transaction_reference', array_column($standardCustomerState['columns'], 'key'), true)
            && in_array('lead_traveler_name', array_column($standardCustomerState['columns'], 'key'), true)
    );
    $renderAccountPrint = static function (array $state): string {
        $state['title'] = 'Account Ledger';
        ob_start();
        (static function (array $viewState): void {
            extract($viewState);
            require BASE_PATH . '/app/Views/reports/account_ledger_print.php';
        })($state);

        return (string) ob_get_clean();
    };
    $linkedPrintHtml = $renderAccountPrint($customerState);
    $ordinaryPrintHtml = $renderAccountPrint($standardCustomerState);
    $check(
        'Ordinary and linked account print views retain the same summary layout',
        substr_count($linkedPrintHtml, '<section class="ledger-print-meta">') === 1
            && substr_count($ordinaryPrintHtml, '<section class="ledger-print-meta">') === 1
            && str_contains($linkedPrintHtml, 'Customers in Summary')
            && str_contains($ordinaryPrintHtml, 'Customers in Summary')
    );
    $supplierState = $reportService->reportState([
        'report' => 'supplier_ledger',
        'business_source_id' => $businessSourceId,
    ], [$branchId], $actorId, 'print');
    $check('Standalone supplier ledger keeps its existing view', ($supplierState['isLinkedPartyLedger'] ?? false) === false);
    $ordinarySupplierState = $reportService->reportState([
        'report' => 'supplier_ledger',
    ], [$branchId], $actorId, 'print');
    $ordinarySupplierDirect = (new \App\Services\SupplierLedgerService($app))->report(
        $ordinarySupplierState['filters'],
        [$branchId]
    );
    $check(
        'Ordinary Supplier Ledger remains the unchanged service output',
        ($ordinarySupplierState['isLinkedPartyLedger'] ?? false) === false
            && count($ordinarySupplierState['rows']) === count($ordinarySupplierDirect['rows'])
            && $ordinarySupplierState['summaryCards'] === $ordinarySupplierDirect['summaryCards']
            && ($ordinarySupplierState['footerRows'] ?? []) === []
            && array_column($ordinarySupplierState['columns'], 'key') === [
                'supplier_name', 'ledger_date', 'booking_reference', 'entry_type', 'passenger_name',
                'route', 'currency', 'debit_amount', 'credit_amount', 'balance_amount',
            ]
    );
    $linkedSupplierState = $reportService->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $supplierId,
    ], [$branchId], $actorId, 'screen');
    $check(
        'Selecting a linked supplier activates only the combined Supplier Ledger projection',
        ($linkedSupplierState['isLinkedPartyLedger'] ?? false) === true
            && ($linkedSupplierState['footerRows'] ?? []) !== []
            && array_column($linkedSupplierState['columns'], 'key') === [
                'supplier_name', 'ledger_date', 'booking_reference', 'entry_type', 'passenger_name',
                'route', 'currency', 'debit_amount', 'credit_amount', 'balance_amount', 'trace',
            ]
    );
    $linkedSupplierReceivableState = $linkedSupplierState;
    $linkedSupplierReceivableState['rows'] = $linkedSupplierReceivable['rows'];
    $linkedSupplierReceivableState['footerRows'] = $linkedSupplierReceivable['footerRows'];
    $linkedSupplierReceivableState['summaryCards'] = $linkedSupplierReceivable['summaryCards'];
    ob_start();
    (static function (array $state): void {
        extract($state);
        require BASE_PATH . '/app/Views/reports/index.php';
    })($linkedSupplierReceivableState);
    $linkedSupplierReceivableScreen = (string) ob_get_clean();
    $linkedSupplierReceivablePrintState = $linkedSupplierReceivableState;
    $linkedSupplierReceivablePrintState['columns'] = array_values(array_filter(
        $linkedSupplierReceivablePrintState['columns'],
        static fn (array $column): bool => (string) ($column['key'] ?? '') !== 'trace'
    ));
    ob_start();
    (static function (array $state): void {
        extract($state);
        require BASE_PATH . '/app/Views/reports/report_print.php';
    })($linkedSupplierReceivablePrintState);
    $linkedSupplierReceivablePrint = (string) ob_get_clean();
    $check(
        'Linked Supplier Ledger screen and print label PKR 200 as receivable, not advance',
        str_contains($linkedSupplierReceivableScreen, '200 Receivable')
            && ! str_contains($linkedSupplierReceivableScreen, '200 Advance')
            && str_contains($linkedSupplierReceivablePrint, '200 Receivable')
            && ! str_contains($linkedSupplierReceivablePrint, '200 Advance')
    );
    $linkId = (int) $db->query('SELECT id FROM business_source_supplier_links WHERE business_source_id = ' . $businessSourceId)->fetchColumn();
    $check('Link reconciliation cannot auto-settle', $service->autoSettleLink($linkId, date('Y-m-d'), $actorId) === []);
    $check('Existing-balance reconciliation cannot auto-settle', $service->autoSettleExisting([$branchId], date('Y-m-d'), $actorId) === []);
    $linked = (new \App\Services\CounterpartyLinkService($app))->linkAccountToSupplier($businessSourceId, $supplierId, $actorId);
    $check('Saving the same link cannot auto-settle', $linked['automatic_settlements'] === []);
    $linkService = new \App\Services\CounterpartyLinkService($app);
    $linkService->unlinkAccountFromSupplier($linkId, $actorId, 'QA manual-only policy');
    $newLink = $linkService->linkAccountToSupplier($businessSourceId, $supplierId, $actorId);
    $check('Creating a link with matching balances cannot auto-settle', $newLink['action'] === 'linked' && $newLink['automatic_settlements'] === []);
    $check('No cash or bank movement is created', (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $beforeTreasury);

    $restoredReceivable = $db->query('SELECT outstanding_amount FROM customer_receivable_items WHERE id = ' . $receivableId)->fetchColumn();
    $restoredPayable = $db->query('SELECT net_payable_amount FROM supplier_obligations WHERE id = ' . $obligationId)->fetchColumn();
    $check('All automatic entry points leave supplier balance and customer due unchanged', abs((float) $restoredReceivable - 30000) <= 0.005 && abs((float) $restoredPayable - 100000) <= 0.005);
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

if ($failures !== []) {
    fwrite(STDERR, 'Automatic linked-party settlement regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Automatic linked-party settlement regression passed.' . PHP_EOL;
