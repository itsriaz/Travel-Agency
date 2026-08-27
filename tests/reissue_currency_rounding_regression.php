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
$serviceRepository = new \App\Repositories\BookingServiceRepository($app);
$eventRepository = new \App\Repositories\BookingServiceEventRepository($app);
$receivableRepository = new \App\Repositories\CustomerPaymentRepository($app);
$supplierRepository = new \App\Repositories\SupplierRepository($app);
$exchangeRates = new \App\Repositories\ExchangeRateRepository($app);

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

echo 'Reissue currency rounding regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$db->beginTransaction();

try {
    $traveler = $db->query(
        'SELECT id, full_name, mobile
         FROM travelers
         WHERE branch_id = 1
         ORDER BY id ASC
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    if ($traveler === false || (int) ($traveler['id'] ?? 0) <= 0) {
        throw new RuntimeException('An existing branch-1 customer is required for the reissue rounding regression.');
    }

    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => 1,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'selected_customer_id' => (int) $traveler['id'],
        'lead_traveler_name' => (string) ($traveler['full_name'] ?? 'Reissue Currency Customer'),
        'contact_mobile' => (string) ($traveler['mobile'] ?? '03001234567'),
        'remarks' => 'Temporary reissue currency rounding regression',
    ], $actorUserId, $accessibleBranchIds);

    $booking = $bookingResult['booking'] ?? [];
    $bookingId = (int) ($booking['id'] ?? 0);
    $bookingReference = (string) ($booking['booking_reference'] ?? '');

    $check('Temporary booking created', $bookingId > 0 && $bookingReference !== '', $bookingReference);

    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'service_charge_currency' => 'AED',
        'supplier_name' => 'Reissue Currency Regression Supplier',
        'service_passenger_name' => 'Reissue Currency Passenger',
        'sale_price' => '200.00',
        'purchase_cost' => '200.00',
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
        'service_charge' => '20.00',
        'discount_amount' => '0.00',
        'remarks' => 'Temporary reissue currency rounding regression',
        'ticket_pnr' => 'RCR001',
        'ticket_number' => 'RCR-001',
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_return_date' => '',
        'ticket_class' => 'Economy',
        'ticket_fare' => '200.00',
        'ticket_tax' => '0.00',
        'ticket_vat' => '0.00',
        'ticket_commission' => '0.00',
        'ticket_remarks' => 'Temporary reissue currency rounding regression',
    ], $actorUserId, $accessibleBranchIds);

    $service = $serviceResult['service'] ?? [];
    $serviceId = (int) ($service['id'] ?? 0);
    $lineReference = (string) ($service['line_reference'] ?? '');

    $check('AED air-ticket seed service created', $serviceId > 0 && $lineReference !== '', $lineReference);

    $exchangeRates->upsertDailyRate('AED', 'PKR', $today, 77.00, 1, $actorUserId);

    $reissueResult = $serviceWorkspace->reissueService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'reissue_reason' => 'Whole-number FX regression reissue',
        'reissue_event_date' => $today,
        'new_ticket_number' => 'RCR-002',
        'new_pnr' => 'RCR002',
        'reissue_pricing_mode' => 'supplier_plus_service',
        'supplier_cost_difference_amount' => '150.00',
        'reissue_supplier_currency' => 'PKR',
        'reissue_service_fee_amount' => '77.00',
        'reissue_agency_fee_currency' => 'PKR',
        'reissue_customer_amount' => '3',
        'reissue_customer_currency' => 'AED',
        'reissue_received_amount' => '0',
    ], $actorUserId, $accessibleBranchIds);

    $reissueEventId = (int) ($reissueResult['service_event_id'] ?? 0);
    $reissuedService = $serviceRepository->findServiceById($serviceId);
    $reissueEvent = $eventRepository->findPostedEventById($reissueEventId, 'reissue');
    $reissuePayload = json_decode((string) ($reissueEvent['payload_json'] ?? ''), true);
    $reissuePayload = is_array($reissuePayload) ? $reissuePayload : [];
    $receivable = $receivableRepository->findReceivableByServiceLine($bookingReference, $lineReference, 'service_sale');
    $baseObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference, 'service_cost');
    $foreignObligation = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference, 'reissue_supplier_' . $reissueEventId);
    (new \App\Services\CommercialObligationSyncService($app))->syncForServiceId($serviceId, $actorUserId, ['entry_date' => $today]);
    $baseObligationAfterResync = $supplierRepository->findObligationByServiceLine($bookingReference, $lineReference, 'service_cost');

    $check(
        'Cross-currency reissue accepts rounded customer total and updates service mirrors consistently',
        $reissueEventId > 0
            && (string) ($reissueEvent['currency'] ?? '') === 'AED'
            && round((float) ($reissuePayload['supplier_to_customer_rate'] ?? 0), 8) === round(1 / 77, 8)
            && round((float) ($reissuePayload['supplier_cost_difference_amount'] ?? 0), 2) === 150.00
            && round((float) ($reissuePayload['agency_service_fee_amount'] ?? 0), 2) === 77.00
            && round((float) ($reissuePayload['customer_delta'] ?? 0), 2) === 3.00
            && round((float) ($reissuePayload['supplier_mirror_increment'] ?? 0), 2) === 2.00
            && round((float) ($reissuePayload['agency_mirror_increment'] ?? 0), 2) === 1.00
            && $reissuedService !== null
            && round((float) ($reissuedService['purchase_cost'] ?? 0), 2) === 202.00
            && round((float) ($reissuedService['service_charge'] ?? 0), 2) === 21.00
            && round((float) ($reissuedService['final_sale_price'] ?? 0), 2) === 223.00
            && $receivable !== null
            && round((float) ($receivable['due_amount'] ?? 0), 2) === 223.00
            && $baseObligation !== null
            && round((float) ($baseObligation['gross_amount'] ?? 0), 2) === 200.00
            && $foreignObligation !== null
            && (string) ($foreignObligation['currency'] ?? '') === 'PKR'
            && round((float) ($foreignObligation['gross_amount'] ?? 0), 2) === 150.00
            && $baseObligationAfterResync !== null
            && round((float) ($baseObligationAfterResync['gross_amount'] ?? 0), 2) === 200.00,
        json_encode([
            'event' => $reissueEvent,
            'payload' => $reissuePayload,
            'service' => $reissuedService,
            'receivable' => $receivable,
            'base_obligation' => $baseObligation,
            'foreign_obligation' => $foreignObligation,
            'base_obligation_after_resync' => $baseObligationAfterResync,
        ], JSON_UNESCAPED_SLASHES)
    );
} catch (Throwable $exception) {
    $failures[] = $exception::class . ': ' . $exception->getMessage();
    echo '[FAIL] Unexpected exception - ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Reissue currency rounding regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Reissue currency rounding regression passed.' . PHP_EOL;
