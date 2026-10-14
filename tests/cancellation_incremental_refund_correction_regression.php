<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$bookings = new \App\Services\BookingWorkspaceService($app);
$services = new \App\Services\ServiceWorkspaceService($app);
$receipts = new \App\Services\CustomerReceiptWorkspaceService($app);
$customers = new \App\Repositories\CustomerPaymentRepository($app);
$suppliers = new \App\Repositories\SupplierRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$treasury = new \App\Repositories\TreasuryRepository($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$today = date('Y-m-d');
$actorId = 1;
$branches = [1, 2];
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$failure = null;
$db->beginTransaction();
try {
    $booking = $bookings->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Incremental refund correction fixture',
        'remarks' => 'Rollback-only fixture',
    ], $actorId, $branches)['booking'];
    $bookingId = (int) $booking['id'];
    $reference = (string) $booking['booking_reference'];
    $service = $services->saveService([
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01297859',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Incremental correction supplier ' . bin2hex(random_bytes(4)),
        'service_passenger_name' => 'Refund correction passenger',
        'sale_price' => '80835',
        'purchase_cost' => '80835',
        'service_charge' => '51',
        'final_sale_price' => '1100',
        'ticket_pnr' => 'IC' . random_int(100000, 999999),
        'ticket_number' => 'IC-' . random_int(100000, 999999),
        'ticket_airline' => 'TEST AIR',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
    ], $actorId, $branches)['service'];
    $serviceId = (int) $service['id'];
    $line = (string) $service['line_reference'];
    $receivable = $customers->findReceivableByServiceLine($reference, $line);
    $obligation = $suppliers->findObligationByServiceLine($reference, $line);
    $assert(abs((float) $receivable['due_amount'] - 1100) < 0.005
        && abs((float) $obligation['gross_amount'] - 80835) < 0.005,
        'Fixture starts with AED 1,100 receivable and PKR 80,835 supplier cost');

    $receipts->saveReceipt([
        'booking_id' => $bookingId,
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => 1100,
        'payment_method' => 'cash',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Rollback-only original customer payment',
    ], $actorId, $branches);
    $receiptQuery = $db->prepare('SELECT * FROM customer_receipts WHERE booking_reference = :reference ORDER BY id DESC LIMIT 1');
    $receiptQuery->execute(['reference' => $reference]);
    $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $receiptId = (int) ($receipt['id'] ?? 0);
    $assert($receiptId > 0 && abs((float) $receipt['allocated_amount'] - 1100) < 0.005,
        'Original customer receipt is fully allocated');

    $services->cancelService([
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Rollback-only cancellation',
        'cancel_event_date' => $today,
    ], $actorId, $branches);
    $settlement = [
        'booking_id' => $bookingId,
        'service_id' => $serviceId,
        'settlement_currency' => 'PKR',
        'settlement_exchange_rate' => '0.01297859',
        'customer_penalty_amount' => '0',
        'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => '51',
        'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_exchange_rate' => '0.01297859',
        'expected_supplier_refund_rate_date' => $today,
        'supplier_penalty_currency' => 'PKR',
        'supplier_penalty_exchange_rate' => '0.01297859',
    ];
    $initialJournalCount = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $overCostRejected = false;
    try {
        $services->settleCancellationFinancials(array_merge($settlement, [
            'settlement_reason' => 'Reject supplier penalty above original cost',
            'settlement_event_date' => $today,
            'supplier_penalty_amount' => '80836',
        ]), $actorId, $branches);
    } catch (RuntimeException) {
        $overCostRejected = true;
    }
    $assert($overCostRejected
        && (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $initialJournalCount
        && abs((float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] - 80835) < 0.005,
        'Supplier penalty above original cost is rejected without silently capping or posting it');
    $services->settleCancellationFinancials(array_merge($settlement, [
        'settlement_reason' => 'Original expected supplier refund',
        'settlement_event_date' => $today,
        'expected_supplier_refund_amount' => '80135',
        'expected_supplier_refund_equivalent_amount' => '1040',
        'supplier_penalty_amount' => '700',
    ]), $actorId, $branches);
    $cancelBefore = $events->latestPostedEvent($serviceId, 'cancel');
    $payloadBefore = json_decode((string) $cancelBefore['payload_json'], true);
    $receivable = $customers->findReceivableByServiceLine($reference, $line);
    $obligation = $suppliers->findObligationByServiceLine($reference, $line);
    $assert(abs((float) $receivable['due_amount'] - 9) < 0.005
        && abs((float) $obligation['gross_amount'] - 700) < 0.005
        && abs((float) $payloadBefore['released_customer_credit'] - 1091) < 0.005,
        'Original settlement leaves AED 9 customer charge and PKR 700 supplier penalty');

    $otherBooking = $bookings->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Incremental refund correction fixture',
        'remarks' => 'Rollback-only destination booking',
    ], $actorId, $branches)['booking'];
    $otherReference = (string) $otherBooking['booking_reference'];
    $otherService = $services->saveService([
        'booking_id' => (int) $otherBooking['id'],
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Incremental correction destination ' . bin2hex(random_bytes(4)),
        'sale_price' => '1000',
        'purchase_cost' => '1000',
        'service_charge' => '91',
        'final_sale_price' => '1091',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit Visa',
    ], $actorId, $branches)['service'];
    $otherReceivable = $customers->findReceivableByServiceLine($otherReference, (string) $otherService['line_reference']);
    $allocation = $customers->allocateReceiptExplicit([
        'receipt_id' => $receiptId,
        'receivable_item_id' => (int) $otherReceivable['id'],
        'payment_currency' => 'AED',
        'receivable_amount_to_settle' => 1091,
        'payment_amount_to_consume' => 1091,
        'rate_from_currency' => 'AED',
        'rate_to_currency' => 'AED',
        'exchange_rate' => 1,
        'exchange_rate_effective_date' => $today,
        'allocation_note' => 'Rollback-only credit use on another booking',
        'actor_user_id' => $actorId,
    ]);
    $allocationId = (int) ($allocation['allocation']['id'] ?? $allocation['id'] ?? 0);
    $accounting->postCustomerReceiptAllocation([
        'branch_id' => 2,
        'booking_reference' => $otherReference,
        'source_reference' => 'ROLLBACK-CREDIT-ALLOC-' . $allocationId,
        'entry_date' => $today,
        'currency' => 'AED',
        'service_line_reference' => (string) $otherService['line_reference'],
        'customer_receivable_item_id' => (int) $otherReceivable['id'],
        'customer_receipt_id' => $receiptId,
        'allocated_amount' => 1091,
        'actor_user_id' => $actorId,
    ]);
    $assert($customers->availableReceiptCreditsForBooking($reference, 'AED') === [],
        'Released customer credit is fully used before the correction');

    $paymentAccount = $treasury->defaultTreasuryAccountForPayment(1, 'PKR', 'cash');
    $assert((int) ($paymentAccount['id'] ?? 0) > 0, 'PKR supplier payment account is available');
    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $payment = [
        'supplier_id' => (int) $service['supplier_id'],
        'branch_id' => 2,
        'booking_reference' => $reference,
        'payment_no' => $paymentNo,
        'payment_date' => $today,
        'currency' => 'PKR',
        'payment_scope' => 'booking',
        'paid_amount' => 700,
        'payment_method' => 'cash',
        'treasury_account_id' => (int) $paymentAccount['id'],
        'charges_amount' => 0,
        'status' => 'paid',
        'actor_user_id' => $actorId,
    ];
    $paymentId = $suppliers->createSupplierPayment($payment);
    $accounting->postSupplierPaymentRecorded(array_merge($payment, [
        'supplier_payment_id' => $paymentId,
        'entry_date' => $today,
    ]));
    $supplierAllocationId = $suppliers->allocateSupplierPayment(
        $paymentId, (int) $obligation['id'], 700, 1, 'Rollback-only supplier allocation', $actorId
    );
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2,
        'booking_reference' => $reference,
        'source_reference' => $paymentNo . '-ALLOC-' . $supplierAllocationId,
        'service_line_reference' => $line,
        'supplier_obligation_id' => (int) $obligation['id'],
        'supplier_payment_id' => $paymentId,
        'allocated_amount' => 700,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);
    $cashBefore = $treasury->currentBalanceForAccountId((int) $paymentAccount['id']);
    $treasuryTransactionsBefore = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
    $journalCountBefore = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    foreach ([
        ['agency_fee_refund_currency' => 'PKR'],
        ['supplier_penalty_amount' => '1'],
    ] as $invalidFields) {
        $rejected = false;
        try {
            $services->correctPenaltyRefundWorkflow(array_merge($settlement, [
                'correction_reason' => 'Rejected currency or supplier penalty mismatch',
                'correction_event_date' => $today,
                'expected_supplier_refund_amount' => '80835',
                'expected_supplier_refund_equivalent_amount' => '1049',
                'supplier_penalty_amount' => '0',
                'customer_refund_amount' => '0',
                'supplier_refund_amount' => '0',
            ], $invalidFields), $actorId, $branches);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected
            && (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() === $journalCountBefore
            && (float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 9.0
            && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 700.0,
            'Invalid settlement currency or penalty cannot change the existing balances');
    }

    $services->correctPenaltyRefundWorkflow(array_merge($settlement, [
        'correction_reason' => 'Actual supplier refund should equal the full supplier cost',
        'correction_event_date' => $today,
        'expected_supplier_refund_amount' => '80835',
        'expected_supplier_refund_equivalent_amount' => '1049',
        'supplier_penalty_amount' => '0',
        'customer_refund_amount' => '0',
        'supplier_refund_amount' => '0',
    ]), $actorId, $branches);

    $receiptQuery->execute(['reference' => $reference]);
    $receiptAfter = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $otherAfter = $customers->findReceivableByServiceLine($otherReference, (string) $otherService['line_reference']);
    $customerAfter = $customers->findReceivableByServiceLine($reference, $line);
    $supplierAfter = $suppliers->findObligationByServiceLine($reference, $line);
    $cancelAfter = $events->latestPostedEvent($serviceId, 'cancel');
    $payloadAfter = json_decode((string) $cancelAfter['payload_json'], true);
    $assert(abs((float) $receiptAfter['allocated_amount'] - 1091) < 0.005
        && abs((float) $receiptAfter['unallocated_amount'] - 9) < 0.005
        && abs((float) $otherAfter['allocated_amount'] - 1091) < 0.005,
        'Used AED 1,091 credit stays on the other booking; only AED 9 is newly released');
    $assert(abs((float) $customerAfter['due_amount']) < 0.005
        && abs((float) $supplierAfter['gross_amount']) < 0.005
        && abs((float) $payloadAfter['released_customer_credit'] - 1100) < 0.005
        && abs((float) $payloadAfter['expected_supplier_refund_source_amount'] - 80835) < 0.005
        && abs((float) $payloadAfter['released_supplier_credit_source_amount'] - 700) < 0.005,
        'Corrected settlement has zero customer charge, zero supplier penalty, and PKR 80,835 expected refund');
    $assert(count((array) $payloadAfter['journal_entry_ids']) > count((array) $payloadBefore['journal_entry_ids'])
        && array_slice((array) $payloadAfter['journal_entry_ids'], 0, count((array) $payloadBefore['journal_entry_ids'])) === $payloadBefore['journal_entry_ids'],
        'Correction appends journals without reversing original settlement history');
    $assert(abs($treasury->currentBalanceForAccountId((int) $paymentAccount['id']) - $cashBefore) < 0.005
        && (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $treasuryTransactionsBefore
        && $events->latestPostedEvent($serviceId, 'refund') === null,
        'Settlement correction records no new customer or supplier cash movement');

    $convertedAdvanceId = (int) ($payloadAfter['supplier_credit_converted_advance_ids'][0] ?? 0);
    $assert($convertedAdvanceId > 0, 'Released supplier payment is retained as a traceable advance');
    $advanceQuery = $db->prepare('SELECT available_amount FROM supplier_advances WHERE id = :id');

    $services->correctPenaltyRefundWorkflow(array_merge($settlement, [
        'correction_reason' => 'Supplier refund corrected down after the first edit',
        'correction_event_date' => $today,
        'expected_supplier_refund_amount' => '80635',
        'expected_supplier_refund_equivalent_amount' => '1047',
        'supplier_penalty_amount' => '200',
        'customer_refund_amount' => '0',
        'supplier_refund_amount' => '0',
    ]), $actorId, $branches);

    $receiptQuery->execute(['reference' => $reference]);
    $receiptReverse = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $otherReverse = $customers->findReceivableByServiceLine($otherReference, (string) $otherService['line_reference']);
    $customerReverse = $customers->findReceivableByServiceLine($reference, $line);
    $supplierReverse = $suppliers->findObligationByServiceLine($reference, $line);
    $payloadReverse = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
    $advanceQuery->execute(['id' => $convertedAdvanceId]);
    $assert(abs((float) $receiptReverse['allocated_amount'] - 1093) < 0.005
        && abs((float) $receiptReverse['unallocated_amount'] - 7) < 0.005
        && abs((float) $otherReverse['allocated_amount'] - 1091) < 0.005
        && abs((float) $customerReverse['due_amount'] - 2) < 0.005
        && abs((float) $customerReverse['outstanding_amount']) < 0.005,
        'Reverse edit reapplies only AED 2 from the original receipt and preserves the other booking');
    $assert(abs((float) $supplierReverse['gross_amount'] - 200) < 0.005
        && abs((float) $supplierReverse['advance_applied_amount'] - 200) < 0.005
        && abs((float) $supplierReverse['net_payable_amount']) < 0.005
        && abs((float) $advanceQuery->fetchColumn() - 500) < 0.005
        && abs((float) $payloadReverse['released_customer_credit'] - 1098) < 0.005
        && abs((float) $payloadReverse['released_supplier_credit_source_amount'] - 500) < 0.005
        && abs((float) $payloadReverse['supplier_credit_recorded_source_amount'] - 500) < 0.005,
        'Reverse edit reapplies PKR 200 supplier advance and updates net released-credit metadata');
    $reverseJournalIds = array_values(array_diff(
        array_map('intval', (array) $payloadReverse['journal_entry_ids']),
        array_map('intval', (array) $payloadAfter['journal_entry_ids'])
    ));
    $assert(count($reverseJournalIds) === 4,
        'Reverse edit appends receivable, payable, customer allocation, and supplier advance journals');
    $journalQuery = $db->prepare(
        'SELECT je.source_type, SUM(jel.debit_amount) AS debits, SUM(jel.credit_amount) AS credits
         FROM journal_entries je
         JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.id = :id
         GROUP BY je.id, je.source_type'
    );
    $journalTypes = [];
    foreach ($reverseJournalIds as $journalId) {
        $journalQuery->execute(['id' => $journalId]);
        $journal = $journalQuery->fetch(PDO::FETCH_ASSOC);
        $assert($journal !== false && abs((float) $journal['debits'] - (float) $journal['credits']) < 0.005,
            'Correction journal ' . $journalId . ' is balanced');
        $journalTypes[] = (string) $journal['source_type'];
    }
    sort($journalTypes);
    $expectedJournalTypes = ['customer_receipt_allocated', 'service_receivable_adjusted', 'supplier_advance_applied', 'supplier_payable_adjusted'];
    sort($expectedJournalTypes);
    $assert($journalTypes === $expectedJournalTypes, 'Reverse edit posts each required control journal exactly once');
    $accountReport = (new \App\Services\AccountLedgerService($app))->report([
        'bookingReference' => $reference,
    ], [2]);
    $supplierReport = (new \App\Services\SupplierLedgerService($app))->report([
        'bookingReference' => $reference,
        'supplierId' => (int) $service['supplier_id'],
        'currency' => 'PKR',
    ], [2]);
    $supplierCards = array_column((array) ($supplierReport['summaryCards'] ?? []), 'value', 'label');
    $assert(abs((float) ($accountReport['summaryRows'][0]['raw_outstanding_amount'] ?? -1)) < 0.005
        && ($supplierCards['Supplier Balance / PKR'] ?? '') === 'PKR 0.00',
        'Account and supplier ledgers agree that the corrected customer charge and supplier penalty are settled');

    $services->correctPenaltyRefundWorkflow(array_merge($settlement, [
        'correction_reason' => 'Supplier refund corrected back to the full cost',
        'correction_event_date' => $today,
        'expected_supplier_refund_amount' => '80835',
        'expected_supplier_refund_equivalent_amount' => '1049',
        'supplier_penalty_amount' => '0',
        'customer_refund_amount' => '0',
        'supplier_refund_amount' => '0',
    ]), $actorId, $branches);
    $receiptQuery->execute(['reference' => $reference]);
    $receiptAgain = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $supplierAgain = $suppliers->findObligationByServiceLine($reference, $line);
    $payloadAgain = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
    $advanceQuery->execute(['id' => $convertedAdvanceId]);
    $assert(abs((float) $receiptAgain['unallocated_amount'] - 9) < 0.005
        && abs((float) $supplierAgain['gross_amount']) < 0.005
        && abs((float) $advanceQuery->fetchColumn() - 700) < 0.005
        && abs((float) $payloadAgain['released_customer_credit'] - 1100) < 0.005
        && abs((float) $payloadAgain['released_supplier_credit_source_amount'] - 700) < 0.005
        && abs($treasury->currentBalanceForAccountId((int) $paymentAccount['id']) - $cashBefore) < 0.005
        && (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() === $treasuryTransactionsBefore,
        'Repeating corrections restores the original credit balances without moving cash');

    $thirdBooking = $bookings->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Incremental refund correction fixture',
        'remarks' => 'Rollback-only fully spent customer credit destination',
    ], $actorId, $branches)['booking'];
    $thirdReference = (string) $thirdBooking['booking_reference'];
    $thirdService = $services->saveService([
        'booking_id' => (int) $thirdBooking['id'],
        'service_type' => 'visa',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'supplier_name' => 'Incremental correction destination ' . bin2hex(random_bytes(4)),
        'sale_price' => '9',
        'purchase_cost' => '9',
        'service_charge' => '0',
        'final_sale_price' => '9',
        'visa_country' => 'UAE',
        'visa_type' => 'Visit Visa',
    ], $actorId, $branches)['service'];
    $thirdReceivable = $customers->findReceivableByServiceLine($thirdReference, (string) $thirdService['line_reference']);
    $customers->allocateReceiptExplicit([
        'receipt_id' => $receiptId,
        'receivable_item_id' => (int) $thirdReceivable['id'],
        'payment_currency' => 'AED',
        'receivable_amount_to_settle' => 9,
        'payment_amount_to_consume' => 9,
        'rate_from_currency' => 'AED',
        'rate_to_currency' => 'AED',
        'exchange_rate' => 1,
        'exchange_rate_effective_date' => $today,
        'allocation_note' => 'Rollback-only complete use of released credit',
        'actor_user_id' => $actorId,
    ]);
    $services->correctPenaltyRefundWorkflow(array_merge($settlement, [
        'correction_reason' => 'Lower refund after all customer credit has been used',
        'correction_event_date' => $today,
        'expected_supplier_refund_amount' => '80635',
        'expected_supplier_refund_equivalent_amount' => '1047',
        'supplier_penalty_amount' => '200',
        'customer_refund_amount' => '0',
        'supplier_refund_amount' => '0',
    ]), $actorId, $branches);
    $customerWithoutCredit = $customers->findReceivableByServiceLine($reference, $line);
    $thirdAfter = $customers->findReceivableByServiceLine($thirdReference, (string) $thirdService['line_reference']);
    $assert(abs((float) $customerWithoutCredit['due_amount'] - 2) < 0.005
        && abs((float) $customerWithoutCredit['outstanding_amount'] - 2) < 0.005
        && abs((float) $thirdAfter['allocated_amount'] - 9) < 0.005
        && abs($treasury->currentBalanceForAccountId((int) $paymentAccount['id']) - $cashBefore) < 0.005,
        'If original credit is fully spent, the new AED 2 charge stays outstanding without taking another booking payment');
    $accountWithoutCredit = (new \App\Services\AccountLedgerService($app))->report([
        'bookingReference' => $reference,
    ], [2]);
    $assert(abs((float) ($accountWithoutCredit['summaryRows'][0]['raw_outstanding_amount'] ?? 0) - 2) < 0.005,
        'Account ledger shows the new AED 2 as outstanding when the original receipt credit is spent');
    $usedCancelEvent = $events->latestPostedEvent($serviceId, 'cancel');
    $usedAdvanceBefore = $suppliers->findAdvanceById($convertedAdvanceId);
    $receiptQuery->execute(['reference' => $reference]);
    $usedReceiptBefore = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $undoRejected = false;
    try {
        $services->undoMistakenRefund([
            'booking_id' => $bookingId,
            'service_id' => $serviceId,
            'cancel_event_id' => (int) $usedCancelEvent['id'],
            'undo_last_refund_event_id' => 0,
            'undo_reason' => 'Rollback-only reject undo of credit already used on other bookings',
            'undo_mistake_confirmed' => '1',
        ], $actorId, $branches);
    } catch (RuntimeException) {
        $undoRejected = true;
    }
    $receiptQuery->execute(['reference' => $reference]);
    $usedReceiptAfter = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $usedAdvanceAfter = $suppliers->findAdvanceById($convertedAdvanceId);
    $customerAfterRejectedUndo = $customers->findReceivableByServiceLine($reference, $line);
    $supplierAfterRejectedUndo = $suppliers->findObligationByServiceLine($reference, $line);
    $assert($undoRejected
        && abs((float) $usedReceiptAfter['allocated_amount'] - (float) $usedReceiptBefore['allocated_amount']) < 0.005
        && abs((float) $usedAdvanceAfter['available_amount'] - (float) $usedAdvanceBefore['available_amount']) < 0.005
        && abs((float) $customerAfterRejectedUndo['due_amount'] - 2) < 0.005
        && abs((float) $supplierAfterRejectedUndo['gross_amount'] - 200) < 0.005
        && (int) $events->latestPostedEvent($serviceId, 'cancel')['id'] === (int) $usedCancelEvent['id'],
        'Complete undo refuses credit spent on another booking and rolls back all attempted changes');

    $reversalBooking = $bookings->saveBooking([
        'branch_id' => 2,
        'booking_status' => 'draft',
        'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Incremental full reversal fixture',
        'remarks' => 'Rollback-only reversal after bidirectional correction',
    ], $actorId, $branches)['booking'];
    $reversalReference = (string) $reversalBooking['booking_reference'];
    $reversalService = $services->saveService([
        'booking_id' => (int) $reversalBooking['id'],
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01297859',
        'pricing_rate_effective_date' => $today,
        'service_charge_currency' => 'AED',
        'service_charge_exchange_rate' => '1',
        'service_charge_rate_effective_date' => $today,
        'supplier_name' => 'Reversal correction supplier ' . bin2hex(random_bytes(4)),
        'service_passenger_name' => 'Reversal correction passenger',
        'sale_price' => '80835',
        'purchase_cost' => '80835',
        'service_charge' => '51',
        'final_sale_price' => '1100',
        'ticket_pnr' => 'RV' . random_int(100000, 999999),
        'ticket_number' => 'RV-' . random_int(100000, 999999),
        'ticket_airline' => 'TEST AIR',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today,
        'ticket_class' => 'Economy',
    ], $actorId, $branches)['service'];
    $reversalServiceId = (int) $reversalService['id'];
    $reversalLine = (string) $reversalService['line_reference'];
    $reversalObligation = $suppliers->findObligationByServiceLine($reversalReference, $reversalLine);
    $receipts->saveReceipt([
        'booking_id' => (int) $reversalBooking['id'],
        'receipt_date' => $today,
        'receipt_currency' => 'AED',
        'received_amount' => 1100,
        'payment_method' => 'cash',
        'receipt_status' => 'received',
        'receipt_remarks' => 'Rollback-only reversal customer receipt',
    ], $actorId, $branches);
    $reversalPaymentNo = $suppliers->nextSupplierPaymentNumber();
    $reversalPaymentData = array_merge($payment, [
        'supplier_id' => (int) $reversalService['supplier_id'],
        'booking_reference' => $reversalReference,
        'payment_no' => $reversalPaymentNo,
    ]);
    $reversalPaymentId = $suppliers->createSupplierPayment($reversalPaymentData);
    $accounting->postSupplierPaymentRecorded(array_merge($reversalPaymentData, [
        'supplier_payment_id' => $reversalPaymentId,
        'entry_date' => $today,
    ]));
    $reversalAllocationId = $suppliers->allocateSupplierPayment(
        $reversalPaymentId, (int) $reversalObligation['id'], 700, 1,
        'Rollback-only supplier allocation before cancellation', $actorId
    );
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2,
        'booking_reference' => $reversalReference,
        'source_reference' => $reversalPaymentNo . '-ALLOC-' . $reversalAllocationId,
        'service_line_reference' => $reversalLine,
        'supplier_obligation_id' => (int) $reversalObligation['id'],
        'supplier_payment_id' => $reversalPaymentId,
        'allocated_amount' => 700,
        'entry_date' => $today,
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);
    $reversalCashBefore = $treasury->currentBalanceForAccountId((int) $paymentAccount['id']);
    $services->cancelService([
        'booking_id' => (int) $reversalBooking['id'],
        'service_id' => $reversalServiceId,
        'cancel_reason' => 'Rollback-only cancellation before reversal',
        'cancel_event_date' => $today,
    ], $actorId, $branches);
    $reversalBase = array_merge($settlement, [
        'booking_id' => (int) $reversalBooking['id'],
        'service_id' => $reversalServiceId,
    ]);
    $services->settleCancellationFinancials(array_merge($reversalBase, [
        'settlement_reason' => 'Initial partial supplier refund',
        'settlement_event_date' => $today,
        'expected_supplier_refund_amount' => '80135',
        'expected_supplier_refund_equivalent_amount' => '1040',
        'supplier_penalty_amount' => '700',
    ]), $actorId, $branches);
    foreach ([
        ['80835', '1049', '0'],
        ['80635', '1047', '200'],
        ['80435', '1044', '400'],
        ['80635', '1047', '200'],
    ] as [$refund, $equivalent, $penalty]) {
        $services->correctPenaltyRefundWorkflow(array_merge($reversalBase, [
            'correction_reason' => 'Rollback-only bidirectional correction before reversal',
            'correction_event_date' => $today,
            'expected_supplier_refund_amount' => $refund,
            'expected_supplier_refund_equivalent_amount' => $equivalent,
            'supplier_penalty_amount' => $penalty,
            'customer_refund_amount' => '0',
            'supplier_refund_amount' => '0',
        ]), $actorId, $branches);
    }
    foreach ([['1', 3], ['0', 2]] as [$customerPenalty, $expectedCharge]) {
        $services->settleCancellationFinancials(array_merge($reversalBase, [
            'settlement_edit_mode' => 1,
            'settlement_reason' => 'Customer-only correction retaining the saved supplier penalty',
            'settlement_event_date' => $today,
            'customer_penalty_amount' => $customerPenalty,
            'agency_fee_refund_amount' => '51',
        ]), $actorId, $branches);
        $customerOnlyReceivable = $customers->findReceivableByServiceLine($reversalReference, $reversalLine);
        $customerOnlyObligation = $suppliers->findObligationByServiceLine($reversalReference, $reversalLine);
        $assert(abs((float) $customerOnlyReceivable['due_amount'] - $expectedCharge) < 0.005
            && abs((float) $customerOnlyObligation['gross_amount'] - 200) < 0.005,
            'Customer-only correction moves its AED charge without changing the saved PKR supplier penalty');
    }
    $reversalPayload = json_decode((string) $events->latestPostedEvent($reversalServiceId, 'cancel')['payload_json'], true);
    $reversalAdvanceId = (int) ($reversalPayload['supplier_credit_converted_advance_ids'][0] ?? 0);
    $assert($reversalAdvanceId > 0
        && abs((float) ($reversalPayload['correction_supplier_advance_applications'][$reversalAdvanceId] ?? 0) - 200) < 0.005,
        'The increased supplier penalty is traced to its exact correction advance application');

    $reversalCancelEventId = (int) $events->latestPostedEvent($reversalServiceId, 'cancel')['id'];
    $db->exec('SAVEPOINT direct_cancellation_reversal_probe');
    $services->reverseCancellationSettlement([
        'booking_id' => (int) $reversalBooking['id'],
        'service_id' => $reversalServiceId,
        'settlement_reverse_reason' => 'Rollback-only direct reversal after corrections',
    ], $actorId, $branches);
    $directReceivable = $customers->findReceivableByServiceLine($reversalReference, $reversalLine);
    $directObligation = $suppliers->findObligationByServiceLine($reversalReference, $reversalLine);
    $directAdvance = $suppliers->findAdvanceById($reversalAdvanceId);
    $assert(abs((float) $directReceivable['due_amount'] - 1100) < 0.005
        && abs((float) $directReceivable['allocated_amount'] - 1100) < 0.005
        && abs((float) $directObligation['gross_amount'] - 80835) < 0.005
        && abs((float) $directObligation['net_payable_amount'] - 80135) < 0.005
        && abs((float) $directAdvance['available_amount']) < 0.005
        && abs($treasury->currentBalanceForAccountId((int) $paymentAccount['id']) - $reversalCashBefore) < 0.005,
        'Direct settlement reversal restores original balances after bidirectional corrections');
    $db->exec('ROLLBACK TO SAVEPOINT direct_cancellation_reversal_probe');
    $db->exec('RELEASE SAVEPOINT direct_cancellation_reversal_probe');

    $services->undoMistakenRefund([
        'booking_id' => (int) $reversalBooking['id'],
        'service_id' => $reversalServiceId,
        'cancel_event_id' => $reversalCancelEventId,
        'undo_last_refund_event_id' => 0,
        'undo_reason' => 'Rollback-only complete undo after correction',
        'undo_mistake_confirmed' => '1',
    ], $actorId, $branches);
    $reversalReceivableAfter = $customers->findReceivableByServiceLine($reversalReference, $reversalLine);
    $reversalObligationAfter = $suppliers->findObligationByServiceLine($reversalReference, $reversalLine);
    $receiptQuery->execute(['reference' => $reversalReference]);
    $reversalReceiptAfter = $receiptQuery->fetch(PDO::FETCH_ASSOC);
    $advanceQuery->execute(['id' => $reversalAdvanceId]);
    $reversalAdvanceAfter = $advanceQuery->fetchColumn();
    $paymentQuery = $db->prepare(
        'SELECT allocated_amount, unallocated_amount, converted_advance_amount FROM supplier_payments WHERE id = :id'
    );
    $paymentQuery->execute(['id' => $reversalPaymentId]);
    $reversalPaymentAfter = $paymentQuery->fetch(PDO::FETCH_ASSOC);
    $reversedEventQuery = $db->prepare('SELECT event_status, payload_json FROM booking_service_events WHERE id = :id');
    $reversedEventQuery->execute(['id' => $reversalCancelEventId]);
    $reversedEvent = $reversedEventQuery->fetch(PDO::FETCH_ASSOC);
    $reversedEventPayload = json_decode((string) ($reversedEvent['payload_json'] ?? ''), true);
    $assert(abs((float) $reversalReceivableAfter['due_amount'] - 1100) < 0.005
        && abs((float) $reversalReceivableAfter['allocated_amount'] - 1100) < 0.005
        && abs((float) $reversalReceiptAfter['unallocated_amount']) < 0.005
        && abs((float) $reversalObligationAfter['gross_amount'] - 80835) < 0.005
        && abs((float) $reversalObligationAfter['net_payable_amount'] - 80135) < 0.005
        && abs((float) $reversalPaymentAfter['allocated_amount'] - 700) < 0.005
        && abs((float) $reversalPaymentAfter['unallocated_amount']) < 0.005
        && abs((float) $reversalPaymentAfter['converted_advance_amount']) < 0.005
        && abs((float) $reversalAdvanceAfter) < 0.005
        && (string) ($reversedEvent['event_status'] ?? '') === 'voided'
        && ($reversedEventPayload['settlement_reversed'] ?? false) === true
        && abs($treasury->currentBalanceForAccountId((int) $paymentAccount['id']) - $reversalCashBefore) < 0.005,
        'Complete undo restores original receipt and supplier payment after both correction directions');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
if ($failure !== null) {
    fwrite(STDERR, '[FAIL] ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
