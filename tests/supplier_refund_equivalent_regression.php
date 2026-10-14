<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$workspace = new \App\Services\ServiceWorkspaceService(\App\Core\App::bootstrap(BASE_PATH));
$normalize = new ReflectionMethod($workspace, 'normalizeSupplierRefundEquivalentInput');
$service = ['currency' => 'AED', 'cost_currency' => 'PKR', 'pricing_exchange_rate' => 0.01302083];
$base = [
    'supplier_refund_conversion_mode' => 'manual_equivalent',
    'expected_supplier_refund_amount' => 50242,
    'expected_supplier_refund_currency' => 'PKR',
    'expected_supplier_refund_equivalent_amount' => 653,
    'expected_supplier_refund_exchange_rate' => 999,
];
$run = static fn (array $input, string $invoice = 'AED'): array => $normalize->invoke($workspace, $input, $invoice, $service);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
    echo '[PASS] ' . $message . PHP_EOL;
};

$original = $run($base);
$assert((float) $original['expected_supplier_refund_equivalent_amount'] === 653.0
    && $original['expected_supplier_refund_equivalent_currency'] === 'AED'
    && round(50242 * (float) $original['expected_supplier_refund_exchange_rate'], 0) === 653.0,
    'PKR 50,242 and entered AED 653 are retained; the old rate is ignored');

$sameEquivalent = $run(array_replace($base, ['expected_supplier_refund_amount' => 50942]));
$changedEquivalent = $run(array_replace($base, [
    'expected_supplier_refund_amount' => 50942,
    'expected_supplier_refund_equivalent_amount' => 661,
]));
$assert((float) $sameEquivalent['expected_supplier_refund_equivalent_amount'] === 653.0
    && (float) $changedEquivalent['expected_supplier_refund_equivalent_amount'] === 661.0,
    'AED equivalent may remain 653 or change to 661 independently of PKR');

foreach (['', 'abc', 0, -1, 653.5, INF, NAN] as $invalid) {
    try {
        $run(array_replace($base, ['expected_supplier_refund_equivalent_amount' => $invalid]));
        throw new RuntimeException('Invalid equivalent was accepted: ' . var_export($invalid, true));
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'equivalent amount in AED'),
            'Missing or invalid AED equivalent is rejected: ' . var_export($invalid, true));
    }
}

$sameCurrency = $run(array_replace($base, [
    'expected_supplier_refund_currency' => 'AED',
    'expected_supplier_refund_amount' => 700,
    'expected_supplier_refund_equivalent_amount' => 661,
]));
$assert((float) $sameCurrency['expected_supplier_refund_equivalent_amount'] === 700.0,
    'Same-currency refund uses its actual amount, not a separate equivalent');

$zero = $run(array_replace($base, [
    'expected_supplier_refund_amount' => 0,
    'expected_supplier_refund_equivalent_amount' => '',
]));
$assert((float) $zero['expected_supplier_refund_equivalent_amount'] === 0.0,
    'Zero expected refund needs no equivalent');

echo 'No financial records written; input normalization only.' . PHP_EOL;
