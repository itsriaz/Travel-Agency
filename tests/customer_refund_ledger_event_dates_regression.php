<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$repository = new \App\Repositories\ReportRepository($app);
$service = new \App\Services\ReportService($app);
$rowsMethod = new ReflectionMethod($service, 'customerLedgerRowsForCustomer');
$reportMethod = new ReflectionMethod($service, 'customerLedgerReport');

$bookingRows = array_values(array_filter(
    $repository->customerLedger([1, 2], null, null, '', 0, '', 'BK-000221'),
    static fn (array $row): bool => ($row['booking_reference'] ?? '') === 'BK-000221'
        && ! isset($row['row_type'])
));
if (count($bookingRows) !== 1) {
    throw new RuntimeException('BK-000221 must have one customer ledger service row.');
}
$postedRefunds = $repository->postedCustomerRefundsForBookings([(int) $bookingRows[0]['booking_id']]);
$key = $bookingRows[0]['booking_id'] . '|' . $bookingRows[0]['service_line_reference'];
$bookingRows[0]['posted_customer_refunds'] = $postedRefunds[$key] ?? [];
$actualRows = $rowsMethod->invoke($service, $bookingRows[0], []);
$actualPaid = array_values(array_filter(
    $actualRows,
    static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Refund paid to customer'
));
if (count($actualPaid) !== 1
    || ($actualPaid[0]['booking_date'] ?? '') !== '2026-06-16'
    || (float) ($actualPaid[0]['raw_credit_amount'] ?? 0) !== 1300.0
    || ($actualPaid[0]['currency'] ?? '') !== 'AED') {
    throw new RuntimeException('BK-000221 refund row must use its posted AED event date and amount.');
}
$report = $service->reportState(
    ['report' => 'customer_detail_ledger', 'booking_reference' => 'BK-000221'],
    [1, 2],
    1
);
$reportPaid = array_values(array_filter(
    $report['rows'] ?? [],
    static fn (array $row): bool => ($row['booking_reference'] ?? '') === 'BK-000221'
        && ($row['ledger_entry'] ?? '') === 'Refund paid to customer'
));
if (count($reportPaid) !== 1
    || ($reportPaid[0]['booking_date'] ?? '') !== '2026-06-16'
    || (float) ($reportPaid[0]['raw_credit_amount'] ?? 0) !== 1300.0) {
    throw new RuntimeException('The customer detail report must show the posted refund event date.');
}
echo '[PASS] BK-000221 paid refund uses its own recorded event date.' . PHP_EOL;

$source = $bookingRows[0];
$source['refund_event_date'] = '2026-08-30';
$source['refund_customer_refund_posted'] = 1300.0;
$source['posted_customer_refunds'] = [
    ['event_date' => '2026-09-12', 'amount' => 500.0, 'currency' => 'AED'],
    ['event_date' => '2026-09-18', 'amount' => 800.0, 'currency' => 'AED'],
];
[$rows, $summary] = $reportMethod->invoke($service, [$source], [], 'customer', true);
$paidRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Refund paid to customer'
));
if (count($paidRows) !== 2
    || array_column($paidRows, 'booking_date') !== ['2026-09-12', '2026-09-18']
    || array_map(static fn (array $row): float => (float) $row['raw_credit_amount'], $paidRows) !== [500.0, 800.0]
    || abs((float) ($paidRows[1]['raw_balance_amount'] ?? 1)) > 0.005) {
    throw new RuntimeException('Installment refunds must retain each date and close the customer balance.');
}
echo '[PASS] Partial customer refunds remain separate, dated, and balanced.' . PHP_EOL;
