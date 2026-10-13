<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Services\BookingWorkspaceService;
use App\Services\CommercialObligationSyncService;
use App\Services\ServiceWorkspaceService;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$today = date('Y-m-d');
$db->beginTransaction();
try {
    $workspace = new ServiceWorkspaceService($app);
    $serviceRepository = new BookingServiceRepository($app);
    $events = new BookingServiceEventRepository($app);
    $customers = new CustomerPaymentRepository($app);
    $suppliers = new SupplierRepository($app);
    $sync = new CommercialObligationSyncService($app);
    $booking = (new BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Cancellation Resync Regression',
        'remarks' => 'Rollback-only regression',
    ], 1, [1, 2])['booking'];
    $bookingId = (int) $booking['id'];
    $reference = (string) $booking['booking_reference'];
    $input = [
        'booking_id' => $bookingId,
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Resync Guard Supplier ' . random_int(100000, 999999),
        'service_passenger_name' => 'Resync Guard Passenger',
        'sale_price' => '1000',
        'service_charge' => '100',
        'vat' => '0',
        'discount_amount' => '0',
        'final_sale_price' => '1100',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit Visa',
    ];
    $service = $workspace->saveService($input, 1, [1, 2])['service'];
    $serviceId = (int) $service['id'];
    $line = (string) $service['line_reference'];
    $assert((float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 1100.0,
        'Fixture must begin with AED 1,100 customer invoice.');
    $workspace->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Regression cancellation',
        'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'settlement_reason' => 'Regression settlement',
        'settlement_event_date' => $today,
        'customer_penalty_amount' => '50',
        'expected_supplier_refund_amount' => '900',
    ], 1, [1, 2]);
    $event = $events->latestPostedEvent($serviceId, 'cancel');
    $payload = json_decode((string) $event['payload_json'], true);
    $assert(is_array($payload) && $events->hasPostedCancellationSettlement($serviceId),
        'Fixture must have a posted financial cancellation.');
    $snapshot = static function () use ($db, $customers, $suppliers, $reference, $line): array {
        $receivable = $customers->findReceivableByServiceLine($reference, $line);
        $obligation = $suppliers->findObligationByServiceLine($reference, $line);
        $journal = $db->prepare('SELECT je.id, je.source_type, je.currency,
                jel.account_id, jel.debit_amount, jel.credit_amount
            FROM journal_entries je
            INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            WHERE je.booking_reference = ? ORDER BY je.id, jel.id');
        $journal->execute([$reference]);
        $applications = $db->prepare('SELECT id, supplier_advance_id, applied_amount
            FROM supplier_advance_applications WHERE supplier_obligation_id = ? ORDER BY id');
        $applications->execute([(int) $obligation['id']]);
        $receipts = $db->prepare('SELECT id, treasury_account_id, received_amount
            FROM customer_receipts WHERE booking_reference = ? ORDER BY id');
        $receipts->execute([$reference]);
        return [$receivable, $obligation, $journal->fetchAll(PDO::FETCH_ASSOC),
            $applications->fetchAll(PDO::FETCH_ASSOC), $receipts->fetchAll(PDO::FETCH_ASSOC)];
    };
    $before = $snapshot();
    $assert((float) $before[0]['due_amount'] === (float) $payload['customer_final_charge_amount']
        && (float) $before[0]['due_amount'] < 1100.0
        && (float) $before[0]['allocated_amount'] === 0.0
        && $before[4] === [],
        'Unpaid cancellation must retain its final charge without inventing customer cash.');
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $saved = $serviceRepository->findServiceById($serviceId);
        $workspace->saveService(array_merge($input, [
            'service_id' => $serviceId,
            'service_status' => 'Cancelled',
            'service_updated_at' => $saved['updated_at'],
            'remarks' => 'Operational note ' . $attempt,
        ]), 1, [1, 2]);
        $sync->syncForServiceId($serviceId, 1);
        $assert($snapshot() === $before,
            'Post-settlement edit or sync rebuilt invoice, supplier obligation, advance, journal, or cash.');
    }
    echo '[PASS] Repeated edits cannot recreate the original invoice or supplier cost after cancellation.' . PHP_EOL;
    echo '[PASS] Customer cash, supplier advances, and journals remain unchanged.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
echo 'All fixture data rolled back.' . PHP_EOL;
exit(isset($failed) ? 1 : 0);
