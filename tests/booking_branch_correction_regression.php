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

echo 'Booking branch correction regression' . PHP_EOL;

$movable = $repository->findBookingById(298);
if ($movable === null || (string) ($movable['booking_reference'] ?? '') !== 'BK-000298') {
    fwrite(STDERR, '[FAIL] BK-000298 fixture is unavailable.' . PHP_EOL);
    exit(1);
}

$db->beginTransaction();
try {
    $blocked = false;
    $toNoble = $payloadFor($movable, 2);
    try {
        $repository->updateBooking(298, $toNoble['booking'], $toNoble['party']);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'supplier payment');
    }
    $check('Existing bank receipt is not the blocker; cross-branch supplier payment still requires review', $blocked);
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
        $blocked = str_contains($exception->getMessage(), 'supplier advance')
            || str_contains($exception->getMessage(), 'money returned to the customer');
    }
    $check('Historical returned receipt or shared supplier credit blocks an unsafe branch change', $blocked);
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

