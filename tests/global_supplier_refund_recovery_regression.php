<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$workspace = new \App\Services\ServiceWorkspaceService($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$treasury = new \App\Repositories\TreasuryRepository($app);
$today = date('Y-m-d');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
};

$db->beginTransaction();
try {
    $booking = (new \App\Services\BookingWorkspaceService($app))->saveBooking([
        'branch_id' => 1, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Global Supplier Refund Regression',
        'remarks' => 'Rollback-only fixture',
    ], 1, [1, 2]);
    $bookingId = (int) $booking['booking']['id'];
    $reference = (string) $booking['booking']['booking_reference'];
    $service = $workspace->saveService([
        'booking_id' => $bookingId, 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'PKR', 'cost_currency' => 'PKR',
        'supplier_name' => 'Global Refund Regression ' . random_int(100000, 999999),
        'sale_price' => 800, 'final_sale_price' => 1000,
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa', 'remarks' => 'Rollback-only fixture',
    ], 1, [1, 2])['service'];
    $serviceId = (int) $service['id'];
    $obligation = $suppliers->findObligationByServiceLine($reference, (string) $service['line_reference']);
    $cash = $treasury->defaultTreasuryAccountForPayment(1, 'PKR', 'cash');
    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $payment = [
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 1,
        'booking_reference' => 'GLOBAL', 'payment_no' => $paymentNo, 'payment_date' => $today,
        'currency' => 'PKR', 'payment_scope' => 'global', 'paid_amount' => 800,
        'payment_method' => 'cash', 'treasury_account_id' => (int) $cash['id'],
        'charges_amount' => 0, 'status' => 'paid', 'actor_user_id' => 1,
    ];
    $paymentId = $suppliers->createSupplierPayment($payment);
    $accounting->postSupplierPaymentRecorded(array_merge($payment, [
        'supplier_payment_id' => $paymentId, 'entry_date' => $today,
    ]));
    $allocationId = $suppliers->allocateSupplierPayment($paymentId, (int) $obligation['id'], 800, 1, 'Original global payment allocation', 1);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 1, 'booking_reference' => $reference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $service['line_reference'],
        'supplier_obligation_id' => $obligation['id'], 'supplier_payment_id' => $paymentId,
        'allocated_amount' => 800, 'entry_date' => $today, 'currency' => 'PKR', 'actor_user_id' => 1,
    ]);

    $workspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_reason' => 'Regression cancellation', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'settlement_reason' => 'Regression settlement', 'settlement_event_date' => $today,
        'customer_penalty_amount' => 0, 'expected_supplier_refund_amount' => 800,
    ], 1, [1, 2]);
    $cancel = $events->latestPostedEvent($serviceId, 'cancel');
    $cancelPayload = json_decode((string) $cancel['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    // Reconstruct the historical pending-credit state for the legacy cash
    // refund/complete-undo checks below.
    $suppliers->reverseRetainedRefundAdvanceConversion(
        (array) ($cancelPayload['supplier_credit_converted_advance_ids'] ?? []),
        1,
        'Rollback-only historical global payment fixture'
    );
    foreach ([
        'supplier_credit_recording_version',
        'supplier_credit_recorded_source_amount',
        'supplier_credit_recorded_booking_amount',
        'supplier_credit_recorded_currency',
        'supplier_credit_converted_payment_amount',
        'supplier_credit_converted_advance_ids',
    ] as $legacyField) {
        unset($cancelPayload[$legacyField]);
    }
    $events->updateCancellationFinancials((int) $cancel['id'], [
        'penalty_amount' => $cancel['penalty_amount'],
        'customer_credit_amount' => $cancel['customer_credit_amount'],
        'supplier_credit_amount' => $cancel['supplier_credit_amount'],
        'journal_entry_id' => $cancel['journal_entry_id'],
        'reason' => $cancel['reason'],
        'notes' => $cancel['notes'],
        'payload_json' => $cancelPayload,
    ]);
    $assert(($cancelPayload['released_supplier_payment_ids'] ?? []) === [$paymentId], 'Cancellation did not retain the global payment ID');
    $assert($suppliers->availableRefundableCreditForService((int) $service['supplier_id'], $reference, 'PKR') === 0.0,
        'Global payment must not be exposed without its cancellation link');
    $assert($suppliers->availableRefundableCreditForService((int) $service['supplier_id'], $reference, 'PKR', [$paymentId]) === 800.0,
        'Linked global payment should be refundable');

    $workspace->refundService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'refund_reason' => 'Regression supplier cash return', 'refund_event_date' => $today,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 800,
        'supplier_refund_payment_method' => 'cash', 'supplier_refund_treasury_account_id' => (int) $cash['id'],
    ], 1, [1, 2]);
    $refund = $events->latestPostedEvent($serviceId, 'refund');
    $refundPayload = json_decode((string) $refund['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $assert((float) ($refundPayload['cash_refund_credit_consumption']['amount'] ?? 0) === 800.0,
        'Supplier cash refund did not consume the linked global payment');
    $assert(($refundPayload['cash_refund_credit_consumption']['affected_payment_ids'] ?? []) === [$paymentId],
        'Supplier refund consumed an unrelated payment');
    $db->exec('SAVEPOINT before_global_refund_undo');
    $undo = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_event_id' => (int) $cancel['id'],
        'undo_last_refund_event_id' => (int) $refund['id'],
        'undo_reason' => 'Regression complete undo', 'undo_mistake_confirmed' => '1',
        'undo_customer_money' => 'not_moved', 'undo_supplier_money' => 'moved',
    ];
    $workspace->undoMistakenRefund($undo, 1, [1, 2]);
    $restored = $suppliers->findObligationByServiceLine($reference, (string) $service['line_reference']);
    $assert((float) $restored['net_payable_amount'] === 800.0,
        'Preserved supplier cash return must leave the original payable open');

    $db->exec('ROLLBACK TO SAVEPOINT before_global_refund_undo');
    $workspace->undoMistakenRefund(array_replace($undo, ['undo_supplier_money' => 'not_moved']), 1, [1, 2]);
    $restored = $suppliers->findObligationByServiceLine($reference, (string) $service['line_reference']);
    $assert((float) $restored['net_payable_amount'] === 0.0,
        'Entry-only reversal must reallocate the released global payment');
    echo '[PASS] Global supplier payment refund linkage and both complete-undo money choices', PHP_EOL;
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
