<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$failedClosed = false;
try {
    \App\Core\Database::connection([
        'host' => '127.0.0.1',
        'port' => 9,
        'database' => 'unreachable_test',
        'charset' => 'utf8mb4',
        'username' => 'test',
        'password' => 'test',
    ]);
} catch (PDOException $exception) {
    $failedClosed = $exception->getMessage() === 'Database connection failed.'
        && $exception->getPrevious() instanceof PDOException;
}

if (! $failedClosed) {
    fwrite(STDERR, "[FAIL] Unavailable database did not fail closed with a preserved cause.\n");
    exit(1);
}

echo "[PASS] Unavailable database fails closed without requiring an initialized App.\n";
