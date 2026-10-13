<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Account-holder unallocated receipt regression' . PHP_EOL;

$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY (r.code = "super_admin") DESC, u.id
     LIMIT 1'
)->fetchColumn();
$treasuryAccount = $db->query(
    'SELECT ta.id, ta.branch_id, ta.account_type, ta.currency
     FROM treasury_accounts ta
     INNER JOIN branches b ON b.id = ta.branch_id AND b.is_active = 1
     WHERE ta.is_active = 1
       AND ta.currency = "PKR"
       AND ta.account_type IN ("cash", "bank")
     ORDER BY (ta.account_type = "cash") DESC, ta.is_default DESC, ta.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
if ($actorId <= 0 || (int) ($treasuryAccount['id'] ?? 0) <= 0) {
    throw new RuntimeException('A financial administrator and active PKR cash/bank account are required.');
}

$branchId = (int) $treasuryAccount['branch_id'];
$paymentMethod = (string) $treasuryAccount['account_type'] === 'bank' ? 'bank_transfer' : 'cash';
$automaticBefore = (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"')->fetchColumn();

$db->beginTransaction();
try {
    $suffix = date('His') . random_int(100, 999);
    $db->prepare('INSERT INTO business_sources (code, name, is_system, is_active) VALUES (:code, :name, 0, 1)')
        ->execute(['code' => 'QA-AHR-' . $suffix, 'name' => 'QA Receipt Account ' . $suffix]);
    $businessSourceId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    )->execute([
        'branch_id' => $branchId,
        'code' => 'QA-AHR-S-' . $suffix,
        'name' => 'QA Receipt Supplier ' . $suffix,
    ]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO business_source_supplier_links (business_source_id, supplier_id, linked_by_user_id)
         VALUES (:business_source_id, :supplier_id, :actor_user_id)'
    )->execute([
        'business_source_id' => $businessSourceId,
        'supplier_id' => $supplierId,
        'actor_user_id' => $actorId,
    ]);
    $linkId = (int) $db->lastInsertId();

    $service = new \App\Services\AccountHolderReceiptService($app);
    $input = [
        'link_id' => $linkId,
        'branch_id' => $branchId,
        'currency' => 'PKR',
        'amount' => 300,
        'receipt_date' => date('Y-m-d'),
        'payment_method' => $paymentMethod,
        'payer_role' => 'supplier',
        'treasury_account_id' => (int) $treasuryAccount['id'],
        'reference_number' => 'QA-LUMP-' . $suffix,
        'remarks' => 'QA unallocated linked-party cash receipt',
    ];

    foreach ([
        'wrong linked party' => ['link_id' => PHP_INT_MAX],
        'inaccessible branch' => ['branch_id' => PHP_INT_MAX],
        'unsupported method' => ['payment_method' => 'account_holder_balance'],
        'unknown cash or bank account' => ['treasury_account_id' => PHP_INT_MAX],
        'zero amount' => ['amount' => 0],
        'invalid payer role' => ['payer_role' => 'other'],
    ] as $label => $override) {
        $receiptCount = (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn();
        $rejected = false;
        try {
            $service->record(array_replace($input, $override), $actorId, [$branchId]);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $check('Rejects ' . $label . ' without a receipt', $rejected && $receiptCount === (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn());
    }

    $result = $service->record($input, $actorId, [$branchId]);
    $receiptId = (int) $result['receipt_id'];
    $receipt = $db->query('SELECT * FROM customer_receipts WHERE id = ' . $receiptId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Receipt belongs to the account holder and not a traveler',
        (int) ($receipt['business_source_id'] ?? 0) === $businessSourceId
        && ($receipt['traveler_id'] ?? null) === null
        && (string) ($receipt['receipt_purpose'] ?? '') === 'account_holder_receipt');
    $check('Receipt remembers the supplier as the actual payer',
        (string) ($receipt['linked_payer_role'] ?? '') === 'supplier');
    $check('PKR 300 remains fully unallocated',
        (float) ($receipt['received_amount'] ?? 0) === 300.0
        && (float) ($receipt['allocated_amount'] ?? -1) === 0.0
        && (float) ($receipt['unallocated_amount'] ?? 0) === 300.0);
    $check('No passenger receivable allocation is created',
        (int) $db->query('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ' . $receiptId)->fetchColumn() === 0);

    $journal = $db->prepare(
        'SELECT j.id, j.source_type, j.currency,
                SUM(l.debit_amount) AS total_debit, SUM(l.credit_amount) AS total_credit,
                SUM(CASE WHEN c.code = "CUSTOMER_CREDIT" THEN l.credit_amount ELSE 0 END) AS customer_credit,
                SUM(CASE WHEN c.code IN ("SALES_REVENUE", "SERVICE_REVENUE", "OTHER_INCOME") THEN l.credit_amount ELSE 0 END) AS income_credit
         FROM journal_entries j
         INNER JOIN journal_entry_lines l ON l.journal_entry_id = j.id
         INNER JOIN chart_of_accounts c ON c.id = l.account_id
         WHERE l.customer_receipt_id = :receipt_id AND j.source_type = "customer_receipt_recorded"
         GROUP BY j.id, j.source_type, j.currency'
    );
    $journal->execute(['receipt_id' => $receiptId]);
    $journalRow = $journal->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Receipt journal is balanced cash/bank against customer credit',
        (string) ($journalRow['source_type'] ?? '') === 'customer_receipt_recorded'
        && (string) ($journalRow['currency'] ?? '') === 'PKR'
        && (float) ($journalRow['total_debit'] ?? 0) === 300.0
        && (float) ($journalRow['total_credit'] ?? 0) === 300.0
        && (float) ($journalRow['customer_credit'] ?? 0) === 300.0);
    $check('Receipt does not post revenue or profit', (float) ($journalRow['income_credit'] ?? -1) === 0.0);

    $accountReport = (new \App\Services\AccountLedgerService($app))->report([
        'currency' => 'PKR',
        'businessSourceId' => $businessSourceId,
    ], [$branchId]);
    $receiptRows = array_values(array_filter(
        (array) ($accountReport['rows'] ?? []),
        static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Net payment received'
    ));
    $check('Supplier-paid receipt is a PKR 300 debit in the linked account ledger',
        count($receiptRows) === 1
        && (float) ($receiptRows[0]['raw_debit_amount'] ?? 0) === 300.0
        && (float) ($receiptRows[0]['raw_credit_amount'] ?? -1) === 0.0
        && (float) ($receiptRows[0]['raw_balance_amount'] ?? 0) === 300.0
        && str_contains((string) ($receiptRows[0]['transaction_note'] ?? ''), 'Received from QA Receipt Supplier ' . $suffix));
    $linkedStatement = (new \App\Services\LinkedPartyLedgerService($app))->buildReport(
        (array) ($accountReport['rows'] ?? []),
        [],
        null,
        null,
        'PKR'
    );
    $linkedReceiptRows = array_values(array_filter(
        (array) ($linkedStatement['rows'] ?? []),
        static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Net payment received'
    ));
    $check('Linked ledger treats the received PKR 300 as one credit',
        count($linkedReceiptRows) === 1
        && (float) ($linkedReceiptRows[0]['raw_debit_amount'] ?? 0) === 0.0
        && (float) ($linkedReceiptRows[0]['raw_credit_amount'] ?? 0) === 300.0);
    $supplierReport = (new \App\Services\ReportService($app))->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $supplierId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $supplierMirrorRows = array_values(array_filter(
        (array) ($supplierReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === (string) $result['receipt_no']
    ));
    $supplierColumnKeys = array_column((array) ($supplierReport['columns'] ?? []), 'key');
    $check('Direct cash appears once as supplier-view debit and reduces the linked net',
        count($supplierMirrorRows) === 1
        && (float) ($supplierMirrorRows[0]['raw_debit_amount'] ?? 0) === 300.0
        && (float) ($supplierMirrorRows[0]['raw_credit_amount'] ?? -1) === 0.0
        && ! in_array('linked_receipt_amount', $supplierColumnKeys, true)
        && str_contains((string) ($supplierMirrorRows[0]['entry_type'] ?? ''), 'QA Receipt Supplier ' . $suffix)
        && str_contains((string) ($supplierMirrorRows[0]['trace'] ?? ''), 'Received from QA Receipt Supplier ' . $suffix)
        && ! str_contains((string) ($supplierMirrorRows[0]['trace'] ?? ''), 'Included once in linked net')
        && str_contains((string) ($supplierMirrorRows[0]['booking_reference_href'] ?? ''), '/linked-party-settlements?'));
    $pooledPositions = (new \App\Services\LinkedPartyPositionService($app))->positions([$branchId]);
    $receiptOnlyPosition = current(array_filter(
        $pooledPositions,
        static fn (array $row): bool => (int) ($row['link_id'] ?? 0) === $linkId && (string) ($row['currency'] ?? '') === 'PKR'
    ));
    $check('Receipt-only linked balance is PKR 300 due to the party without a ticket allocation',
        is_array($receiptOnlyPosition)
        && (float) ($receiptOnlyPosition['on_account_credit'] ?? 0) === 300.0
        && (float) ($receiptOnlyPosition['net_balance'] ?? 0) === -300.0);
    $check('Synthetic account-holder receipt does not increase invoice count',
        array_sum(array_map(static fn (array $row): int => (int) ($row['invoice_count'] ?? 0), (array) ($accountReport['summaryRows'] ?? []))) === 0);

    $unallocatedReport = (new \App\Services\ReportService($app))->reportState([
        'report' => 'unallocated_money',
        'branch_id' => $branchId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $onAccountRows = array_values(array_filter(
        (array) ($unallocatedReport['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['document_no'] ?? '') === (string) $result['receipt_no']
    ));
    $onAccountRow = $onAccountRows[0] ?? [];
    $columnLabels = array_column((array) ($unallocatedReport['columns'] ?? []), 'label', 'key');
    $summaryLabels = array_column((array) ($unallocatedReport['summaryCards'] ?? []), 'label');
    $check('Open-money report identifies the PKR 300 as received on account, not a ticket allocation',
        count($onAccountRows) === 1
        && (string) ($onAccountRow['source_type'] ?? '') === 'Linked-Party Direct Payment'
        && (string) ($onAccountRow['counterparty_name'] ?? '') === 'QA Receipt Account ' . $suffix
        && (string) ($onAccountRow['booking_reference'] ?? '') === '-'
        && (string) ($onAccountRow['allocated_amount'] ?? '') === '0'
        && (string) ($onAccountRow['unallocated_amount'] ?? '') === '300'
        && (string) ($onAccountRow['status'] ?? '') === 'Included In Linked Balance'
        && str_contains((string) ($onAccountRow['document_no_href'] ?? ''), '/linked-party-settlements?')
        && ($columnLabels['unallocated_amount'] ?? '') === 'Open / Available'
        && in_array('Linked Direct Payments / PKR', $summaryLabels, true));

    $positions = [];
    $manualPosition = [
        'link_id' => $linkId,
        'branch_id' => $branchId,
        'currency' => 'PKR',
        'business_source_name' => 'QA Receipt Account ' . $suffix,
        'supplier_name' => 'QA Receipt Supplier ' . $suffix,
    ];
    $linkedPartyOptions = [[
        'id' => $linkId,
        'business_source_name' => 'QA Receipt Account ' . $suffix,
        'supplier_name' => 'QA Receipt Supplier ' . $suffix,
    ]];
    $manualAccounts = [];
    $manualPayables = [];
    $accountHolderReceiptAccounts = [[
        'id' => (int) $treasuryAccount['id'],
        'account_name' => 'QA Treasury',
        'account_type' => (string) $treasuryAccount['account_type'],
        'currency' => 'PKR',
    ]];
    $accountHolderReceipts = (new \App\Repositories\CustomerPaymentRepository($app))->accountHolderReceipts(
        $businessSourceId,
        $branchId,
        'PKR'
    );
    $recentOffsets = [];
    $selectedOffset = null;
    $selectedAccountAllocations = [];
    $selectedPayableAllocations = [];
    ob_start();
    require BASE_PATH . '/app/Views/control/linked_party_settlements.php';
    $receiptView = (string) ob_get_clean();
    $check('Financial-admin view renders the net-payment form and on-account history',
        str_contains($receiptView, '>Receive Payment</button>')
        && str_contains($receiptView, '<h2>Receive Payment</h2>')
        && str_contains($receiptView, 'QA Receipt Account ' . $suffix . ' or QA Receipt Supplier ' . $suffix)
        && str_contains($receiptView, '/linked-party-settlements/receipts/save')
        && str_contains($receiptView, (string) $result['receipt_no'])
        && str_contains($receiptView, 'aria-label="Save received payment"')
        && str_contains($receiptView, 'name="payer_role" required')
        && str_contains($receiptView, '<th>Received From</th>')
        && str_contains($receiptView, 'Included in linked balance')
        && ! str_contains($receiptView, '<th>Unallocated</th>'));

    $voided = $service->void([
        'link_id' => $linkId,
        'customer_receipt_id' => $receiptId,
        'void_reason' => 'QA receipt reversal',
    ], $actorId, [$branchId]);
    $check('Void records a reversal journal', (int) ($voided['reversal_journal_entry_id'] ?? 0) > 0);
    $check('Voided receipt is removed from active linked ledger movements',
        count(array_filter(
            (new \App\Services\AccountLedgerService($app))->report([
                'currency' => 'PKR',
                'businessSourceId' => $businessSourceId,
            ], [$branchId])['rows'] ?? [],
            static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Net payment received'
        )) === 0);
    $supplierAfterVoid = (new \App\Services\ReportService($app))->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $supplierId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $check('Voided receipt disappears from the supplier linked-payment mirror',
        count(array_filter((array) ($supplierAfterVoid['rows'] ?? []),
            static fn (array $row): bool => (string) ($row['booking_reference'] ?? '') === (string) $result['receipt_no'])) === 0);
    $positionsAfterVoid = (new \App\Services\LinkedPartyPositionService($app))->positions([$branchId]);
    $check('Voiding direct cash removes its pooled on-account credit',
        count(array_filter($positionsAfterVoid, static fn (array $row): bool => (int) ($row['link_id'] ?? 0) === $linkId)) === 0);
    $withoutSubmittedBranch = $input;
    unset($withoutSubmittedBranch['branch_id'], $withoutSubmittedBranch['payer_role']);
    $withoutSubmittedBranch['amount'] = 100;
    $derivedReceipt = $service->record($withoutSubmittedBranch, $actorId, [$branchId]);
    $derivedRow = $db->query('SELECT branch_id, linked_payer_role FROM customer_receipts WHERE id = ' . (int) $derivedReceipt['receipt_id'])->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Receipt branch is derived from the chosen accessible cash or bank account', (int) ($derivedRow['branch_id'] ?? 0) === $branchId);
    $check('Legacy callers default to the linked account as payer',
        (string) ($derivedRow['linked_payer_role'] ?? '') === 'account_holder');
    $derivedLedgerRows = (new \App\Services\AccountLedgerService($app))->report([
        'currency' => 'PKR',
        'businessSourceId' => $businessSourceId,
    ], [$branchId])['rows'] ?? [];
    $check('Account ledger identifies the account name when it actually paid',
        count(array_filter($derivedLedgerRows, static fn (array $row): bool =>
            (string) ($row['transaction_reference'] ?? '') === (string) $derivedReceipt['receipt_no']
            && (float) ($row['raw_debit_amount'] ?? -1) === 0.0
            && (float) ($row['raw_credit_amount'] ?? 0) === 100.0
            && str_contains((string) ($row['transaction_note'] ?? ''), 'Received from QA Receipt Account ' . $suffix))) === 1);
    $derivedSupplierReport = (new \App\Services\ReportService($app))->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $supplierId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $derivedAccountReport = (new \App\Services\ReportService($app))->reportState([
        'report' => 'customer_ledger',
        'business_source_id' => $businessSourceId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $accountPaidAccountRows = array_values(array_filter((array) ($derivedAccountReport['rows'] ?? []), static fn (array $row): bool =>
        (string) ($row['transaction_reference'] ?? '') === (string) $derivedReceipt['receipt_no']));
    $accountPaidSupplierRows = array_values(array_filter((array) ($derivedSupplierReport['rows'] ?? []), static fn (array $row): bool =>
        (string) ($row['booking_reference'] ?? '') === (string) $derivedReceipt['receipt_no']));
    $check('Account-paid cash appears once as account credit and once as supplier debit',
        count($accountPaidAccountRows) === 1
        && count($accountPaidSupplierRows) === 1
        && (float) ($accountPaidAccountRows[0]['raw_debit_amount'] ?? -1) === 0.0
        && (float) ($accountPaidAccountRows[0]['raw_credit_amount'] ?? 0) === 100.0
        && (float) ($accountPaidSupplierRows[0]['raw_debit_amount'] ?? 0) === 100.0
        && (float) ($accountPaidSupplierRows[0]['raw_credit_amount'] ?? -1) === 0.0
        && str_contains((string) ($accountPaidAccountRows[0]['transaction_reference_href'] ?? ''), '/linked-party-settlements?'));
    $check('Account-paid receipt is included as supplier debit',
        count($accountPaidSupplierRows) === 1
        && (float) ($accountPaidSupplierRows[0]['raw_debit_amount'] ?? 0) === 100.0
        && (float) ($accountPaidSupplierRows[0]['raw_credit_amount'] ?? -1) === 0.0
        && str_contains((string) ($accountPaidSupplierRows[0]['entry_type'] ?? ''), 'QA Receipt Account ' . $suffix)
        && str_contains((string) ($accountPaidSupplierRows[0]['trace'] ?? ''), 'Received from QA Receipt Account ' . $suffix));
    $supplierSummaryLabels = array_values(array_map(static fn (array $card): string => (string) ($card['label'] ?? ''),
        array_filter((array) ($derivedSupplierReport['summaryCards'] ?? []), static fn (array $card): bool =>
            (string) ($card['group'] ?? '') === '')));
    $check('Linked supplier summary does not repeat ledger debits, credits, or net balance',
        $supplierSummaryLabels === []
        && count((array) ($derivedSupplierReport['summaryCards'] ?? [])) === 3);
    $derivedPositions = (new \App\Services\LinkedPartyPositionService($app))->positions([$branchId]);
    $derivedPosition = current(array_filter($derivedPositions, static fn (array $position): bool =>
        (int) ($position['link_id'] ?? 0) === $linkId && (string) ($position['currency'] ?? '') === 'PKR'));
    $check('Account-paid receipt is identified separately for the receivable card without altering invoice balances',
        is_array($derivedPosition)
        && (float) ($derivedPosition['receivable'] ?? -1) === 0.0
        && (float) ($derivedPosition['account_paid_direct'] ?? 0) === 100.0
        && (float) ($derivedPosition['net_balance'] ?? 0) === -100.0);
    $invoiceBalanceCards = array_values(array_filter((array) ($derivedAccountReport['summaryCards'] ?? []), static fn (array $card): bool =>
        (string) ($card['label'] ?? '') === 'Invoice Outstanding / PKR'));
    $check('Receipt-only account summary does not invent a customer invoice balance',
        $invoiceBalanceCards === []);
    $accountPaidCards = array_values(array_filter((array) ($derivedSupplierReport['summaryCards'] ?? []), static fn (array $card): bool =>
        (string) ($card['label'] ?? '') === 'Payable to QA Receipt Account ' . $suffix . ' / PKR'));
    $check('Excess account-paid cash is shown as payable to the account, not negative receivable',
        count($accountPaidCards) === 1
        && (string) ($accountPaidCards[0]['value'] ?? '') === 'PKR 100.00');
    $db->prepare('UPDATE customer_receipts SET linked_payer_role = NULL WHERE id = :receipt_id')
        ->execute(['receipt_id' => (int) $derivedReceipt['receipt_id']]);
    $legacySupplierReport = (new \App\Services\ReportService($app))->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $supplierId,
        'currency' => 'PKR',
    ], [$branchId], $actorId);
    $legacyRows = array_values(array_filter((array) ($legacySupplierReport['rows'] ?? []), static fn (array $row): bool =>
        (string) ($row['booking_reference'] ?? '') === (string) $derivedReceipt['receipt_no']));
    $check('Legacy receipt stays traceable without inventing a supplier payer',
        count($legacyRows) === 1
        && (float) ($legacyRows[0]['raw_debit_amount'] ?? 0) === 100.0
        && (float) ($legacyRows[0]['raw_credit_amount'] ?? -1) === 0.0
        && (string) ($legacyRows[0]['entry_type'] ?? '') === 'Linked payment received'
        && ! str_contains((string) ($legacyRows[0]['trace'] ?? ''), 'Received from QA Receipt Supplier ' . $suffix));
    $service->void([
        'link_id' => $linkId,
        'customer_receipt_id' => (int) $derivedReceipt['receipt_id'],
        'void_reason' => 'QA derived branch reversal',
    ], $actorId, [$branchId]);
    $journalIds = implode(',', [(int) $journalRow['id'], (int) $voided['reversal_journal_entry_id']]);
    $journalNet = $db->query(
        'SELECT account_id, SUM(debit_amount - credit_amount) AS net
         FROM journal_entry_lines
         WHERE journal_entry_id IN (' . $journalIds . ')
         GROUP BY account_id
         HAVING ABS(net) > 0.005'
    )->fetchAll(PDO::FETCH_ASSOC);
    $check('Receipt and void reversal net to zero by account', $journalNet === []);
    $check('Automatic linked adjustments remain untouched',
        (int) $db->query('SELECT COUNT(*) FROM counterparty_offsets WHERE offset_no LIKE "AUTO-OFFSET-%"')->fetchColumn() === $automaticBefore);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Account-holder unallocated receipt regression passed.' . PHP_EOL;
