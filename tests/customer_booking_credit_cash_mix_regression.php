<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$booking = $db->query('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference="BK-001183"')
    ->fetch(PDO::FETCH_ASSOC);
$due = $db->query('SELECT due_amount, allocated_amount, outstanding_amount
    FROM customer_receivable_items WHERE booking_reference="BK-001183" AND currency="PKR" ORDER BY id LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
$cash = $db->query('SELECT received_amount, allocated_amount, unallocated_amount
    FROM customer_receipts WHERE id=1304')->fetch(PDO::FETCH_ASSOC);
$credits = $db->query('SELECT cr.id, cr.traveler_id, cr.unallocated_amount,
    COALESCE(SUM(CASE WHEN cri.booking_reference="BK-001183" THEN cra.payment_amount_consumed ELSE 0 END),0) AS transferred
    FROM customer_receipts cr
    LEFT JOIN customer_receipt_allocations cra ON cra.customer_receipt_id=cr.id
    LEFT JOIN customer_receivable_items cri ON cri.id=cra.customer_receivable_item_id
    WHERE cr.id IN (999,1137)
    GROUP BY cr.id, cr.traveler_id, cr.unallocated_amount ORDER BY cr.id')->fetchAll(PDO::FETCH_ASSOC);

$checks = [
    'Booking remains with Akbar in branch 1' => $booking && (int) $booking['branch_id'] === 1
        && (int) $booking['lead_traveler_id'] === 477,
    'PKR 105,000 invoice has PKR 95,000 settled and PKR 10,000 remaining' => $due
        && abs((float) $due['due_amount'] - 105000) < 0.005
        && abs((float) $due['allocated_amount'] - 95000) < 0.005
        && abs((float) $due['outstanding_amount'] - 10000) < 0.005,
    'Original cash receipt remains PKR 2,000' => $cash
        && abs((float) $cash['received_amount'] - 2000) < 0.005
        && abs((float) $cash['allocated_amount'] - 2000) < 0.005
        && abs((float) $cash['unallocated_amount']) < 0.005,
    'PKR 14,000 and 79,000 transferred once; original payer preserved' => count($credits) === 2
        && (int) $credits[0]['traveler_id'] === 531
        && (int) $credits[1]['traveler_id'] === 531
        && abs((float) $credits[0]['transferred'] - 14000) < 0.005
        && abs((float) $credits[1]['transferred'] - 79000) < 0.005
        && abs((float) $credits[0]['unallocated_amount']) < 0.005
        && abs((float) $credits[1]['unallocated_amount']) < 0.005,
];
$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $ok;
}
exit($failed ? 1 : 0);
