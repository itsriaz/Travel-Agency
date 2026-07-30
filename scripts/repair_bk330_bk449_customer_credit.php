<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply', 'actor-user-id::']);
$apply = array_key_exists('apply', $options);
$actorUserId = max(1, (int) ($options['actor-user-id'] ?? 1));
$targetBookingReference = 'BK-000459';
$expectedSources = [
    'BK-000330' => 64500.00,
    'BK-000449' => 22000.00,
];
$expectedTotal = 86500.00;
$moneyEquals = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) <= 0.005;

echo 'BK-000330 / BK-000449 customer-credit restoration' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Target booking: ' . $targetBookingReference . PHP_EOL;

$bookingStatement = $db->prepare(
    'SELECT b.id, b.booking_reference, b.branch_id, b.lead_traveler_id,
            COALESCE(bp.lead_traveler_name, "") AS customer_name
     FROM bookings b
     LEFT JOIN booking_parties bp ON bp.booking_id = b.id
     WHERE b.booking_reference = :booking_reference
     LIMIT 1'
);
$eventStatement = $db->prepare(
    'SELECT b.id AS booking_id, b.branch_id, b.lead_traveler_id,
            bs.id AS service_id, bse.id AS refund_event_id,
            bse.currency, bse.customer_refund_amount
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id AND bs.is_active = 1
     INNER JOIN booking_service_events bse ON bse.booking_service_id = bs.id
     WHERE b.booking_reference = :booking_reference
       AND bse.event_type = "refund"
       AND bse.event_status = "posted"
       AND bse.customer_refund_amount > 0.005
     ORDER BY bse.id DESC
     LIMIT 1'
);
$receiptStatement = $db->prepare(
    'SELECT id, receipt_no, branch_id, traveler_id, currency,
            allocated_amount, unallocated_amount, returned_amount, status
     FROM customer_receipts
     WHERE booking_reference = :booking_reference
       AND currency = "PKR"
       AND status <> "void"
     ORDER BY id DESC
     LIMIT 1'
);

$bookingStatement->execute(['booking_reference' => $targetBookingReference]);
$targetBooking = $bookingStatement->fetch(PDO::FETCH_ASSOC);
if ($targetBooking === false) {
    fwrite(STDERR, '[ABORT] Target booking BK-000459 was not found.' . PHP_EOL);
    exit(1);
}

$repairs = [];
foreach ($expectedSources as $bookingReference => $expectedCredit) {
    $bookingStatement->execute(['booking_reference' => $bookingReference]);
    $sourceBooking = $bookingStatement->fetch(PDO::FETCH_ASSOC);
    $eventStatement->execute(['booking_reference' => $bookingReference]);
    $refundEvent = $eventStatement->fetch(PDO::FETCH_ASSOC);
    $receiptStatement->execute(['booking_reference' => $bookingReference]);
    $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);

    if ($sourceBooking === false || $receipt === false) {
        fwrite(STDERR, '[ABORT] Required booking or receipt evidence is missing for ' . $bookingReference . '.' . PHP_EOL);
        exit(1);
    }
    if ((int) $sourceBooking['branch_id'] !== (int) $targetBooking['branch_id']
        || (int) $sourceBooking['lead_traveler_id'] !== (int) $targetBooking['lead_traveler_id']
    ) {
        fwrite(STDERR, '[ABORT] ' . $bookingReference . ' does not match BK-000459 customer and branch; nothing was changed.' . PHP_EOL);
        exit(1);
    }

    $alreadyRestored = $refundEvent === false
        && strtoupper((string) $receipt['currency']) === 'PKR'
        && $moneyEquals($receipt['unallocated_amount'], $expectedCredit)
        && $moneyEquals($receipt['returned_amount'], 0.00);
    if ($alreadyRestored) {
        echo '[NO CHANGE] ' . $bookingReference . ': PKR ' . number_format($expectedCredit, 2) . ' is already transferable credit.' . PHP_EOL;
        continue;
    }

    if ($refundEvent === false
        || strtoupper((string) $refundEvent['currency']) !== 'PKR'
        || ! $moneyEquals($refundEvent['customer_refund_amount'], $expectedCredit)
        || ! $moneyEquals($receipt['returned_amount'], $expectedCredit)
        || ! $moneyEquals($receipt['unallocated_amount'], 0.00)
    ) {
        fwrite(STDERR, '[ABORT] ' . $bookingReference . ' no longer matches the confirmed PKR ' . number_format($expectedCredit, 2) . ' case; nothing was changed.' . PHP_EOL);
        exit(1);
    }

    $repairs[] = [
        'booking_reference' => $bookingReference,
        'booking_id' => (int) $sourceBooking['id'],
        'branch_id' => (int) $sourceBooking['branch_id'],
        'service_id' => (int) $refundEvent['service_id'],
        'refund_event_id' => (int) $refundEvent['refund_event_id'],
        'receipt_id' => (int) $receipt['id'],
        'amount' => $expectedCredit,
    ];
    echo '[READY] ' . $bookingReference . ': restore PKR ' . number_format($expectedCredit, 2) . ' from customer-paid refund to transferable credit.' . PHP_EOL;
}

echo 'Planned available credit for BK-000459: PKR ' . number_format($expectedTotal, 2) . PHP_EOL;
echo 'Supplier refund events and supplier balances are not changed.' . PHP_EOL;

if ($repairs === []) {
    echo '[NO CHANGE] Both source credits are already restored and available to BK-000459.' . PHP_EOL;
    exit(0);
}

if (! $apply) {
    echo 'Dry run complete. Re-run with --apply after confirming a database backup.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $service = new \App\Services\ServiceWorkspaceService($app);
    foreach ($repairs as $repair) {
        $service->reverseRefundService([
            'booking_id' => $repair['booking_id'],
            'service_id' => $repair['service_id'],
            'refund_event_id' => $repair['refund_event_id'],
            'refund_scope' => 'customer',
            'refund_reverse_reason' => 'Customer refund was retained and transferred to BK-000459, not paid in cash or bank.',
        ], $actorUserId, [$repair['branch_id']]);
    }

    $availableCredits = (new \App\Repositories\CustomerPaymentRepository($app))->availableBookingCredits(
        (int) $targetBooking['branch_id'],
        (int) $targetBooking['lead_traveler_id'],
        'PKR',
        $targetBookingReference
    );
    $matchingCredits = array_values(array_filter(
        $availableCredits,
        static fn (array $row): bool => array_key_exists((string) ($row['booking_reference'] ?? ''), $expectedSources)
    ));
    $availableTotal = round(array_reduce(
        $matchingCredits,
        static fn (float $sum, array $row): float => $sum + (float) ($row['unallocated_amount'] ?? 0),
        0.0
    ), 2);
    if (count($matchingCredits) !== 2 || ! $moneyEquals($availableTotal, $expectedTotal)) {
        throw new RuntimeException('Post-repair verification did not expose both source credits totaling PKR 86,500.00.');
    }

    $db->commit();
    echo '[APPLIED] PKR 86,500.00 is now available to BK-000459 as two audited customer credits.' . PHP_EOL;
    foreach ($matchingCredits as $credit) {
        echo ' - ' . (string) $credit['booking_reference'] . ' / ' . (string) $credit['receipt_no']
            . ' / PKR ' . number_format((float) $credit['unallocated_amount'], 2) . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[ROLLED BACK] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
