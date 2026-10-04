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
$receiptCount = (int) $db->query('SELECT COUNT(*) FROM customer_receipts
    WHERE booking_reference = "BK-000897" AND status <> "void"')->fetchColumn();
$refundPaid = (float) $db->query('SELECT COALESCE(SUM(customer_refund_amount), 0)
    FROM booking_service_events WHERE booking_reference = "BK-000897"
      AND event_type = "refund" AND event_status = "posted"')->fetchColumn();
$near = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) < .005;
$checks = [
    'Cancellation final charge is PKR 30,000' => is_array($payload)
        && $near($payload['customer_final_charge_amount'] ?? -1, 30000),
    'Unpaid customer receivable is PKR 30,000, not PKR 40,000 or 70,000' => is_array($receivable)
        && $near($receivable['due_amount'], 30000)
        && $near($receivable['allocated_amount'], 0)
        && $near($receivable['outstanding_amount'], 30000),
    'Cancelled service remains visible in the workspace balance source' => count($visible) === 1
        && $near($visible[0]['outstanding_amount'], 30000),
    'No customer receipt or cash refund was invented' => $receiptCount === 0 && $near($refundPaid, 0),
];
$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}
exit($failed ? 1 : 0);
