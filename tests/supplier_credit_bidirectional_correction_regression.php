<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
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
$supplierLedger = new \App\Services\SupplierLedgerService($app);
$today = date('Y-m-d');
$supplierName = 'Bidirectional credit supplier ' . bin2hex(random_bytes(4));
$assert = static function (bool $valid, string $message): void {
    if (! $valid) throw new RuntimeException($message);
    echo '[PASS] ' . $message . PHP_EOL;
};

$db->beginTransaction();
try {
    $booking = $bookings->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Bidirectional supplier credit regression',
        'remarks' => 'Rollback-only fixture',
    ], 1, [1, 2])['booking'];
    $bookingId = (int) $booking['id'];
    $reference = (string) $booking['booking_reference'];
    $service = $services->saveService([
        'booking_id' => $bookingId, 'service_type' => 'air ticket', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01297859', 'pricing_rate_effective_date' => $today,
        'supplier_name' => $supplierName,
        'service_passenger_name' => 'Credit correction passenger',
        'sale_price' => '90942', 'purchase_cost' => '90942', 'final_sale_price' => '1180',
        'ticket_pnr' => 'BC' . random_int(100000, 999999),
        'ticket_number' => 'BC-' . random_int(100000, 999999),
        'ticket_airline' => 'TEST AIR', 'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB', 'ticket_departure_date' => $today, 'ticket_class' => 'Economy',
    ], 1, [1, 2])['service'];
    $serviceId = (int) $service['id'];
    $line = (string) $service['line_reference'];
    $obligation = $suppliers->findObligationByServiceLine($reference, $line);
    $receipts->saveReceipt([
        'booking_id' => $bookingId, 'receipt_date' => $today,
        'receipt_currency' => 'AED', 'received_amount' => 1180,
        'payment_method' => 'cash', 'receipt_status' => 'received',
        'receipt_remarks' => 'Rollback-only customer payment',
    ], 1, [1, 2]);
    $cash = $treasury->defaultTreasuryAccountForPayment(1, 'PKR', 'cash');
    $services->cancelService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_reason' => 'Rollback-only cancellation', 'cancel_event_date' => $today,
    ], 1, [1, 2]);
    $services->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'settlement_reason' => 'Initial supplier credit', 'settlement_event_date' => $today,
        'settlement_currency' => 'AED', 'customer_penalty_amount' => 53,
        'customer_penalty_currency' => 'AED', 'agency_fee_refund_amount' => 0,
        'expected_supplier_refund_amount' => 50242, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700, 'supplier_penalty_currency' => 'PKR',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
    ], 1, [1, 2]);
    $paymentNo = $suppliers->nextSupplierPaymentNumber();
    $payment = [
        'supplier_id' => (int) $service['supplier_id'], 'branch_id' => 2,
        'booking_reference' => $reference, 'payment_no' => $paymentNo, 'payment_date' => $today,
        'currency' => 'PKR', 'payment_scope' => 'booking', 'paid_amount' => 90942,
        'payment_method' => 'cash', 'treasury_account_id' => (int) $cash['id'],
        'charges_amount' => 0, 'status' => 'paid', 'actor_user_id' => 1,
    ];
    $paymentId = $suppliers->createSupplierPayment($payment);
    $accounting->postSupplierPaymentRecorded(array_merge($payment, [
        'supplier_payment_id' => $paymentId, 'entry_date' => $today,
    ]));
    $allocationId = $suppliers->allocateSupplierPayment($paymentId, (int) $obligation['id'], 40700, 1,
        'Original supplier penalty', 1);
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2, 'booking_reference' => $reference,
        'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
        'service_line_reference' => $line, 'supplier_obligation_id' => (int) $obligation['id'],
        'supplier_payment_id' => $paymentId, 'allocated_amount' => 40700,
        'entry_date' => $today, 'currency' => 'PKR', 'actor_user_id' => 1,
    ]);
    $initialCancel = $events->latestPostedEvent($serviceId, 'cancel');
    $initialPayload = json_decode((string) $initialCancel['payload_json'], true);
    // Model an older cancellation where supplier credit was posted as its own refund event.
    unset($initialPayload['supplier_credit_recording_version'], $initialPayload['supplier_credit_recorded_source_amount'],
        $initialPayload['supplier_payable_reduced_source_amount'], $initialPayload['supplier_credit_converted_advance_ids']);
    $initialPayload['released_supplier_payment_ids'] = [$paymentId];
    $events->updateCancellationFinancials((int) $initialCancel['id'], [
        'penalty_amount' => (float) $initialCancel['penalty_amount'],
        'customer_credit_amount' => (float) $initialCancel['customer_credit_amount'],
        'supplier_credit_amount' => (float) $initialCancel['supplier_credit_amount'],
        'journal_entry_id' => $initialCancel['journal_entry_id'],
        'payload_json' => $initialPayload,
    ]);
    $customerCash = $treasury->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
    $services->refundService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'refund_reason' => 'Initial customer cash refund', 'refund_event_date' => $today,
        'refund_payment_method' => 'cash', 'refund_treasury_account_id' => (int) $customerCash['id'],
        'customer_refund_treatment' => 'pay_now', 'customer_refund_amount' => 600,
        'customer_refund_source_amount' => 600, 'customer_refund_currency' => 'AED',
        'supplier_refund_amount' => 0,
    ], 1, [1, 2]);
    $services->refundService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'refund_reason' => 'Initial confirmed supplier credit', 'refund_event_date' => $today,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 50242,
        'supplier_refund_amount_basis' => 'expected_source',
        'supplier_refund_currency' => 'PKR', 'supplier_refund_payment_method' => 'supplier_credit',
    ], 1, [1, 2]);
    $assert((float) ($events->latestPostedEvent($serviceId, 'refund')['supplier_refund_amount'] ?? 0) === 653.0,
        'Fixture has a separately posted PKR supplier credit with AED equivalent');
    $correction = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'correction_reason' => 'Rollback-only bidirectional credit correction',
        'correction_event_date' => $today,
        'customer_penalty_amount' => 53, 'customer_penalty_currency' => 'AED',
        'agency_fee_refund_amount' => 0, 'agency_fee_refund_currency' => 'AED',
        'expected_supplier_refund_amount' => 50942, 'expected_supplier_refund_currency' => 'PKR',
        'expected_supplier_refund_equivalent_amount' => 661,
        'supplier_penalty_amount' => 40000, 'supplier_penalty_currency' => 'PKR',
        'customer_refund_amount' => 600,
        'customer_refund_source_amount' => 600, 'customer_refund_currency' => 'AED',
        'refund_payment_method' => 'cash', 'refund_treasury_account_id' => (int) $customerCash['id'],
        'supplier_refund_amount' => 0, 'supplier_refund_source_amount' => 0,
        'supplier_refund_currency' => 'AED', 'supplier_refund_payment_method' => 'cash',
        'settlement_currency' => 'AED', 'refund_currency' => 'AED',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
    ];
    $beforeCash = $treasury->currentBalanceForAccountId((int) $cash['id']);
    $beforeCustomerCash = $treasury->currentBalanceForAccountId((int) $customerCash['id']);
    $services->correctPenaltyRefundWorkflow($correction, 1, [1, 2]);
    $afterIncrease = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
    $assert((float) $afterIncrease['expected_supplier_refund_source_amount'] === 50942.0
        && (float) $afterIncrease['expected_supplier_refund_booking_amount'] === 661.0,
        'PKR and AED supplier credit amounts increased independently');
    $increasedLedger = $supplierLedger->report([
        'bookingReference' => $reference, 'supplierId' => (int) $service['supplier_id'], 'currency' => 'PKR',
    ], [2]);
    $increasedCards = array_column($increasedLedger['summaryCards'], 'value', 'label');
    $assert(($increasedCards['Supplier Balance / PKR'] ?? '') === 'PKR 700.00 Advance',
        'Increasing supplier credit adds only the new PKR 700 to the booking ledger after the original credit was moved to advance');

    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);
    $afterDecrease = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
    $state = $services->serviceState($bookingId, [1, 2]);
    $lineState = array_values(array_filter((array) ($state['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) $afterDecrease['expected_supplier_refund_source_amount'] === 50242.0
        && (float) $afterDecrease['expected_supplier_refund_booking_amount'] === 653.0
        && (float) ($lineState['supplier_refund_total_source_amount'] ?? 0) === 50242.0
        && (float) ($lineState['supplier_refund_total_amount'] ?? 0) === 653.0,
        'One Save restores PKR 50,242 and AED 653 without changing customer cash refund');
    $decreasedLedger = $supplierLedger->report([
        'bookingReference' => $reference, 'supplierId' => (int) $service['supplier_id'], 'currency' => 'PKR',
    ], [2]);
    $decreasedCards = array_column($decreasedLedger['summaryCards'], 'value', 'label');
    $assert(($decreasedCards['Supplier Balance / PKR'] ?? '') === 'PKR 0.00',
        'Decreasing supplier credit by PKR 700 removes the new booking-ledger advance');
    $assert((float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40700.0
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'Supplier payable is restored without moving either cash account again');
    $customerPosition = $customers->findReceivableByServiceLine($reference, $line);
    $assert((float) $customerPosition['outstanding_amount'] === 0.0
        && (float) $customerPosition['allocated_amount'] === (float) $customerPosition['due_amount'],
        'Released AED customer credit is reapplied instead of leaving a false refund due');

    $services->correctPenaltyRefundWorkflow($correction, 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50592,
        'expected_supplier_refund_equivalent_amount' => 657,
        'supplier_penalty_amount' => 40350,
    ]), 1, [1, 2]);
    $partialState = $services->serviceState($bookingId, [1, 2]);
    $partialLine = array_values(array_filter((array) ($partialState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($partialLine['supplier_refund_total_source_amount'] ?? 0) === 50592.0
        && (float) ($partialLine['supplier_refund_total_amount'] ?? 0) === 657.0,
        'Partial decrease preserves the separately entered PKR and AED totals');
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);

    $services->correctPenaltyRefundWorkflow($correction, 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 60942,
        'expected_supplier_refund_equivalent_amount' => 700,
        'supplier_penalty_amount' => 30000,
    ]), 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);
    $multiState = $services->serviceState($bookingId, [1, 2]);
    $multiLine = array_values(array_filter((array) ($multiState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($multiLine['supplier_refund_total_source_amount'] ?? 0) === 50242.0
        && (float) ($multiLine['supplier_refund_total_amount'] ?? 0) === 653.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40700.0,
        'One decrease removes two unused credit increments and restores both balances');

    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 60942,
        'expected_supplier_refund_equivalent_amount' => 700,
        'supplier_penalty_amount' => 30000,
    ]), 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'customer_penalty_amount' => 61,
    ]), 1, [1, 2]);
    $combinedPayload = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
    $combinedReceivable = $customers->findReceivableByServiceLine($reference, $line);
    $assert((float) $combinedPayload['customer_penalty_amount'] === 61.0
        && (float) $combinedPayload['expected_supplier_refund_source_amount'] === 50942.0
        && (float) $combinedPayload['expected_supplier_refund_booking_amount'] === 661.0
        && (float) $combinedReceivable['due_amount'] === 580.0
        && (float) $combinedReceivable['outstanding_amount'] === 0.0,
        'PKR 60,942 / AED 700 and AED 53 penalty correct together to PKR 50,942 / AED 661 and AED 61');
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 60942,
        'expected_supplier_refund_equivalent_amount' => 700,
        'supplier_penalty_amount' => 30000,
    ]), 1, [1, 2]);
    $reverseCombined = $customers->findReceivableByServiceLine($reference, $line);
    $assert((float) $reverseCombined['due_amount'] === 533.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 30000.0,
        'Opposite combined correction restores customer charge and supplier payable without moving cash');
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);
    $customerOnly = array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
        'customer_penalty_amount' => 52,
    ]);
    $services->correctPenaltyRefundWorkflow($customerOnly, 1, [1, 2]);
    $assert((float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 579.0,
        'Customer penalty alone can decrease without changing posted supplier credit');
    $services->correctPenaltyRefundWorkflow(array_replace($customerOnly, [
        'customer_penalty_amount' => 53,
    ]), 1, [1, 2]);
    $assert((float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 580.0,
        'Customer penalty alone can increase back when the paid cash refund remains covered');
    $overpaidRejected = false;
    try {
        $services->correctPenaltyRefundWorkflow(array_replace($customerOnly, [
            'customer_penalty_amount' => 54,
        ]), 1, [1, 2]);
    } catch (RuntimeException $exception) {
        $overpaidRejected = str_contains($exception->getMessage(), 'refund already paid');
    }
    $assert($overpaidRejected
        && (float) $customers->findReceivableByServiceLine($reference, $line)['due_amount'] === 580.0,
        'An overpaid cash refund is rejected with no receivable change');

    $sourceOnly = array_replace($correction, [
        'expected_supplier_refund_equivalent_amount' => 653,
    ]);
    $services->correctPenaltyRefundWorkflow($sourceOnly, 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($sourceOnly, [
        'expected_supplier_refund_amount' => 50242,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);
    $sourceOnlyState = $services->serviceState($bookingId, [1, 2]);
    $sourceOnlyLine = array_values(array_filter((array) ($sourceOnlyState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($sourceOnlyLine['supplier_refund_total_source_amount'] ?? 0) === 50242.0
        && (float) ($sourceOnlyLine['supplier_refund_total_amount'] ?? 0) === 653.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40700.0
        && (float) $customers->findReceivableByServiceLine($reference, $line)['outstanding_amount'] === 0.0
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'PKR-only increase and decrease leave AED and both cash balances unchanged');

    $services->correctPenaltyRefundWorkflow($sourceOnly, 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($sourceOnly, [
        'expected_supplier_refund_amount' => 50592,
        'supplier_penalty_amount' => 40350,
    ]), 1, [1, 2]);
    $partialSourceState = $services->serviceState($bookingId, [1, 2]);
    $partialSourceLine = array_values(array_filter((array) ($partialSourceState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($partialSourceLine['supplier_refund_total_source_amount'] ?? 0) === 50592.0
        && (float) ($partialSourceLine['supplier_refund_total_amount'] ?? 0) === 653.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40350.0,
        'Partial PKR-only decrease retains the unused remainder without changing AED');
    $services->correctPenaltyRefundWorkflow(array_replace($sourceOnly, [
        'expected_supplier_refund_amount' => 50242,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);

    $equivalentOnly = array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'supplier_penalty_amount' => 40700,
    ]);
    $services->correctPenaltyRefundWorkflow($equivalentOnly, 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($equivalentOnly, [
        'expected_supplier_refund_equivalent_amount' => 653,
    ]), 1, [1, 2]);
    $equivalentState = $services->serviceState($bookingId, [1, 2]);
    $equivalentLine = array_values(array_filter((array) ($equivalentState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($equivalentLine['supplier_refund_total_source_amount'] ?? 0) === 50242.0
        && (float) ($equivalentLine['supplier_refund_total_amount'] ?? 0) === 653.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40700.0
        && (float) $customers->findReceivableByServiceLine($reference, $line)['outstanding_amount'] === 0.0
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'AED-only increase and decrease leave PKR supplier credit and cash unchanged');

    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_equivalent_amount' => 645,
    ]), 1, [1, 2]);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 50242,
        'expected_supplier_refund_equivalent_amount' => 653,
        'supplier_penalty_amount' => 40700,
    ]), 1, [1, 2]);
    $mixedState = $services->serviceState($bookingId, [1, 2]);
    $mixedLine = array_values(array_filter((array) ($mixedState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($mixedLine['supplier_refund_total_source_amount'] ?? 0) === 50242.0
        && (float) ($mixedLine['supplier_refund_total_amount'] ?? 0) === 653.0
        && (float) $suppliers->findObligationByServiceLine($reference, $line)['gross_amount'] === 40700.0
        && (float) $customers->findReceivableByServiceLine($reference, $line)['outstanding_amount'] === 0.0
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'Opposite-direction PKR and AED edits restore both ledgers in one Save');

    $laterBooking = $bookings->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Later booking funded by the same supplier credit',
    ], 1, [1, 2])['booking'];
    $laterService = $services->saveService([
        'booking_id' => (int) $laterBooking['id'], 'service_type' => 'visa',
        'service_status' => 'Open', 'currency' => 'AED', 'cost_currency' => 'PKR',
        'pricing_exchange_rate' => '0.01297859', 'pricing_rate_effective_date' => $today,
        'supplier_name' => $supplierName, 'sale_price' => 50242, 'purchase_cost' => 50242,
        'final_sale_price' => 700, 'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ], 1, [1, 2])['service'];
    $laterObligation = $suppliers->findObligationByServiceLine(
        (string) $laterBooking['booking_reference'], (string) $laterService['line_reference']);
    $assert((float) ($laterObligation['advance_applied_amount'] ?? 0) === 50242.0
        && (float) ($laterObligation['net_payable_amount'] ?? -1) === 0.0,
        'Later booking used the entire original PKR supplier credit');
    $laterReceivableBefore = $customers->findReceivableByServiceLine(
        (string) $laterBooking['booking_reference'], (string) $laterService['line_reference']);
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 49542,
        'expected_supplier_refund_equivalent_amount' => 645,
        'supplier_penalty_amount' => 41400,
    ]), 1, [1, 2]);
    $laterObligationAfter = $suppliers->findObligationById((int) $laterObligation['id']);
    $laterReceivableAfter = $customers->findReceivableByServiceLine(
        (string) $laterBooking['booking_reference'], (string) $laterService['line_reference']);
    $assert((float) ($laterObligationAfter['advance_applied_amount'] ?? 0) === 49542.0
        && (float) ($laterObligationAfter['net_payable_amount'] ?? 0) === 700.0
        && (float) $laterReceivableAfter['due_amount'] === (float) $laterReceivableBefore['due_amount']
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'Used supplier credit reduction reopens only the later PKR payable shortfall');
    $laterLedger = (new \App\Services\SupplierLedgerService($app))->report([
        'supplierId' => (int) $laterService['supplier_id'], 'currency' => 'PKR',
        'bookingReference' => (string) $laterBooking['booking_reference'],
    ], [2]);
    $laterCards = array_column((array) ($laterLedger['summaryCards'] ?? []), 'value', 'label');
    $assert(($laterCards['Supplier Balance / PKR'] ?? '') === 'PKR 700.00 Payable'
        && ($laterCards['Advance Used / PKR'] ?? '') === 'PKR 49,542.00',
        'Later booking supplier ledger shows PKR 700 payable and net PKR 49,542 advance used');
    $journalCheck = $db->prepare(
        'SELECT je.id, je.source_type, SUM(jel.debit_amount) AS debits, SUM(jel.credit_amount) AS credits
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.booking_reference = :reference
           AND je.source_type = "supplier_advance_adjusted"
         GROUP BY je.id, je.source_type'
    );
    $journalCheck->execute(['reference' => (string) $laterBooking['booking_reference']]);
    $adjustmentJournals = $journalCheck->fetchAll() ?: [];
    $assert(count($adjustmentJournals) > 0 && array_reduce($adjustmentJournals,
        static fn (bool $valid, array $row): bool => $valid
            && abs((float) $row['debits'] - (float) $row['credits']) <= 0.005, true),
        'Later booking supplier-advance reversal has balanced journal entries');

    $laterCustomerDue = (float) $laterReceivableAfter['due_amount'];
    $services->correctPenaltyRefundWorkflow(array_replace($correction, [
        'expected_supplier_refund_amount' => 48842,
        'expected_supplier_refund_equivalent_amount' => 645,
        'supplier_penalty_amount' => 42100,
    ]), 1, [1, 2]);
    $laterAfterSourceOnly = $suppliers->findObligationById((int) $laterObligation['id']);
    $laterCustomerAfterSourceOnly = $customers->findReceivableByServiceLine(
        (string) $laterBooking['booking_reference'], (string) $laterService['line_reference']);
    $sourceOnlyUsedState = $services->serviceState($bookingId, [1, 2]);
    $sourceOnlyUsedLine = array_values(array_filter((array) ($sourceOnlyUsedState['services'] ?? []),
        static fn (array $row): bool => (int) ($row['id'] ?? 0) === $serviceId))[0] ?? [];
    $assert((float) ($sourceOnlyUsedLine['supplier_refund_total_source_amount'] ?? 0) === 48842.0
        && (float) ($sourceOnlyUsedLine['supplier_refund_total_amount'] ?? 0) === 645.0
        && (float) ($laterAfterSourceOnly['net_payable_amount'] ?? 0) === 1400.0
        && (float) $laterCustomerAfterSourceOnly['due_amount'] === $laterCustomerDue
        && (float) $treasury->currentBalanceForAccountId((int) $cash['id']) === (float) $beforeCash
        && (float) $treasury->currentBalanceForAccountId((int) $customerCash['id']) === (float) $beforeCustomerCash,
        'PKR-only decrease of used credit reopens later payable and preserves entered AED equivalent');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
echo 'All fixture changes rolled back.' . PHP_EOL;
