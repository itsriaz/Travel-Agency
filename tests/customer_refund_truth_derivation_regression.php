<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\ServiceWorkspaceService($app);

$reflection = new ReflectionClass($service);
$resolveCurrency = $reflection->getMethod('resolveCustomerRefundTruthCurrency');
$resolveCurrency->setAccessible(true);
$resolveAmount = $reflection->getMethod('resolveModernCustomerRefundTruthAmount');
$resolveAmount->setAccessible(true);

$payload = [
    'available_customer_refund_credit' => 714.28,
    'customer_penalty_amount' => 10.73,
    'customer_penalty_currency' => 'PKR',
    'customer_penalty_source_amount' => 826,
    'agency_fee_refund_amount' => 0,
    'expected_supplier_refund_amount' => 725.01,
    'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_source_amount' => 55826,
];

$currency = $resolveCurrency->invoke($service, $payload, 'AED');
$amount = $resolveAmount->invoke($service, $payload, $currency);

if ((string) $currency !== 'PKR') {
    fwrite(STDERR, "[FAIL] Refund truth currency should resolve to PKR.\n");
    exit(1);
}

if (abs((float) $amount - 55000.0) > 0.005) {
    fwrite(STDERR, "[FAIL] Refund truth amount should derive to PKR 55,000 from source amounts when legacy converted values are mixed into the payload.\n");
    exit(1);
}

echo "[PASS] Modern cancellation refund truth derives PKR payout from source amounts when legacy converted values are mixed into the payload.\n";

$explicitZeroPayload = [
    'available_customer_refund_credit' => 0,
    'available_customer_refund_credit_currency' => 'AED',
    'target_customer_refund_credit' => 0,
    'target_customer_refund_credit_currency' => 'AED',
    'customer_refund_basis' => 0,
    'customer_refund_basis_currency' => 'AED',
    'customer_penalty_amount' => 100,
    'customer_penalty_currency' => 'AED',
    'customer_penalty_source_amount' => 100,
    'agency_fee_refund_amount' => 0,
    'expected_supplier_refund_amount' => 300,
    'expected_supplier_refund_currency' => 'AED',
    'expected_supplier_refund_source_amount' => 300,
];

$explicitZeroAmount = $resolveAmount->invoke($service, $explicitZeroPayload, 'AED');
if (abs((float) $explicitZeroAmount - 0.0) > 0.005) {
    fwrite(STDERR, "[FAIL] Explicit saved zero refund credit must stay zero and must not be re-derived from supplier-side amounts.\n");
    exit(1);
}

echo "[PASS] Explicit saved zero refund credit is preserved and does not get replaced by supplier-side refund math.\n";
