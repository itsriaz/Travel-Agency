<?php

declare(strict_types=1);

// One-off, guarded reconciliation of a mistaken cancellation and an older unowned receipt.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Repositories\SupplierRepository;
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
    $scalar = static function (string $sql, array $params = []) use ($db): int {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    };
    $check = static function (bool $condition, string $message): void {
        if (! $condition) { throw new RuntimeException($message); }
    };
    $source = $row('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000087']);
    $target = $row('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000795']);
    $check($source !== null && $target !== null && (int) $source['branch_id'] === 2
        && (int) $target['branch_id'] === 2 && (int) $source['lead_traveler_id'] > 0
        && (int) $source['lead_traveler_id'] === (int) $target['lead_traveler_id'],
        'Booking ownership, customer or branch differs from the verified case.');

    $service = $row('SELECT id, service_status, line_reference, currency, cost_currency, final_sale_price,
        purchase_cost, created_at, updated_at FROM booking_services WHERE booking_id = ? FOR UPDATE', [(int) $target['id']]);
    $check($service !== null && (int) $service['id'] === 805 && $service['service_status'] === 'Cancelled'
        && $service['line_reference'] === 'SV-001' && $service['currency'] === 'AED'
        && $service['cost_currency'] === 'PKR' && abs((float) $service['final_sale_price'] - 1081) < .005
        && abs((float) $service['purchase_cost'] - 83012) < .005,
        'BK-000795 service no longer matches the verified original invoice and supplier cost.');
    $check($scalar('SELECT COUNT(*) FROM booking_services WHERE booking_id = ?', [(int) $target['id']]) === 1,
        'BK-000795 now has more than one service; review it manually.');

    $events = new BookingServiceEventRepository($app);
    $cancel = $events->latestPostedEvent(805, 'cancel');
    $check($cancel !== null && (int) $cancel['id'] === 49 && $events->latestPostedEvent(805, 'refund') === null,
        'BK-000795 cancellation/refund history changed.');
    $check($scalar('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = ?', [805]) === 1,
        'BK-000795 has additional service events requiring review.');
    $payload = json_decode((string) $cancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $check(is_array($payload) && ($payload['mode'] ?? '') === 'cancellation_financial_adjustment'
        && ! isset($payload['prior_service_status'])
        && abs((float) ($payload['prior_customer_due_amount'] ?? -1) - 1081) < .005
        && abs((float) ($payload['supplier_cost_basis'] ?? -1) - 83012) < .005
        && abs((float) ($payload['released_customer_credit'] ?? -1)) < .005
        && abs((float) ($payload['released_supplier_credit'] ?? -1)) < .005
        && ($payload['released_customer_receipt_ids'] ?? null) === []
        && ($payload['released_supplier_payment_ids'] ?? null) === []
        && ($payload['released_supplier_advance_ids'] ?? null) === [],
        'Cancellation basis or payment history differs from the verified entry-only case.');
    $check($scalar('SELECT COUNT(*) FROM audit_logs WHERE event_name = ? AND JSON_EXTRACT(payload_json, ?) = ?',
        ['service.created', '$.service_id', 805]) === 1
        && $scalar('SELECT COUNT(*) FROM audit_logs WHERE event_name IN (?, ?) AND JSON_EXTRACT(payload_json, ?) = ?',
            ['service.updated', 'service.status.updated', '$.service_id', 805]) === 0,
        'Original service status cannot be established from its creation history.');

    $payments = new CustomerPaymentRepository($app);
    $receipt = $payments->findReceiptById(92);
    $check($receipt !== null && $receipt['booking_reference'] === 'BK-000087'
        && (int) $receipt['branch_id'] === 2 && $receipt['currency'] === 'AED'
        && (int) ($receipt['traveler_id'] ?? 0) === 0 && $receipt['status'] === 'received'
        && abs((float) $receipt['received_amount'] - 1280) < .005
        && abs((float) $receipt['unallocated_amount'] - 1280) < .005
        && abs((float) $receipt['allocated_amount']) < .005
        && abs((float) $receipt['returned_amount']) < .005,
        'Original AED 1,280 receipt is no longer fully available.');
    $check($scalar('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = ?', [92]) === 0,
        'The source receipt has allocation history requiring review.');
    $receivable = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
    $obligation = (new SupplierRepository($app))->findObligationByServiceLine('BK-000795', 'SV-001');
    $check($receivable !== null && (int) $receivable['id'] === 810
        && $receivable['currency'] === 'AED' && abs((float) $receivable['due_amount']) < .005
        && abs((float) $receivable['allocated_amount']) < .005
        && $obligation !== null && (int) $obligation['id'] === 799
        && $obligation['currency'] === 'PKR' && abs((float) $obligation['gross_amount']) < .005
        && abs((float) $obligation['advance_applied_amount']) < .005,
        'Cancelled invoice or supplier obligation changed.');
    $check($scalar('SELECT COUNT(*) FROM supplier_payment_allocations WHERE supplier_obligation_id = ?', [799]) === 0
        && $scalar('SELECT COUNT(*) FROM supplier_advance_applications WHERE supplier_obligation_id = ?', [799]) === 0,
        'BK-000795 has supplier payment allocations requiring review.');

    // Older settlement overwrote the original status. Creation plus unchanged history supports Open.
    $events->mergePayload(49, [
        'prior_service_status' => 'Open',
        'prior_service_status_recovery_basis' => 'single service.created audit; no intervening status update; original service newly created',
    ]);
    AuditLog::recordRequired($app, 'service.cancellation_legacy_basis.reconciled', [
        'user_id' => $actor, 'booking_id' => (int) $target['id'], 'service_id' => 805,
        'cancel_event_id' => 49, 'prior_service_status' => 'Open',
        'original_invoice_amount' => 1081, 'original_invoice_currency' => 'AED',
        'original_supplier_cost_amount' => 83012, 'original_supplier_cost_currency' => 'PKR',
        'reason' => 'Recover legacy cancellation status before complete mistaken-refund undo for BK-000795.',
    ]);
    (new ServiceWorkspaceService($app))->undoMistakenRefund([
        'booking_id' => (int) $target['id'], 'service_id' => 805, 'cancel_event_id' => 49,
        'undo_last_refund_event_id' => 0,
        'undo_reason' => 'Mistaken cancellation/refund on BK-000795; restore original invoice. No refund cash changed hands.',
        'undo_mistake_confirmed' => '1', 'undo_customer_money' => 'not_moved',
        'undo_supplier_money' => 'not_moved',
    ], $actor, [2]);
    $restored = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
    $check($restored !== null && abs((float) $restored['outstanding_amount'] - 1081) < .005,
        'Original AED 1,081 invoice was not restored.');

    // Keep the historical receipt on BK-000087; only its allocation links to BK-000795.
    $allocation = $payments->allocateReceiptExplicit([
        'receipt_id' => 92, 'receivable_item_id' => 810,
        'receivable_amount_to_settle' => 1081, 'payment_currency' => 'AED',
        'rate_from_currency' => 'AED', 'rate_to_currency' => 'AED', 'exchange_rate' => 1,
        'exchange_rate_effective_date' => date('Y-m-d'),
        'allocation_note' => 'Customer credit transferred from BK-000087 to BK-000795 after mistaken cancellation reversal.',
        'actor_user_id' => $actor,
    ]);
    $allocationId = (int) ($allocation['allocation_id'] ?? 0);
    $check($allocationId > 0 && abs((float) ($allocation['allocated_amount'] ?? 0) - 1081) < .005,
        'Cross-booking credit allocation did not settle exactly AED 1,081.');
    $journalId = (new AccountingRepository($app))->postCustomerReceiptAllocation([
        'branch_id' => 2, 'booking_reference' => 'BK-000795',
        'source_reference' => 'RCPT-000092-XFER-' . $allocationId,
        'service_line_reference' => 'SV-001', 'customer_receivable_item_id' => 810,
        'customer_receipt_id' => 92, 'allocated_amount' => 1081,
        'entry_date' => date('Y-m-d'), 'currency' => 'AED',
        'narration' => 'Customer credit transferred from BK-000087 to BK-000795',
        'actor_user_id' => $actor,
    ]);
    $afterReceipt = $payments->findReceiptById(92);
    $afterReceivable = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
    $check($afterReceipt !== null && abs((float) $afterReceipt['unallocated_amount'] - 199) < .005
        && $afterReceivable !== null && abs((float) $afterReceivable['outstanding_amount']) < .005,
        'Post-allocation receipt or invoice balance is incorrect.');
    AuditLog::recordRequired($app, 'customer.booking_credit.transferred', [
        'user_id' => $actor, 'customer_receipt_id' => 92,
        'source_booking_reference' => 'BK-000087', 'target_booking_reference' => 'BK-000795',
        'source_customer_id' => (int) $source['lead_traveler_id'],
        'target_customer_id' => (int) $target['lead_traveler_id'],
        'legacy_receipt_traveler_id_missing' => true,
        'currency' => 'AED', 'allocated_amount' => 1081,
        'allocation_ids' => [$allocationId], 'journal_entry_id' => $journalId,
        'remaining_receipt_credit' => 199,
        'reason' => 'Use BK-000087 customer payment on restored BK-000795 invoice; same customer and branch verified.',
    ]);

    if ($apply) { $db->commit(); }
    else { $db->rollBack(); }
    echo $apply ? 'APPLIED' : 'DRY RUN ROLLED BACK';
    echo ': BK-000795 restored and paid AED 1,081 from RCPT-000092; AED 199 remains unallocated.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
