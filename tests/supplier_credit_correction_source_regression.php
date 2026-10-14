<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$service = new \App\Services\ServiceWorkspaceService(\App\Core\App::bootstrap(BASE_PATH));
$normalize = new ReflectionMethod($service, 'normalizeConfirmedSupplierRefundCorrectionInput');
$postedCredit = [
    'payload_json' => json_encode([
        'supplier_refund_payment_method' => 'supplier_credit',
        'supplier_refund_detail' => ['refund_currency' => 'PKR', 'refund_source_amount' => 50242],
    ], JSON_THROW_ON_ERROR),
];
$submitted = [
    'expected_supplier_refund_amount' => 50942,
    'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_equivalent_amount' => 653,
    'supplier_refund_amount' => 0,
    'supplier_refund_source_amount' => 0,
    'supplier_refund_currency' => 'AED',
    'supplier_refund_payment_method' => 'cash',
];
$same = $normalize->invoke($service, $submitted, 'AED', $postedCredit);
if ((float) $same['supplier_refund_source_amount'] !== 50942.0
    || $same['supplier_refund_currency'] !== 'PKR'
    || (float) $same['supplier_refund_amount'] !== 653.0
    || $same['supplier_refund_payment_method'] !== 'supplier_credit') {
    throw new RuntimeException('Supplier credit must follow the expected PKR amount and currency, not stale duplicate inputs.');
}
echo '[PASS] Supplier credit follows expected PKR 50,942 while AED 653 stays unchanged.' . PHP_EOL;

$changed = $normalize->invoke($service, array_replace($submitted, [
    'expected_supplier_refund_equivalent_amount' => 661,
]), 'AED', $postedCredit);
if ((float) $changed['supplier_refund_source_amount'] !== 50942.0
    || (float) $changed['supplier_refund_amount'] !== 661.0) {
    throw new RuntimeException('The confirmed AED equivalent must remain independent from the PKR supplier credit amount.');
}
echo '[PASS] AED equivalent may change to 661 without changing the PKR credit source.' . PHP_EOL;

$sameCurrency = $normalize->invoke($service, array_replace($submitted, [
    'expected_supplier_refund_currency' => 'AED',
    'expected_supplier_refund_amount' => 700,
    'expected_supplier_refund_equivalent_amount' => 661,
]), 'AED', $postedCredit);
if ((float) $sameCurrency['supplier_refund_source_amount'] !== 700.0
    || (float) $sameCurrency['supplier_refund_amount'] !== 700.0) {
    throw new RuntimeException('Same-currency credit must use the expected source amount, not a stale equivalent.');
}
echo '[PASS] Same-currency supplier credit uses the expected amount.' . PHP_EOL;

$postedCash = ['payload_json' => json_encode(['supplier_refund_payment_method' => 'cash'], JSON_THROW_ON_ERROR)];
$cash = $normalize->invoke($service, array_replace($submitted, [
    'supplier_refund_source_amount' => 50242,
    'supplier_refund_currency' => 'PKR',
    'supplier_refund_equivalent_amount' => 653,
]), 'AED', $postedCash);
if ((float) $cash['supplier_refund_source_amount'] !== 50242.0
    || (float) $cash['supplier_refund_amount'] !== 653.0) {
    throw new RuntimeException('Historic cash refunds must retain their separately confirmed actual amount.');
}
echo '[PASS] Existing cash refund corrections retain their separate amount path.' . PHP_EOL;
