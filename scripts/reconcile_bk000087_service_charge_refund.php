<?php

declare(strict_types=1);

// Reconcile the retained AED 100 service charge before transferring BK-000087 credit.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Services\ServiceWorkspaceService;

$options = getopt('', ['apply', 'actor:']);
$apply = array_key_exists('apply', $options);
$actor = filter_var($options['actor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
if ($actor === false || $actor === null) {
    fwrite(STDERR, 'A valid --actor=ID is required.' . PHP_EOL);
    exit(1);
}
$actorCheck = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.id=? AND u.is_active=1 AND r.code=?');
$actorCheck->execute([$actor, 'super_admin']);
if (! $actorCheck->fetchColumn()) {
    fwrite(STDERR, 'Actor must be an active super admin.' . PHP_EOL);
    exit(1);
}
$db->beginTransaction();

try {
    $row = static function (string $sql, array $params = []) use ($db): ?array {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $count = static function (string $sql, array $params = []) use ($db): int {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    };
    $check = static function (bool $valid, string $message): void {
        if (! $valid) { throw new RuntimeException($message); }
    };
    $booking = $row('SELECT id, branch_id FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000087']);
    $service = $row('SELECT id, booking_id, service_status, currency, cost_currency, final_sale_price, purchase_cost
        FROM booking_services WHERE id = ? FOR UPDATE', [87]);
    $event = $row('SELECT * FROM booking_service_events WHERE id = ? FOR UPDATE', [50]);
    $receipt = $row('SELECT * FROM customer_receipts WHERE id = ? FOR UPDATE', [92]);
    $item = $row('SELECT * FROM customer_receivable_items WHERE id = ? FOR UPDATE', [87]);
    $check($booking !== null && (int) $booking['id'] === 87 && (int) $booking['branch_id'] === 2
        && $service !== null && (int) $service['booking_id'] === 87
        && $service['service_status'] === 'Cancelled' && $service['currency'] === 'AED'
        && $service['cost_currency'] === 'PKR' && (float) $service['final_sale_price'] === 1280.0
        && (float) $service['purchase_cost'] === 90605.0,
        'BK-000087 booking or service differs from the reviewed case.');
    $check($event !== null && (int) $event['booking_service_id'] === 87
        && $event['event_type'] === 'cancel' && $event['event_status'] === 'posted'
        && $count('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = ?', [87]) === 1,
        'BK-000087 cancellation history changed.');
    $payload = json_decode((string) $event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $check(is_array($payload) && (float) ($payload['expected_supplier_refund_source_amount'] ?? 0) === 90605.0,
        'BK-000087 supplier refund expectation changed.');
    $check($receipt !== null && $receipt['booking_reference'] === 'BK-000087'
        && $receipt['currency'] === 'AED' && (float) $receipt['received_amount'] === 1280.0
        && (float) $receipt['returned_amount'] === 0.0
        && $item !== null && $item['booking_reference'] === 'BK-000087' && $item['currency'] === 'AED',
        'BK-000087 receipt or receivable differs from the reviewed case.');

    if ((float) ($payload['agency_fee_refund_amount'] ?? 0) === 100.0
        && (float) ($payload['available_customer_refund_credit'] ?? 0) === 1280.0
        && (float) $item['due_amount'] === 0.0) {
        $db->rollBack();
        echo 'ALREADY APPLIED: BK-000087 refund entitlement AED 1,280 includes the AED 100 service charge.' . PHP_EOL;
        exit(0);
    }

    $allocation = $row('SELECT id, customer_receivable_item_id, allocated_amount
        FROM customer_receipt_allocations WHERE customer_receipt_id = ?', [92]);
    $check($count('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ?', [92]) === 1
        && $allocation !== null && (int) $allocation['customer_receivable_item_id'] === 87
        && (float) $allocation['allocated_amount'] === 100.0
        && (float) $receipt['allocated_amount'] === 100.0
        && (float) $receipt['unallocated_amount'] === 1180.0
        && (float) $item['due_amount'] === 100.0 && (float) $item['allocated_amount'] === 100.0
        && (float) $item['outstanding_amount'] === 0.0
        && (float) ($payload['agency_fee_refund_amount'] ?? 0) === 0.0,
        'BK-000087 service-charge allocation is not in its reviewed starting state.');
    $cashBefore = $count('SELECT COUNT(*) FROM treasury_transactions');
    $auditBefore = $count('SELECT COUNT(*) FROM audit_logs WHERE event_name = ? AND JSON_EXTRACT(payload_json, ?) = ?',
        ['service.penalty_refund.corrected', '$.service_id', 87]);

    (new ServiceWorkspaceService($app))->correctPenaltyRefundWorkflow([
        'booking_id' => 87, 'service_id' => 87,
        'correction_reason' => 'Refund the previously retained AED 100 service charge on cancelled BK-000087; total customer credit AED 1,280.',
        'correction_event_date' => date('Y-m-d'),
        'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => 90605, 'expected_supplier_refund_currency' => 'PKR',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'expected_supplier_refund_equivalent_amount' => 0,
        'agency_fee_refund_amount' => 100, 'supplier_penalty_amount' => 0,
        'supplier_penalty_currency' => 'PKR', 'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => 1180 / 90605,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 0,
    ], $actor, [1, 2]);

    $afterReceipt = $row('SELECT * FROM customer_receipts WHERE id = ?', [92]);
    $afterItem = $row('SELECT * FROM customer_receivable_items WHERE id = ?', [87]);
    $afterEvent = $row('SELECT * FROM booking_service_events WHERE id = ?', [50]);
    $afterPayload = json_decode((string) ($afterEvent['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $check((float) $afterReceipt['allocated_amount'] === 0.0
        && (float) $afterReceipt['unallocated_amount'] === 1280.0
        && (float) $afterReceipt['returned_amount'] === 0.0
        && (float) $afterItem['due_amount'] === 0.0
        && (float) ($afterPayload['agency_fee_refund_amount'] ?? 0) === 100.0
        && (float) ($afterPayload['available_customer_refund_credit'] ?? 0) === 1280.0
        && ($afterPayload['available_customer_refund_credit_currency'] ?? '') === 'AED'
        && $count('SELECT COUNT(*) FROM treasury_transactions') === $cashBefore
        && $count('SELECT COUNT(*) FROM audit_logs WHERE event_name = ? AND JSON_EXTRACT(payload_json, ?) = ?',
            ['service.penalty_refund.corrected', '$.service_id', 87]) === $auditBefore + 1,
        'BK-000087 post-correction credit, cash or audit did not reconcile.');

    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    echo $apply ? 'APPLIED' : 'DRY RUN ROLLED BACK';
    echo ': BK-000087 AED 1,280 customer credit available, including AED 100 service charge; no cash refund posted.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
