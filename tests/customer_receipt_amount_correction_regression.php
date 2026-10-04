<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();
$check = static function (bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException('[FAIL] ' . $message); }
    echo '[PASS] ', $message, PHP_EOL;
};
try {
    $bookingResult = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => date('Y-m-d'),
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Receipt Amount Correction Rollback Test',
    ], 1, [2]);
    $bookingId = (int) $bookingResult['booking']['id'];
    $reference = (string) $bookingResult['booking']['booking_reference'];
    $serviceResult = (new \App\Services\ServiceWorkspaceService($app))->saveService([
        'booking_id' => $bookingId, 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED', 'sale_price' => 338,
        'purchase_cost' => 100, 'final_sale_price' => 338,
        'supplier_name' => 'Receipt Correction Test Supplier',
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [2]);
    $service = $serviceResult['service'];
    $receipts = new \App\Services\CustomerReceiptWorkspaceService($app);
    $saved = $receipts->saveReceipt([
        'booking_id' => $bookingId, 'receipt_date' => date('Y-m-d'),
        'receipt_currency' => 'AED', 'received_amount' => 1668,
        'payment_method' => 'cash', 'receipt_status' => 'received',
    ], 1, [2]);
    $receiptId = (int) ($saved['receipt']['id'] ?? 0);
    $check($receiptId > 0, 'Fixture receipt was posted');
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $before = $payments->findReceiptById($receiptId);
    $receivable = $payments->findReceivableByServiceLine($reference, (string) $service['line_reference']);
    $check($before !== null && abs((float) $before['allocated_amount'] - 338) < .005
        && abs((float) $before['unallocated_amount'] - 1330) < .005,
        'Original receipt is partly allocated and the rest is credit');
    $allocationCount = (int) $db->query('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ' . $receiptId)->fetchColumn();

    $blocked = false;
    try {
        $receipts->voidReceipt([
            'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
            'void_reason' => 'Entered wrong amount',
        ], 1, [2]);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'no money was actually received');
    }
    $check($blocked && $payments->findReceiptById($receiptId)['status'] !== 'void',
        'Void without explicit no-money confirmation leaves cash and receipt untouched');

    $result = $receipts->correctReceiptAmount([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'expected_received_amount' => 1668, 'corrected_received_amount' => 1660,
        'correction_reason' => 'Customer actually paid AED 1,660',
    ], 1, [2]);
    $after = $payments->findReceiptById($receiptId);
    $check(abs((float) $after['received_amount'] - 1660) < .005
        && abs((float) $after['allocated_amount'] - 338) < .005
        && abs((float) $after['unallocated_amount'] - 1322) < .005
        && (int) $db->query('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ' . $receiptId)->fetchColumn() === $allocationCount
        && abs((float) $receivable['allocated_amount'] - 338) < .005,
        'Correction changes only actual receipt and unused credit, not invoice allocations');
    $journal = $db->query('SELECT ROUND(SUM(debit_amount),2) debit, ROUND(SUM(credit_amount),2) credit
        FROM journal_entry_lines WHERE journal_entry_id = ' . (int) $result['journal_entry_id'])->fetch(PDO::FETCH_ASSOC);
    $check(abs((float) $journal['debit'] - 8) < .005 && abs((float) $journal['credit'] - 8) < .005,
        'AED 8 difference has a balanced compensating journal');
    $audit = $db->query('SELECT COUNT(*) FROM audit_logs WHERE event_name = "customer.receipt.amount_corrected"
        AND JSON_EXTRACT(payload_json, "$.customer_receipt_id") = ' . $receiptId)->fetchColumn();
    $check((int) $audit === 1, 'Original and corrected amounts have required audit history');
    $cashBeforeVoid = $db->query('SELECT COALESCE(ROUND(SUM(l.debit_amount-l.credit_amount),2),0)
        FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id
        WHERE l.customer_receipt_id = ' . $receiptId . ' AND c.code LIKE "CASH_%"')->fetchColumn();
    $check(abs((float) $cashBeforeVoid - 1660) < .005,
        'Cash journal equals the corrected AED 1,660 actually received');

    foreach ([[1668, 1650], [1660, 300]] as [$expected, $new]) {
        $blocked = false;
        try {
            $receipts->correctReceiptAmount([
                'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
                'expected_received_amount' => $expected, 'corrected_received_amount' => $new,
                'correction_reason' => 'Invalid correction test',
            ], 1, [2]);
        } catch (RuntimeException) { $blocked = true; }
        $check($blocked && abs((float) $payments->findReceiptById($receiptId)['received_amount'] - 1660) < .005,
            'Stale or below-allocation correction is rejected without changing cash');
    }
    $receipts->voidReceipt([
        'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
        'void_reason' => 'No actual customer money was received in rollback fixture',
        'no_money_received_confirmed' => '1',
    ], 1, [2]);
    $cashBalance = $db->query('SELECT COALESCE(ROUND(SUM(l.debit_amount-l.credit_amount),2),0)
        FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id
        WHERE l.customer_receipt_id = ' . $receiptId . ' AND c.code LIKE "CASH_%"')->fetchColumn();
    $check(abs((float) $cashBalance) < .005,
        'Later confirmed void reverses the corrected receipt, not the stale original amount');
    $blocked = false;
    try {
        $receipts->correctReceiptAmount([
            'booking_id' => $bookingId, 'customer_receipt_id' => $receiptId,
            'expected_received_amount' => 1660, 'corrected_received_amount' => 1650,
            'correction_reason' => 'Invalid voided receipt correction',
        ], 1, [2]);
    } catch (RuntimeException) { $blocked = true; }
    $check($blocked && $payments->findReceiptById($receiptId)['status'] === 'void',
        'Voided receipts cannot be amount-corrected or silently revived');
    $db->rollBack();
    echo 'All fixture data rolled back.', PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
