<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$query = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message . ' No changes were saved.'); }
};

$db->beginTransaction();
try {
    $rows = $query('SELECT * FROM booking_service_events WHERE id IN (452, 454) ORDER BY id FOR UPDATE');
    $assert(count($rows) === 2, 'Reviewed cancellation/refund events are missing');
    [$cancel, $refund] = $rows;
    foreach ([$cancel, $refund] as $event) {
        $assert((int) $event['booking_id'] === 1446 && (int) $event['booking_service_id'] === 1262
            && $event['booking_reference'] === 'BK-000981' && $event['event_status'] === 'posted',
            'The test booking or event status changed');
    }
    $assert($cancel['event_type'] === 'cancel' && $refund['event_type'] === 'refund'
        && $refund['currency'] === 'PKR' && (float) $refund['supplier_refund_amount'] === 800.0
        && (float) $refund['customer_refund_amount'] === 0.0
        && (int) $refund['journal_entry_id'] === 10758, 'The reviewed refund changed');
    $cancelPayload = json_decode((string) $cancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $refundPayload = json_decode((string) $refund['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $assert(($cancelPayload['released_supplier_payment_ids'] ?? []) === [367]
        && (float) ($cancelPayload['released_supplier_payment_credit'] ?? 0) === 800.0,
        'Cancellation no longer identifies this payment as the released source');
    $assert(($refundPayload['supplier_refund_payment_method'] ?? '') === 'cash'
        && (int) ($refundPayload['supplier_refund_detail']['treasury_account_id'] ?? 0) === 33,
        'Supplier cash receipt details changed');
    $paymentRows = $query('SELECT * FROM supplier_payments WHERE id = ? FOR UPDATE', [367]);
    $assert(count($paymentRows) === 1, 'Original global supplier payment is missing');
    $payment = $paymentRows[0];
    $assert((int) $payment['supplier_id'] === 785 && (int) $payment['branch_id'] === 1
        && $payment['booking_reference'] === 'GLOBAL' && $payment['currency'] === 'PKR'
        && (float) $payment['paid_amount'] === 800.0 && (float) $payment['allocated_amount'] === 0.0,
        'Original global payment changed');
    $journals = $query('SELECT j.id, j.source_type, j.source_reference, j.booking_reference,
            j.branch_id, j.currency, SUM(l.debit_amount) AS debits, SUM(l.credit_amount) AS credits
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
        WHERE j.id = ? GROUP BY j.id', [10758]);
    $assert(count($journals) === 1 && $journals[0]['source_type'] === 'service_refund_posted'
        && $journals[0]['source_reference'] === 'REFUND-EVT-454'
        && $journals[0]['booking_reference'] === 'BK-000981'
        && (int) $journals[0]['branch_id'] === 1 && $journals[0]['currency'] === 'PKR'
        && (float) $journals[0]['debits'] === 800.0 && (float) $journals[0]['credits'] === 800.0,
        'Posted supplier cash journal changed');
    $assert($query('SELECT id FROM supplier_payment_allocations WHERE supplier_payment_id = ? FOR UPDATE', [367]) === [],
        'The original payment has a newer allocation');
    $assert($query('SELECT id FROM supplier_advances WHERE supplier_id = ? AND currency = ? AND available_amount > 0 FOR UPDATE', [785, 'PKR']) === [],
        'Supplier has other available credit that would make matching ambiguous');

    $existing = (array) ($refundPayload['cash_refund_credit_consumption'] ?? []);
    if ((float) ($existing['amount'] ?? 0) === 800.0
        && ($existing['affected_payment_ids'] ?? []) === [367]
        && (float) $payment['returned_amount'] === 800.0
        && (float) $payment['unallocated_amount'] === 0.0) {
        $db->rollBack();
        echo "BK-000981 supplier refund source was already repaired.\n";
        exit(0);
    }
    $assert((float) ($existing['amount'] ?? 0) === 0.0
        && (float) ($refundPayload['cash_refund_credit_consumption_amount'] ?? 0) === 0.0
        && (float) $payment['unallocated_amount'] === 800.0
        && (float) $payment['returned_amount'] === 0.0,
        'Refund/payment is not in the reviewed zero-allocation state');
    $assert((new \App\Repositories\SupplierRepository($app))->availableRefundableCreditForService(
        785, 'BK-000981', 'PKR', [367]
    ) === 800.0, 'The released global payment is not fully available');

    if ($apply) {
        $reason = 'Repair BK-000981 QA refund source: cancellation 452 released global payment 367; refund 454 cash journal 10758 received PKR 800.';
        $consumption = (new \App\Repositories\SupplierRepository($app))->consumeRefundableCreditForService(
            785, 'BK-000981', 'PKR', 800.0, 1, $reason, [367]
        );
        $assert((float) ($consumption['amount'] ?? 0) === 800.0
            && ($consumption['affected_payment_ids'] ?? []) === [367]
            && (float) ($consumption['consumed_advance_amount'] ?? 0) === 0.0,
            'Repair did not consume exactly the reviewed payment');
        (new \App\Repositories\BookingServiceEventRepository($app))->updateRefundFinancials(454, [
            'payload_patch' => [
                'cash_refund_credit_consumption' => $consumption,
                'cash_refund_credit_consumption_amount' => 800.0,
                'cash_refund_credit_repository_available_before' => 800.0,
                'source_reconciliation' => ['payment_id' => 367, 'cancel_event_id' => 452, 'reason' => $reason],
            ],
        ]);
        \App\Helpers\AuditLog::recordRequired($app, 'service.refund.source_reconciled', [
            'user_id' => 1, 'booking_reference' => 'BK-000981', 'service_id' => 1262,
            'cancel_event_id' => 452, 'refund_event_id' => 454, 'supplier_payment_id' => 367,
            'amount' => 800.0, 'currency' => 'PKR', 'reason' => $reason,
        ]);
        $db->commit();
        echo "BK-000981 supplier refund source repaired; no cash or journal entry was changed.\n";
    } else {
        $db->rollBack();
        echo "Dry run passed for BK-000981. Use --apply to link the existing PKR 800 supplier refund to payment SPAY-000219.\n";
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
