<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

$filters = [];
$limit = 20;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(request|trace|record|payment|account)=(.+)$/', $argument, $matches) === 1) {
        $filters[$matches[1]] = trim($matches[2]);
    } elseif (preg_match('/^--limit=(\d+)$/', $argument, $matches) === 1) {
        $limit = min(100, max(1, (int) $matches[1]));
    } else {
        fwrite(STDERR, "Usage: php scripts/inspect_salary_operation_trace.php --record=ID|--payment=ID|--account=ID|--request=ID|--trace=ID [--limit=20]\n");
        exit(1);
    }
}
if ($filters === []) {
    fwrite(STDERR, "Select at least one trace filter.\n");
    exit(1);
}

$containsField = static function (mixed $value, string $field, string $expected) use (&$containsField): bool {
    if (! is_array($value)) {
        return false;
    }
    foreach ($value as $key => $item) {
        if ($key === $field && (string) $item === $expected) {
            return true;
        }
        if (is_array($item) && $containsField($item, $field, $expected)) {
            return true;
        }
    }
    return false;
};

$groups = [];
foreach (glob(app_log_path('salary-operations-*.jsonl')) ?: [] as $path) {
    $file = new SplFileObject($path);
    while (! $file->eof()) {
        $row = json_decode(trim((string) $file->fgets()), true);
        if (! is_array($row) || ! isset($row['trace_id'])) {
            continue;
        }
        $groups[(string) $row['trace_id']][] = $row;
    }
}

$matches = [];
foreach ($groups as $traceId => $rows) {
    foreach ($rows as $row) {
        $matchesRow = true;
        foreach ($filters as $filter => $expected) {
            $matchesRow = match ($filter) {
                'request' => (string) ($row['request_id'] ?? '') === $expected,
                'trace' => $traceId === $expected,
                'record' => $containsField($row, 'salary_record_id', $expected)
                    || $containsField($row['before']['record'] ?? [], 'id', $expected)
                    || $containsField($row['after']['record'] ?? [], 'id', $expected),
                'payment' => $containsField($row, 'salary_payment_id', $expected)
                    || $containsField($row['before']['payment'] ?? [], 'id', $expected)
                    || $containsField($row['after']['payment'] ?? [], 'id', $expected),
                'account' => $containsField($row, 'business_source_id', $expected),
                default => false,
            };
            if (! $matchesRow) {
                break;
            }
        }
        if ($matchesRow) {
            $matches[$traceId] = $rows;
            break;
        }
    }
}

$selected = array_slice($matches, -$limit, null, true);
echo json_encode([
    'inspection_only' => true,
    'trace_count' => count($selected),
    'traces' => array_values($selected),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
