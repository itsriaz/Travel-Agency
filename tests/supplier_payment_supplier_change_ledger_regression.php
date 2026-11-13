<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$payment = $db->query('SELECT id, supplier_id, currency, paid_amount
    FROM supplier_payments WHERE payment_no = "SPAY-000114" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (! is_array($payment)) {
    echo '[SKIP] SPAY-000114 is not present in this database.' . PHP_EOL;
    exit(0);
}

$branchIds = array_map('intval', $db->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN));
$ledger = new \App\Services\SupplierLedgerService($app);
$supplierId = (int) $payment['supplier_id'];
$paymentId = (int) $payment['id'];
$currency = (string) $payment['currency'];
$full = $ledger->report(['supplierId' => $supplierId, 'currency' => $currency], $branchIds);
$paymentRows = array_values(array_filter(
    $full['rows'],
    static fn (array $row): bool => (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
        && ($row['source_entry_type'] ?? '') === 'Supplier Payment'
));
if (count($paymentRows) !== 1
    || abs((float) $paymentRows[0]['raw_credit_amount'] - (float) $payment['paid_amount']) > 0.005) {
    throw new RuntimeException('The full supplier ledger must show the saved payment exactly once.');
}

$paymentSearch = $ledger->report([
    'supplierId' => $supplierId,
    'currency' => $currency,
    'bookingReference' => 'SPAY-000114',
], $branchIds);
if (count($paymentSearch['rows']) !== 1
    || (int) ($paymentSearch['rows'][0]['supplier_payment_id'] ?? 0) !== $paymentId
    || (string) ($paymentSearch['rows'][0]['booking_reference'] ?? '') !== 'SPAY-000114'
    || abs((float) ($paymentSearch['rows'][0]['raw_credit_amount'] ?? 0) - (float) $payment['paid_amount']) > 0.005) {
    throw new RuntimeException('Searching the supplier ledger by payment number must return its saved payment once.');
}

$newBooking = $ledger->report([
    'supplierId' => $supplierId,
    'currency' => $currency,
    'bookingReference' => 'BK-000297',
], $branchIds);
$newBookingPayment = array_sum(array_map(
    static fn (array $row): float => (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
        ? (float) ($row['raw_credit_amount'] ?? 0) : 0.0,
    $newBooking['rows']
));
if (abs($newBookingPayment - 160837.00) > 0.005) {
    throw new RuntimeException('The new-supplier booking lost its corrected payment allocation.');
}

$oldBooking = $ledger->report([
    'supplierId' => $supplierId,
    'currency' => $currency,
    'bookingReference' => 'BK-000716',
], $branchIds);
foreach ($oldBooking['rows'] as $row) {
    if ((int) ($row['supplier_payment_id'] ?? 0) === $paymentId
        && (float) ($row['raw_credit_amount'] ?? 0) > 0.005) {
        throw new RuntimeException('The released old-supplier allocation still appears under the corrected supplier.');
    }
}

echo '[PASS] SPAY-000114 full ledger equals its saved payment amount.' . PHP_EOL;
echo '[PASS] Searching SPAY-000114 returns its saved payment without unrelated rows.' . PHP_EOL;
echo '[PASS] Corrected new-supplier booking retains its payment allocation.' . PHP_EOL;
echo '[PASS] Released old-supplier booking does not retain the corrected payment.' . PHP_EOL;
