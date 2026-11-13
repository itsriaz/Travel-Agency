<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = (new ReflectionClass(\App\Services\ReportService::class))->newInstanceWithoutConstructor();

$summaryMethod = new ReflectionMethod($service, 'prepaidSupplierLedgerReport');
[$summaryRows, $summaryCards] = $summaryMethod->invoke($service, [[
    'supplier_id' => 999,
    'supplier_name' => 'Independent Supplier',
    'currency' => 'PKR',
    'total_advance_paid' => 1000,
    'total_advance_used' => 600,
    'remaining_advance_balance' => 400,
    'advance_payments_count' => 1,
    'advance_uses_count' => 1,
]]);

if (($summaryRows[0]['remaining_advance_balance'] ?? '') !== '400'
    || ($summaryRows[0]['total_advance_used'] ?? '') !== '600'
    || ! in_array(['label' => 'Supplier Account Advance / PKR', 'value' => 'PKR 400'], $summaryCards, true)) {
    throw new RuntimeException('Supplier account summary did not preserve its supplied ledger balance.');
}

$detailMethod = new ReflectionMethod($service, 'supplierPrepaidPaymentsReport');
[$detailRows, $detailCards] = $detailMethod->invoke($service, [[
    'id' => 1,
    'supplier_id' => 999,
    'supplier_name' => 'Independent Supplier',
    'branch_id' => 1,
    'currency' => 'PKR',
    'deposit_amount' => 1000,
    'used_amount' => 600,
    'available_amount' => 400,
    'payment_date' => '2026-10-17',
    'status' => 'partially_used',
]]);

if (($detailRows[0]['used_amount'] ?? '') !== '600'
    || ($detailRows[0]['available_amount'] ?? '') !== '400'
    || ! in_array(['label' => 'Prepaid Available / PKR', 'value' => 'PKR 400'], $detailCards, true)) {
    throw new RuntimeException('Supplier advance detail did not preserve its source balance.');
}

echo '[PASS] Supplier account summary renders its supplied ledger balance.' . PHP_EOL;
echo '[PASS] Supplier advance detail leaves used and available amounts unchanged.' . PHP_EOL;
