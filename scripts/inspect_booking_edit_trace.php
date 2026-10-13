<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$booking = '';
$group = '';
$full = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--booking=')) {
        $booking = strtoupper(trim(substr($argument, 10)));
    } elseif (str_starts_with($argument, '--group=')) {
        $group = trim(substr($argument, 8));
    } elseif ($argument === '--full') {
        $full = true;
    } else {
        fwrite(STDERR, "Usage: php scripts/inspect_booking_edit_trace.php --booking=BK-001044 [--group=ID] [--full]\n");
        exit(2);
    }
}
if (! preg_match('/^BK-[0-9]{6,}$/', $booking)) {
    fwrite(STDERR, "Provide a booking reference, for example --booking=BK-001044.\n");
    exit(2);
}

$files = glob(BASE_PATH . '/storage/logs/booking-edit-trace-*.jsonl') ?: [];
sort($files);
$readRecords = static function (array $files, string $group): Generator {
    foreach ($files as $file) {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                if ($group !== '' && (string) ($record['group_id'] ?? '') !== $group) {
                    continue;
                }
                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
};
$summary = static function (mixed $snapshot): mixed {
    if (! is_array($snapshot)) {
        return $snapshot;
    }
    $result = ['booking' => $snapshot['booking'] ?? null];
    foreach (['services', 'receivables', 'service_events', 'refund_details', 'supplier_advances', 'customer_receipts', 'receipt_allocations',
        'supplier_payments', 'supplier_obligations', 'supplier_allocations', 'supplier_advance_applications'] as $section) {
        $result[$section] = $snapshot[$section] ?? [];
    }
    $result['journal_ids'] = array_column((array) ($snapshot['journals'] ?? []), 'id');
    $result['correction_ids'] = array_column((array) ($snapshot['corrections'] ?? []), 'id');
    if (isset($snapshot['snapshot_error'])) {
        $result['snapshot_error'] = $snapshot['snapshot_error'];
    }
    return $result;
};
$matchedTraceIds = [];
foreach ($readRecords($files, $group) as $record) {
    if ((string) ($record['booking_reference'] ?? '') === $booking) {
        $matchedTraceIds[(string) ($record['trace_id'] ?? '')] = true;
    }
}
$records = [];
foreach ($readRecords($files, $group) as $record) {
    if (! isset($matchedTraceIds[(string) ($record['trace_id'] ?? '')])) {
        continue;
    }
    if (! $full) {
        $payload = (array) ($record['payload'] ?? []);
        $record['payload'] = [
            'submitted' => $payload['submitted'] ?? null,
            'observed' => $payload['observed'] ?? null,
            'note' => $payload['note'] ?? null,
            'steps' => $payload['steps'] ?? [],
            'changes' => $payload['changes'] ?? [],
            'error' => $payload['error'] ?? null,
            'before' => $summary($payload['before'] ?? null),
            'after' => $summary($payload['after'] ?? null),
        ];
    }
    $records[] = $record;
}

echo json_encode(['inspection_only' => true, 'booking_reference' => $booking, 'records' => $records],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
