<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$booking = $db->query('SELECT branch_id, lead_traveler_id FROM bookings WHERE booking_reference = "BK-000558"')->fetch(PDO::FETCH_ASSOC);
if (! $booking || (int) $booking['lead_traveler_id'] !== 477) {
    fwrite(STDERR, "BK-000558 customer-change fixture is missing.\n");
    exit(1);
}

$payments = new \App\Repositories\CustomerPaymentRepository($app);
$reports = new \App\Repositories\ReportRepository($app);
$currentIds = array_map(static fn (array $row): int => (int) $row['id'],
    $payments->availableBookingCredits((int) $booking['branch_id'], 477, 'PKR'));
$formerIds = array_map(static fn (array $row): int => (int) $row['id'],
    $payments->availableBookingCredits((int) $booking['branch_id'], 531, 'PKR'));
$positions = $reports->customerAvailableCreditPositions([(int) $booking['branch_id']], 'PKR');
$targetPositions = array_values(array_filter($positions,
    static fn (array $row): bool => in_array((int) $row['receipt_id'], [999, 1137], true)));
$akbarFiltered = $reports->customerAvailableCreditPositions([(int) $booking['branch_id']], 'PKR', 'AKBAR ZAIB AGENT');
$javedFiltered = $reports->customerAvailableCreditPositions([(int) $booking['branch_id']], 'PKR', 'JAVED IQBAL AGENT');
$filteredIds = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['receipt_id'], $rows);
$customers = array_column($reports->customerAdvanceCustomers([(int) $booking['branch_id']]), 'customer_name');

$rows = $db->query('SELECT id, traveler_id, received_amount, allocated_amount, unallocated_amount, returned_amount
    FROM customer_receipts WHERE id IN (999, 1137) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$sourceConserved = count($rows) === 2;
foreach ($rows as $row) {
    $sourceConserved = $sourceConserved
        && (int) $row['traveler_id'] === 531
        && abs((float) $row['received_amount'] - (float) $row['allocated_amount']
            - (float) $row['unallocated_amount'] - (float) $row['returned_amount']) < 0.005;
}
$positionCorrect = count($targetPositions) === 2;
foreach ($targetPositions as $row) {
    $positionCorrect = $positionCorrect
        && (int) $row['traveler_id'] === 477
        && (string) $row['customer_name'] === 'AKBAR ZAIB AGENT';
}
$amount = array_sum(array_map(static fn (array $row): float => (float) $row['available_amount'], $targetPositions));
$availableSource = array_sum(array_map(static fn (array $row): float => (float) $row['unallocated_amount'], $rows));
$checks = [
    'Credits no longer offered to former booking customer' => ! in_array(999, $formerIds, true) && ! in_array(1137, $formerIds, true),
    'Original receipt payer and money conservation preserved' => $sourceConserved,
];
if (abs($availableSource - 93000) < 0.005) {
    $checks['Current customer appears in customer credit filter'] = in_array('AKBAR ZAIB AGENT', $customers, true);
    $checks['PKR 14,000 and 79,000 credits offered to current booking customer'] =
        in_array(999, $currentIds, true) && in_array(1137, $currentIds, true);
    $checks['Available credit report names and totals agree'] = $positionCorrect && abs($amount - 93000) < 0.005;
    $checks['Customer-filtered credit report follows current booking customer'] =
        in_array(999, $filteredIds($akbarFiltered), true)
        && in_array(1137, $filteredIds($akbarFiltered), true)
        && ! in_array(999, $filteredIds($javedFiltered), true)
        && ! in_array(1137, $filteredIds($javedFiltered), true);
} else {
    $allocated = $db->query('SELECT cra.customer_receipt_id, SUM(cra.payment_amount_consumed) AS amount
        FROM customer_receipt_allocations cra
        INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
        WHERE cri.booking_reference = "BK-001183" AND cra.customer_receipt_id IN (999, 1137)
        GROUP BY cra.customer_receipt_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $checks['Consumed credit is absent from both available-credit lists'] =
        abs($availableSource) < 0.005 && ! in_array(999, $currentIds, true)
        && ! in_array(1137, $currentIds, true) && count($targetPositions) === 0;
    $checks['Both receipts are traced to BK-001183 exactly once'] =
        count($allocated) === 2 && abs((float) $allocated[999] - 14000) < 0.005
        && abs((float) $allocated[1137] - 79000) < 0.005;
}
$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}
exit($failed ? 1 : 0);
