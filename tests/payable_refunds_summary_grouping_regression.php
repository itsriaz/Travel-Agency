<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\ReportService($app);
$method = new ReflectionMethod($service, 'payableRefundsReport');

[, $cards] = $method->invoke($service, [[
    'booking_id' => 1,
    'booking_reference' => 'BK-000001',
    'event_date' => '2026-07-28',
    'currency' => 'PKR',
    'customer_paid_amount' => 1180.00,
    'customer_penalty_amount' => 53.00,
    'customer_refund_payable' => 600.00,
    'supplier_penalty_amount' => 535.00,
    'supplier_refund_received' => 0.00,
    'supplier_refund_receivable' => 653.00,
]], ['PKR' => 1.0]);

$failures = [];
$expectedGroups = [
    'payable_refunds_customer',
    'payable_refunds_customer',
    'payable_refunds_customer',
    'payable_refunds_supplier',
    'payable_refunds_supplier',
    'payable_refunds_supplier',
];
$actualGroups = array_column($cards, 'group');

if ($actualGroups !== $expectedGroups) {
    $failures[] = 'Customer and supplier refund cards are not separated into ordered rows.';
}

$titlesByGroup = [];
foreach ($cards as $card) {
    $titlesByGroup[(string) ($card['group'] ?? '')] = (string) ($card['group_title'] ?? '');
}
if (($titlesByGroup['payable_refunds_customer'] ?? '') !== 'Customer Refund') {
    $failures[] = 'Customer refund row title is missing.';
}
if (($titlesByGroup['payable_refunds_supplier'] ?? '') !== 'Supplier Refund') {
    $failures[] = 'Supplier refund row title is missing.';
}

if ($failures !== []) {
    fwrite(STDERR, "Payable Refunds summary grouping regression failed:\n - "
        . implode("\n - ", $failures)
        . PHP_EOL);
    exit(1);
}

echo '[PASS] Payable Refunds keeps customer cards on row one and supplier cards on row two.' . PHP_EOL;
