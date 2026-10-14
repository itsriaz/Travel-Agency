<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$payments = new \App\Repositories\CustomerPaymentRepository($app);
$source = $payments->findReceiptById(92);
$booking = $db->query('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference = "BK-000795"')->fetch(PDO::FETCH_ASSOC);
$receivable = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
if ($source === null || $booking === false || $receivable === null) {
    fwrite(STDERR, "BK-000087/BK-000795 local fixture is missing.\n");
    exit(1);
}

$customerId = (int) $booking['lead_traveler_id'];
$sourceBooking = $db->query('SELECT branch_id, lead_traveler_id FROM bookings WHERE booking_reference = "BK-000087"')->fetch(PDO::FETCH_ASSOC);
$eligible = $payments->availableBookingCredits(2, $customerId, 'AED', 'BK-000795');
$receiptIds = array_map(static fn (array $row): int => (int) $row['id'], $eligible);
$wrongCustomer = $payments->availableBookingCredits(2, $customerId + 1000000, 'AED', 'BK-000795');
if ((float) ($source['unallocated_amount'] ?? 0) <= 0.005) {
    $transferStatement = $db->prepare('SELECT COUNT(*) AS transfer_count,
            COALESCE(SUM(cra.payment_amount_consumed), 0) AS transferred_amount
        FROM customer_receipt_allocations cra
        INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
        WHERE cra.customer_receipt_id = :receipt_id
          AND cri.booking_reference = :target_booking_reference');
    $transferStatement->execute([
        'receipt_id' => 92,
        'target_booking_reference' => 'BK-000795',
    ]);
    $transfer = $transferStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $transferred = (float) ($transfer['transferred_amount'] ?? 0);
    $sourcePreview = (new \App\Services\CustomerPaymentFoundationService($app))
        ->buildWorkspacePreview('BK-000087', $customerId, [2]);
    $targetPreview = (new \App\Services\CustomerPaymentFoundationService($app))
        ->buildWorkspacePreview('BK-000795', $customerId, [2]);
    $outgoing = array_values(array_filter($sourcePreview['outgoingBookingCredits'] ?? [],
        static fn (array $row): bool => (int) ($row['receiptId'] ?? 0) === 92
            && ($row['bookingReference'] ?? '') === 'BK-000795'));
    $incoming = array_values(array_filter($targetPreview['incomingBookingCredits'] ?? [],
        static fn (array $row): bool => (int) ($row['receiptId'] ?? 0) === 92
            && ($row['receiptBookingReference'] ?? '') === 'BK-000087'));
    $usedInPreview = static fn (array $rows): float => array_reduce($rows,
        static fn (float $sum, array $row): float => $sum + (float) ($row['paymentAmountConsumed'] ?? 0), 0.0);
    if ((int) ($source['traveler_id'] ?? 0) !== 0
        || (int) ($sourceBooking['lead_traveler_id'] ?? 0) !== $customerId
        || (int) ($sourceBooking['branch_id'] ?? 0) !== (int) $booking['branch_id']
        || (string) ($source['status'] ?? '') !== 'fully_allocated'
        || (int) ($transfer['transfer_count'] ?? 0) < 1
        || abs($transferred - (float) ($source['received_amount'] ?? 0)) > 0.005
        || abs($transferred - (float) ($source['allocated_amount'] ?? 0)) > 0.005
        || (float) ($source['returned_amount'] ?? 0) !== 0.0
        || in_array(92, $receiptIds, true)
        || in_array(92, array_map(static fn (array $row): int => (int) $row['id'], $wrongCustomer), true)
        || abs((float) ($sourcePreview['summary']['customerCredit']['AED'] ?? 0)) > 0.005
        || abs($usedInPreview($outgoing) - $transferred) > 0.005
        || abs($usedInPreview($incoming) - $transferred) > 0.005) {
        fwrite(STDERR, '[FAIL] Fully transferred legacy credit is missing from history or offered again.' . PHP_EOL);
        exit(1);
    }
    echo '[PASS] Legacy AED ' . number_format($transferred, 0)
        . ' credit transferred to BK-000795 is traced once and cannot be spent again.' . PHP_EOL;
    exit(0);
}
if ((int) ($source['traveler_id'] ?? 0) !== 0
    || (int) ($sourceBooking['lead_traveler_id'] ?? 0) !== $customerId
    || (int) ($sourceBooking['branch_id'] ?? 0) !== (int) $booking['branch_id']
    || ! in_array(92, $receiptIds, true)
    || in_array(92, array_map(static fn (array $row): int => (int) $row['id'], $wrongCustomer), true)
) {
    fwrite(STDERR, "[FAIL] Legacy receipt customer identity or GUI eligibility differs.\n");
    exit(1);
}
echo "[PASS] Legacy unowned receipt is offered only to the matching customer and branch.\n";

$foundation = new \App\Services\CustomerPaymentFoundationService($app);
$sourcePreview = $foundation->buildWorkspacePreview('BK-000087', $customerId, [2]);
$targetPreview = $foundation->buildWorkspacePreview('BK-000795', $customerId, [2]);
if (round((float) ($sourcePreview['summary']['customerCredit']['AED'] ?? 0), 2) !== 199.0) {
    fwrite(STDERR, "[FAIL] Payment panel must show all AED 199 still available, including refunded service charge.\n");
    exit(1);
}
echo "[PASS] Payment panel agrees with the AED 199 refund credit; service charge is not deducted twice.\n";

$db->beginTransaction();
try {
    $eventStatement = $db->prepare('SELECT id, payload_json FROM booking_service_events
        WHERE booking_reference = ? AND event_type = "cancel" AND event_status = "posted"
        ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $eventStatement->execute(['BK-000087']);
    $cancelEvent = $eventStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $originalPayload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $updateEvent = $db->prepare('UPDATE booking_service_events SET payload_json = ? WHERE id = ?');
    foreach ([
        ['fee_refund' => 0, 'entitlement' => 1180, 'available' => 99],
        ['fee_refund' => 50, 'entitlement' => 1230, 'available' => 149],
        ['fee_refund' => 100, 'entitlement' => 1280, 'available' => 199],
    ] as $case) {
        $payload = $originalPayload;
        $payload['agency_fee_refund_amount'] = $case['fee_refund'];
        $payload['target_customer_refund_credit'] = $case['entitlement'];
        $updateEvent->execute([json_encode($payload, JSON_THROW_ON_ERROR), (int) $cancelEvent['id']]);
        $modernPreview = $foundation->buildWorkspacePreview('BK-000087', $customerId, [2]);
        if (round((float) ($modernPreview['summary']['customerCredit']['AED'] ?? 0), 2) !== (float) $case['available']) {
            throw new RuntimeException('Saved entitlement ignored the selected service-fee refund of AED ' . $case['fee_refund'] . '.');
        }

        unset($payload['target_customer_refund_credit'], $payload['target_customer_refund_credit_currency']);
        $updateEvent->execute([json_encode($payload, JSON_THROW_ON_ERROR), (int) $cancelEvent['id']]);
        $legacyPreview = $foundation->buildWorkspacePreview('BK-000087', $customerId, [2]);
        if (round((float) ($legacyPreview['summary']['customerCredit']['AED'] ?? 0), 2) !== (float) $case['available']) {
            throw new RuntimeException('Legacy fallback ignored the selected service-fee refund of AED ' . $case['fee_refund'] . '.');
        }
    }
    echo "[PASS] Modern and legacy credit displays respect AED 0, 50, or 100 service-fee refunds.\n";
    $db->rollBack();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

$outgoing = array_values(array_filter($sourcePreview['outgoingBookingCredits'] ?? [],
    static fn (array $row): bool => (int) ($row['receiptId'] ?? 0) === 92 && ($row['bookingReference'] ?? '') === 'BK-000795'));
$incoming = array_values(array_filter($targetPreview['incomingBookingCredits'] ?? [],
    static fn (array $row): bool => (int) ($row['receiptId'] ?? 0) === 92 && ($row['receiptBookingReference'] ?? '') === 'BK-000087'));
if (count($outgoing) !== 1 || count($incoming) !== 1
    || round((float) $outgoing[0]['paymentAmountConsumed'], 2) !== 1081.0
    || round((float) $incoming[0]['paymentAmountConsumed'], 2) !== 1081.0) {
    fwrite(STDERR, "[FAIL] Both booking views must trace the AED 1,081 transfer.\n");
    exit(1);
}
echo "[PASS] Both bookings show the existing credit-transfer provenance.\n";

$serviceState = (new \App\Services\ServiceWorkspaceService($app))->serviceState(87, [2]);
$refundSummary = (array) ($serviceState['services'][0]['customer_refund_summary'] ?? []);
if (round((float) ($refundSummary['entitlement'] ?? 0), 2) !== 1280.0
    || round((float) ($refundSummary['paid'] ?? -1), 2) !== 0.0
    || round((float) ($refundSummary['remaining'] ?? 0), 2) !== 199.0
    || round((float) $receivable['due_amount'], 2) !== 1081.0
    || round((float) $receivable['allocated_amount'], 2) !== 1081.0) {
    fwrite(STDERR, "[FAIL] Refund entitlement, cash paid, spendable credit or target invoice differs.\n");
    exit(1);
}
echo "[PASS] AED 1,280 entitlement = AED 1,081 transferred + AED 199 available; no cash refund.\n";

// Create only a temporary extra due inside a transaction to exercise the GUI's zero-cash save path.
$db->beginTransaction();
try {
    $beforeReceipt = $payments->findReceiptById(92);
    $beforeReceivable = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
    $due = (float) $beforeReceivable['due_amount'];
    $db->prepare('UPDATE customer_receivable_items SET due_amount = ?, outstanding_amount = ?, status = "partially_paid" WHERE id = ?')
        ->execute([$due + 100, 100, (int) $beforeReceivable['id']]);
    $result = (new \App\Services\CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id' => (int) $booking['id'],
        'receipt_action' => 'no_receipt',
        'receipt_scope' => 'whole_invoice',
        'receipt_currency' => 'AED',
        'received_amount' => '0',
        'receipt_date' => date('Y-m-d'),
        'payment_method' => 'cash',
        'charges_amount' => '0',
        'receipt_status' => 'received',
        'customer_credit_receipt_id' => 92,
        'customer_credit_apply_amount' => '100',
    ], 10, [2]);
    $afterReceipt = $payments->findReceiptById(92);
    $afterReceivable = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
    $applied = (array) ($result['customer_credit_applied'] ?? []);
    if (round((float) ($applied['allocated_amount'] ?? 0), 2) !== 100.0
        || round((float) $afterReceipt['unallocated_amount'], 2) !== round((float) $beforeReceipt['unallocated_amount'] - 100, 2)
        || round((float) $afterReceivable['outstanding_amount'], 2) !== 0.0
        || (int) ($result['receipt']['id'] ?? 0) !== 0) {
        throw new RuntimeException('GUI credit transfer did not settle the temporary due without new cash.');
    }
    echo "[PASS] Zero-cash GUI backend applies AED 100 of legacy credit to an open invoice.\n";
    $db->rollBack();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

$afterRollback = $payments->findReceiptById(92);
$targetAfterRollback = $payments->findReceivableByServiceLine('BK-000795', 'SV-001');
if (round((float) $afterRollback['unallocated_amount'], 2) !== round((float) $source['unallocated_amount'], 2)
    || round((float) $targetAfterRollback['due_amount'], 2) !== round((float) $receivable['due_amount'], 2)) {
    fwrite(STDERR, "[FAIL] Test transaction did not fully roll back.\n");
    exit(1);
}
echo "[PASS] Test rolled back; historical balances were not changed.\n";
