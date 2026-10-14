<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$workspace = new \App\Services\ServiceWorkspaceService(\App\Core\App::bootstrap(BASE_PATH));
$normalize = new ReflectionMethod($workspace, 'normalizeCancellationSettlementInput');
$toInvoice = new ReflectionMethod($workspace, 'settlementAmountInBookingCurrency');
$partialSupplierRefund = new ReflectionMethod($workspace, 'normalizeExpectedSourceSupplierRefundInput');
$failures = [];
$check = static function (string $name, bool $pass) use (&$failures): void {
    echo ($pass ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (! $pass) $failures[] = $name;
};

$aedService = ['currency' => 'AED', 'cost_currency' => 'PKR', 'pricing_exchange_rate' => 0.01297859];
$aedInput = [
    'settlement_currency' => 'PKR', 'settlement_exchange_rate' => 999,
    'supplier_refund_conversion_mode' => 'manual_equivalent',
    'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'AED',
    'expected_supplier_refund_amount' => 80835, 'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_equivalent_amount' => 1049,
    'supplier_penalty_amount' => 0, 'supplier_penalty_currency' => 'PKR',
    'agency_fee_refund_amount' => 51,
];
$aed = $normalize->invoke($workspace, $aedInput, 'AED', 'PKR', $aedService);
$check('AED service amount remains AED 51 even when the supplier settlement is PKR',
    $aed['agency_fee_refund_currency'] === 'AED'
    && $toInvoice->invoke($workspace, $aed, 'agency_fee_refund_amount', 'AED') === 51.0);
$check('Supplier PKR 80,835 uses the reviewed AED 1,049 equivalent, not the generic rate',
    $toInvoice->invoke($workspace, $aed, 'expected_supplier_refund_amount', 'AED') === 1049.0
    && $aed['expected_supplier_refund_exchange_rate'] !== 999.0);

$pkrService = ['currency' => 'PKR', 'cost_currency' => 'AED', 'pricing_exchange_rate' => 77.0];
$pkrInput = [
    'settlement_currency' => 'AED', 'settlement_exchange_rate' => 0.01,
    'supplier_refund_conversion_mode' => 'manual_equivalent',
    'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'PKR',
    'expected_supplier_refund_amount' => 100, 'expected_supplier_refund_currency' => 'AED',
    'expected_supplier_refund_equivalent_amount' => 7700,
    'agency_fee_refund_amount' => 51,
];
$pkr = $normalize->invoke($workspace, $pkrInput, 'PKR', 'AED', $pkrService);
$check('Reverse pairing keeps the service amount in PKR and converts supplier AED separately',
    $toInvoice->invoke($workspace, $pkr, 'agency_fee_refund_amount', 'PKR') === 51.0
    && $toInvoice->invoke($workspace, $pkr, 'expected_supplier_refund_amount', 'PKR') === 7700.0);

$reject = static function (string $name, array $input, string $expected) use ($normalize, $workspace, $aedService, $check): void {
    try {
        $normalize->invoke($workspace, $input, 'AED', 'PKR', $aedService);
        $check($name, false);
    } catch (RuntimeException $error) {
        $check($name, str_contains($error->getMessage(), $expected));
    }
};
$reject('Mislabeled service amount is rejected before posting',
    array_replace($aedInput, ['agency_fee_refund_currency' => 'PKR']), 'Service amount must use');
$reject('Invalid amount is rejected instead of silently becoming zero',
    array_replace($aedInput, ['agency_fee_refund_amount' => 'fifty-one']), 'valid non-negative');
$reject('Supplier refund needs a confirmed AED equivalent even when a booking rate exists',
    array_diff_key($aedInput, ['expected_supplier_refund_equivalent_amount' => true]), 'equivalent amount in AED');
$reject('A generic settlement rate cannot replace a missing supplier field rate', [
    'settlement_currency' => 'PKR', 'settlement_exchange_rate' => 0.01297859,
    'expected_supplier_refund_amount' => 700,
    'expected_supplier_refund_currency' => 'PKR',
], 'exchange rate for expected supplier refund');
$reject('A generic settlement rate cannot replace a missing customer penalty rate', [
    'settlement_currency' => 'PKR', 'settlement_exchange_rate' => 0.01297859,
    'customer_penalty_amount' => 700, 'customer_penalty_currency' => 'PKR',
], 'exchange rate for customer penalty');

$savedExpectation = [
    'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_source_amount' => 80835,
    'expected_supplier_refund_booking_amount' => 1049,
];
try {
    $partialSupplierRefund->invoke($workspace, [
        'supplier_refund_amount' => 40000, 'supplier_refund_currency' => 'PKR',
    ], $savedExpectation, 'AED', 'PKR');
    $check('Partial supplier refund requires its own confirmed AED equivalent', false);
} catch (RuntimeException $error) {
    $check('Partial supplier refund requires its own confirmed AED equivalent',
        str_contains($error->getMessage(), 'confirmed supplier refund equivalent'));
}
$partial = $partialSupplierRefund->invoke($workspace, [
    'supplier_refund_amount' => 40000, 'supplier_refund_currency' => 'PKR',
    'supplier_refund_equivalent_amount' => 500,
], $savedExpectation, 'AED', 'PKR');
$check('Partial supplier refund uses AED 500 entered by the client, not a prorated guess',
    (float) $partial['supplier_refund_amount'] === 500.0
    && (float) $partial['supplier_refund_source_amount'] === 40000.0
    && (float) $partial['supplier_refund_exchange_rate'] === 80.0);

echo 'No financial records written; currency normalization only.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
