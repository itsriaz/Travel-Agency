<?php

declare(strict_types=1);

// Dry-run by default. Apply only after real cash, account and date are confirmed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\CustomerPaymentRepository;
use App\Services\CustomerReceiptWorkspaceService;
use App\Services\ServiceWorkspaceService;

$apply = in_array('--apply', $argv, true);
if ($apply && ! in_array('--confirmed-2026-07-04-cash-account-34', $argv, true)) {
    fwrite(STDERR, 'Confirm actual AED 1,660 cash was received on 2026-07-04 into account 34, with no cash returned.\n');
    exit(1);
}
$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();
try {
    $check = static function (bool $condition, string $message): void {
        if (! $condition) { throw new RuntimeException($message); }
    };
    $rows = static function (string $sql, array $params = []) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    $booking = $rows('SELECT id, branch_id, lead_traveler_id FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000221']);
    $check(count($booking) === 1 && (int) $booking[0]['id'] === 221
        && (int) $booking[0]['branch_id'] === 2 && (int) $booking[0]['lead_traveler_id'] === 145,
        'BK-000221 customer/branch differs from the reviewed case.');
    $payments = new CustomerPaymentRepository($app);
    $old = $payments->findReceiptById(219);
    $check($old !== null && $old['booking_reference'] === 'BK-000221'
        && $old['status'] === 'void' && $old['receipt_no'] === 'RCPT-000219'
        && $old['currency'] === 'AED' && $old['payment_method'] === 'cash'
        && (int) $old['treasury_account_id'] === 34 && $old['receipt_date'] === '2026-07-04'
        && abs((float) $old['received_amount'] - 1668) < .005
        && abs((float) $old['returned_amount']) < .005,
        'Original voided receipt no longer matches its reviewed audit trail.');
    $account = $rows('SELECT branch_id, currency, account_type, is_active FROM treasury_accounts WHERE id = ? FOR UPDATE', [34]);
    $check(count($account) === 1 && (int) $account[0]['branch_id'] === 2
        && $account[0]['currency'] === 'AED' && $account[0]['account_type'] === 'cash'
        && (int) $account[0]['is_active'] === 1,
        'The original Cash-UAE-AED account is not active for this booking.');
    $check($rows('SELECT id FROM customer_receipts WHERE booking_reference = ? AND status <> ? LIMIT 1',
        ['BK-000221', 'void']) === [],
        'Another active customer receipt now exists for BK-000221; stop to avoid duplicate money.');
    $item = $payments->findReceivableByServiceLine('BK-000221', 'SV-001');
    $check($item !== null && (int) $item['id'] === 220 && $item['currency'] === 'AED'
        && abs((float) $item['due_amount'] - 360) < .005
        && abs((float) $item['allocated_amount']) < .005,
        'Corrected AED 360 cancellation charge is no longer the open invoice.');
    $events = new BookingServiceEventRepository($app);
    $check($events->latestPostedEvent(220, 'refund') === null,
        'A refund is already posted; review it before recording the corrected receipt.');
    $cancel = $events->latestPostedEvent(220, 'cancel');
    $cancelPayload = json_decode((string) ($cancel['payload_json'] ?? ''), true);
    $check($cancel !== null && is_array($cancelPayload)
        && ($cancelPayload['mode'] ?? '') === 'cancellation_financial_adjustment'
        && abs((float) ($cancelPayload['customer_final_charge_amount'] ?? 0) - 360) < .005
        && abs((float) ($cancelPayload['expected_supplier_refund_source_amount'] ?? 0) - 101500) < .005
        && abs((float) ($cancelPayload['expected_supplier_refund_booking_amount'] ?? 0) - 1318) < .005,
        'The reviewed cancellation settlement no longer matches BK-000221.');

    $workspace = new ServiceWorkspaceService($app);
    $workspace->reverseCancellationSettlement([
        'booking_id' => 221, 'service_id' => 220,
        'settlement_reverse_reason' => 'Reorder confirmed historical cash before BK-000221 cancellation settlement; original void receipt remains in audit.',
    ], 1, [2]);
    $restoredItem = $payments->findReceivableByServiceLine('BK-000221', 'SV-001');
    $check($restoredItem !== null && abs((float) $restoredItem['due_amount'] - 1660) < .005
        && abs((float) $restoredItem['allocated_amount']) < .005,
        'Reversed cancellation did not restore the original AED 1,660 invoice.');

    $result = (new CustomerReceiptWorkspaceService($app))->saveReceipt([
        'booking_id' => 221, 'receipt_date' => '2026-07-04',
        'receipt_currency' => 'AED', 'received_amount' => 1660,
        'payment_method' => 'cash', 'treasury_account_id' => 34,
        'receipt_status' => 'received',
        'receipt_remarks' => 'Verified amount correction for voided RCPT-000219 (AED 1,668 entered; AED 1,660 actually received).',
    ], 1, [2]);
    $replacement = $result['receipt'] ?? null;
    $check(is_array($replacement) && abs((float) $replacement['allocated_amount'] - 1660) < .005
        && abs((float) $replacement['unallocated_amount']) < .005,
        'Confirmed cash was not fully allocated to the original invoice.');

    $workspace->settleCancellationFinancials([
        'booking_id' => 221, 'service_id' => 220,
        'settlement_reason' => 'Reapply reviewed cancellation after verified AED 1,660 cash receipt; PKR 101,500 supplier expectation equals AED 1,318.',
        'settlement_event_date' => (string) $cancel['event_date'],
        'customer_penalty_amount' => 18, 'customer_penalty_currency' => 'AED',
        'expected_supplier_refund_amount' => 101500, 'expected_supplier_refund_currency' => 'PKR',
        'supplier_refund_conversion_mode' => 'manual_equivalent',
        'expected_supplier_refund_equivalent_amount' => 1318,
        'expected_supplier_refund_rate_date' => (string) ($cancelPayload['expected_supplier_refund_rate_date'] ?? $cancel['event_date']),
    ], 1, [2]);
    $replacement = $payments->findReceiptById((int) $replacement['id']);
    $afterItem = $payments->findReceivableByServiceLine('BK-000221', 'SV-001');
    $check(is_array($replacement) && (int) ($replacement['id'] ?? 0) > 0
        && $replacement['booking_reference'] === 'BK-000221'
        && (int) $replacement['treasury_account_id'] === 34
        && abs((float) $replacement['received_amount'] - 1660) < .005
        && abs((float) $replacement['allocated_amount'] - 360) < .005
        && abs((float) $replacement['unallocated_amount'] - 1300) < .005
        && $afterItem !== null && abs((float) $afterItem['outstanding_amount']) < .005
        && $payments->findReceiptById(219)['status'] === 'void',
        'Corrected receipt, invoice allocation, or preserved void history is inconsistent.');
    $refundState = $workspace->serviceState(221, [2]);
    $matchingService = null;
    foreach (($refundState['services'] ?? []) as $service) {
        if ((int) ($service['id'] ?? 0) === 220) { $matchingService = $service; break; }
    }
    $check($matchingService !== null
        && abs((float) ($matchingService['customer_refund_summary']['entitlement'] ?? 0) - 1300) < .005
        && abs((float) ($matchingService['customer_refund_summary']['remaining'] ?? 0) - 1300) < .005
        && abs((float) ($matchingService['customer_refundable_credit_amount'] ?? 0) - 1300) < .005,
        'Refund view does not show the AED 1,300 owed after correcting cash and cancellation.');

    $balances = $rows('SELECT c.code, ROUND(SUM(l.debit_amount-l.credit_amount),2) balance
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
        JOIN chart_of_accounts c ON c.id = l.account_id
        WHERE j.booking_reference = ? AND j.currency = ?
          AND c.code IN (?, ?, ?)
        GROUP BY c.code', ['BK-000221', 'AED', 'AR_CONTROL', 'CUSTOMER_CREDIT', 'CASH_CASH_NOBLE_AED_AED']);
    $balanceByCode = array_column($balances, 'balance', 'code');
    $check(abs((float) ($balanceByCode['AR_CONTROL'] ?? 0)) < .005
        && abs((float) ($balanceByCode['CUSTOMER_CREDIT'] ?? 0) + 1300) < .005
        && abs((float) ($balanceByCode['CASH_CASH_NOBLE_AED_AED'] ?? 0) - 1660) < .005,
        'Cash, customer credit, and receivable control do not reconcile to the corrected receipt.');
    $supplier = $rows('SELECT currency, gross_amount, net_payable_amount FROM supplier_obligations
        WHERE booking_reference = ? AND service_line_reference = ?', ['BK-000221', 'SV-001']);
    $supplierControl = $rows('SELECT ROUND(SUM(l.debit_amount-l.credit_amount),2) balance
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
        JOIN chart_of_accounts c ON c.id = l.account_id
        WHERE j.booking_reference = ? AND j.currency = ? AND c.code = ?',
        ['BK-000221', 'PKR', 'AP_CONTROL']);
    $check(count($supplier) === 1 && $supplier[0]['currency'] === 'PKR'
        && abs((float) $supplier[0]['gross_amount'] - 20000) < .005
        && abs((float) $supplier[0]['net_payable_amount'] - 20000) < .005
        && abs((float) ($supplierControl[0]['balance'] ?? 0) + 20000) < .005
        && $events->latestPostedEvent(220, 'refund') === null,
        'PKR supplier payable or unposted-refund controls do not match the reviewed case.');
    AuditLog::recordRequired($app, 'customer.receipt.historical_cash.reconciled', [
        'user_id' => 1, 'booking_id' => 221, 'booking_reference' => 'BK-000221',
        'original_void_receipt_id' => 219, 'original_void_receipt_no' => 'RCPT-000219',
        'replacement_receipt_id' => (int) $replacement['id'],
        'replacement_receipt_no' => (string) $replacement['receipt_no'],
        'currency' => 'AED', 'wrong_recorded_amount' => 1668,
        'confirmed_received_amount' => 1660, 'treasury_account_id' => 34,
        'confirmed_receipt_date' => '2026-07-04',
        'invoice_allocated' => 360, 'remaining_customer_credit' => 1300,
        'reason' => 'Correct already-voided wrong-amount receipt while preserving its history; no duplicate cash.',
    ]);
    if ($apply) { $db->commit(); } else { $db->rollBack(); }
    echo ($apply ? 'APPLIED' : 'DRY RUN ROLLED BACK') . ': AED 1,660 receipt; AED 360 allocated; AED 1,300 credit.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
