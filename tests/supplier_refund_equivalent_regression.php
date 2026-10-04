<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$workspace = new \App\Services\ServiceWorkspaceService(\App\Core\App::bootstrap(BASE_PATH));
$method = new ReflectionMethod($workspace, 'normalizeSupplierRefundEquivalentInput');
$base = [
    'supplier_refund_conversion_mode' => 'manual_equivalent',
    'expected_supplier_refund_amount' => 50242,
    'expected_supplier_refund_currency' => 'PKR',
    'customer_penalty_currency' => 'AED', 'customer_penalty_amount' => 53,
    'expected_supplier_refund_equivalent_amount' => 653,
    'expected_supplier_refund_exchange_rate' => 999,
];
$failures = [];
$check = static function (string $name, bool $pass) use (&$failures): void {
    echo ($pass ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (! $pass) $failures[] = $name;
};
$normalize = static fn (array $input, string $invoice = 'AED'): array => $method->invoke($workspace, $input, $invoice);
$result = $normalize($base);
$recorded = ['currency' => 'AED', 'cost_currency' => 'PKR', 'pricing_exchange_rate' => 0.01302083];
$zeroPenalty = array_replace($base, ['expected_supplier_refund_amount' => 90605,
    'customer_penalty_amount' => 0, 'expected_supplier_refund_equivalent_amount' => 0]);
$reused = $method->invoke($workspace, $zeroPenalty, 'AED', $recorded);
$check('Zero penalty uses server-loaded original booking FX without a new equivalent',
    $reused['expected_supplier_refund_equivalent_amount'] === 1180.0
    && $reused['expected_supplier_refund_exchange_rate'] === 0.01302083
    && $reused['expected_supplier_refund_conversion_basis'] === 'saved_booking_rate');
foreach ([array_replace($recorded, ['pricing_exchange_rate' => 0]), array_replace($recorded, ['cost_currency' => 'USD'])] as $missing) {
    try { $method->invoke($workspace, $zeroPenalty, 'AED', $missing); $pass = false; }
    catch (RuntimeException $e) { $pass = str_contains($e->getMessage(), 'equivalent amount'); }
    $check('Zero penalty cannot bypass missing or mismatched original currency conversion', $pass);
}
$check('BK38 equivalent derives rate instead of trusting posted hidden rate',
    round(50242 * $result['expected_supplier_refund_exchange_rate'], 0) === 653.0
    && $result['expected_supplier_refund_equivalent_currency'] === 'AED');
foreach (['', 'abc', 0, -1, 653.5, INF, NAN] as $bad) {
    try { $normalize(array_replace($base, ['expected_supplier_refund_equivalent_amount' => $bad])); $pass = false; }
    catch (RuntimeException $e) { $pass = str_contains($e->getMessage(), 'equivalent amount in AED'); }
    $check('Missing/invalid/fractional equivalent rejected: ' . var_export($bad, true), $pass);
}
$same = $normalize(array_replace($base, ['customer_penalty_currency' => 'PKR', 'customer_penalty_exchange_rate' => 1 / 77]));
$check('Same source and penalty currency needs no entered equivalent',
    $same['expected_supplier_refund_equivalent_amount'] === 50242.0
    && round(50242 * $same['expected_supplier_refund_exchange_rate'], 0) === round(50242 / 77, 0));
$reverse = $normalize(array_replace($base, [
    'expected_supplier_refund_amount' => 653, 'expected_supplier_refund_currency' => 'AED',
    'customer_penalty_currency' => 'PKR', 'expected_supplier_refund_equivalent_amount' => 50242,
]));
$check('Prompt follows penalty currency even when supplier currency equals invoice',
    $reverse['expected_supplier_refund_equivalent_currency'] === 'PKR'
    && $reverse['expected_supplier_refund_exchange_rate'] === 1.0
    && abs($reverse['customer_penalty_exchange_rate'] - 653 / 50242) < 1e-8);
$third = $normalize(array_replace($base, [
    'expected_supplier_refund_currency' => 'USD', 'expected_supplier_refund_amount' => 100,
    'customer_penalty_currency' => 'PKR', 'expected_supplier_refund_equivalent_amount' => 28000,
    'customer_penalty_exchange_rate' => 1 / 77,
]));
$check('Three currencies preserve penalty equivalent and separate invoice conversion',
    $third['expected_supplier_refund_equivalent_currency'] === 'PKR'
    && round(100 * $third['expected_supplier_refund_exchange_rate'], 0) === 364.0);
try {
    $normalize(array_replace($base, ['customer_penalty_currency' => 'USD']));
    $check('Missing third-currency bridge rejects rather than guessing', false);
} catch (RuntimeException $e) {
    $check('Missing third-currency bridge rejects rather than guessing', str_contains($e->getMessage(), 'customer penalty currency conversion'));
}
$zero = $normalize(array_replace($base, ['expected_supplier_refund_amount' => 0, 'expected_supplier_refund_equivalent_amount' => '']));
$check('Zero expectation needs no equivalent', $zero['expected_supplier_refund_equivalent_amount'] === 0.0);
$legacy = $base; unset($legacy['supplier_refund_conversion_mode']);
$check('Internal legacy explicit-rate contract retained', $normalize($legacy) === $legacy);
echo 'No financial records written; input normalization only.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
