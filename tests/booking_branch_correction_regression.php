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
$repository = new \App\Repositories\BookingRepository($app);
$failures = [];

$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};

$payloadFor = static function (array $booking, int $branchId): array {
    return [
        'booking' => [
            'branch_id' => $branchId,
            'business_source_id' => $booking['business_source_id'],
            'booking_status' => $booking['booking_status'],
            'booking_date' => $booking['booking_date'],
            'due_date' => $booking['due_date'],
            'departure_date' => $booking['departure_date'],
            'return_date' => $booking['return_date'],
            'remarks' => $booking['remarks'],
            'actor_user_id' => 1,
        ],
        'party' => [
            'party_label' => $booking['party_label'],
            'lead_traveler_name' => $booking['lead_traveler_name'],
            'contact_mobile' => $booking['contact_mobile'],
            'passport_number' => $booking['passport_number'],
            'notes' => $booking['party_notes'],
        ],
    ];
};

$mismatchCount = static function (PDO $db, string $bookingReference, int $branchId): int {
    $statement = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM booking_services bs INNER JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference = :service_reference AND bs.branch_id <> :service_branch)
          + (SELECT COUNT(*) FROM customer_receivable_items WHERE booking_reference = :receivable_reference AND branch_id <> :receivable_branch)
          + (SELECT COUNT(*) FROM supplier_obligations WHERE booking_reference = :obligation_reference AND branch_id <> :obligation_branch)
          + (SELECT COUNT(*) FROM service_financial_corrections WHERE booking_reference = :correction_reference AND branch_id <> :correction_branch)
          + (SELECT COUNT(*) FROM journal_entries je
             WHERE je.booking_reference = :journal_reference
               AND je.currency IN (
                    SELECT bs.currency FROM booking_services bs INNER JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference = :currency_reference
                    UNION
                    SELECT bs.cost_currency FROM booking_services bs INNER JOIN bookings b ON b.id = bs.booking_id WHERE b.booking_reference = :cost_currency_reference
               )
               AND je.branch_id <> :journal_branch) AS mismatches'
    );
    $statement->execute([
        'service_reference' => $bookingReference,
        'service_branch' => $branchId,
        'receivable_reference' => $bookingReference,
        'receivable_branch' => $branchId,
        'obligation_reference' => $bookingReference,
        'obligation_branch' => $branchId,
        'correction_reference' => $bookingReference,
        'correction_branch' => $branchId,
        'journal_reference' => $bookingReference,
        'currency_reference' => $bookingReference,
        'cost_currency_reference' => $bookingReference,
        'journal_branch' => $branchId,
    ]);

    return (int) $statement->fetchColumn();
};

echo 'Booking branch correction regression' . PHP_EOL;

$movable = $repository->findBookingById(298);
if ($movable === null || (string) ($movable['booking_reference'] ?? '') !== 'BK-000298') {
    fwrite(STDERR, '[FAIL] BK-000298 fixture is unavailable.' . PHP_EOL);
    exit(1);
}

$db->beginTransaction();
try {
    $toNoble = $payloadFor($movable, 2);
    $repository->updateBooking(298, $toNoble['booking'], $toNoble['party']);
    $check('Branch correction synchronizes current records to Noble', $mismatchCount($db, 'BK-000298', 2) === 0);

    $nobleBooking = $repository->findBookingById(298);
    $toImdad = $payloadFor((array) $nobleBooking, 1);
    $repository->updateBooking(298, $toImdad['booking'], $toImdad['party']);
    $check('Branch correction synchronizes current records back to Imdad', $mismatchCount($db, 'BK-000298', 1) === 0);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$protected = $repository->findBookingById(38);
if ($protected === null || (string) ($protected['booking_reference'] ?? '') !== 'BK-000038') {
    fwrite(STDERR, '[FAIL] BK-000038 protection fixture is unavailable.' . PHP_EOL);
    exit(1);
}

$db->beginTransaction();
try {
    $blocked = false;
    try {
        $toImdad = $payloadFor($protected, 1);
        $repository->updateBooking(38, $toImdad['booking'], $toImdad['party']);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'active receipt');
    }
    $check('Cross-branch active treasury money blocks an unsafe branch change', $blocked);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Booking branch correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Booking branch correction regression passed.' . PHP_EOL;

