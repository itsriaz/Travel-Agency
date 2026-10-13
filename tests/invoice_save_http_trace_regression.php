<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$testRoot = sys_get_temp_dir() . '/invoice-save-http-trace-' . bin2hex(random_bytes(6));
$originalServer = $_SERVER;
$originalPost = $_POST;

try {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/Travel-Agency/workspace/services/financial-correction';
    if (\App\Services\InvoiceSaveHttpTrace::beginForCurrentRequest($testRoot) !== null) {
        throw new RuntimeException('Non-POST request unexpectedly created a trace.');
    }

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'booking_id' => '41',
        'service_id' => '52',
        '_token' => 'secret-csrf-value',
        'financial_correction_reason' => 'private-reason-value',
        'corrected_cost_basis' => '123456',
    ];
    $trace = \App\Services\InvoiceSaveHttpTrace::beginForCurrentRequest($testRoot);
    if ($trace === null) {
        throw new RuntimeException('Invoice Save POST did not create a trace.');
    }
    $trace->checkpoint('app_bootstrapped');
    $trace->finish();
    $trace->finish();

    $files = glob($testRoot . '/storage/logs/invoice-save-http-*.jsonl') ?: [];
    $content = count($files) === 1 ? (string) file_get_contents($files[0]) : '';
    $lines = array_values(array_filter(explode("\n", trim($content))));
    $events = array_map(static fn (string $line): mixed => json_decode($line, true), $lines);
    if (count($events) !== 3
        || array_column($events, 'event') !== ['started', 'checkpoint', 'finished']
        || (int) ($events[0]['booking_id'] ?? 0) !== 41
        || (int) ($events[0]['service_id'] ?? 0) !== 52
        || count(array_unique(array_column($events, 'request_id'))) !== 1) {
        throw new RuntimeException('Invoice Save trace stages or identifiers did not reconcile.');
    }
    foreach (['secret-csrf-value', 'private-reason-value', '123456'] as $privateValue) {
        if (str_contains($content, $privateValue)) {
            throw new RuntimeException('Submitted form data leaked into the lifecycle trace.');
        }
    }

    $blockedRoot = $testRoot . '/blocked';
    mkdir($blockedRoot);
    file_put_contents($blockedRoot . '/storage', 'not a directory');
    $blockedTrace = \App\Services\InvoiceSaveHttpTrace::beginForCurrentRequest($blockedRoot);
    if ($blockedTrace === null) {
        throw new RuntimeException('Unwritable-log scenario did not reach the invoice trace.');
    }
    $blockedTrace->finish();

    echo "[PASS] Invoice Save traces are paired, private, and cannot block a save when storage is unavailable.\n";
} finally {
    $_SERVER = $originalServer;
    $_POST = $originalPost;
    foreach (glob($testRoot . '/storage/logs/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($testRoot . '/storage/logs');
    @rmdir($testRoot . '/storage');
    @unlink($testRoot . '/blocked/storage');
    @rmdir($testRoot . '/blocked');
    @rmdir($testRoot);
}
