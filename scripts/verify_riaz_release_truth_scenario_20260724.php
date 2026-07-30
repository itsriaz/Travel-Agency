<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$marker = 'QA-RIAZ-RELEASE-20260724';
$failures = [];
$check = static function (string $label, bool $passed, array $detail = []) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== []) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Riaz retained release-truth verification (read-only)' . PHP_EOL;
echo 'Marker: ' . $marker . PHP_EOL . PHP_EOL;

$positionStatement = $db->prepare(
    'SELECT b.id booking_id,
            b.booking_reference,
            b.business_source_id,
            bp.lead_traveler_name customer,
            bs.id service_id,
            bs.supplier_id,
            bs.supplier_name_snapshot supplier,
            bs.cost_currency,
            bs.currency invoice_currency,
            bs.service_charge_currency service_currency,
            bs.purchase_cost,
            bs.service_charge,
            bs.final_sale_price,
            bs.net_profit_loss,
            bs.service_status,
            cri.id receivable_id,
            cri.due_amount,
            cri.allocated_amount customer_allocated,
            cri.outstanding_amount customer_open,
            so.id obligation_id,
            so.gross_amount,
            so.advance_applied_amount,
            so.net_payable_amount supplier_open,
            so.status supplier_status
     FROM bookings b
     INNER JOIN booking_parties bp ON bp.booking_id = b.id
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     INNER JOIN customer_receivable_items cri
        ON cri.booking_reference = b.booking_reference
       AND cri.service_line_reference = bs.line_reference
     INNER JOIN supplier_obligations so
        ON so.booking_reference = b.booking_reference
       AND so.service_line_reference = bs.line_reference
     WHERE b.remarks LIKE :marker
     ORDER BY b.id'
);
$positionStatement->execute(['marker' => '%' . $marker . '%']);
$rows = $positionStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$positions = [];
foreach ($rows as $row) {
    if (preg_match('/QA(\d+)$/', (string) $row['customer'], $matches) === 1) {
        $positions['QA' . $matches[1]] = $row;
        continue;
    }
    $reference = (string) $row['booking_reference'];
    $positions[$reference] = $row;
}
$byReference = array_column($rows, null, 'booking_reference');
$check('Exactly twelve retained Riaz bookings exist', count($rows) === 12, [
    'references' => array_column($rows, 'booking_reference'),
]);

$sourceIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['business_source_id'], $rows)));
$sourceName = '';
if (count($sourceIds) === 1) {
    $sourceStatement = $db->prepare('SELECT name FROM business_sources WHERE id = :id');
    $sourceStatement->execute(['id' => $sourceIds[0]]);
    $sourceName = (string) ($sourceStatement->fetchColumn() ?: '');
}
$check('Every retained booking belongs to account holder Riaz', count($sourceIds) === 1 && mb_strtolower($sourceName) === 'riaz', [
    'business_source_id' => $sourceIds[0] ?? null,
    'name' => $sourceName,
]);

$findCustomer = static function (array $rows, string $customer): array {
    foreach ($rows as $row) {
        if ((string) $row['customer'] === $customer) {
            return $row;
        }
    }

    return [];
};

$qa1 = $findCustomer($rows, 'QA-RIAZ-ABC1');
$check(
    'QA1 preserves realistic service-fee currency correction and closes both sides',
    (string) ($qa1['cost_currency'] ?? '') === 'PKR'
        && (string) ($qa1['invoice_currency'] ?? '') === 'PKR'
        && (string) ($qa1['service_currency'] ?? '') === 'AED'
        && round((float) ($qa1['service_charge'] ?? 0), 2) === 100.00
        && round((float) ($qa1['final_sale_price'] ?? 0), 2) === 88000.00
        && round((float) ($qa1['net_profit_loss'] ?? 0), 2) === 8000.00
        && round((float) ($qa1['customer_open'] ?? -1), 2) === 0.00
        && round((float) ($qa1['supplier_open'] ?? -1), 2) === 0.00,
    $qa1
);

$qa2 = $findCustomer($rows, 'QA-RIAZ-ABC2');
$check(
    'QA2 keeps PKR 33,000 customer outstanding and PKR 50,000 supplier payable',
    round((float) ($qa2['due_amount'] ?? 0), 2) === 53000.00
        && round((float) ($qa2['customer_allocated'] ?? 0), 2) === 20000.00
        && round((float) ($qa2['customer_open'] ?? 0), 2) === 33000.00
        && round((float) ($qa2['supplier_open'] ?? 0), 2) === 50000.00,
    $qa2
);

$qa3 = $findCustomer($rows, 'QA-RIAZ-ABC3');
$qa3ReceiptStatement = $db->prepare(
    'SELECT currency, received_amount, allocated_amount, unallocated_amount
     FROM customer_receipts
     WHERE booking_reference = :booking_reference AND status <> "void"
     ORDER BY id DESC LIMIT 1'
);
$qa3ReceiptStatement->execute(['booking_reference' => (string) ($qa3['booking_reference'] ?? '')]);
$qa3Receipt = $qa3ReceiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA3 independently keeps PKR cost, USD service fee, AED invoice, and explicit PKR customer receipt',
    (string) ($qa3['cost_currency'] ?? '') === 'PKR'
        && (string) ($qa3['service_currency'] ?? '') === 'USD'
        && (string) ($qa3['invoice_currency'] ?? '') === 'AED'
        && round((float) ($qa3['final_sale_price'] ?? 0), 2) === 1040.00
        && (string) ($qa3Receipt['currency'] ?? '') === 'PKR'
        && round((float) ($qa3Receipt['received_amount'] ?? 0), 2) === 83200.00
        && round((float) ($qa3['customer_open'] ?? -1), 2) === 0.00
        && round((float) ($qa3['supplier_open'] ?? 0), 2) === 80000.00,
    ['position' => $qa3, 'receipt' => $qa3Receipt]
);

$qa4 = $findCustomer($rows, 'QA-RIAZ-ABC4');
$qa4Payments = $db->query(
    'SELECT payment_no, paid_amount, currency, status, reference_number
     FROM supplier_payments
     WHERE reference_number IN ("QA-RZ-QA4-AMOUNT-EDIT", "QA-RZ-QA4-CORRECTED-900")
     ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA4 amount edit voids AED 1,000 once, replaces it with AED 900, and reopens AED 100',
    count($qa4Payments) === 2
        && (string) ($qa4Payments[0]['status'] ?? '') === 'void'
        && round((float) ($qa4Payments[0]['paid_amount'] ?? 0), 2) === 1000.00
        && (string) ($qa4Payments[1]['status'] ?? '') !== 'void'
        && round((float) ($qa4Payments[1]['paid_amount'] ?? 0), 2) === 900.00
        && (string) ($qa4Payments[1]['currency'] ?? '') === 'AED'
        && round((float) ($qa4['supplier_open'] ?? 0), 2) === 100.00,
    ['payments' => $qa4Payments, 'supplier_open' => $qa4['supplier_open'] ?? null]
);

$qa5 = $findCustomer($rows, 'QA-RIAZ-CASH-REFUND');
$qa5ReceiptStatement = $db->prepare(
    'SELECT received_amount, allocated_amount, unallocated_amount, returned_amount
     FROM customer_receipts
     WHERE booking_reference = :booking_reference AND status <> "void"
     ORDER BY id DESC LIMIT 1'
);
$qa5ReceiptStatement->execute(['booking_reference' => (string) ($qa5['booking_reference'] ?? '')]);
$qa5Receipt = $qa5ReceiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$qa5Payment = $db->query(
    'SELECT paid_amount, allocated_amount, unallocated_amount, returned_amount
     FROM supplier_payments
     WHERE reference_number = "QA-RZ-QA5-CASH-REFUND"
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA5 cash cancellation retains PKR 15,000 and returns PKR 40,000 on both customer and supplier money',
    (string) ($qa5['service_status'] ?? '') === 'Cancelled'
        && round((float) ($qa5['due_amount'] ?? 0), 2) === 15000.00
        && round((float) ($qa5['customer_open'] ?? -1), 2) === 0.00
        && round((float) ($qa5Receipt['returned_amount'] ?? 0), 2) === 40000.00
        && round((float) ($qa5Payment['returned_amount'] ?? 0), 2) === 40000.00
        && round((float) ($qa5['supplier_open'] ?? -1), 2) === 0.00,
    ['position' => $qa5, 'receipt' => $qa5Receipt, 'supplier_payment' => $qa5Payment]
);

$qa6 = $findCustomer($rows, 'QA-RIAZ-CREDIT-CUSTOMER');
$creditRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => (string) $row['customer'] === 'QA-RIAZ-CREDIT-CUSTOMER'
));
usort($creditRows, static fn (array $left, array $right): int => (int) $left['booking_id'] <=> (int) $right['booking_id']);
$qa6 = $creditRows[0] ?? [];
$qa7 = $creditRows[1] ?? [];
$qa6ReceiptStatement = $db->prepare(
    'SELECT id, allocated_amount, unallocated_amount, returned_amount
     FROM customer_receipts
     WHERE booking_reference = :booking_reference AND status <> "void"
     ORDER BY id DESC LIMIT 1'
);
$qa6ReceiptStatement->execute(['booking_reference' => (string) ($qa6['booking_reference'] ?? '')]);
$qa6Receipt = $qa6ReceiptStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA6/QA7 transfer PKR 30,000 customer credit, leave PKR 10,000 reusable, and apply PKR 40,000 supplier credit',
    count($creditRows) === 2
        && (string) ($qa6['service_status'] ?? '') === 'Cancelled'
        && round((float) ($qa6Receipt['unallocated_amount'] ?? 0), 2) === 10000.00
        && round((float) ($qa6Receipt['returned_amount'] ?? -1), 2) === 0.00
        && round((float) ($qa7['customer_allocated'] ?? 0), 2) === 30000.00
        && round((float) ($qa7['customer_open'] ?? 0), 2) === 35000.00
        && round((float) ($qa7['advance_applied_amount'] ?? 0), 2) === 40000.00
        && round((float) ($qa7['supplier_open'] ?? 0), 2) === 20000.00,
    ['source' => $qa6, 'target' => $qa7, 'source_receipt' => $qa6Receipt]
);

$qa8 = $findCustomer($rows, 'QA-RIAZ-WRONG-PAYMENT');
$qa9 = $findCustomer($rows, 'QA-RIAZ-RIGHT-PAYMENT');
$correctedPayment = $db->query(
    'SELECT p.id, p.payment_no, p.paid_amount, p.currency, p.status, s.name supplier,
            COUNT(a.id) allocations,
            GROUP_CONCAT(o.booking_reference ORDER BY a.id) allocated_bookings
     FROM supplier_payments p
     INNER JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN supplier_payment_allocations a ON a.supplier_payment_id = p.id
     LEFT JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
     WHERE p.reference_number = "QA-RZ-PAYMENT-SUPPLIER-CORRECTED"
     GROUP BY p.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA8/QA9 move the existing PKR 45,000 payment to the correct supplier without duplicating cash',
    round((float) ($qa8['supplier_open'] ?? 0), 2) === 45000.00
        && round((float) ($qa9['supplier_open'] ?? -1), 2) === 0.00
        && (string) ($correctedPayment['supplier'] ?? '') === 'QA RIGHT PAYMENT SUPPLIER'
        && round((float) ($correctedPayment['paid_amount'] ?? 0), 2) === 45000.00
        && (int) ($correctedPayment['allocations'] ?? 0) === 1
        && (string) ($correctedPayment['allocated_bookings'] ?? '') === (string) ($qa9['booking_reference'] ?? ''),
    ['wrong_booking' => $qa8, 'right_booking' => $qa9, 'payment' => $correctedPayment]
);

$qa10 = $findCustomer($rows, 'QA-RIAZ-OVERPAYMENT');
$qa11 = $findCustomer($rows, 'QA-RIAZ-ADVANCE-NEXT');
$advance = $db->query(
    'SELECT a.deposit_amount, a.available_amount, s.name supplier
     FROM supplier_advances a
     INNER JOIN suppliers s ON s.id = a.supplier_id
     WHERE s.name = "QA ADVANCE AIR"
     ORDER BY a.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    'QA10/QA11 convert PKR 5,000 overpayment to advance and consume it against the next payable',
    round((float) ($qa10['supplier_open'] ?? -1), 2) === 0.00
        && round((float) ($advance['deposit_amount'] ?? 0), 2) === 5000.00
        && round((float) ($advance['available_amount'] ?? -1), 2) === 0.00
        && round((float) ($qa11['advance_applied_amount'] ?? 0), 2) === 5000.00
        && round((float) ($qa11['supplier_open'] ?? 0), 2) === 3000.00,
    ['source' => $qa10, 'target' => $qa11, 'advance' => $advance]
);

$qa12 = $findCustomer($rows, 'QA-RIAZ-BOOKING-SUPPLIER-EDIT');
$oldQa12ObligationCountStatement = $db->prepare(
    'SELECT COUNT(*)
     FROM supplier_obligations o
     INNER JOIN suppliers s ON s.id = o.supplier_id
     WHERE o.booking_reference = :booking_reference
       AND s.name = "QA WRONG BOOKING SUPPLIER"
       AND o.net_payable_amount > 0.005'
);
$oldQa12ObligationCountStatement->execute(['booking_reference' => (string) ($qa12['booking_reference'] ?? '')]);
$check(
    'QA12 booking supplier correction moves its active payable to the corrected supplier',
    (string) ($qa12['supplier'] ?? '') === 'QA CORRECT BOOKING SUPPLIER'
        && round((float) ($qa12['supplier_open'] ?? 0), 2) === 30000.00
        && (int) $oldQa12ObligationCountStatement->fetchColumn() === 0,
    $qa12
);

$currencyMismatchCount = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT p.id
        FROM supplier_payments p
        INNER JOIN supplier_payment_allocations a ON a.supplier_payment_id = p.id
        INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
        WHERE LOWER(REPLACE(p.status, " ", "_")) <> "void"
        GROUP BY p.id, p.currency
        HAVING COUNT(DISTINCT o.currency) <> 1
            OR MAX(UPPER(TRIM(o.currency))) <> UPPER(TRIM(p.currency))
     ) mismatches'
)->fetchColumn();
$check('Every active supplier payment matches its allocated payable currency', $currencyMismatchCount === 0, [
    'mismatches' => $currencyMismatchCount,
]);

$receiptDriftCount = (int) $db->query(
    'SELECT COUNT(*)
     FROM customer_receipts
     WHERE ABS(received_amount - allocated_amount - unallocated_amount - returned_amount) > 0.005'
)->fetchColumn();
$supplierPaymentDriftCount = (int) $db->query(
    'SELECT COUNT(*)
     FROM supplier_payments
     WHERE LOWER(REPLACE(status, " ", "_")) <> "void"
       AND ABS(paid_amount - allocated_amount - unallocated_amount - returned_amount - converted_advance_amount) > 0.005'
)->fetchColumn();
$journalImbalanceCount = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT journal_entry_id
        FROM journal_entry_lines
        GROUP BY journal_entry_id
        HAVING ABS(SUM(debit_amount) - SUM(credit_amount)) > 0.005
     ) broken'
)->fetchColumn();
$check('All retained receipts conserve value', $receiptDriftCount === 0, ['broken_receipts' => $receiptDriftCount]);
$check('All retained supplier payments conserve value', $supplierPaymentDriftCount === 0, ['broken_payments' => $supplierPaymentDriftCount]);
$check('Every retained journal remains balanced', $journalImbalanceCount === 0, ['unbalanced_journals' => $journalImbalanceCount]);

$businessSourceId = (int) ($sourceIds[0] ?? 0);
$accountLedger = new \App\Services\AccountLedgerService($app);
$accountReport = $accountLedger->report([
    'dateFrom' => null,
    'dateTo' => null,
    'currency' => '',
    'businessSourceId' => $businessSourceId,
    'customerName' => '',
    'bookingReference' => '',
], [1, 2]);
$accountRows = (array) ($accountReport['rows'] ?? []);
$accountBookingReferences = array_values(array_unique(array_filter(array_map(
    static fn (array $row): string => (string) ($row['booking_reference'] ?? ''),
    $accountRows
))));
$check(
    'Riaz Account Ledger exposes retained cash/payment/refund activity',
    count($accountRows) > 0
        && in_array((string) ($qa1['booking_reference'] ?? ''), $accountBookingReferences, true)
        && in_array((string) ($qa5['booking_reference'] ?? ''), $accountBookingReferences, true)
        && in_array((string) ($qa7['booking_reference'] ?? ''), $accountBookingReferences, true),
    [
        'row_count' => count($accountRows),
        'booking_references' => $accountBookingReferences,
        'profit_cards' => (array) ($accountReport['summaryCards'] ?? []),
    ]
);

echo PHP_EOL . 'Manual inspection map:' . PHP_EOL;
foreach ($rows as $row) {
    echo ' - ' . $row['booking_reference']
        . ' | ' . $row['customer']
        . ' | ' . $row['supplier']
        . ' | cost ' . $row['cost_currency'] . ' ' . number_format((float) $row['purchase_cost'], 2)
        . ' | invoice ' . $row['invoice_currency'] . ' ' . number_format((float) $row['final_sale_price'], 2)
        . ' | customer open ' . number_format((float) $row['customer_open'], 2)
        . ' | supplier open ' . number_format((float) $row['supplier_open'], 2)
        . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'RESULT: FAIL' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'RESULT: PASS - persistent Riaz scenario is internally consistent and remains available for manual review.' . PHP_EOL;

