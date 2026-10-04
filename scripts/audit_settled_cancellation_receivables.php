<?php

declare(strict_types=1);

// Read-only audit: a later service edit must not restore an invoice over a posted cancellation.
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
$statement = $db->query('SELECT e.id AS cancellation_event_id, e.booking_reference,
        e.service_line_reference, e.currency AS cancellation_currency, e.payload_json,
        cri.id AS receivable_id, cri.currency AS receivable_currency,
        cri.due_amount, cri.allocated_amount, cri.outstanding_amount
    FROM booking_service_events e
    JOIN booking_services bs ON bs.id = e.booking_service_id
    LEFT JOIN customer_receivable_items cri
        ON cri.booking_reference = e.booking_reference
        AND cri.service_line_reference = e.service_line_reference
        AND cri.due_group = "service_sale"
    WHERE e.event_type = "cancel" AND e.event_status = "posted"
        AND LOWER(bs.service_status) = "cancelled"
        AND NOT EXISTS (
            SELECT 1 FROM booking_service_events later
            WHERE later.booking_service_id = e.booking_service_id
                AND later.event_type = "cancel" AND later.event_status = "posted"
                AND later.id > e.id
        )
    ORDER BY e.booking_reference, e.service_line_reference');

$checked = 0;
$review = 0;
$unverified = 0;
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payload = json_decode((string) $row['payload_json'], true);
    if (($payload['mode'] ?? null) === 'operational_cancel_only') {
        continue;
    }
    if (! is_array($payload) || ! is_numeric($payload['customer_final_charge_amount'] ?? null)) {
        $unverified++;
        printf("[UNVERIFIED] %s / %s: cancellation #%d has no final-charge snapshot; inspect legacy evidence manually.\n",
            $row['booking_reference'], $row['service_line_reference'], $row['cancellation_event_id']);
        continue;
    }
    $checked++;
    $finalCharge = round((float) $payload['customer_final_charge_amount'], 2);
    $due = $row['due_amount'] === null ? null : round((float) $row['due_amount'], 2);
    if ($due !== null && abs($due - $finalCharge) < .005
        && $row['cancellation_currency'] === $row['receivable_currency']) {
        continue;
    }
    $review++;
    printf("[REVIEW] %s / %s: cancellation #%d final charge %s %.2f; receivable #%s %s %s; allocated %s; outstanding %s. Investigate before any repair.\n",
        $row['booking_reference'], $row['service_line_reference'], $row['cancellation_event_id'],
        $row['cancellation_currency'], $finalCharge, $row['receivable_id'] ?? 'missing',
        $row['receivable_currency'] ?? '-', $due === null ? 'missing' : number_format($due, 2, '.', ''),
        $row['allocated_amount'] ?? '-', $row['outstanding_amount'] ?? '-');
}
printf("Scanned %d settled cancellations with a final-charge snapshot; %d require review and %d legacy settlements are unverified. No rows changed.\n",
    $checked, $review, $unverified);
