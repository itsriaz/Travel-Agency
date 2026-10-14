<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$events = new \App\Repositories\BookingServiceEventRepository($app);
$customers = new \App\Repositories\CustomerPaymentRepository($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$services = new \App\Services\ServiceWorkspaceService($app);
$cancel = $events->latestPostedEvent(38, 'cancel');
$payload = json_decode((string) ($cancel['payload_json'] ?? ''), true);
$atOriginal = is_array($payload)
    && (float) ($payload['expected_supplier_refund_source_amount'] ?? 0) === 60942.0
    && (float) ($payload['expected_supplier_refund_booking_amount'] ?? 0) === 700.0
    && (float) ($payload['customer_penalty_amount'] ?? 0) === 53.0;
$atCorrected = is_array($payload)
    && (float) ($payload['expected_supplier_refund_source_amount'] ?? 0) === 50942.0
    && (float) ($payload['expected_supplier_refund_booking_amount'] ?? 0) === 661.0
    && (float) ($payload['customer_penalty_amount'] ?? 0) === 61.0;
if (! $atOriginal && ! $atCorrected) {
    echo '[SKIP] BK-000038 is not at either known regression baseline.' . PHP_EOL;
    exit(0);
}
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
    echo '[PASS] ' . $message . PHP_EOL;
};
if ($atCorrected) {
    $booking = $db->query('SELECT booking_reference FROM bookings WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
    $line = $db->query('SELECT line_reference FROM booking_services WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
    $cashRowsBefore = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
    $db->beginTransaction();
    try {
        $base = [
            'booking_id' => 38, 'service_id' => 38,
            'correction_reason' => 'Rollback-only reverse and forward correction regression',
            'correction_event_date' => date('Y-m-d'),
            'customer_penalty_currency' => 'AED',
            'agency_fee_refund_amount' => 0, 'agency_fee_refund_currency' => 'AED',
            'expected_supplier_refund_currency' => 'PKR',
            'supplier_penalty_currency' => 'PKR',
            'customer_refund_amount' => 600, 'customer_refund_source_amount' => 600,
            'customer_refund_currency' => 'AED', 'refund_payment_method' => 'cash',
            'supplier_refund_amount' => 0, 'supplier_refund_source_amount' => 0,
            'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'cash',
            'settlement_currency' => 'AED', 'refund_currency' => 'AED',
            'supplier_refund_conversion_mode' => 'manual_equivalent',
        ];
        $services->correctPenaltyRefundWorkflow($base + [
            'customer_penalty_amount' => 53,
            'expected_supplier_refund_amount' => 60942,
            'expected_supplier_refund_equivalent_amount' => 700,
            'supplier_penalty_amount' => 30000,
        ], 1, [1, 2]);
        $receivable = $customers->findReceivableByServiceLine((string) $booking['booking_reference'], (string) $line['line_reference']);
        $receipt = $customers->findReceiptById(38);
        $obligation = $suppliers->findObligationByServiceLine((string) $booking['booking_reference'], (string) $line['line_reference']);
        $assert((float) $receivable['due_amount'] === 533.0
            && (float) $receipt['unallocated_amount'] === 47.0
            && (float) $obligation['gross_amount'] === 30000.0,
            'Decreasing penalty and increasing supplier refund restore AED47 credit and PKR30000 payable');
        $services->correctPenaltyRefundWorkflow($base + [
            'customer_penalty_amount' => 61,
            'expected_supplier_refund_amount' => 50942,
            'expected_supplier_refund_equivalent_amount' => 661,
            'supplier_penalty_amount' => 40000,
        ], 1, [1, 2]);
        $receivable = $customers->findReceivableByServiceLine((string) $booking['booking_reference'], (string) $line['line_reference']);
        $receipt = $customers->findReceiptById(38);
        $obligation = $suppliers->findObligationByServiceLine((string) $booking['booking_reference'], (string) $line['line_reference']);
        $assert((float) $receivable['due_amount'] === 580.0
            && (float) $receipt['unallocated_amount'] === 0.0
            && (float) $obligation['gross_amount'] === 40000.0,
            'Increasing penalty and decreasing supplier refund consume only the AED47 credit');
        $assert(abs((float) $receipt['received_amount'] - (float) $receipt['allocated_amount']
            - (float) $receipt['unallocated_amount'] - (float) $receipt['returned_amount']) < 0.005
            && (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $cashRowsBefore,
            'Receipt conservation and cash transactions stay unchanged in both directions');
    } finally {
        $db->rollBack();
    }
    echo 'All BK-000038 changes were rolled back.' . PHP_EOL;
    exit(0);
}
$cashRowsBefore = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
$journalBefore = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
$db->beginTransaction();
try {
    $services->correctPenaltyRefundWorkflow([
        'booking_id' => 38, 'service_id' => 38,
        'correction_reason' => 'Rollback-only combined correction regression',
        'correction_event_date' => date('Y-m-d'),
        'customer_penalty_amount' => 61, 'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => 0, 'agency_fee_refund_currency' => 'AED',
        'expected_supplier_refund_amount' => 50942, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 661,
        'supplier_penalty_amount' => 40000, 'supplier_penalty_currency' => 'PKR',
        'customer_refund_amount' => 600, 'customer_refund_source_amount' => 600,
        'customer_refund_currency' => 'AED', 'refund_payment_method' => 'cash',
        'supplier_refund_amount' => 0, 'supplier_refund_source_amount' => 0,
        'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'cash',
        'settlement_currency' => 'AED', 'refund_currency' => 'AED',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
    ], 1, [1, 2]);
    $booking = $db->query('SELECT booking_reference FROM bookings WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
    $line = $db->query('SELECT line_reference FROM booking_services WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
    $reference = (string) $booking['booking_reference'];
    $lineReference = (string) $line['line_reference'];
    $after = json_decode((string) $events->latestPostedEvent(38, 'cancel')['payload_json'], true);
    $receivable = $customers->findReceivableByServiceLine($reference, $lineReference);
    $obligation = $suppliers->findObligationByServiceLine($reference, $lineReference);
    $receipt = $customers->findReceiptById(38);
    $assert((float) $after['customer_penalty_amount'] === 61.0
        && (float) $after['expected_supplier_refund_source_amount'] === 50942.0
        && (float) $after['expected_supplier_refund_booking_amount'] === 661.0,
        'The entered AED penalty, PKR refund, and AED equivalent are all retained');
    $assert((float) $receivable['due_amount'] === 580.0
        && (float) $receivable['allocated_amount'] === 580.0
        && (float) $receipt['unallocated_amount'] === 0.0
        && abs((float) $receipt['received_amount'] - (float) $receipt['allocated_amount']
            - (float) $receipt['unallocated_amount'] - (float) $receipt['returned_amount']) < 0.005,
        'The AED 47 remaining credit is allocated to the corrected customer charge');
    $state = $services->serviceState(38, [1, 2]);
    $serviceRow = array_values(array_filter((array) ($state['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) ($serviceRow['customer_refundable_credit_amount'] ?? -1) === 0.0,
        'The displayed remaining customer refund is AED 0 after correction');
    $assert((float) $obligation['gross_amount'] === 40000.0
        && (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $cashRowsBefore,
        'The supplier payable rises by PKR 10,000 without another cash movement');
    $statement = $db->prepare('SELECT je.id, SUM(jel.debit_amount) AS debit, SUM(jel.credit_amount) AS credit
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        WHERE je.id > :last_id GROUP BY je.id');
    $statement->execute(['last_id' => $journalBefore]);
    $journals = $statement->fetchAll(PDO::FETCH_ASSOC);
    $assert($journals !== [] && array_reduce($journals, static fn (bool $ok, array $row): bool =>
        $ok && abs((float) $row['debit'] - (float) $row['credit']) < 0.005, true),
        'Every correction journal is balanced');
    $services->correctPenaltyRefundWorkflow([
        'booking_id' => 38, 'service_id' => 38,
        'correction_reason' => 'Rollback-only restore of reported starting values',
        'correction_event_date' => date('Y-m-d'),
        'customer_penalty_amount' => 53, 'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => 0, 'agency_fee_refund_currency' => 'AED',
        'expected_supplier_refund_amount' => 60942, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 700,
        'supplier_penalty_amount' => 30000, 'supplier_penalty_currency' => 'PKR',
        'customer_refund_amount' => 600, 'customer_refund_source_amount' => 600,
        'customer_refund_currency' => 'AED', 'refund_payment_method' => 'cash',
        'supplier_refund_amount' => 0, 'supplier_refund_source_amount' => 0,
        'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'cash',
        'settlement_currency' => 'AED', 'refund_currency' => 'AED',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
    ], 1, [1, 2]);
    $restoredReceivable = $customers->findReceivableByServiceLine($reference, $lineReference);
    $restoredReceipt = $customers->findReceiptById(38);
    $assert((float) $restoredReceivable['due_amount'] === 533.0
        && (float) $restoredReceipt['unallocated_amount'] === 47.0
        && (float) $suppliers->findObligationByServiceLine($reference, $lineReference)['gross_amount'] === 30000.0,
        'The opposite correction restores the AED 47 credit and PKR supplier payable');
    $restoredState = $services->serviceState(38, [1, 2]);
    $restoredRow = array_values(array_filter((array) ($restoredState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === 38))[0] ?? [];
    $assert((float) ($restoredRow['customer_refundable_credit_amount'] ?? -1) === 47.0,
        'The displayed remaining customer refund returns to AED 47 after reversal');
} finally {
    $db->rollBack();
}
echo 'All BK-000038 changes were rolled back.' . PHP_EOL;
