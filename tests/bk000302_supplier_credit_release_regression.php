<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$booking = $db->query("SELECT id FROM bookings WHERE booking_reference = 'BK-000302'")->fetch(PDO::FETCH_ASSOC);
if (! $booking) {
    echo '[SKIP] BK-000302 is not in this database.' . PHP_EOL;
    exit(0);
}

$workspace = new \App\Services\ServiceWorkspaceService($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$db->beginTransaction();
try {
    $state = $workspace->serviceState((int) $booking['id'], [1, 2]);
    $service = (array) (($state['services'] ?? [])[0] ?? []);
    $serviceId = (int) ($service['id'] ?? 0);
    $cancel = $events->latestPostedEvent($serviceId, 'cancel');
    $payload = json_decode((string) ($cancel['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if ($serviceId <= 0 || ! $cancel || ! is_array($payload)
        || (float) ($payload['released_supplier_payment_credit'] ?? 0) !== 75277.0
        || empty($payload['released_supplier_payment_ids'])) {
        throw new RuntimeException('The BK-000302 historical release evidence has changed; review the fixture.');
    }
    if ((float) ($service['supplier_credit_recorded_source_amount'] ?? -1) !== 75277.0
        || (float) ($service['supplier_refundable_credit_amount'] ?? -1) !== 0.0
        || ($service['supplier_credit_auto_recording'] ?? false) !== true) {
        throw new RuntimeException('Released PKR 75,277 must appear as already recorded, with no further supplier action.');
    }
    echo '[PASS] Legacy released supplier payment credit is recognized; no second credit is requested.' . PHP_EOL;

    $blocked = false;
    try {
        $workspace->refundService([
            'booking_id' => (int) $booking['id'],
            'service_id' => $serviceId,
            'refund_reason' => 'Rollback-only duplicate credit check',
            'refund_event_date' => date('Y-m-d'),
            'customer_refund_amount' => 0,
            'supplier_refund_amount' => 75277,
            'supplier_refund_currency' => 'PKR',
            'supplier_refund_payment_method' => 'supplier_credit',
        ], 1, [1, 2]);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Do not record it again');
    }
    if (! $blocked) {
        throw new RuntimeException('A second supplier credit was not blocked by the release evidence.');
    }
    echo '[PASS] A second posting of the same PKR 75,277 is rejected.' . PHP_EOL;

    // A missing release reference must not turn an expected refund into
    // an apparently recorded credit. These payload edits are rolled back.
    $update = $db->prepare('UPDATE booking_service_events SET payload_json = :payload WHERE id = :id');
    $withoutReleaseIds = $payload;
    unset($withoutReleaseIds['released_supplier_payment_ids'], $withoutReleaseIds['released_supplier_advance_ids']);
    $update->execute(['payload' => json_encode($withoutReleaseIds, JSON_THROW_ON_ERROR), 'id' => (int) $cancel['id']]);
    $unproven = (array) (($workspace->serviceState((int) $booking['id'], [1, 2])['services'] ?? [])[0] ?? []);
    if ((float) ($unproven['supplier_credit_recorded_source_amount'] ?? -1) !== 0.0
        || (float) ($unproven['supplier_refundable_credit_amount'] ?? -1) !== 75277.0
        || ($unproven['supplier_credit_auto_recording'] ?? true) !== false) {
        throw new RuntimeException('An expected supplier refund without release IDs must remain unrecorded.');
    }
    echo '[PASS] An expected refund without release evidence stays unrecorded.' . PHP_EOL;

    $partialRelease = $payload;
    $partialRelease['released_supplier_payment_credit'] = 50000;
    $update->execute(['payload' => json_encode($partialRelease, JSON_THROW_ON_ERROR), 'id' => (int) $cancel['id']]);
    $partial = (array) (($workspace->serviceState((int) $booking['id'], [1, 2])['services'] ?? [])[0] ?? []);
    if ((float) ($partial['supplier_credit_recorded_source_amount'] ?? -1) !== 50000.0
        || (float) ($partial['supplier_refundable_credit_amount'] ?? -1) !== 25277.0
        || ($partial['supplier_credit_auto_recording'] ?? true) !== false) {
        throw new RuntimeException('A partial release must leave only the unreleased difference pending.');
    }
    echo '[PASS] A partial release retains only PKR 25,277 as pending.' . PHP_EOL;
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
