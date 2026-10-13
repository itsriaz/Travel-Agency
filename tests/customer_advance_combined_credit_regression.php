<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\ReportRepository;
use App\Services\ReportService;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$payments = new CustomerPaymentRepository($app);
$reports = new ReportRepository($app);
$services = new ReportService($app);
$booking = $db->query(
    'SELECT booking_reference, branch_id, lead_traveler_id
     FROM bookings WHERE lead_traveler_id IS NOT NULL AND lead_traveler_id > 0
     ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$actorId = (int) ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if (! is_array($booking) || $actorId <= 0) {
    fwrite(STDERR, "[FAIL] A booking with customer and an active actor are required.\n");
    exit(1);
}

$branchId = (int) $booking['branch_id'];
$bookingReference = (string) $booking['booking_reference'];
$travelerId = (int) $booking['lead_traveler_id'];
$token = strtoupper(bin2hex(random_bytes(4)));
$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $checks[] = $label;
    }
};
$receipt = static function (string $purpose, string $currency, float $amount, string $suffix) use (
    $payments, $branchId, $bookingReference, $travelerId, $actorId, $token
): int {
    return $payments->createReceipt([
        'branch_id' => $branchId,
        'traveler_id' => $travelerId,
        'receipt_purpose' => $purpose,
        'booking_reference' => $bookingReference,
        'receipt_no' => 'QA-CREDIT-' . $token . '-' . $suffix,
        'receipt_date' => date('Y-m-d'),
        'currency' => $currency,
        'received_amount' => $amount,
        'payment_method' => 'cash',
        'status' => 'received',
        'actor_user_id' => $actorId,
    ]);
};
$number = static fn (string $value): float => (float) (preg_replace('/[^0-9.\-]/', '', $value) ?: 0);

$db->beginTransaction();
$createdIds = [];
try {
    $advanceId = $receipt('customer_advance', 'AED', 40, 'ADV');
    $excessId = $receipt('booking_payment', 'AED', 20, 'EXTRA');
    $releasedId = $receipt('booking_payment', 'PKR', 100, 'RELEASE');
    $createdIds = [$advanceId, $excessId, $releasedId];
    $receivableId = $payments->createReceivableItem([
        'branch_id' => $branchId,
        'booking_reference' => $bookingReference,
        'service_line_reference' => 'QA-CREDIT-' . $token,
        'currency' => 'PKR',
        'due_amount' => 100,
        'status' => 'open',
        'actor_user_id' => $actorId,
    ]);
    $payments->allocateReceipt($releasedId, $receivableId, 100, null, 'Rollback-only credit history', $actorId);
    $payments->releaseAllocatedCreditForReceivable($receivableId, 50, $actorId, 'Rollback-only refund credit release');

    $positions = $reports->customerAvailableCreditPositions([$branchId]);
    $fixturePositions = array_column(array_values(array_filter(
        $positions,
        static fn (array $row): bool => in_array((int) ($row['receipt_id'] ?? 0), $createdIds, true)
    )), null, 'receipt_id');
    $check('Advance, invoice excess and released credit each have one current position',
        count($fixturePositions) === 3
            && abs((float) ($fixturePositions[$advanceId]['available_amount'] ?? 0) - 40) < 0.005
            && abs((float) ($fixturePositions[$excessId]['available_amount'] ?? 0) - 20) < 0.005
            && abs((float) ($fixturePositions[$releasedId]['available_amount'] ?? 0) - 50) < 0.005);

    $state = $services->reportState(['report' => 'customer_advance_ledger', 'branch_id' => $branchId], [$branchId], $actorId);
    $cards = (array) ($state['summaryCards'] ?? []);
    $labels = array_column($cards, 'label');
    $check('Summary cards group all AED before all PKR',
        array_slice($labels, 0, 3) === [
            'Advance Available / AED', 'Refund + Extra Credit / AED', 'Total Available / AED',
        ] && array_slice($labels, 3, 3) === [
            'Advance Available / PKR', 'Refund + Extra Credit / PKR', 'Total Available / PKR',
        ]);
    $_SERVER['REQUEST_URI'] = '/reports?report=customer_advance_ledger';
    $html = (new \App\Core\View($app))->render('reports/index', $state);
    $aedCardPosition = strpos($html, 'Advance Available / AED');
    $pkrCardPosition = strpos($html, 'Advance Available / PKR');
    $check('Rendered report places AED cards above PKR cards',
        str_contains($html, 'report-stat-grid--customer-advance')
            && $aedCardPosition !== false && $pkrCardPosition !== false
            && $aedCardPosition < $pkrCardPosition);

    $expected = [];
    foreach ($positions as $position) {
        $currency = (string) $position['currency'];
        $kind = (string) $position['receipt_purpose'] === 'customer_advance' ? 'advance' : 'booking_credit';
        $expected[$currency][$kind] = ($expected[$currency][$kind] ?? 0) + (float) $position['available_amount'];
    }
    $cardsByLabel = array_column($cards, 'value', 'label');
    $totalsMatch = true;
    foreach (['AED', 'PKR'] as $currency) {
        $advance = (float) ($expected[$currency]['advance'] ?? 0);
        $bookingCredit = (float) ($expected[$currency]['booking_credit'] ?? 0);
        $totalsMatch = $totalsMatch
            && abs($number((string) ($cardsByLabel['Advance Available / ' . $currency] ?? '')) - $advance) < 0.005
            && abs($number((string) ($cardsByLabel['Refund + Extra Credit / ' . $currency] ?? '')) - $bookingCredit) < 0.005
            && abs($number((string) ($cardsByLabel['Total Available / ' . $currency] ?? '')) - $advance - $bookingCredit) < 0.005;
    }
    $check('Combined available cards sum each receipt exactly once', $totalsMatch);

    $snapshotRows = array_values(array_filter((array) ($state['rows'] ?? []),
        static fn (array $row): bool => (string) ($row['entry_type'] ?? '') === 'Booking Credit Available'
            && in_array((int) ($row['receipt_id'] ?? 0), [$excessId, $releasedId], true)
    ));
    $check('Booking credits appear as current positions, not extra cash receipts',
        count($snapshotRows) === 2
            && array_sum(array_map(static fn (array $row): float => $number((string) $row['received_amount']), $snapshotRows)) === 0.0
            && abs(array_sum(array_map(static fn (array $row): float => $number((string) $row['balance_amount']), $snapshotRows)) - 70) < 0.005);

    $future = date('Y-m-d', strtotime('+1 day'));
    $filtered = $services->reportState([
        'report' => 'customer_advance_ledger', 'branch_id' => $branchId,
        'date_from' => $future, 'date_to' => $future,
    ], [$branchId], $actorId);
    $check('Date-filtered history does not erase current available credit',
        array_column((array) $filtered['summaryCards'], 'value', 'label') === $cardsByLabel);
} catch (Throwable $exception) {
    $check('Combined credit regression completed', false);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($checks !== []) {
    exit(1);
}
echo "All combined-credit fixture data rolled back.\n";
