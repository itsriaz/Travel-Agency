<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$startedAt = microtime(true);

echo 'Travel Agency production preflight' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$commands = [
    'PHP syntax: public entrypoint' => [PHP_BINARY, '-l', BASE_PATH . '/public/index.php'],
    'PHP syntax: production readiness test' => [PHP_BINARY, '-l', BASE_PATH . '/tests/production_readiness.php'],
    'PHP syntax: backup script' => [PHP_BINARY, '-l', BASE_PATH . '/scripts/backup_database.php'],
    'Migration status' => [PHP_BINARY, BASE_PATH . '/database/migrate.php', 'status'],
    'Production readiness' => [PHP_BINARY, BASE_PATH . '/tests/production_readiness.php'],
];

$failures = [];

foreach ($commands as $label => $command) {
    echo '== ' . $label . ' ==' . PHP_EOL;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, BASE_PATH);
    if (! is_resource($process)) {
        echo '[FAIL] Could not start command.' . PHP_EOL . PHP_EOL;
        $failures[] = $label;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    echo trim((string) $stdout) . PHP_EOL;
    if (trim((string) $stderr) !== '') {
        echo trim((string) $stderr) . PHP_EOL;
    }

    if ($exitCode !== 0) {
        echo '[FAIL] Exit code: ' . $exitCode . PHP_EOL . PHP_EOL;
        $failures[] = $label;
        continue;
    }

    echo '[PASS]' . PHP_EOL . PHP_EOL;
}

$seconds = number_format(microtime(true) - $startedAt, 2);

if ($failures !== []) {
    echo 'Preflight failed after ' . $seconds . 's:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Preflight passed in ' . $seconds . 's.' . PHP_EOL;
