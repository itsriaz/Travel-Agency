<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$service = (new ReflectionClass(\App\Services\ReportService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'supplierAllPaymentsReport');

[$rows, $cards] = $method->invoke($service, [[
    'id' => 71,
    'payment_no' => 'SPAY-TEST-71',
    'payment_date' => '2026-10-18',
    'currency' => 'PKR',
    'paid_amount' => 10000,
    'allocated_amount' => 2500,
    'unallocated_amount' => 0,
    'status' => 'fully_allocated',
]], [[
    'id' => 81,
    'source_supplier_payment_id' => 71,
    'credit_source' => 'supplier_advance',
    'source_payment_no' => 'SPAY-TEST-71',
    'currency' => 'PKR',
    'payment_date' => '2026-10-18',
    'deposit_amount' => 7500,
    'used_amount' => 3500,
    'available_amount' => 4000,
], [
    'id' => 0,
    'source_supplier_payment_id' => 71,
    'credit_source' => 'supplier_payment_credit',
    'currency' => 'PKR',
    'deposit_amount' => 0,
    'used_amount' => 0,
    'available_amount' => 0,
]]);

if (count($rows) !== 1
    || ($rows[0]['gross_amount'] ?? '') !== '10,000'
    || ($rows[0]['used_or_allocated_amount'] ?? '') !== '6,000'
    || ($rows[0]['balance_amount'] ?? '') !== '4,000'
    || ($rows[0]['status'] ?? '') !== 'Partially Used') {
    throw new RuntimeException('Linked advance was not folded into its original payment once.');
}
$byLabel = array_column($cards, 'value', 'label');
if (($byLabel['All Supplier Paid / PKR'] ?? '') !== 'PKR 10,000'
    || ($byLabel['All Supplier Used / PKR'] ?? '') !== 'PKR 6,000'
    || ($byLabel['All Supplier Balance / PKR'] ?? '') !== 'PKR 4,000') {
    throw new RuntimeException('Combined payment cards do not conserve the original payment.');
}

[$voidRows] = $method->invoke($service, [], [[
    'id' => 82,
    'source_supplier_payment_id' => 72,
    'credit_source' => 'supplier_advance',
    'currency' => 'PKR',
    'deposit_amount' => 0,
    'used_amount' => 0,
    'available_amount' => 0,
]]);
if ($voidRows !== []) {
    throw new RuntimeException('Zero-value void remnant appears in the combined report.');
}

echo '[PASS] Direct use and later advance use add to one payment, with no duplicate credit row.' . PHP_EOL;
echo '[PASS] Available balance and status follow current values, not a stale payment status.' . PHP_EOL;
echo '[PASS] Zero-value void credit is hidden without changing its stored record.' . PHP_EOL;
