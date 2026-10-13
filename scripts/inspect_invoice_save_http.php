<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$options = getopt('', ['booking:', 'booking-id:', 'request-id:', 'limit:']);
$bookingId = (int) ($options['booking-id'] ?? 0);
$requestId = trim((string) ($options['request-id'] ?? ''));
$bookingReference = strtoupper(trim((string) ($options['booking'] ?? '')));
$limit = min(max((int) ($options['limit'] ?? 5), 1), 50);

if (($bookingId <= 0 && $bookingReference === '' && $requestId === '')
    || ($requestId !== '' && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $requestId) !== 1)) {
    fwrite(STDERR, "Usage: php scripts/inspect_invoice_save_http.php --booking=BK-001048 [--limit=5]\n"
        . "   or: php scripts/inspect_invoice_save_http.php --booking-id=1461\n"
        . "   or: php scripts/inspect_invoice_save_http.php --request-id=REQUEST_ID\n");
    exit(2);
}

if ($bookingReference !== '') {
    if (preg_match('/^BK-\d{6}$/', $bookingReference) !== 1) {
        fwrite(STDERR, "Invalid booking reference.\n");
        exit(2);
    }
    $db = \App\Core\App::bootstrap(BASE_PATH)->get('db');
    $query = $db->prepare('SELECT id FROM bookings WHERE booking_reference = ? LIMIT 1');
    $query->execute([$bookingReference]);
    $bookingId = (int) ($query->fetchColumn() ?: 0);
    if ($bookingId <= 0) {
        fwrite(STDERR, "Booking not found. Use --request-id if the database is unavailable.\n");
        exit(1);
    }
}

$requests = [];
$openLog = static function (string $path) {
    try {
        return fopen($path, 'rb');
    } catch (\Throwable) {
        return false;
    }
};
$files = glob(BASE_PATH . '/storage/logs/invoice-save-http-*.jsonl') ?: [];
sort($files);
foreach ($files as $path) {
    $handle = $openLog($path);
    if ($handle === false) {
        continue;
    }
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        if (! is_array($row)
            || ($requestId !== '' && (string) ($row['request_id'] ?? '') !== $requestId)
            || ($bookingId > 0 && (int) ($row['booking_id'] ?? 0) !== $bookingId)) {
            continue;
        }
        $id = (string) ($row['request_id'] ?? '');
        if ($id !== '') {
            $requests[$id][] = $row;
        }
    }
    fclose($handle);
}

if ($requests === []) {
    echo "No PHP entry log matched. If a 503 occurred, check the hosting web-server/proxy logs and PHP startup errors.\n";
    exit(0);
}

$requests = array_slice($requests, -$limit, null, true);
$selectedIds = array_fill_keys(array_keys($requests), true);
$runtime = [];
$runtimePath = BASE_PATH . '/storage/logs/app-runtime.log';
$handle = is_file($runtimePath) ? $openLog($runtimePath) : false;
if ($handle !== false) {
    while (($line = fgets($handle)) !== false) {
        $separator = strpos($line, ' | {');
        if ($separator === false) {
            continue;
        }
        $context = json_decode(substr($line, $separator + 3), true);
        $id = (string) ($context['request_id'] ?? '');
        if (! isset($selectedIds[$id])) {
            continue;
        }
        if (preg_match('/^\[[^]]+\] ([A-Za-z0-9._-]+):/', $line, $match) !== 1) {
            continue;
        }
        $channel = $match[1];
        if (! str_starts_with($channel, 'security.launcher_gate.')
            && ! in_array($channel, ['app.uncaught_exception', 'app.shutdown_fatal', 'booking_edit_trace.write_failed'], true)) {
            continue;
        }
        $runtime[$id][] = [
            'channel' => $channel,
            'type' => $context['type'] ?? null,
            'reason' => $context['reason'] ?? null,
            'file' => isset($context['file']) ? basename((string) $context['file']) : null,
            'line' => $context['line'] ?? null,
        ];
    }
    fclose($handle);
}

$bookingTrace = [];
$traceFiles = glob(BASE_PATH . '/storage/logs/booking-edit-trace-*.jsonl') ?: [];
sort($traceFiles);
foreach ($traceFiles as $path) {
    $handle = $openLog($path);
    if ($handle === false) {
        continue;
    }
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        $id = (string) ($row['request_id'] ?? '');
        if (! isset($selectedIds[$id])) {
            continue;
        }
        $bookingTrace[$id][] = [
            'action' => $row['action'] ?? null,
            'status' => $row['status'] ?? null,
            'trace_id' => $row['trace_id'] ?? null,
        ];
    }
    fclose($handle);
}

foreach ($requests as $id => $events) {
    echo json_encode([
        'request_id' => $id,
        'booking_id' => $events[0]['booking_id'] ?? null,
        'service_id' => $events[0]['service_id'] ?? null,
        'http_events' => $events,
        'server_errors' => $runtime[$id] ?? [],
        'booking_trace_outcomes' => $bookingTrace[$id] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
}
