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

$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receivableRepository = new \App\Repositories\CustomerPaymentRepository($app);
$eventRepository = new \App\Repositories\BookingServiceEventRepository($app);

$actorUserId = 1;
$accessibleBranchIds = [1, 2];
$today = date('Y-m-d');
$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Cancellation unpaid refund cap regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Refund Cap Regression Customer',
        'contact_mobile' => '03001230000',
        'remarks' => 'Unpaid cancellation refund cap regression',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Refund Cap Regression Supplier',
        'service_passenger_name' => 'Refund Cap Passenger',
        'sale_price' => '579.00',
        'purchase_cost' => '579.00',
        'taxes' => '0.00',
        'other_fare' => '0.00',
        'soto_fare' => '0.00',
        'spyi_amount' => '0.00',
        'aq_yr_pk_amount' => '0.00',
        'yq_amount' => '0.00',
        'oth_amount' => '0.00',
        'vat_input' => '0.00',
        'vat' => '0.00',
        'commission' => '0.00',
        'service_charge' => '41.00',
        'discount_amount' => '0.00',
        'remarks' => 'Unpaid cancellation refund cap regression service',
        'ticket_pnr' => 'RFCAP1',
        'ticket_number' => 'RFCAP-001',
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
        'ticket_fare' => '579.00',
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => 'Unpaid cancellation refund cap regression service',
    ], $actorUserId, $accessibleBranchIds);

    $service = $serviceResult['service'] ?? [];
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');

    $serviceWorkspace->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Regression unpaid cancellation',
        'cancel_event_date' => $today,
        'cancel_notes' => 'Unpaid cancellation regression',
    ], $actorUserId, $accessibleBranchIds);

    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'settlement_reason' => 'Regression unpaid cancellation settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '0',
        'expected_supplier_refund_amount' => '579',
        'settlement_notes' => 'No customer money was received',
    ], $actorUserId, $accessibleBranchIds);

    $receivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $lineReference);
    $cancelEvent = $eventRepository->latestPostedEvent($serviceId, 'cancel');
    $payload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];

    $check(
        'Unpaid cancellation keeps customer refund credit at zero',
        round((float) ($payload['available_customer_refund_credit'] ?? -1), 2) === 0.0
            && round((float) ($payload['target_customer_refund_credit'] ?? -1), 2) === 0.0,
        json_encode([
            'available_customer_refund_credit' => $payload['available_customer_refund_credit'] ?? null,
            'target_customer_refund_credit' => $payload['target_customer_refund_credit'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Unpaid cancellation stores zero customer received amount in refund truth',
        round((float) ($payload['customer_received_amount'] ?? -1), 2) === 0.0
            && round((float) ($payload['customer_refund_basis'] ?? -1), 2) === 0.0,
        json_encode([
            'customer_received_amount' => $payload['customer_received_amount'] ?? null,
            'customer_refund_basis' => $payload['customer_refund_basis'] ?? null,
        ], JSON_UNESCAPED_SLASHES)
    );

    $check(
        'Unpaid cancellation leaves the retained service charge as open receivable',
        $receivable !== null
            && round((float) ($receivable['due_amount'] ?? -1), 2) === 41.0
            && round((float) ($receivable['allocated_amount'] ?? -1), 2) === 0.0
            && round((float) ($receivable['outstanding_amount'] ?? -1), 2) === 41.0,
        json_encode($receivable, JSON_UNESCAPED_SLASHES)
    );

    $db->rollBack();
} catch (Throwable $throwable) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    fwrite(STDERR, $throwable->getTraceAsString() . PHP_EOL);
    exit(1);
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILURES:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Cancellation unpaid refund cap regression passed.' . PHP_EOL;
