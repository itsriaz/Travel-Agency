<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();
$check = static function (bool $condition, string $label): void {
    if (! $condition) { throw new RuntimeException('[FAIL] ' . $label); }
    echo '[PASS] ', $label, PHP_EOL;
};

try {
    $bookings = new \App\Services\BookingWorkspaceService($app);
    $workspace = new \App\Services\ServiceWorkspaceService($app);
    $receipts = new \App\Services\CustomerReceiptWorkspaceService($app);
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $events = new \App\Repositories\BookingServiceEventRepository($app);
    $today = date('Y-m-d');
    $originalDate = date('Y-m-d', strtotime('-1 day'));

    foreach ([
        ['name' => 'Late AED Cancellation Test', 'cost_currency' => 'AED', 'cost' => 579,
            'invoice' => 620, 'fee' => 41, 'supplier_return' => 579, 'equivalent' => null,
            'penalty' => 0, 'final_charge' => 41, 'refund' => 579, 'supplier_payable' => 0],
        ['name' => 'Late Mixed Cancellation Test', 'cost_currency' => 'PKR', 'cost' => 121500,
            'invoice' => 1660, 'fee' => 72, 'supplier_return' => 101500, 'equivalent' => 1318,
            'penalty' => 18, 'final_charge' => 360, 'refund' => 1300, 'supplier_payable' => 20000],
    ] as $case) {
        $booking = $bookings->saveBooking([
            'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $originalDate,
            'party_label' => 'Lead Traveler / Booking Party', 'lead_traveler_name' => $case['name'],
        ], 1, [2])['booking'];
        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];
        $serviceInput = [
            'booking_id' => $bookingId, 'service_type' => 'air ticket', 'service_status' => 'Open',
            'currency' => 'AED', 'cost_currency' => $case['cost_currency'],
            'sale_price' => $case['cost'], 'final_sale_price' => $case['invoice'],
            'purchase_cost' => $case['cost'], 'service_charge' => $case['fee'],
            'supplier_name' => 'Late Cancellation Test Supplier',
            'ticket_pnr' => 'LATECANCEL', 'ticket_number' => 'LATE-001',
            'ticket_airline' => 'PIA', 'ticket_sector_from' => 'ISB', 'ticket_sector_to' => 'DXB',
            'ticket_departure_date' => $originalDate, 'ticket_class' => 'Economy',
            'ticket_fare' => $case['cost'],
        ];
        if ($case['cost_currency'] !== 'AED') {
            $serviceInput['pricing_exchange_rate'] = 1660 / 121500;
            $serviceInput['pricing_rate_effective_date'] = $originalDate;
            $serviceInput['service_charge_currency'] = 'AED';
        }
        $service = $workspace->saveService($serviceInput, 1, [2])['service'];
        $check((float) ($service['purchase_cost'] ?? 0) >= (float) $case['supplier_return'],
            $case['name'] . ': fixture supplier cost supports expected return '
            . json_encode(['purchase_cost' => $service['purchase_cost'] ?? null,
                'cost_currency' => $service['cost_currency'] ?? null], JSON_UNESCAPED_SLASHES));
        $serviceId = (int) $service['id'];
        $workspace->cancelService([
            'booking_id' => $bookingId, 'service_id' => $serviceId,
            'cancel_reason' => 'Rollback-only late receipt regression', 'cancel_event_date' => $originalDate,
        ], 1, [2]);
        $settlement = [
            'booking_id' => $bookingId, 'service_id' => $serviceId,
            'settlement_reason' => 'Rollback-only cancellation before customer receipt',
            'settlement_event_date' => $originalDate, 'customer_penalty_amount' => $case['penalty'],
            'customer_penalty_currency' => 'AED',
            'expected_supplier_refund_amount' => $case['supplier_return'],
            'expected_supplier_refund_currency' => $case['cost_currency'],
        ];
        if ($case['equivalent'] !== null) {
            $settlement['supplier_refund_conversion_mode'] = 'manual_equivalent';
            $settlement['expected_supplier_refund_equivalent_amount'] = $case['equivalent'];
        }
        $workspace->settleCancellationFinancials($settlement, 1, [2]);
        $before = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
        $check(isset($before['customer_receipt_id_watermark'])
            && abs((float) $before['target_customer_refund_credit']) < .005,
            $case['name'] . ': unpaid settlement has receipt watermark and no invented refund');
        $supplierBefore = (new \App\Repositories\SupplierRepository($app))->findObligationByServiceLine(
            $reference, (string) $service['line_reference']
        );
        $journalBeforeLateReceipt = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn();
        if ($case['cost_currency'] === 'AED') {
            $receiptBeforeGuard = $payments->latestReceiptIdForBooking($reference);
            foreach ([
                ['account_balance_mode' => 'Account Holder Balance'],
                ['advance_apply_amount' => 50],
                ['customer_credit_apply_amount' => 50],
            ] as $extraFunding) {
                $reusedCreditBlocked = false;
                try {
                    $receipts->saveReceipt(array_merge([
                        'booking_id' => $bookingId, 'receipt_date' => $today,
                        'receipt_currency' => 'AED', 'received_amount' => 100,
                        'payment_method' => 'cash', 'receipt_status' => 'received',
                    ], $extraFunding), 1, [2]);
                } catch (RuntimeException $exception) {
                    $reusedCreditBlocked = str_contains($exception->getMessage(), 'financial admin must reconcile');
                }
                $check($reusedCreditBlocked && $payments->latestReceiptIdForBooking($reference) === $receiptBeforeGuard,
                    'Late cancellation does not mistake reused credit for new cash');
            }
            $cancelEvent = $events->latestPostedEvent($serviceId, 'cancel');
            $removeWatermark = $db->prepare(
                'UPDATE booking_service_events SET payload_json = JSON_REMOVE(payload_json, "$.customer_receipt_id_watermark") WHERE id = ?'
            );
            $removeWatermark->execute([(int) $cancelEvent['id']]);
            $legacyBlocked = false;
            try {
                $receipts->saveReceipt([
                    'booking_id' => $bookingId, 'receipt_date' => $today,
                    'receipt_currency' => 'AED', 'received_amount' => 100,
                    'payment_method' => 'cash', 'receipt_status' => 'received',
                ], 1, [2]);
            } catch (RuntimeException $exception) {
                $legacyBlocked = str_contains($exception->getMessage(), 'incomplete settlement history');
            }
            $check($legacyBlocked && $payments->latestReceiptIdForBooking($reference) === $receiptBeforeGuard,
                'Older cancellation without receipt snapshot stops for review without posting cash');
            $restoreWatermark = $db->prepare('UPDATE booking_service_events SET payload_json = ? WHERE id = ?');
            $restoreWatermark->execute([(string) $cancelEvent['payload_json'], (int) $cancelEvent['id']]);
            $invalidAccountBlocked = false;
            try {
                $receipts->saveReceipt([
                    'booking_id' => $bookingId, 'receipt_date' => $today,
                    'receipt_currency' => 'AED', 'received_amount' => 100,
                    'payment_method' => 'cash', 'receipt_status' => 'received',
                    'treasury_account_id' => 999999999,
                ], 1, [2]);
            } catch (RuntimeException $exception) {
                $invalidAccountBlocked = true;
            }
            $check($invalidAccountBlocked
                && $payments->latestReceiptIdForBooking($reference) === $receiptBeforeGuard
                && $events->latestPostedEvent($serviceId, 'cancel')['payload_json'] === $cancelEvent['payload_json']
                && (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM journal_entries')->fetchColumn() === $journalBeforeLateReceipt,
                'Invalid treasury account rolls back cancellation reversal and receipt inside an outer transaction');
            $backdatedBlocked = false;
            try {
                $receipts->saveReceipt([
                    'booking_id' => $bookingId,
                    'receipt_date' => date('Y-m-d', strtotime('-2 days')),
                    'receipt_currency' => 'AED', 'received_amount' => 100,
                    'payment_method' => 'cash', 'receipt_status' => 'received',
                ], 1, [2]);
            } catch (RuntimeException $exception) {
                $backdatedBlocked = str_contains($exception->getMessage(), 'financial admin must reconcile');
            }
            $check($backdatedBlocked && $payments->latestReceiptIdForBooking($reference) === $receiptBeforeGuard,
                'Payment dated before the cancellation stops for historical review without posting cash');
        }

        $installments = $case['cost_currency'] === 'PKR' ? [1000, 660] : [$case['invoice']];
        $receiptIds = [];
        foreach ($installments as $index => $installment) {
            $saved = $receipts->saveReceipt([
                'booking_id' => $bookingId, 'receipt_date' => $today,
                'receipt_currency' => 'AED', 'received_amount' => $installment,
                'payment_method' => 'cash', 'receipt_status' => 'received',
            ], 1, [2]);
            $receiptIds[] = (int) $saved['receipt']['id'];
            if ($index === 0 && count($installments) === 2) {
                $firstSummary = $workspace->serviceState($bookingId, [2])['services'][0]['customer_refund_summary'];
                $check(abs((float) $firstSummary['entitlement'] - 640) < .005,
                    $case['name'] . ': first installment exposes only AED 640 refundable');
            }
        }
        $receipt = $saved['receipt'];
        $allocatedTotal = 0.0;
        $creditTotal = 0.0;
        foreach ($receiptIds as $receiptId) {
            $savedReceipt = $payments->findReceiptById($receiptId);
            $allocatedTotal += (float) $savedReceipt['allocated_amount'];
            $creditTotal += (float) $savedReceipt['unallocated_amount'];
        }
        $after = json_decode((string) $events->latestPostedEvent($serviceId, 'cancel')['payload_json'], true);
        $item = $payments->findReceivableByServiceLine($reference, (string) $service['line_reference']);
        $supplier = (new \App\Repositories\SupplierRepository($app))->findObligationByServiceLine(
            $reference, (string) $service['line_reference']
        );
        $summary = $workspace->serviceState($bookingId, [2])['services'][0]['customer_refund_summary'];
        $check((int) ($receipt['id'] ?? 0) > 0
            && abs($allocatedTotal - $case['final_charge']) < .005
            && abs($creditTotal - $case['refund']) < .005
            && abs((float) $item['due_amount'] - $case['final_charge']) < .005
            && abs((float) $item['outstanding_amount']) < .005,
            $case['name'] . ': cash installments settle final charge and leave exact customer credit');
        $receiptDocument = (new \App\Services\OperationalOutputService($app))->buildOutputDocument(
            $bookingId, 'customer_receipt', [2], (int) $receipt['id'], null, null, 1
        );
        $receiptHtml = (string) $app->get('view')->render('workspace/output', $receiptDocument, 'layouts/print');
        $receiptText = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($receiptHtml)));
        $check(str_contains($receiptText, 'Air Ticket (Cancelled)')
            && str_contains($receiptText, 'Current Invoice Amount AED ' . number_format($case['final_charge']))
            && str_contains($receiptText, 'Invoice Outstanding Balance AED 0')
            && ! str_contains($receiptText, 'Current Invoice Amount AED ' . number_format($case['invoice'])),
            $case['name'] . ': receipt prints settled cancellation charge and zero outstanding');
        $check(abs((float) $after['target_customer_refund_credit'] - $case['refund']) < .005
            && abs((float) $summary['entitlement'] - $case['refund']) < .005
            && abs((float) $summary['remaining'] - $case['refund']) < .005
            && (int) $after['customer_receipt_id_watermark'] === (int) $receipt['id'],
            $case['name'] . ': saved cancellation and refund screen agree after late receipt');
        $check((string) $supplier['currency'] === $case['cost_currency']
            && (string) $supplier['currency'] === (string) $supplierBefore['currency']
            && abs((float) $supplier['gross_amount'] - $case['supplier_payable']) < .005
            && abs((float) $supplier['gross_amount'] - (float) $supplierBefore['gross_amount']) < .005
            && abs((float) $supplier['net_payable_amount'] - (float) $supplierBefore['net_payable_amount']) < .005,
            $case['name'] . ': customer receipt does not change supplier currency or payable');
        $controlStatement = $db->prepare(
            'SELECT c.code, ROUND(SUM(l.debit_amount-l.credit_amount),2) AS balance
             FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
             JOIN chart_of_accounts c ON c.id = l.account_id
             WHERE j.booking_reference = ? AND j.currency = "AED"
               AND c.code IN ("AR_CONTROL", "CUSTOMER_CREDIT", "CASH_CASH_NOBLE_AED_AED")
             GROUP BY c.code'
        );
        $controlStatement->execute([$reference]);
        $controls = array_column($controlStatement->fetchAll(PDO::FETCH_ASSOC), 'balance', 'code');
        $check(abs((float) ($controls['AR_CONTROL'] ?? 0)) < .005
            && abs((float) ($controls['CUSTOMER_CREDIT'] ?? 0) + $case['refund']) < .005
            && abs((float) ($controls['CASH_CASH_NOBLE_AED_AED'] ?? 0) - $case['invoice']) < .005,
            $case['name'] . ': cash, credit liability and invoice control reconcile');
        $lateJournalStatement = $db->prepare(
            'SELECT entry_date FROM journal_entries WHERE booking_reference = ? AND id > ?
             AND source_type IN ("service_cancellation_financials_reversed", "service_receivable_adjusted",
               "supplier_payable_adjusted", "customer_receipt_allocation_released")'
        );
        $lateJournalStatement->execute([$reference, $journalBeforeLateReceipt]);
        $adjustmentDates = $lateJournalStatement->fetchAll(PDO::FETCH_COLUMN);
        $check($adjustmentDates !== [] && count(array_filter($adjustmentDates,
            static fn (string $date): bool => $date !== $today)) === 0,
            $case['name'] . ': reversal and repost use current period, not the original cancellation period');

        $blocked = false;
        try {
            $receipts->voidReceipt([
                'booking_id' => $bookingId, 'customer_receipt_id' => (int) $receipt['id'],
                'void_reason' => 'Regression wrong receipt', 'no_money_received_confirmed' => '1',
            ], 1, [2]);
        } catch (RuntimeException $exception) {
            $blocked = str_contains($exception->getMessage(), 'posted cancellation/refund');
        }
        $check($blocked && $payments->findReceiptById((int) $receipt['id'])['status'] !== 'void',
            $case['name'] . ': void cannot silently invalidate posted cancellation');
        if ($case['cost_currency'] === 'AED') {
            $cash = (new \App\Repositories\TreasuryRepository($app))->defaultTreasuryAccountForPayment(2, 'AED', 'cash');
            $workspace->refundService([
                'booking_id' => $bookingId, 'service_id' => $serviceId,
                'refund_reason' => 'Rollback-only partial customer refund after late receipt',
                'refund_event_date' => $today, 'customer_refund_amount' => 200,
                'customer_refund_treatment' => 'pay_now', 'supplier_refund_amount' => 0,
                'refund_payment_method' => 'cash', 'refund_treasury_account_id' => (int) $cash['id'],
            ], 1, [2]);
            $paidSummary = $workspace->serviceState($bookingId, [2])['services'][0]['customer_refund_summary'];
            $check(abs((float) $paidSummary['paid'] - 200) < .005
                && abs((float) $paidSummary['remaining'] - 379) < .005
                && abs((float) $payments->findReceiptById((int) $receipt['id'])['unallocated_amount'] - 379) < .005,
                'Late receipt supports partial customer refund with matching remaining cash credit');
        }
    }

    $multiBooking = $bookings->saveBooking([
        'branch_id' => 2, 'booking_status' => 'draft', 'booking_date' => $today,
        'party_label' => 'Lead Traveler / Booking Party', 'lead_traveler_name' => 'Multiple Service Cancellation Test',
    ], 1, [2])['booking'];
    $multiId = (int) $multiBooking['id'];
    $multiRef = (string) $multiBooking['booking_reference'];
    $multiServiceInput = [
        'booking_id' => $multiId, 'service_type' => 'visa', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED', 'sale_price' => 100,
        'service_charge' => 0, 'final_sale_price' => 100,
        'supplier_name' => 'Multiple Service Test Supplier',
        'visa_country' => 'UAE', 'visa_type' => 'Visit Visa',
    ];
    $cancelledService = $workspace->saveService($multiServiceInput, 1, [2])['service'];
    $ordinaryService = $workspace->saveService($multiServiceInput, 1, [2])['service'];
    $workspace->cancelService([
        'booking_id' => $multiId, 'service_id' => (int) $cancelledService['id'],
        'cancel_reason' => 'Multiple service regression', 'cancel_event_date' => $today,
    ], 1, [2]);
    $workspace->settleCancellationFinancials([
        'booking_id' => $multiId, 'service_id' => (int) $cancelledService['id'],
        'settlement_reason' => 'Multiple service regression', 'settlement_event_date' => $today,
        'customer_penalty_amount' => 0, 'expected_supplier_refund_amount' => 50,
    ], 1, [2]);
    $multiReceiptInput = [
        'booking_id' => $multiId, 'receipt_date' => $today,
        'receipt_currency' => 'AED', 'received_amount' => 100,
        'payment_method' => 'cash', 'receipt_status' => 'received',
    ];
    $priorReceiptId = $payments->latestReceiptIdForBooking($multiRef);
    $ambiguousBlocked = false;
    try {
        $receipts->saveReceipt($multiReceiptInput, 1, [2]);
    } catch (RuntimeException $exception) {
        $ambiguousBlocked = str_contains($exception->getMessage(), 'multiple services');
    }
    $check($ambiguousBlocked && $payments->latestReceiptIdForBooking($multiRef) === $priorReceiptId,
        'Multiple-service booking cannot guess that a whole-invoice receipt belongs to cancelled ticket');
    $ordinaryItem = $payments->findReceivableByServiceLine($multiRef, (string) $ordinaryService['line_reference']);
    $ordinaryReceipt = $receipts->saveReceipt($multiReceiptInput + [
        'receipt_scope' => 'passenger_specific', 'target_receivable_item_id' => (int) $ordinaryItem['id'],
    ], 1, [2])['receipt'];
    $check(abs((float) $ordinaryReceipt['allocated_amount'] - 100) < .005
        && abs((float) $payments->findReceivableByServiceLine($multiRef, (string) $cancelledService['line_reference'])['allocated_amount']) < .005,
        'Explicit ordinary-service receipt remains available without altering cancelled service');
    $receipts->voidReceipt([
        'booking_id' => $multiId, 'customer_receipt_id' => (int) $ordinaryReceipt['id'],
        'void_reason' => 'Rollback-only ordinary-service receipt error',
        'no_money_received_confirmed' => '1',
    ], 1, [2]);
    $check($payments->findReceiptById((int) $ordinaryReceipt['id'])['status'] === 'void'
        && abs((float) $payments->findReceivableByServiceLine($multiRef, (string) $cancelledService['line_reference'])['allocated_amount']) < .005,
        'Unrelated ordinary-service receipt can still be voided without changing cancelled service');

    $db->rollBack();
    echo 'All fixture data rolled back.', PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL . $exception->getTraceAsString() . PHP_EOL);
    exit(1);
}
