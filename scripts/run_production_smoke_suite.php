<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', [
    'base-url::',
    'health-token::',
    'php-bin::',
    'report-path::',
    'timeout::',
    'skip-http',
    'skip-write-regressions',
]);

$baseUrl = trim((string) ($options['base-url'] ?? (string) config('app.url', '')));
$healthToken = trim((string) ($options['health-token'] ?? (string) config('security.health.token', '')));
$phpBinary = trim((string) ($options['php-bin'] ?? PHP_BINARY));
$timeoutSeconds = max(5, (int) ($options['timeout'] ?? 20));
$skipHttp = array_key_exists('skip-http', $options);
$skipWriteRegressions = array_key_exists('skip-write-regressions', $options);
$reportPath = trim((string) ($options['report-path'] ?? ''));

if ($reportPath === '') {
    $reportPath = BASE_PATH . '/storage/logs/production_smoke_suite_' . date('Ymd_His') . '.json';
}

$results = [];
$failures = [];
$warnings = [];
$startedAt = microtime(true);

$emit = static function (string $status, string $label, string $details = '') use (&$failures, &$warnings): void {
    $prefix = match ($status) {
        'pass' => '[PASS] ',
        'warn' => '[WARN] ',
        default => '[FAIL] ',
    };

    echo $prefix . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if ($status === 'fail') {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }

    if ($status === 'warn') {
        $warnings[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$record = static function (string $status, string $label, float $durationMs, string $details = '', array $extra = []) use (&$results, $emit): void {
    $emit($status, $label, $details);
    $results[] = array_merge([
        'status' => $status,
        'label' => $label,
        'duration_ms' => round($durationMs, 2),
        'details' => $details,
    ], $extra);
};

$runTimed = static function (callable $callback) {
    $started = microtime(true);
    $result = $callback();

    return [$result, (microtime(true) - $started) * 1000];
};

$columnExists = static function (string $table, string $column) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $statement->fetchColumn() !== false;
};

$httpGet = static function (string $url, int $timeoutSeconds): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'travel-agency-production-smoke/1.0',
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if (! is_string($response)) {
            return [
                'ok' => false,
                'status_code' => $statusCode,
                'body' => '',
                'error' => $error !== '' ? $error : 'No response body returned.',
            ];
        }

        return [
            'ok' => $error === '',
            'status_code' => $statusCode,
            'body' => substr($response, $headerSize),
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "User-Agent: travel-agency-production-smoke/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = is_array($http_response_header ?? null) ? $http_response_header : [];
    $statusCode = 0;
    foreach ($headers as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
            break;
        }
    }

    return [
        'ok' => is_string($body),
        'status_code' => $statusCode,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'HTTP request failed.',
    ];
};

$canUseExec = static function (): bool {
    if (! function_exists('exec')) {
        return false;
    }

    $disabled = array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) ini_get('disable_functions'))
    );

    return ! in_array('exec', $disabled, true);
};

$runPhpScript = static function (string $phpBinary, string $scriptPath): array {
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $outputLines = [];
    $exitCode = 1;
    exec($command, $outputLines, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode(PHP_EOL, $outputLines),
    ];
};

$runIncludedScript = static function (string $scriptPath): array {
    $output = '';
    $exitCode = 1;

    $runner = static function (string $path) use (&$output, &$exitCode): void {
        ob_start();
        try {
            if (! defined('SMOKE_SUITE_EMBEDDED')) {
                define('SMOKE_SUITE_EMBEDDED', true);
            }

            $result = include $path;
            $output = (string) ob_get_clean();
            $exitCode = is_int($result) ? $result : 0;
        } catch (\Throwable $exception) {
            $buffer = ob_get_clean();
            $output = trim($buffer . PHP_EOL . $exception::class . ': ' . $exception->getMessage());
            $exitCode = 1;
        }
    };

    $runner($scriptPath);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
};

$scriptSuites = [
    [
        'label' => 'Production readiness',
        'path' => BASE_PATH . '/tests/production_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Security layer readiness',
        'path' => BASE_PATH . '/tests/security_layer_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Two-factor readiness',
        'path' => BASE_PATH . '/tests/two_factor_readiness.php',
        'writes' => false,
        'production_only' => true,
    ],
    [
        'label' => 'Accounting engine readiness',
        'path' => BASE_PATH . '/tests/accounting_engine_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Customer global settlement readiness',
        'path' => BASE_PATH . '/tests/customer_global_settlement_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Supplier global settlement readiness',
        'path' => BASE_PATH . '/tests/supplier_global_settlement_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Workspace payment readiness',
        'path' => BASE_PATH . '/tests/workspace_payment_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Workspace salesperson receipt readiness',
        'path' => BASE_PATH . '/tests/workspace_salesperson_receipt_readiness.php',
        'writes' => false,
    ],
    [
        'label' => 'Report truth regression',
        'path' => BASE_PATH . '/tests/report_truth_regression.php',
        'writes' => false,
    ],
    [
        'label' => 'Financial invariants audit',
        'path' => BASE_PATH . '/tests/financial_invariants_audit.php',
        'writes' => false,
    ],
    [
        'label' => 'Service lifecycle regression',
        'path' => BASE_PATH . '/tests/service_lifecycle_regression.php',
        'writes' => true,
    ],
    [
        'label' => 'Treasury transfer regression',
        'path' => BASE_PATH . '/tests/treasury_transfer_regression.php',
        'writes' => true,
    ],
    [
        'label' => 'Supplier advance FX regression',
        'path' => BASE_PATH . '/tests/supplier_advance_fx_application_regression.php',
        'writes' => true,
    ],
    [
        'label' => 'Supplier overpayment advance regression',
        'path' => BASE_PATH . '/tests/supplier_overpayment_to_advance_regression.php',
        'writes' => true,
    ],
    [
        'label' => 'Supplier advance service lookup regression',
        'path' => BASE_PATH . '/tests/supplier_advance_service_lookup_regression.php',
        'writes' => true,
    ],
];

$classifyScriptResult = static function (array $suite, int $exitCode, string $output): array {
    if ($exitCode === 0) {
        return [
            'status' => 'pass',
            'details' => 'exit=0',
        ];
    }

    if (
        ($suite['label'] ?? '') === 'Treasury transfer regression'
        && str_contains($output, 'Need one active cash account and one active bank account in the same branch/currency.')
    ) {
        return [
            'status' => 'warn',
            'details' => 'Skipped because treasury regression prerequisites are not configured in this environment.',
        ];
    }

    return [
        'status' => 'fail',
        'details' => 'exit=' . $exitCode,
    ];
};

echo 'Production smoke suite' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL;
echo 'Environment: ' . (string) config('app.env', 'unknown') . PHP_EOL;
echo 'Database: ' . (string) config('database.database', 'unknown') . PHP_EOL;
echo 'Runner mode: ' . ($canUseExec() ? 'subprocess' : 'embedded') . PHP_EOL;
echo PHP_EOL;

[$brokenAutoIncrementTables, $autoIncrementDurationMs] = $runTimed(static function () use ($db): array {
    $statement = $db->query(
        "SELECT TABLE_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND COLUMN_NAME = 'id'
           AND COLUMN_KEY = 'PRI'
           AND EXTRA NOT LIKE '%auto_increment%'
         ORDER BY TABLE_NAME ASC"
    );

    return $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
});

$record(
    $brokenAutoIncrementTables === [] ? 'pass' : 'fail',
    'Primary-key id columns retain AUTO_INCREMENT',
    $autoIncrementDurationMs,
    $brokenAutoIncrementTables === [] ? '0 broken tables' : implode(', ', $brokenAutoIncrementTables),
    ['broken_tables' => $brokenAutoIncrementTables]
);

[$journalImbalances, $journalDurationMs] = $runTimed(static function () use ($db): array {
    $statement = $db->query(
        'SELECT je.id,
                ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit_total,
                ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit_total
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         GROUP BY je.id
         HAVING debit_total <> credit_total
         LIMIT 10'
    );

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$record(
    $journalImbalances === [] ? 'pass' : 'fail',
    'Journal entries remain balanced',
    $journalDurationMs,
    $journalImbalances === [] ? 'No imbalances found' : json_encode($journalImbalances, JSON_UNESCAPED_SLASHES),
    ['sample_rows' => $journalImbalances]
);

[$receiptMismatches, $receiptDurationMs] = $runTimed(static function () use ($db): array {
    $statement = $db->query(
        'SELECT id, booking_reference, receipt_no, currency, received_amount, allocated_amount, unallocated_amount
         FROM customer_receipts
         WHERE status <> "void"
           AND ABS(received_amount - (allocated_amount + unallocated_amount)) > 0.005
         LIMIT 10'
    );

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$record(
    $receiptMismatches === [] ? 'pass' : 'warn',
    'Customer receipt allocation totals are internally consistent',
    $receiptDurationMs,
    $receiptMismatches === [] ? 'No mismatches found' : json_encode($receiptMismatches, JSON_UNESCAPED_SLASHES),
    ['sample_rows' => $receiptMismatches]
);

$supplierPaymentConvertedAdvanceSelect = $columnExists('supplier_payments', 'converted_advance_amount')
    ? 'converted_advance_amount'
    : '0 AS converted_advance_amount';
$supplierPaymentConvertedAdvanceValue = $columnExists('supplier_payments', 'converted_advance_amount')
    ? 'converted_advance_amount'
    : '0';

[$supplierPaymentMismatches, $supplierPaymentDurationMs] = $runTimed(static function () use ($db, $supplierPaymentConvertedAdvanceSelect, $supplierPaymentConvertedAdvanceValue): array {
    $statement = $db->query(
        'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount, '
            . $supplierPaymentConvertedAdvanceSelect . '
         FROM supplier_payments
         WHERE status <> "void"
           AND ABS(paid_amount - (allocated_amount + unallocated_amount + ' . $supplierPaymentConvertedAdvanceValue . ')) > 0.005
         LIMIT 10'
    );

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

$record(
    $supplierPaymentMismatches === [] ? 'pass' : 'warn',
    'Supplier payment allocation totals are internally consistent',
    $supplierPaymentDurationMs,
    $supplierPaymentMismatches === [] ? 'No mismatches found' : json_encode($supplierPaymentMismatches, JSON_UNESCAPED_SLASHES),
    ['sample_rows' => $supplierPaymentMismatches]
);

if (! $skipHttp) {
    if ($baseUrl === '' || $healthToken === '') {
        $record(
            'warn',
            'Health endpoint smoke check',
            0.0,
            'Skipped because base URL or health token is missing.'
        );
    } else {
        $healthUrl = rtrim($baseUrl, '/') . '/health?token=' . rawurlencode($healthToken);
        [$httpResponse, $httpDurationMs] = $runTimed(static function () use ($httpGet, $healthUrl, $timeoutSeconds): array {
            return $httpGet($healthUrl, $timeoutSeconds);
        });

        $healthPayload = json_decode((string) ($httpResponse['body'] ?? ''), true);
        $healthOk = ($httpResponse['ok'] ?? false) === true
            && (int) ($httpResponse['status_code'] ?? 0) === 200
            && is_array($healthPayload)
            && (string) ($healthPayload['status'] ?? '') === 'ok';

        $healthDetails = $healthOk
            ? ('status=ok, checks=' . implode(', ', array_keys((array) ($healthPayload['checks'] ?? []))))
            : ('http=' . (int) ($httpResponse['status_code'] ?? 0) . ', error=' . (string) ($httpResponse['error'] ?? ''));

        $record(
            $healthOk ? 'pass' : 'fail',
            'Health endpoint smoke check',
            $httpDurationMs,
            $healthDetails,
            [
                'url' => $healthUrl,
                'http_status' => (int) ($httpResponse['status_code'] ?? 0),
                'payload' => $healthPayload,
            ]
        );
    }
}

foreach ($scriptSuites as $suite) {
    if (! is_file($suite['path'])) {
        $record('fail', $suite['label'], 0.0, 'Missing script: ' . $suite['path']);
        continue;
    }

    if (($suite['production_only'] ?? false) && ! app_is_production()) {
        $record('warn', $suite['label'], 0.0, 'Skipped because this check is only meaningful in production.');
        continue;
    }

    if ($skipWriteRegressions && $suite['writes']) {
        $record('warn', $suite['label'], 0.0, 'Skipped write regression by request.');
        continue;
    }

    [$runResult, $durationMs] = $runTimed(static function () use ($runPhpScript, $runIncludedScript, $canUseExec, $phpBinary, $suite): array {
        return $canUseExec()
            ? $runPhpScript($phpBinary, $suite['path'])
            : $runIncludedScript($suite['path']);
    });

    $output = trim((string) ($runResult['output'] ?? ''));
    $exitCode = (int) ($runResult['exit_code'] ?? 1);
    $classified = $classifyScriptResult($suite, $exitCode, $output);

    $record(
        (string) $classified['status'],
        $suite['label'],
        $durationMs,
        (string) $classified['details'],
        [
            'script' => $suite['path'],
            'writes' => (bool) $suite['writes'],
            'exit_code' => $exitCode,
            'output' => $output,
        ]
    );
}

$endedAt = microtime(true);
$summary = [
    'started_at' => date(DATE_ATOM, (int) $startedAt),
    'finished_at' => date(DATE_ATOM, (int) $endedAt),
    'elapsed_seconds' => round($endedAt - $startedAt, 2),
    'environment' => (string) config('app.env', 'unknown'),
    'database' => (string) config('database.database', 'unknown'),
    'base_url' => $baseUrl,
    'skip_http' => $skipHttp,
    'skip_write_regressions' => $skipWriteRegressions,
    'failures' => $failures,
    'warnings' => $warnings,
    'results' => $results,
];

$reportDirectory = dirname($reportPath);
if (! is_dir($reportDirectory)) {
    @mkdir($reportDirectory, 0775, true);
}
@file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo PHP_EOL;
echo 'JSON report: ' . $reportPath . PHP_EOL;

if ($warnings !== []) {
    echo 'Warnings: ' . count($warnings) . PHP_EOL;
}

if ($failures !== []) {
    echo 'Failures: ' . count($failures) . PHP_EOL;
    exit(1);
}

echo 'Production smoke suite passed.' . PHP_EOL;
