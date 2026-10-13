<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$booking = $db->query('SELECT id, booking_reference FROM bookings ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (! is_array($booking)) {
    fwrite(STDERR, "[FAIL] No saved booking is available for a read-only trace test.\n");
    exit(1);
}

$bookingId = (int) $booking['id'];
$before = (new \App\Repositories\BookingEditTraceRepository($app))->snapshot($bookingId);
if (! array_key_exists('service_events', $before) || ! array_key_exists('refund_details', $before)) {
    fwrite(STDERR, "[FAIL] Refund event and payout snapshots are missing from the booking trace.\n");
    exit(1);
}
$groupId = 'test-trace-' . bin2hex(random_bytes(6));
$trace = \App\Services\BookingEditTraceService::begin($app, 'test.read_only', [
    'booking_id' => $bookingId,
    'edit_trace_group_id' => $groupId,
    'corrected_invoice_currency' => 'AED',
    'financial_correction_reason' => 'Regression test only',
    '_token' => 'must-not-be-recorded',
], 0, [(int) $before['booking']['branch_id']]);
\App\Services\BookingEditTraceService::checkpoint('Test::readOnly', 'completed');
$trace->finish('rejected', $bookingId, new RuntimeException('Test rejection; no save attempted.'));
$trace->finish('committed', $bookingId);
$after = (new \App\Repositories\BookingEditTraceRepository($app))->snapshot($bookingId);
if ($before !== $after) {
    fwrite(STDERR, "[FAIL] Diagnostic trace changed booking financial data.\n");
    exit(1);
}

$file = app_log_path('booking-edit-trace-' . date('Ymd') . '.jsonl');
$matches = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $record = json_decode($line, true);
    if (is_array($record) && ($record['group_id'] ?? '') === $groupId) {
        $matches[] = $record;
    }
}
if (count($matches) !== 2
    || ($matches[0]['status'] ?? '') !== 'started'
    || ($matches[1]['status'] ?? '') !== 'rejected'
    || ($matches[1]['payload']['changes'] ?? null) !== []
    || ($matches[1]['payload']['steps'][0]['service'] ?? '') !== 'Test::readOnly'
    || str_contains(json_encode($matches, JSON_THROW_ON_ERROR), 'must-not-be-recorded')) {
    fwrite(STDERR, "[FAIL] Trace pairing, rejection, checkpoints, or sensitive-field filtering failed.\n");
    exit(1);
}

$outsideTrace = \App\Services\BookingEditTraceService::begin($app, 'test.outside_branch', [
    'booking_id' => $bookingId,
    'edit_trace_group_id' => 'outside-' . $groupId,
], 0, []);
$outsideTrace->finish('rejected', $bookingId);
$logLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$lastLine = trim((string) end($logLines));
$outsideRecord = json_decode($lastLine, true);
if (($outsideRecord['payload']['after'] ?? null) !== []) {
    fwrite(STDERR, "[FAIL] Out-of-branch trace exposed a booking snapshot.\n");
    exit(1);
}

$browserGroup = 'browser-' . $groupId;
$observed = \App\Services\BookingEditTraceService::observeBrowserState($app, [
    'booking_id' => $bookingId,
    'group_id' => $browserGroup,
    'stage' => 'refund_field_changed',
    'field' => 'customer_refund_currency',
    'state' => [
        'service_id' => '1',
        'customer_refund_currency' => 'AED',
        'customer_refund_amount' => '50',
        'customer_bank_iban' => 'must-not-be-recorded',
        'correction_reason' => 'must-not-be-recorded',
    ],
], 0, [(int) $before['booking']['branch_id']]);
if (! $observed || \App\Services\BookingEditTraceService::observeBrowserState($app, [
    'booking_id' => $bookingId, 'stage' => 'refund_field_changed',
], 0, [])) {
    fwrite(STDERR, "[FAIL] Booking browser observations ignored the branch access boundary.\n");
    exit(1);
}
$logLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$browserRecord = json_decode((string) end($logLines), true);
if (($browserRecord['group_id'] ?? '') !== $browserGroup
    || ($browserRecord['status'] ?? '') !== 'observed_unsaved'
    || ($browserRecord['payload']['observed']['changed_field'] ?? '') !== 'customer_refund_currency'
    || str_contains(json_encode($browserRecord, JSON_THROW_ON_ERROR), 'must-not-be-recorded')) {
    fwrite(STDERR, "[FAIL] Browser observations were not correlated, labeled unsaved, or privacy-filtered.\n");
    exit(1);
}

$controller = file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php');
foreach (['service.cancel', 'service.refund', 'service.refund_edit', 'service.cancellation_settlement',
    'service.refund_reverse', 'service.refund_undo', 'service.refund_restore'] as $action) {
    if (! str_contains((string) $controller, "traceServiceOperation('" . $action . "'")) {
        fwrite(STDERR, "[FAIL] Missing controller trace for {$action}.\n");
        exit(1);
    }
}

echo "[PASS] Booking/refund traces preserve access boundaries, omit sensitive fields, and make no financial writes.\n";
