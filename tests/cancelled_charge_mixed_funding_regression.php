<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();

$check = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException('[FAIL] ' . $label);
    }
    echo '[PASS] ' . $label . PHP_EOL;
};

try {
    $bookings = new \App\Services\BookingWorkspaceService($app);
    $services = new \App\Services\ServiceWorkspaceService($app);
    $receipts = new \App\Services\CustomerReceiptWorkspaceService($app);
    $payments = new \App\Repositories\CustomerPaymentRepository($app);
    $events = new \App\Repositories\BookingServiceEventRepository($app);
    $suppliers = new \App\Repositories\SupplierRepository($app);
    $branch = 2;
    $date = date('Y-m-d');
    $cancelDate = date('Y-m-d', strtotime('-1 day'));
    $travelerId = (new \App\Repositories\TravelerRepository($app))->createTraveler([
        'branch_id' => $branch,
        'first_name' => 'Cancelled Charge',
        'last_name' => 'Test',
        'full_name' => 'Cancelled Charge Test',
        'passport_number' => null,
        'nationality' => null,
        'date_of_birth' => null,
        'gender' => 'unspecified',
        'passport_expiry' => null,
        'mobile' => null,
        'address' => null,
        'permanent_residence' => null,
        'current_residence' => null,
        'occupation' => null,
        'village' => null,
        'district' => null,
        'family_id' => null,
        'color_tag' => null,
        'notes' => null,
        'actor_user_id' => 1,
    ]);

    $target = $bookings->saveBooking([
        'branch_id' => $branch,
        'booking_status' => 'draft',
        'booking_date' => $cancelDate,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Cancelled Charge Mixed Funding Test',
        'selected_customer_id' => $travelerId,
    ], 1, [$branch])['booking'];
    $targetId = (int) $target['id'];
    $reference = (string) $target['booking_reference'];
    $check((int) ($target['lead_traveler_id'] ?? 0) === $travelerId, 'Test booking has a saved customer');

    $source = $bookings->saveBooking([
        'branch_id' => $branch,
        'booking_status' => 'draft',
        'booking_date' => $cancelDate,
        'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Cancelled Charge Mixed Funding Test',
        'selected_customer_id' => $travelerId,
    ], 1, [$branch])['booking'];
    $sourceReference = (string) $source['booking_reference'];
    $check((int) ($source['lead_traveler_id'] ?? 0) === $travelerId, 'Source and target share the same customer');

    $serviceInput = static fn (int $bookingId): array => [
        'booking_id' => $bookingId,
        'service_type' => 'air ticket',
        'service_status' => 'Open',
        'currency' => 'AED',
        'cost_currency' => 'AED',
        'sale_price' => 80,
        'final_sale_price' => 100,
        'purchase_cost' => 80,
        'service_charge' => 20,
        'supplier_name' => 'Cancelled Charge Test Supplier',
        'ticket_pnr' => 'CHARGEMIX',
        'ticket_number' => 'CHARGE-001',
        'ticket_airline' => 'PIA',
        'ticket_sector_from' => 'ISB',
        'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $cancelDate,
        'ticket_class' => 'Economy',
        'ticket_fare' => 80,
    ];
    $services->saveService($serviceInput((int) $source['id']), 1, [$branch]);
    $sourceReceipt = $receipts->saveReceipt([
        'booking_id' => (int) $source['id'],
        'receipt_date' => $date,
        'receipt_currency' => 'AED',
        'received_amount' => 130,
        'payment_method' => 'cash',
        'receipt_status' => 'received',
    ], 1, [$branch])['receipt'];
    $creditReceiptId = (int) $sourceReceipt['id'];
    $check(abs((float) $sourceReceipt['unallocated_amount'] - 30) < 0.005, 'Source booking has AED 30 transferable credit');

    $service = $services->saveService($serviceInput($targetId), 1, [$branch])['service'];
    $serviceId = (int) $service['id'];
    $line = (string) $service['line_reference'];
    $services->cancelService([
        'booking_id' => $targetId,
        'service_id' => $serviceId,
        'cancel_reason' => 'Rollback-only mixed funding test',
        'cancel_event_date' => $cancelDate,
    ], 1, [$branch]);
    $services->settleCancellationFinancials([
        'booking_id' => $targetId,
        'service_id' => $serviceId,
        'settlement_reason' => 'Rollback-only final customer charge',
        'settlement_event_date' => $cancelDate,
        'customer_penalty_amount' => 10,
        'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => 80,
        'expected_supplier_refund_currency' => 'AED',
    ], 1, [$branch]);
    $cancelBefore = $events->latestPostedEvent($serviceId, 'cancel');
    $supplierBefore = $suppliers->findObligationByServiceLine($reference, $line);
    $itemBefore = $payments->findReceivableByServiceLine($reference, $line);
    $check(abs((float) ($itemBefore['outstanding_amount'] ?? 0) - 30) < 0.005,
        'Cancellation leaves an AED 30 customer charge');

    $request = [
        'booking_id' => $targetId,
        'receipt_date' => $date,
        'receipt_currency' => 'AED',
        'received_amount' => 10,
        'payment_method' => 'cash',
        'receipt_status' => 'received',
        'receipt_scope' => 'whole_invoice',
        'customer_credit_receipt_id' => $creditReceiptId,
        'customer_credit_apply_amount' => 20,
    ];

    $blocked = false;
    try {
        $receipts->saveReceipt(array_replace($request, ['customer_credit_receipt_id' => 999999999]), 1, [$branch]);
    } catch (RuntimeException $exception) {
        $blocked = true;
    }
    $check($blocked
        && $payments->latestReceiptIdForBooking($reference) === 0
        && abs((float) $payments->findReceivableByServiceLine($reference, $line)['outstanding_amount'] - 30) < 0.005,
        'Invalid credit rolls back the new cash receipt inside an outer transaction');

    $blocked = false;
    try {
        $receipts->saveReceipt(array_replace($request, [
            'received_amount' => 20,
            'customer_credit_apply_amount' => 20,
        ]), 1, [$branch]);
    } catch (RuntimeException $exception) {
        $blocked = true;
    }
    $check($blocked && $payments->latestReceiptIdForBooking($reference) === 0,
        'Cash and credit exceeding the final charge remain blocked');

    $result = $receipts->saveReceipt($request, 1, [$branch]);
    $savedReceipt = $result['receipt'] ?? [];
    $itemAfter = $payments->findReceivableByServiceLine($reference, $line);
    $sourceAfter = $payments->findReceiptById($creditReceiptId);
    $cancelAfter = $events->latestPostedEvent($serviceId, 'cancel');
    $supplierAfter = $suppliers->findObligationByServiceLine($reference, $line);
    $check(abs((float) ($savedReceipt['allocated_amount'] ?? 0) - 10) < 0.005
        && abs((float) ($savedReceipt['unallocated_amount'] ?? 0)) < 0.005
        && abs((float) ($result['customer_credit_applied']['allocated_amount'] ?? 0) - 20) < 0.005
        && abs((float) ($itemAfter['outstanding_amount'] ?? -1)) < 0.005
        && abs((float) ($sourceAfter['unallocated_amount'] ?? 0) - 10) < 0.005,
        'AED 10 cash plus AED 20 existing credit clears only the AED 30 charge');
    $check((int) ($cancelAfter['id'] ?? 0) === (int) ($cancelBefore['id'] ?? -1)
        && (string) ($cancelAfter['payload_json'] ?? '') === (string) ($cancelBefore['payload_json'] ?? '')
        && (float) ($supplierAfter['gross_amount'] ?? -1) === (float) ($supplierBefore['gross_amount'] ?? -2)
        && (float) ($supplierAfter['net_payable_amount'] ?? -1) === (float) ($supplierBefore['net_payable_amount'] ?? -2),
        'Collecting a fixed charge does not reopen cancellation or change supplier balances');
    $summary = $services->serviceState($targetId, [$branch])['services'][0]['customer_refund_summary'] ?? [];
    $check(abs((float) ($summary['settled'] ?? 0) - 30) < 0.005
        && abs((float) ($summary['outstanding'] ?? -1)) < 0.005
        && abs((float) ($summary['entitlement'] ?? -1)) < 0.005,
        'Refund summary shows current paid charge and no invented customer refund');

    $journal = $db->prepare('SELECT ROUND(SUM(jel.debit_amount - jel.credit_amount), 2)
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND je.currency = "AED" AND coa.code = "AR_CONTROL"');
    $journal->execute([$reference]);
    $check(abs((float) $journal->fetchColumn()) < 0.005, 'Customer receivable journal closes at AED zero');

    $credits = $payments->availableBookingCredits($branch, $travelerId, 'AED', $reference);
    $check(count(array_filter($credits, static fn (array $row): bool => (int) $row['id'] === $creditReceiptId)) === 1,
        'Remaining AED 10 source credit stays reusable on its original booking');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo 'All fixture data rolled back.' . PHP_EOL;
}
