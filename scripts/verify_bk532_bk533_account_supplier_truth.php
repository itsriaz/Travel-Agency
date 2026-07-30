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
$money = static fn (mixed $value): float => round((float) $value, 2);

echo 'BK-000532 / BK-000533 account-supplier truth verification (read-only)' . PHP_EOL;
echo 'This script performs SELECT queries only; it cannot change data.' . PHP_EOL . PHP_EOL;

$receivable = $db->prepare(
    'SELECT id, booking_reference, currency, due_amount, allocated_amount, outstanding_amount, status
     FROM customer_receivable_items
     WHERE booking_reference = :booking AND due_group = "service_sale"
     ORDER BY id LIMIT 1'
);
$receivable->execute(['booking' => 'BK-000532']);
$bk532 = $receivable->fetch(PDO::FETCH_ASSOC);
$receivable->execute(['booking' => 'BK-000533']);
$bk533 = $receivable->fetch(PDO::FETCH_ASSOC);

$check(
    'BK-000532 customer PADMAARO still owes PKR 12,000',
    is_array($bk532)
        && strtoupper((string) $bk532['currency']) === 'PKR'
        && $money($bk532['due_amount']) === 12000.00
        && $money($bk532['allocated_amount']) === 0.00
        && $money($bk532['outstanding_amount']) === 12000.00
        && (string) $bk532['status'] === 'open',
    is_array($bk532) ? json_encode($bk532, JSON_UNESCAPED_SLASHES) : 'missing'
);
$check(
    'BK-000533 customer MARITES COMPR still owes PKR 18,000',
    is_array($bk533)
        && strtoupper((string) $bk533['currency']) === 'PKR'
        && $money($bk533['due_amount']) === 18000.00
        && $money($bk533['allocated_amount']) === 0.00
        && $money($bk533['outstanding_amount']) === 18000.00
        && (string) $bk533['status'] === 'open',
    is_array($bk533) ? json_encode($bk533, JSON_UNESCAPED_SLASHES) : 'missing'
);

$customerAllocationCount = 0;
if (is_array($bk532) && is_array($bk533)) {
    $allocationCount = $db->prepare(
        'SELECT COUNT(*)
         FROM customer_receipt_allocations
         WHERE customer_receivable_item_id IN (:bk532, :bk533)'
    );
    $allocationCount->execute(['bk532' => (int) $bk532['id'], 'bk533' => (int) $bk533['id']]);
    $customerAllocationCount = (int) $allocationCount->fetchColumn();
}
$check('Neither booking has a fabricated customer payment allocation', $customerAllocationCount === 0, 'rows=' . $customerAllocationCount);

$supplier = $db->query(
    'SELECT so.id, so.booking_reference, so.branch_id, so.supplier_id, so.currency, so.gross_amount, so.net_payable_amount, so.status, s.name AS supplier_name
     FROM supplier_obligations so
     INNER JOIN suppliers s ON s.id = so.supplier_id
     WHERE so.booking_reference = "BK-000533" AND so.obligation_group = "service_cost"
     ORDER BY so.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$check(
    'BK-000533 Supplier Riaz payable is PKR 3,000',
    is_array($supplier)
        && strtoupper((string) $supplier['currency']) === 'PKR'
        && $money($supplier['gross_amount']) === 15000.00
        && $money($supplier['net_payable_amount']) === 3000.00
        && in_array((string) $supplier['status'], ['partial', 'partially_covered'], true),
    is_array($supplier) ? json_encode($supplier, JSON_UNESCAPED_SLASHES) : 'missing'
);

$offset = $db->query(
    'SELECT o.id, o.offset_no, o.amount, o.currency, o.status,
            COALESCE(SUM(DISTINCT aa.allocated_amount), 0) AS account_amount,
            COALESCE(SUM(DISTINCT pa.allocated_amount), 0) AS payable_amount,
            MAX(account_item.booking_reference) AS account_booking,
            MAX(supplier_item.booking_reference) AS supplier_booking,
            o.journal_entry_id
     FROM counterparty_offsets o
     INNER JOIN counterparty_offset_account_allocations aa ON aa.counterparty_offset_id = o.id
     INNER JOIN customer_receivable_items account_item ON account_item.id = aa.customer_receivable_item_id
     INNER JOIN counterparty_offset_payable_allocations pa ON pa.counterparty_offset_id = o.id
     INNER JOIN supplier_obligations supplier_item ON supplier_item.id = pa.supplier_obligation_id
     WHERE o.status = "posted"
       AND account_item.booking_reference = "BK-000532"
       AND supplier_item.booking_reference = "BK-000533"
     GROUP BY o.id
     ORDER BY o.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$check(
    'PKR 12,000 is recorded as an account-to-supplier non-cash settlement',
    is_array($offset)
        && strtoupper((string) $offset['currency']) === 'PKR'
        && $money($offset['amount']) === 12000.00
        && $money($offset['account_amount']) === 12000.00
        && $money($offset['payable_amount']) === 12000.00,
    is_array($offset) ? json_encode($offset, JSON_UNESCAPED_SLASHES) : 'missing'
);

$legacyCount = (int) $db->query(
    'SELECT COUNT(*)
     FROM counterparty_offset_receivable_allocations ra
     INNER JOIN counterparty_offsets o ON o.id = ra.counterparty_offset_id
     INNER JOIN customer_receivable_items cri ON cri.id = ra.customer_receivable_item_id
     WHERE o.status = "posted" AND cri.booking_reference IN ("BK-000532", "BK-000533")'
)->fetchColumn();
$check('No legacy customer-receivable offset remains on these bookings', $legacyCount === 0, 'rows=' . $legacyCount);

$journalBalanced = false;
if (is_array($offset) && (int) $offset['journal_entry_id'] > 0) {
    $journal = $db->prepare(
        'SELECT COALESCE(SUM(debit_amount), 0) AS debit_total, COALESCE(SUM(credit_amount), 0) AS credit_total
         FROM journal_entry_lines WHERE journal_entry_id = :journal_id'
    );
    $journal->execute(['journal_id' => (int) $offset['journal_entry_id']]);
    $totals = $journal->fetch(PDO::FETCH_ASSOC);
    $journalBalanced = is_array($totals)
        && $money($totals['debit_total']) === 12000.00
        && $money($totals['credit_total']) === 12000.00;
}
$check('The PKR 12,000 non-cash journal is balanced', $journalBalanced);

$riazAccountId = (int) $db->query(
    'SELECT b.business_source_id FROM bookings b WHERE b.booking_reference = "BK-000532" LIMIT 1'
)->fetchColumn();
$supplierLedgerRows = [];
if (is_array($supplier)) {
    $supplierLedger = (new \App\Services\SupplierLedgerService($app))->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => 'PKR',
        'airline' => '',
        'supplierId' => (int) $supplier['supplier_id'],
        // Deliberately pass Account Riaz to prove that a stale account filter
        // cannot hide Supplier Riaz's complete ledger.
        'businessSourceId' => $riazAccountId,
        'bookingReference' => '',
    ], [(int) $supplier['branch_id']]);
    $supplierLedgerRows = (array) ($supplierLedger['rows'] ?? []);
}
$invoiceRow = null;
$offsetRow = null;
foreach ($supplierLedgerRows as $ledgerRow) {
    if ((string) ($ledgerRow['booking_reference'] ?? '') !== 'BK-000533') {
        continue;
    }
    if ((string) ($ledgerRow['source_entry_type'] ?? '') === 'Payable Created') {
        $invoiceRow = $ledgerRow;
    }
    if ((string) ($ledgerRow['source_entry_type'] ?? '') === 'Linked Account Balance Adjustment') {
        $offsetRow = $ledgerRow;
    }
}
$check(
    'Supplier Riaz ledger shows PKR 15,000 invoice and PKR 12,000 account adjustment',
    is_array($invoiceRow)
        && $money($invoiceRow['raw_debit_amount'] ?? 0) === 15000.00
        && is_array($offsetRow)
        && $money($offsetRow['raw_credit_amount'] ?? 0) === 12000.00
        && $money($offsetRow['raw_balance_amount'] ?? 0) === 3000.00,
    'rows=' . count($supplierLedgerRows)
);

$payableAgingRows = [];
if (is_array($supplier)) {
    $payableAgingRows = (new \App\Repositories\ReportRepository($app))->payableAging(
        [(int) $supplier['branch_id']],
        date('Y-m-d'),
        null,
        null,
        '',
        (int) $supplier['supplier_id'],
        $riazAccountId
    );
}
$riazPayableRow = null;
foreach ($payableAgingRows as $agingRow) {
    if ((string) ($agingRow['booking_reference'] ?? '') === 'BK-000533') {
        $riazPayableRow = $agingRow;
        break;
    }
}
$check(
    'Payable Aging Supplier Riaz + Account Riaz filter shows PKR 3,000',
    is_array($riazPayableRow)
        && $money($riazPayableRow['net_payable_amount'] ?? 0) === 3000.00,
    'rows=' . count($payableAgingRows)
);

if ($failures !== []) {
    echo PHP_EOL . 'RESULT: FAIL' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RESULT: PASS — customer dues remain intact and only the account/supplier balances were offset.' . PHP_EOL;
