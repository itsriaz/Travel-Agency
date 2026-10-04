<?php

declare(strict_types=1);

// Read-only inspection of the restored booking and its reused customer receipt.
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

$booking = $read('SELECT id, booking_reference, branch_id, booking_status
    FROM bookings WHERE booking_reference = ?', ['BK-000795']);
$bookingId = (int) ($booking[0]['id'] ?? 0);
$services = $bookingId > 0 ? $read('SELECT id, line_reference, service_type, service_status, is_active,
    supplier_id, currency, cost_currency, sale_price, purchase_cost, pricing_exchange_rate,
    service_charge, vat, discount_amount, final_sale_price, net_profit_loss
    FROM booking_services WHERE booking_id = ? ORDER BY id', [$bookingId]) : [];
$serviceId = (int) ($services[0]['id'] ?? 0);

$report = [
    'inspection_only' => true,
    'booking' => $booking,
    'services' => $services,
    'air_ticket_financials' => $serviceId > 0 ? $read('SELECT booking_service_id, fare, ticket_tax,
        ticket_vat, supplier_cost, sale_amount FROM service_air_ticket WHERE booking_service_id = ?', [$serviceId]) : [],
    'customer_receivable' => $read('SELECT id, service_line_reference, currency, due_amount,
        allocated_amount, outstanding_amount, status FROM customer_receivable_items
        WHERE booking_reference = ? ORDER BY id', ['BK-000795']),
    'supplier_obligation' => $read('SELECT id, service_line_reference, supplier_id, currency,
        gross_amount, advance_applied_amount, net_payable_amount, status FROM supplier_obligations
        WHERE booking_reference = ? ORDER BY id', ['BK-000795']),
    'source_receipt' => $read('SELECT id, booking_reference, currency, received_amount,
        allocated_amount, unallocated_amount, returned_amount, status FROM customer_receipts WHERE id = ?', [92]),
    'source_receipt_allocations' => $read('SELECT id, customer_receivable_item_id, allocated_amount
        FROM customer_receipt_allocations WHERE customer_receipt_id = ? ORDER BY id', [92]),
    'service_events' => $serviceId > 0 ? $read('SELECT id, event_type, event_status, currency,
        customer_refund_amount, supplier_refund_amount FROM booking_service_events
        WHERE booking_service_id = ? ORDER BY id', [$serviceId]) : [],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
