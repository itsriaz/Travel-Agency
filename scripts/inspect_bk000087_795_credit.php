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
$read = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$bookings = $read('SELECT id, booking_reference, branch_id, lead_traveler_id, booking_status
    FROM bookings WHERE booking_reference IN (?, ?) ORDER BY booking_reference', ['BK-000087', 'BK-000795']);
$receipt = $read('SELECT id, receipt_no, booking_reference, traveler_id, branch_id, currency,
    received_amount, allocated_amount, unallocated_amount, returned_amount, status
    FROM customer_receipts WHERE id = ?', [92]);
$allocations = $read('SELECT a.id, a.customer_receipt_id, i.booking_reference AS target_booking_reference,
    a.allocated_amount, a.allocation_note
    FROM customer_receipt_allocations a
    JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
    WHERE a.customer_receipt_id = ? ORDER BY a.id', [92]);
$receivables = $read('SELECT id, booking_reference, currency, due_amount, allocated_amount,
    outstanding_amount, status FROM customer_receivable_items
    WHERE booking_reference IN (?, ?) ORDER BY id', ['BK-000087', 'BK-000795']);
$refunds = $read('SELECT id, booking_reference, event_type, event_status, customer_refund_amount
    FROM booking_service_events WHERE booking_reference IN (?, ?) ORDER BY id', ['BK-000087', 'BK-000795']);
$source = null;
$target = null;
foreach ($bookings as $booking) {
    if ($booking['booking_reference'] === 'BK-000087') { $source = $booking; }
    if ($booking['booking_reference'] === 'BK-000795') { $target = $booking; }
}
$candidateIds = [];
$refundSummary = [];
if ($target !== null) {
    $repository = new \App\Repositories\CustomerPaymentRepository($app);
    $candidateIds = array_map(static fn (array $row): int => (int) $row['id'],
        $repository->availableBookingCredits((int) $target['branch_id'], (int) $target['lead_traveler_id'], 'AED', 'BK-000795'));
}
if ($source !== null) {
    $state = (new \App\Services\ServiceWorkspaceService($app))->serviceState((int) $source['id'], [(int) $source['branch_id']]);
    $refundSummary = (array) ($state['services'][0]['customer_refund_summary'] ?? []);
}
$report = [
    'inspection_only' => true,
    'bookings' => $bookings,
    'receipt_92' => $receipt,
    'receipt_92_allocations' => $allocations,
    'receivables' => $receivables,
    'refund_events' => $refunds,
    'bk000087_customer_refund_summary' => $refundSummary,
    'same_customer_and_branch' => $source !== null && $target !== null
        && (int) $source['branch_id'] === (int) $target['branch_id']
        && (int) $source['lead_traveler_id'] > 0
        && (int) $source['lead_traveler_id'] === (int) $target['lead_traveler_id'],
    'receipt_92_offered_for_new_transfer' => in_array(92, $candidateIds, true),
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
