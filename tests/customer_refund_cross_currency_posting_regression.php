<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$actorUserId = (int) $db->query(
    'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin" ORDER BY u.id LIMIT 1'
)->fetchColumn();
$aedAccount = $db->query(
    'SELECT id, branch_id FROM treasury_accounts
     WHERE is_active = 1 AND currency = "AED" AND account_type = "cash"
     ORDER BY is_default DESC, id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$branchId = (int) ($aedAccount['branch_id'] ?? 0);
$travelerStatement = $db->prepare('SELECT id FROM travelers WHERE branch_id = ? ORDER BY id LIMIT 1');
$travelerStatement->execute([$branchId]);
$travelerId = (int) $travelerStatement->fetchColumn();
if ($actorUserId <= 0 || $branchId <= 0 || $travelerId <= 0) {
    throw new RuntimeException('An AED cash branch, traveler and super admin are required for this rollback-only test.');
}

$accessibleBranchIds = array_map('intval', $db->query('SELECT id FROM branches WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
$today = date('Y-m-d');
$token = (string) random_int(1000000, 9999999);
$bookingWorkspace = new \App\Services\BookingWorkspaceService($app);
$serviceWorkspace = new \App\Services\ServiceWorkspaceService($app);
$receiptWorkspace = new \App\Services\CustomerReceiptWorkspaceService($app);
$events = new \App\Repositories\BookingServiceEventRepository($app);
$details = new \App\Repositories\BookingServiceRefundDetailRepository($app);
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

$db->beginTransaction();
try {
    $accountStatement = $db->prepare(
        'INSERT INTO treasury_accounts (branch_id, account_type, account_name, account_code, currency,
            opening_balance, opening_balance_date, is_default, is_active, created_by_user_id)
         VALUES (?, "cash", ?, ?, "PKR", 0, ?, 0, 1, ?)'
    );
    $accountStatement->execute([$branchId, 'QA Refund PKR ' . $token, 'QA-REF-PKR-' . $token, $today, $actorUserId]);
    $pkrAccountId = (int) $db->lastInsertId();

    $bookingResult = $bookingWorkspace->saveBooking([
        'branch_id' => $branchId, 'booking_status' => 'draft', 'booking_date' => $today,
        'selected_customer_id' => $travelerId, 'party_label' => 'Lead Traveler / Booking Party',
        'lead_traveler_name' => 'Cross Currency Refund QA ' . $token,
        'contact_mobile' => '03001230000', 'remarks' => 'Rollback-only customer refund QA',
    ], $actorUserId, $accessibleBranchIds);
    $bookingId = (int) ($bookingResult['booking']['id'] ?? 0);
    $bookingReference = (string) ($bookingResult['booking']['booking_reference'] ?? '');
    $serviceResult = $serviceWorkspace->saveService([
        'booking_id' => $bookingId, 'service_type' => 'air ticket', 'service_status' => 'Open',
        'currency' => 'AED', 'cost_currency' => 'AED', 'supplier_name' => 'QA Refund Supplier ' . $token,
        'service_passenger_name' => 'QA Refund Passenger', 'sale_price' => '579', 'purchase_cost' => '579',
        'service_charge' => '41', 'discount_amount' => '0', 'taxes' => '0', 'other_fare' => '0',
        'soto_fare' => '0', 'spyi_amount' => '0', 'aq_yr_pk_amount' => '0', 'yq_amount' => '0',
        'oth_amount' => '0', 'vat_input' => '0', 'vat' => '0', 'commission' => '0',
        'ticket_pnr' => 'QA' . $token, 'ticket_number' => 'QA-' . $token,
        'ticket_airline' => 'PIA', 'ticket_sector_from' => 'ISB', 'ticket_sector_to' => 'DXB',
        'ticket_departure_date' => $today, 'ticket_class' => 'Economy', 'ticket_fare' => '579',
    ], $actorUserId, $accessibleBranchIds);
    $serviceId = (int) ($serviceResult['service']['id'] ?? 0);

    $receiptWorkspace->saveReceipt([
        'booking_id' => $bookingId, 'receipt_date' => $today, 'receipt_currency' => 'AED',
        'received_amount' => '620', 'payment_method' => 'cash', 'charges_amount' => '0',
        'receipt_status' => 'received', 'treasury_account_id' => (int) $aedAccount['id'],
        'receipt_remarks' => 'Rollback-only cross-currency refund receipt',
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->cancelService([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_reason' => 'Rollback-only cancellation', 'cancel_event_date' => $today,
    ], $actorUserId, $accessibleBranchIds);
    $serviceWorkspace->settleCancellationFinancials([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'settlement_reason' => 'Rollback-only settlement', 'settlement_event_date' => $today,
        'customer_penalty_amount' => '100', 'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => '300', 'expected_supplier_refund_currency' => 'AED',
        'agency_fee_refund_amount' => '0', 'settlement_currency' => 'AED',
    ], $actorUserId, $accessibleBranchIds);

    $postInput = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'refund_reason' => 'Full customer credit paid in PKR', 'refund_event_date' => $today,
        'customer_refund_treatment' => 'pay_now', 'customer_refund_amount' => '200',
        'customer_refund_source_amount' => '15000', 'customer_refund_currency' => 'PKR',
        'refund_payment_method' => 'cash', 'refund_treasury_account_id' => $pkrAccountId,
        'supplier_refund_amount' => '0',
    ];
    $journalCountStatement = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE booking_reference = ?');
    $journalCountStatement->execute([$bookingReference]);
    $journalCountBefore = (int) $journalCountStatement->fetchColumn();
    try {
        $serviceWorkspace->refundService(array_replace($postInput, [
            'refund_treasury_account_id' => (int) $aedAccount['id'],
        ]), $actorUserId, $accessibleBranchIds);
        $wrongAccountRejected = false;
    } catch (RuntimeException) {
        $wrongAccountRejected = true;
    }
    $journalCountStatement->execute([$bookingReference]);
    $check('PKR payout rejects an AED cash account without writing journals',
        $wrongAccountRejected && (int) $journalCountStatement->fetchColumn() === $journalCountBefore);
    $serviceWorkspace->refundService($postInput, $actorUserId, $accessibleBranchIds);
    $firstEvent = $events->latestPostedEvent($serviceId, 'refund') ?? [];
    $firstPayload = json_decode((string) ($firstEvent['payload_json'] ?? '{}'), true) ?: [];
    $firstDetail = $details->findByServiceEventId((int) ($firstEvent['id'] ?? 0)) ?? [];
    $check('AED 200 credit and PKR 15,000 cash are stored separately',
        (string) ($firstEvent['currency'] ?? '') === 'AED'
        && (float) ($firstEvent['customer_refund_amount'] ?? 0) === 200.0
        && (string) ($firstDetail['refund_currency'] ?? '') === 'PKR'
        && (float) ($firstDetail['refund_source_amount'] ?? 0) === 15000.0);
    $check('Cross-currency payout links both journals',
        (int) ($firstEvent['journal_entry_id'] ?? 0) > 0
        && (int) ($firstPayload['customer_refund_treasury_journal_id'] ?? 0) > 0);

    $journalStatement = $db->prepare(
        'SELECT je.source_type, je.currency, ROUND(SUM(jel.debit_amount), 2) AS debits,
                ROUND(SUM(jel.credit_amount), 2) AS credits
         FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.id IN (?, ?) GROUP BY je.id, je.source_type, je.currency'
    );
    $journalStatement->execute([
        (int) $firstEvent['journal_entry_id'], (int) $firstPayload['customer_refund_treasury_journal_id'],
    ]);
    $journalRows = $journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byCurrency = [];
    foreach ($journalRows as $row) $byCurrency[(string) $row['currency']] = $row;
    $check('Both currency journals balance in their own denominations',
        count($journalRows) === 2
        && (float) ($byCurrency['AED']['debits'] ?? 0) === 200.0
        && (float) ($byCurrency['AED']['credits'] ?? 0) === 200.0
        && (float) ($byCurrency['PKR']['debits'] ?? 0) === 15000.0
        && (float) ($byCurrency['PKR']['credits'] ?? 0) === 15000.0);

    $correctionInput = [
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'correction_reason' => 'Correct actual PKR payout amount', 'correction_event_date' => $today,
        'customer_penalty_amount' => '100', 'customer_penalty_currency' => 'AED',
        'customer_penalty_exchange_rate' => '1', 'expected_supplier_refund_amount' => '300',
        'expected_supplier_refund_currency' => 'AED', 'expected_supplier_refund_exchange_rate' => '1',
        'agency_fee_refund_amount' => '0', 'supplier_penalty_amount' => '279',
        'supplier_penalty_currency' => 'AED', 'supplier_penalty_exchange_rate' => '1',
        'settlement_currency' => 'AED', 'settlement_exchange_rate' => '1',
        'customer_refund_amount' => '200', 'customer_refund_source_amount' => '15200',
        'customer_refund_currency' => 'PKR', 'refund_payment_method' => 'cash',
        'refund_treasury_account_id' => $pkrAccountId, 'supplier_refund_amount' => '0',
    ];
    $serviceWorkspace->correctPenaltyRefundWorkflow($correctionInput, $actorUserId, $accessibleBranchIds);
    $secondEvent = $events->latestPostedEvent($serviceId, 'refund') ?? [];
    $secondDetail = $details->findByServiceEventId((int) ($secondEvent['id'] ?? 0)) ?? [];
    $check('Edit Refund reverses old payout and saves corrected PKR 15,200 once',
        (int) ($secondEvent['id'] ?? 0) !== (int) ($firstEvent['id'] ?? 0)
        && (float) ($secondEvent['customer_refund_amount'] ?? 0) === 200.0
        && (float) ($secondDetail['refund_source_amount'] ?? 0) === 15200.0
        && (string) ($secondDetail['refund_currency'] ?? '') === 'PKR');
    $postedStatement = $db->prepare(
        'SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = ?
         AND event_type = "refund" AND event_status = "posted" AND customer_refund_amount > 0'
    );
    $postedStatement->execute([$serviceId]);
    $check('Only one customer payout remains posted after correction', (int) $postedStatement->fetchColumn() === 1);
    $reversalStatement = $db->prepare(
        'SELECT COUNT(*) FROM journal_entries WHERE booking_reference = ?
         AND source_type = "service_refund_treasury_reversed" AND currency = "PKR"'
    );
    $reversalStatement->execute([$bookingReference]);
    $check('Edit Refund reverses the original PKR cash journal', (int) $reversalStatement->fetchColumn() === 1);

    $db->exec('SAVEPOINT cross_currency_complete_undo');
    $cancelEvent = $events->latestPostedEvent($serviceId, 'cancel') ?? [];
    $serviceWorkspace->undoMistakenRefund([
        'booking_id' => $bookingId, 'service_id' => $serviceId,
        'cancel_event_id' => (int) ($cancelEvent['id'] ?? 0),
        'undo_last_refund_event_id' => (int) ($secondEvent['id'] ?? 0),
        'undo_reason' => 'Rollback-only cross-currency complete undo',
        'undo_mistake_confirmed' => '1',
    ], $actorUserId, $accessibleBranchIds);
    $reversalStatement->execute([$bookingReference]);
    $restoredReceivable = (new \App\Repositories\CustomerPaymentRepository($app))
        ->findReceivableByServiceLine($bookingReference, (string) ($serviceResult['service']['line_reference'] ?? '')) ?? [];
    $serviceStatus = $db->query('SELECT service_status FROM booking_services WHERE id = ' . $serviceId)->fetchColumn();
    $check('Complete undo reverses the PKR cash journal and restores the AED invoice',
        (int) $reversalStatement->fetchColumn() === 2
        && $events->latestPostedEvent($serviceId, 'refund') === null
        && $events->latestPostedEvent($serviceId, 'cancel') === null
        && $serviceStatus === 'Open'
        && (float) ($restoredReceivable['due_amount'] ?? 0) === 620.0
        && (float) ($restoredReceivable['outstanding_amount'] ?? -1) === 0.0);
    $db->exec('ROLLBACK TO SAVEPOINT cross_currency_complete_undo');
    $db->exec('RELEASE SAVEPOINT cross_currency_complete_undo');

    $serviceWorkspace->correctPenaltyRefundWorkflow(array_replace($correctionInput, [
        'correction_reason' => 'Correct actual payout currency back to AED',
        'customer_refund_source_amount' => '200', 'customer_refund_currency' => 'AED',
        'refund_treasury_account_id' => (int) $aedAccount['id'],
    ]), $actorUserId, $accessibleBranchIds);
    $thirdEvent = $events->latestPostedEvent($serviceId, 'refund') ?? [];
    $thirdPayload = json_decode((string) ($thirdEvent['payload_json'] ?? '{}'), true) ?: [];
    $thirdDetail = $details->findByServiceEventId((int) ($thirdEvent['id'] ?? 0)) ?? [];
    $reversalStatement->execute([$bookingReference]);
    $check('Edit Refund can switch PKR payout to AED without relabeling or orphaning cash',
        (int) ($thirdEvent['id'] ?? 0) !== (int) ($secondEvent['id'] ?? 0)
        && (string) ($thirdDetail['refund_currency'] ?? '') === 'AED'
        && (float) ($thirdDetail['refund_source_amount'] ?? 0) === 200.0
        && (int) ($thirdPayload['customer_refund_treasury_journal_id'] ?? 0) === 0
        && (int) $reversalStatement->fetchColumn() === 2);

    $oppositeReference = 'QA-REFUND-' . $token;
    $accounting = new \App\Repositories\AccountingRepository($app);
    $oppositeJournals = $accounting->postCrossCurrencyCustomerRefund([
        'branch_id' => $branchId, 'booking_reference' => $oppositeReference,
        'service_line_reference' => 'SV-QA', 'source_reference' => $oppositeReference,
        'entry_date' => $today, 'currency' => 'PKR', 'customer_refund_amount' => 25000,
        'refund_currency' => 'AED', 'refund_source_amount' => 300,
        'payment_method' => 'cash', 'treasury_account_id' => (int) $aedAccount['id'],
        'actor_user_id' => $actorUserId,
    ]);
    $oppositeStatement = $db->prepare('SELECT currency FROM journal_entries WHERE id = ?');
    $oppositeStatement->execute([(int) $oppositeJournals['credit_journal_id']]);
    $creditCurrency = (string) $oppositeStatement->fetchColumn();
    $oppositeStatement->execute([(int) $oppositeJournals['cash_journal_id']]);
    $cashCurrency = (string) $oppositeStatement->fetchColumn();
    $check('Reverse direction keeps PKR credit and AED cash separate', $creditCurrency === 'PKR' && $cashCurrency === 'AED');
    $accounting->postServiceRefundReversal([
        'branch_id' => $branchId, 'booking_reference' => $oppositeReference,
        'journal_entry_id' => (int) $oppositeJournals['credit_journal_id'],
        'source_reference' => $oppositeReference . '-REV', 'entry_date' => $today,
        'currency' => 'PKR', 'actor_user_id' => $actorUserId,
    ]);
    $accounting->postServiceRefundReversal([
        'branch_id' => $branchId, 'booking_reference' => $oppositeReference,
        'journal_entry_id' => (int) $oppositeJournals['cash_journal_id'],
        'original_source_type' => 'service_refund_treasury_posted',
        'source_reference' => $oppositeReference . '-REV-CASH', 'entry_date' => $today,
        'currency' => 'AED', 'actor_user_id' => $actorUserId,
    ]);
    $oppositeStatement = $db->prepare(
        'SELECT COUNT(*) FROM journal_entries WHERE booking_reference = ? AND source_type IN
            ("service_refund_reversed", "service_refund_treasury_reversed")'
    );
    $oppositeStatement->execute([$oppositeReference]);
    $check('Reverse direction also reverses both linked journals', (int) $oppositeStatement->fetchColumn() === 2);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo '[FAIL] ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

echo 'Transaction rolled back.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
