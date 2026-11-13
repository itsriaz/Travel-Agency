<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$branches = (new \App\Repositories\SupplierAccountBalanceRepository($app))->allBranchIds();
$repository = new \App\Repositories\ReportRepository($app);
$receivables = $repository->supplierReceivable($branches, null, null);
$byBooking = [];
foreach ($receivables as $row) {
    $byBooking[(string) $row['booking_reference']][] = $row;
}

foreach (['BK-000302', 'BK-000897'] as $booking) {
    if (isset($byBooking[$booking])) {
        throw new RuntimeException($booking . ' is still shown as receivable after its cancellation credit was posted to supplier advance.');
    }
}
echo '[PASS] AirBlue cancellation credits already posted to supplier advance are not receivable again' . PHP_EOL;

$partial = $byBooking['BK-000949'][0] ?? null;
if ($partial === null
    || abs((float) $partial['supplier_credit_amount'] - 1296.0) > 0.005
    || abs((float) $partial['supplier_refund_received'] - 783.0) > 0.005
    || abs((float) $partial['supplier_receivable_balance'] - 513.0) > 0.005) {
    throw new RuntimeException('The genuinely unrecorded part of BK-000949 is no longer AED 513.');
}
echo '[PASS] Partially recorded supplier credit still leaves AED 513 receivable' . PHP_EOL;

$refundRows = $repository->payableRefunds($branches, null, null);
foreach ($refundRows as $row) {
    if (in_array((string) ($row['booking_reference'] ?? ''), ['BK-000302', 'BK-000897'], true)
        && (float) ($row['supplier_refund_receivable'] ?? 0) > 0.005) {
        throw new RuntimeException('Payable Refunds still counts a posted AirBlue cancellation credit as supplier receivable.');
    }
}
echo '[PASS] Payable Refunds uses the same recorded-credit evidence' . PHP_EOL;
