<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/Services/CancellationRefundCalculator.php';

$failed = false;
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/cancellation_refund_calculations.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixtures as $fixture) {
    $actual = \App\Services\CancellationRefundCalculator::customerSummary($fixture['input']);
    $passed = array_intersect_key($actual, $fixture['result']) == $fixture['result'];
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $fixture['name'] . PHP_EOL;
    $failed = $failed || ! $passed;
}
$bk64 = \App\Services\CancellationRefundCalculator::customerSummary([
    'invoice' => 600, 'settled' => 550, 'expected' => 50, 'penalty' => 0,
]);
$offsetPassed = $bk64['outstanding'] === 0.0 && $bk64['applied_to_outstanding'] === 50.0
    && $bk64['supplier_applied_to_outstanding'] === 50.0 && $bk64['remaining'] === 0.0;
echo ($offsetPassed ? '[PASS] ' : '[FAIL] ') . 'BK-000064 supplier equivalent clears AED 50 customer balance' . PHP_EOL;
$failed = $failed || ! $offsetPassed;
foreach ([-1, INF, NAN, 'invalid'] as $invalid) {
    try {
        \App\Services\CancellationRefundCalculator::customerSummary(['invoice' => $invalid]);
        $failed = true;
        echo '[FAIL] Invalid input accepted' . PHP_EOL;
    } catch (RuntimeException $exception) {
        echo '[PASS] Invalid input rejected' . PHP_EOL;
    }
}
exit($failed ? 1 : 0);
