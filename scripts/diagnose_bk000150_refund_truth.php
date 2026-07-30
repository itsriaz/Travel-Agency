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

$bookingReference = 'BK-000150';
$bookingStatement = $db->prepare(
    'SELECT b.id, b.branch_id, b.booking_reference, b.business_source_id, b.lead_traveler_id
     FROM bookings b
     WHERE b.booking_reference = :booking_reference
     LIMIT 1'
);
$bookingStatement->execute(['booking_reference' => $bookingReference]);
$booking = $bookingStatement->fetch(PDO::FETCH_ASSOC);

echo 'BK-000150 refund truth diagnostic (read-only)' . PHP_EOL;
if ($booking === false) {
    echo '[FAIL] Booking was not found.' . PHP_EOL;
    exit(1);
}

$serviceStatement = $db->prepare(
    'SELECT bs.id, bs.line_reference, bs.supplier_id, bs.currency, bs.sale_price,
            bs.purchase_cost, bs.final_sale_price, bs.service_status,
            COALESCE(s.name, bs.supplier_name_snapshot, "N/A") AS supplier_name
     FROM booking_services bs
     LEFT JOIN suppliers s ON s.id = bs.supplier_id
     WHERE bs.booking_id = :booking_id
     ORDER BY bs.id'
);
$serviceStatement->execute(['booking_id' => (int) $booking['id']]);
$services = $serviceStatement->fetchAll(PDO::FETCH_ASSOC);

$eventStatement = $db->prepare(
    'SELECT bse.id, bse.booking_service_id, bse.event_type, bse.event_status,
            bse.event_date, bse.currency, bse.customer_refund_amount,
            bse.supplier_refund_amount, bse.payload_json
     FROM booking_service_events bse
     WHERE bse.booking_id = :booking_id
       AND bse.event_type IN ("cancel", "refund")
     ORDER BY bse.event_date, bse.id'
);
$eventStatement->execute(['booking_id' => (int) $booking['id']]);
$events = $eventStatement->fetchAll(PDO::FETCH_ASSOC);

$receiptStatement = $db->prepare(
    'SELECT cr.id, cr.receipt_no, cr.currency, cr.received_amount, cr.allocated_amount,
            cr.unallocated_amount, cr.returned_amount, cr.status,
            cr.treasury_account_id, ta.account_name AS treasury_account_name
     FROM customer_receipts cr
     LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
     WHERE cr.booking_reference = :booking_reference
     ORDER BY cr.id'
);
$receiptStatement->execute(['booking_reference' => $bookingReference]);
$receipts = $receiptStatement->fetchAll(PDO::FETCH_ASSOC);

$supplierIds = array_values(array_unique(array_filter(array_map(
    static fn (array $service): int => (int) ($service['supplier_id'] ?? 0),
    $services
))));
$advances = [];
$allSupplierAdvances = [];
$applications = [];
if ($supplierIds !== []) {
    $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
    $allAdvanceStatement = $db->prepare(
        'SELECT sa.id, sa.supplier_id, s.name AS supplier_name, sa.branch_id, sa.currency,
                sa.deposit_amount, sa.available_amount, sa.reference_no, sa.remarks,
                sa.source_supplier_payment_id
         FROM supplier_advances sa
         INNER JOIN suppliers s ON s.id = sa.supplier_id
         WHERE sa.supplier_id IN (' . $placeholders . ')
         ORDER BY sa.id'
    );
    $allAdvanceStatement->execute($supplierIds);
    $allSupplierAdvances = $allAdvanceStatement->fetchAll(PDO::FETCH_ASSOC);

    $advanceStatement = $db->prepare(
        'SELECT sa.id, sa.supplier_id, s.name AS supplier_name, sa.branch_id, sa.currency,
                sa.deposit_amount, sa.available_amount, sa.reference_no, sa.remarks,
                sa.source_supplier_payment_id
         FROM supplier_advances sa
         INNER JOIN suppliers s ON s.id = sa.supplier_id
         WHERE sa.supplier_id IN (' . $placeholders . ')
           AND (
                sa.reference_no LIKE ?
                OR sa.remarks LIKE ?
                OR sa.source_supplier_payment_id IN (
                    SELECT sp.id FROM supplier_payments sp
                    WHERE sp.booking_reference = ?
                )
           )
         ORDER BY sa.id'
    );
    $advanceStatement->execute([...$supplierIds, '%' . $bookingReference . '%', '%' . $bookingReference . '%', $bookingReference]);
    $advances = $advanceStatement->fetchAll(PDO::FETCH_ASSOC);

    $advanceIds = array_map(static fn (array $advance): int => (int) $advance['id'], $advances);
    if ($advanceIds !== []) {
        $applicationPlaceholders = implode(',', array_fill(0, count($advanceIds), '?'));
        $applicationStatement = $db->prepare(
            'SELECT saa.id, saa.supplier_advance_id, saa.supplier_obligation_id,
                    saa.applied_amount, saa.applied_at,
                    so.booking_reference, so.service_line_reference
             FROM supplier_advance_applications saa
             INNER JOIN supplier_obligations so ON so.id = saa.supplier_obligation_id
             WHERE saa.supplier_advance_id IN (' . $applicationPlaceholders . ')
             ORDER BY saa.id'
        );
        $applicationStatement->execute($advanceIds);
        $applications = $applicationStatement->fetchAll(PDO::FETCH_ASSOC);
    }
}

$reportRepository = new \App\Repositories\ReportRepository($app);
$reportService = new \App\Services\ReportService($app);
$rawLedgerRows = $reportRepository->customerLedger(
    [(int) $booking['branch_id']],
    null,
    null,
    '',
    0,
    '',
    $bookingReference
);
$reportMethod = new ReflectionMethod($reportService, 'customerLedgerReport');
[$ledgerRows, $summaryCards] = $reportMethod->invoke(
    $reportService,
    $rawLedgerRows,
    ['PKR' => 1.0, 'AED' => 1.0, 'USD' => 1.0],
    'customer',
    true
);
$accountLedger = (new \App\Services\AccountLedgerService($app))->report(
    [
        'currency' => 'AED',
        'businessSourceId' => (int) ($booking['business_source_id'] ?? 0),
        'bookingReference' => $bookingReference,
    ],
    [(int) $booking['branch_id']]
);

$display = static function (string $label, array $rows): void {
    echo PHP_EOL . $label . ':' . PHP_EOL;
    echo $rows === []
        ? '[]' . PHP_EOL
        : json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
};

$display('Booking services', $services);
$display('Cancellation/refund events', $events);
$display('Customer receipts', $receipts);
$display('Supplier advances created by/for this booking', $advances);
$display('All advances for the booking supplier', $allSupplierAdvances);
$display('Applications of those supplier advances', $applications);
$display('Customer ledger summary cards', $summaryCards);
$display('Customer ledger rows', array_map(
    static fn (array $row): array => [
        'entry' => $row['ledger_entry'] ?? '',
        'currency' => $row['currency'] ?? '',
        'debit' => $row['raw_debit_amount'] ?? 0,
        'credit' => $row['raw_credit_amount'] ?? 0,
        'balance' => $row['balance_amount'] ?? '',
    ],
    $ledgerRows
));
$display('Account ledger summary', $accountLedger['summaryRows'] ?? []);
$display('Account ledger rows', array_map(
    static fn (array $row): array => [
        'entry' => $row['ledger_entry'] ?? '',
        'status' => $row['status'] ?? '',
        'currency' => $row['currency'] ?? '',
        'debit' => $row['raw_debit_amount'] ?? 0,
        'credit' => $row['raw_credit_amount'] ?? 0,
        'balance' => $row['balance_amount'] ?? '',
        'transaction_detail' => $row['transaction_detail'] ?? '',
    ],
    $accountLedger['rows'] ?? []
));

echo PHP_EOL . 'RESULT: read-only diagnostic complete.' . PHP_EOL;
