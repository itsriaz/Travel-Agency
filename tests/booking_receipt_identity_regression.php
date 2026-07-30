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
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$bookingStatement = $db->query(
    'SELECT b.id, b.booking_reference, b.branch_id
     FROM bookings b
     WHERE NOT EXISTS (
         SELECT 1
         FROM customer_receivable_items cri
         INNER JOIN customer_receipt_allocations cra
             ON cra.customer_receivable_item_id = cri.id
         INNER JOIN customer_receipts cr
             ON cr.id = cra.customer_receipt_id
            AND cr.status <> "void"
         WHERE cri.booking_reference = b.booking_reference
     )
     ORDER BY b.id ASC
     LIMIT 1'
);
$booking = $bookingStatement->fetch() ?: [];

if ((int) ($booking['id'] ?? 0) <= 0) {
    echo '[SKIP] No booking without a customer payment or settlement is available.' . PHP_EOL;
    exit(0);
}

$outputService = new \App\Services\OperationalOutputService($app);
$document = $outputService->buildOutputDocument(
    (int) $booking['id'],
    'booking_summary_receipt',
    [(int) $booking['branch_id']],
    null,
    null,
    null,
    1
);
$rendered = (string) $app->get('view')->render('workspace/output', $document, 'layouts/print');

$check(
    'A booking without payment opens as a booking receipt',
    (string) ($document['outputType'] ?? '') === 'booking_summary_receipt'
        && (string) ($document['outputTypeLabel'] ?? '') === 'Booking Summary Receipt'
);
$check(
    'A booking without payment never displays a fake SETTLEMENT receipt number',
    ! str_contains($rendered, 'SETTLEMENT-' . (string) $booking['booking_reference'])
);
$check(
    'A booking without payment uses the branded receipt layout',
    str_contains($rendered, 'class="receipt-sheet"')
        && str_contains($rendered, 'Booking Summary Receipt')
        && str_contains($rendered, 'No payment was received with this booking.')
        && ! str_contains($rendered, 'class="output-sheet"')
);

$invalidSettlementRejected = false;
try {
    $outputService->buildOutputDocument(
        (int) $booking['id'],
        'customer_settlement_receipt',
        [(int) $booking['branch_id']],
        null,
        null,
        null,
        1
    );
} catch (RuntimeException $exception) {
    $invalidSettlementRejected = str_contains(
        $exception->getMessage(),
        'No customer payment, advance, or credit application is recorded'
    );
}
$check('A direct fake-settlement request is rejected', $invalidSettlementRejected);

$controller = file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php') ?: '';
$station = file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php') ?: '';
$check(
    'Save and Print Receipt fallbacks both select the booking receipt',
    str_contains($controller, "&doc=booking_summary_receipt")
        && str_contains($station, "&doc=booking_summary_receipt")
);

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Booking receipt identity regression failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Booking receipt identity regression passed.' . PHP_EOL;
