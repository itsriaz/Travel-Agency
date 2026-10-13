<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$payments = new \App\Repositories\CustomerPaymentRepository($app);
$receivable = $payments->findReceivableByServiceLine('BK-000897', 'SV-001');
$visible = array_values(array_filter($payments->serviceWiseOutstanding('BK-000897'),
    static fn (array $row): bool => $row['service_line_reference'] === 'SV-001' && $row['currency'] === 'PKR'));
$event = $db->query('SELECT payload_json FROM booking_service_events WHERE id = 57 AND event_status = "posted"')
    ->fetch(PDO::FETCH_ASSOC);
$payload = is_array($event) ? json_decode((string) $event['payload_json'], true) : null;
$receiptRow = $db->query('SELECT id, received_amount FROM customer_receipts
    WHERE booking_reference = "BK-000897" AND status <> "void" ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$bookingCashReceived = (float) $db->query('SELECT COALESCE(SUM(received_amount), 0) FROM customer_receipts
    WHERE booking_reference = "BK-000897" AND status <> "void"')->fetchColumn();
$allocationRows = $db->query('SELECT a.receivable_amount_allocated, a.allocated_amount, r.booking_reference, r.status
    FROM customer_receipt_allocations a
    INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
    WHERE a.customer_receivable_item_id = 912')->fetchAll(PDO::FETCH_ASSOC);
$activeAllocated = 0.0;
$crossBookingAllocated = 0.0;
$ownBookingAllocated = 0.0;
foreach ($allocationRows as $allocationRow) {
    if ($allocationRow['status'] === 'void') {
        continue;
    }
    $amount = (float) ($allocationRow['receivable_amount_allocated'] ?? $allocationRow['allocated_amount'] ?? 0);
    $activeAllocated += $amount;
    if ($allocationRow['booking_reference'] !== 'BK-000897') {
        $crossBookingAllocated += $amount;
    } else {
        $ownBookingAllocated += $amount;
    }
}
$refundPaid = (float) $db->query('SELECT COALESCE(SUM(customer_refund_amount), 0)
    FROM booking_service_events WHERE booking_reference = "BK-000897"
      AND event_type = "refund" AND event_status = "posted"')->fetchColumn();
$near = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) < .005;
$allocationHistory = is_array($receivable)
    ? $payments->allocationHistoryForReceivableItems([(int) $receivable['id']])
    : [];
$allocationOrderIsChronological = count($allocationHistory) >= 2;
foreach ($allocationHistory as $index => $allocation) {
    if ($index === 0) {
        continue;
    }
    $previous = $allocationHistory[$index - 1];
    $previousKey = [(string) $previous['allocated_at'], (int) $previous['allocation_id']];
    $currentKey = [(string) $allocation['allocated_at'], (int) $allocation['allocation_id']];
    if ($previousKey > $currentKey) {
        $allocationOrderIsChronological = false;
        break;
    }
}
$checks = [
    'Cancellation final charge is PKR 30,000' => is_array($payload)
        && $near($payload['customer_final_charge_amount'] ?? -1, 30000),
    'Cancellation receivable stays PKR 30,000 and allocations reconcile' => is_array($receivable)
        && $near($receivable['due_amount'], 30000)
        && $near($receivable['allocated_amount'], $activeAllocated)
        && $near($receivable['outstanding_amount'], 30000 - $activeAllocated),
    'Cancelled service remains visible in the workspace balance source' => count($visible) === 1
        && $near($visible[0]['outstanding_amount'], (float) ($receivable['outstanding_amount'] ?? -1)),
    'Cross-booking credit is separate from cash received on this booking' => $near($activeAllocated, $ownBookingAllocated + $crossBookingAllocated)
        && $ownBookingAllocated <= $bookingCashReceived + 0.005
        && $near($refundPaid, 0),
    'Allocation history follows allocation time rather than source receipt date' => $allocationOrderIsChronological,
    'Allocation history progresses from PKR 10,000 remaining to PKR 0' => count($allocationHistory) === 2
        && $near($allocationHistory[0]['remaining_after_allocation'] ?? -1, 10000)
        && $near($allocationHistory[1]['remaining_after_allocation'] ?? -1, 0),
];
if (is_array($receiptRow) && is_array($receivable)) {
    $bookingId = (int) $db->query('SELECT id FROM bookings WHERE booking_reference = "BK-000897"')->fetchColumn();
    $db->beginTransaction();
    try {
        $document = (new \App\Services\OperationalOutputService($app))->buildOutputDocument(
            $bookingId, 'customer_receipt', [1, 2], (int) $receiptRow['id'], null, null, 10
        );
        $html = (string) $app->get('view')->render('workspace/output', $document, 'layouts/print');
        $receiptText = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
        $checks['Printable receipt uses the settled cancellation charge, not original sale'] =
            str_contains($receiptText, 'Air Ticket (Cancelled)')
            && str_contains($receiptText, 'Subtotal PKR ' . number_format((float) $receivable['due_amount']))
            && str_contains($receiptText, 'Current Invoice Amount PKR ' . number_format((float) $receivable['due_amount']));
        $checks['Printable receipt shows the saved outstanding, not PKR 40,000'] =
            str_contains($receiptText, 'Invoice Outstanding Balance PKR '
                . number_format((float) $receivable['outstanding_amount']));
    } finally {
        $db->rollBack();
    }
}
$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}
exit($failed ? 1 : 0);
