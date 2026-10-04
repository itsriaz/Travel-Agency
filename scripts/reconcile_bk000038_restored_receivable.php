<?php

declare(strict_types=1);

// Restore the reviewed cancellation receivable after a later cost-currency edit resynced the original invoice.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\CustomerPaymentRepository;

$usage = "Usage: php scripts/reconcile_bk000038_restored_receivable.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$rehearse = array_key_exists('rehearse', $options);
$apply = array_key_exists('apply', $options);
if ($rehearse && $apply) {
    fwrite(STDERR, $usage);
    exit(1);
}

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$query = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message . ' Stop for review.');
    }
};
$near = static fn (mixed $value, float $expected): bool => abs((float) $value - $expected) < .005;
$controlBalance = static function (string $accountCode) use ($query): float {
    $rows = $query('SELECT COALESCE(ROUND(SUM(jel.debit_amount - jel.credit_amount), 2), 0) AS balance
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND je.currency = ? AND coa.code = ?',
        ['BK-000038', 'AED', $accountCode]);
    return (float) ($rows[0]['balance'] ?? 0);
};

$db->beginTransaction();
try {
    $booking = $query('SELECT id, branch_id, booking_reference FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000038']);
    $service = $query('SELECT id, booking_id, line_reference, service_status, currency, cost_currency,
            final_sale_price, purchase_cost FROM booking_services WHERE id = ? FOR UPDATE', [38]);
    $receivable = $query('SELECT id, branch_id, booking_reference, service_line_reference, due_group, currency,
            due_amount, allocated_amount, outstanding_amount, due_date, status, remarks
        FROM customer_receivable_items WHERE id = ? FOR UPDATE', [38]);
    $receipt = $query('SELECT id, receipt_no, booking_reference, branch_id, currency, received_amount,
            allocated_amount, unallocated_amount, returned_amount, status, treasury_account_id
        FROM customer_receipts WHERE id = ? FOR UPDATE', [38]);
    $receiptAllocations = $query('SELECT id, customer_receipt_id, customer_receivable_item_id, allocated_amount
        FROM customer_receipt_allocations WHERE customer_receivable_item_id = ? ORDER BY id FOR UPDATE', [38]);
    $events = $query('SELECT id, event_type, event_status, currency, customer_refund_amount,
            supplier_refund_amount, journal_entry_id, payload_json FROM booking_service_events
        WHERE booking_service_id = ? ORDER BY id FOR UPDATE', [38]);
    $obligation = $query('SELECT id, currency, gross_amount, advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = ? FOR UPDATE', [38]);
    $supplierAllocations = $query('SELECT id, supplier_payment_id, supplier_obligation_id, allocated_amount
        FROM supplier_payment_allocations WHERE supplier_obligation_id = ? ORDER BY id FOR UPDATE', [38]);
    $wrongJournal = $query('SELECT je.id, je.branch_id, je.booking_reference, je.source_type, je.source_reference,
            je.entry_date, je.currency, coa.code AS account_code, jel.customer_receivable_item_id,
            jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = ? ORDER BY jel.id FOR UPDATE', [4908]);
    $priorAudit = $query('SELECT id, event_name, payload_json FROM audit_logs WHERE id = ?', [38978]);
    $priorReversal = $query('SELECT id FROM journal_entries WHERE source_type = ? AND source_reference = ?',
        ['historical_receivable_resync_reversed', 'BK000038-REV-JE4908']);

    $check(count($booking) === 1 && (int) $booking[0]['id'] === 38 && (int) $booking[0]['branch_id'] === 2,
        'The booking differs from the reviewed case');
    $check(count($service) === 1 && (int) $service[0]['booking_id'] === 38
        && $service[0]['line_reference'] === 'SV-001'
        && strcasecmp((string) $service[0]['service_status'], 'Cancelled') === 0
        && $service[0]['currency'] === 'AED' && $service[0]['cost_currency'] === 'PKR'
        && $near($service[0]['final_sale_price'], 1188) && $near($service[0]['purchase_cost'], 90942),
        'The cancelled service or original financial basis changed');
    $check(count($receivable) === 1 && (int) $receivable[0]['branch_id'] === 2
        && $receivable[0]['booking_reference'] === 'BK-000038'
        && $receivable[0]['service_line_reference'] === 'SV-001'
        && $receivable[0]['due_group'] === 'service_sale' && $receivable[0]['currency'] === 'AED',
        'The customer receivable identity changed');
    $check(count($receipt) === 1 && $receipt[0]['receipt_no'] === 'RCPT-000038'
        && $receipt[0]['booking_reference'] === 'BK-000038' && $receipt[0]['currency'] === 'AED'
        && $near($receipt[0]['received_amount'], 1180)
        && $near($receipt[0]['allocated_amount'], 580)
        && $near($receipt[0]['returned_amount'], 600)
        && $near($receipt[0]['unallocated_amount'], 0),
        'Customer cash or its application changed');
    $check(count($receiptAllocations) === 3
        && (int) $receiptAllocations[0]['id'] === 34
        && (int) $receiptAllocations[1]['id'] === 103
        && (int) $receiptAllocations[2]['id'] === 424
        && (int) $receiptAllocations[0]['customer_receipt_id'] === 38
        && (int) $receiptAllocations[2]['customer_receipt_id'] === 38
        && $near($receiptAllocations[0]['allocated_amount'], 53)
        && $near($receiptAllocations[2]['allocated_amount'], 527),
        'Historical allocation evidence changed');
    $posted = array_values(array_filter($events, static fn (array $event): bool => $event['event_status'] === 'posted'));
    $optionalCredit = count($posted) === 3 ? json_decode((string) $posted[2]['payload_json'], true) : null;
    $reviewedSupplierCredit = count($posted) === 3 && (int) $posted[2]['id'] === 62
        && $posted[2]['event_type'] === 'refund' && $posted[2]['currency'] === 'AED'
        && $near($posted[2]['customer_refund_amount'], 0)
        && $near($posted[2]['supplier_refund_amount'], 653)
        && $posted[2]['journal_entry_id'] === null
        && ($optionalCredit['refund_scope'] ?? '') === 'supplier'
        && ($optionalCredit['supplier_refund_payment_method'] ?? '') === 'supplier_credit'
        && ($optionalCredit['supplier_refund_detail']['refund_currency'] ?? '') === 'PKR'
        && $near($optionalCredit['supplier_refund_detail']['refund_source_amount'] ?? -1, 50242)
        && $near($optionalCredit['supplier_refund_detail']['refund_booking_equivalent'] ?? -1, 653);
    $check($reviewedSupplierCredit
        && (int) $posted[0]['id'] === 1 && $posted[0]['event_type'] === 'cancel'
        && (int) $posted[1]['id'] === 47 && $posted[1]['event_type'] === 'refund'
        && $near($posted[1]['customer_refund_amount'], 600)
        && $near($posted[1]['supplier_refund_amount'], 0),
        'Cancellation or posted refund history changed');
    $check(count($obligation) === 1 && $obligation[0]['currency'] === 'PKR'
        && $near($obligation[0]['gross_amount'], 40700)
        && $near($obligation[0]['net_payable_amount'], 0)
        && $obligation[0]['status'] === 'paid',
        'Supplier obligation changed; review its history separately');
    $check(count($wrongJournal) === 2
        && $wrongJournal[0]['source_type'] === 'service_receivable_adjusted'
        && $wrongJournal[0]['source_reference'] === 'SV-001'
        && $wrongJournal[0]['booking_reference'] === 'BK-000038'
        && (int) $wrongJournal[0]['branch_id'] === 2
        && $wrongJournal[0]['currency'] === 'AED'
        && $wrongJournal[0]['account_code'] === 'AR_CONTROL'
        && (int) $wrongJournal[0]['customer_receivable_item_id'] === 38
        && $near($wrongJournal[0]['debit_amount'], 608)
        && $near($wrongJournal[0]['credit_amount'], 0)
        && $wrongJournal[1]['account_code'] === 'SERVICE_REVENUE'
        && (int) $wrongJournal[1]['customer_receivable_item_id'] === 38
        && $near($wrongJournal[1]['debit_amount'], 0)
        && $near($wrongJournal[1]['credit_amount'], 608),
        'The erroneous AED 608 journal differs from the reviewed posting');
    $auditPayload = count($priorAudit) === 1 ? json_decode((string) $priorAudit[0]['payload_json'], true) : null;
    $check($priorAudit !== [] && $priorAudit[0]['event_name'] === 'customer.receivable.updated'
        && is_array($auditPayload)
        && ($auditPayload['booking_reference'] ?? '') === 'BK-000038'
        && $near($auditPayload['prior_due_amount'] ?? -1, 580)
        && $near($auditPayload['due_amount'] ?? -1, 1188),
        'The earlier zero-balance settlement cannot be verified');

    if (count($priorReversal) === 1 && $near($receivable[0]['due_amount'], 580)
        && $near($receivable[0]['allocated_amount'], 580)
        && $near($receivable[0]['outstanding_amount'], 0)
        && $near($controlBalance('AR_CONTROL'), 0)) {
        $db->rollBack();
        echo "ALREADY APPLIED: BK-000038 customer receivable AED 0; no cash or supplier rows changed.\n";
        exit(0);
    }
    $check($priorReversal === [], 'A prior reversal exists but the receivable does not match it');
    $check($near($receivable[0]['due_amount'], 1188)
        && $near($receivable[0]['allocated_amount'], 580)
        && $near($receivable[0]['outstanding_amount'], 608)
        && $receivable[0]['status'] === 'partially_paid'
        && $near($controlBalance('AR_CONTROL'), 608)
        && $near($controlBalance('SERVICE_REVENUE'), -1188),
        'Customer receivable or control-account totals differ from the reviewed error');

    $snapshot = [
        'booking' => $booking, 'service' => $service, 'receivable' => $receivable,
        'receipt' => $receipt, 'receipt_allocations' => $receiptAllocations,
        'events' => $events, 'obligation' => $obligation,
        'supplier_allocations' => $supplierAllocations, 'wrong_journal' => $wrongJournal,
    ];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'booking_reference' => 'BK-000038', 'fingerprint' => $fingerprint,
        'wrong_journal_id' => 4908, 'receivable_before' => 'AED 1,188 due / AED 580 allocated / AED 608 open',
        'receivable_after' => 'AED 580 due / AED 580 allocated / AED 0 open',
        'cash_movements' => 0, 'supplier_movements' => 0,
        'note' => 'The existing PKR 50,242 supplier credit remains untouched; this repair only reverses erroneous customer AR.',
    ];
    if (! $rehearse && ! $apply) {
        $db->rollBack();
        echo json_encode(['mode' => 'preview'] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actor = (int) ($options['actor'] ?? 0);
    $givenFingerprint = strtolower((string) ($options['fingerprint'] ?? ''));
    $check($actor > 0 && count($query('SELECT id FROM users WHERE id = ?', [$actor])) === 1
        && hash_equals($fingerprint, $givenFingerprint),
        'Use a verified --actor and the exact fingerprint from a fresh preview');

    $paymentRepository = new CustomerPaymentRepository($app);
    $updated = $paymentRepository->syncReceivableItem([
        'branch_id' => 2, 'booking_reference' => 'BK-000038',
        'service_line_reference' => 'SV-001', 'due_group' => 'service_sale',
        'currency' => 'AED', 'due_amount' => 580,
        'due_date' => $receivable[0]['due_date'], 'remarks' => $receivable[0]['remarks'],
        'actor_user_id' => $actor,
    ]);
    $check($updated !== null && $updated['action'] === 'updated'
        && $near($updated['delta_amount'], -608)
        && $near($updated['record']['due_amount'] ?? -1, 580)
        && $near($updated['record']['allocated_amount'] ?? -1, 580)
        && $near($updated['record']['outstanding_amount'] ?? -1, 0)
        && ($updated['record']['status'] ?? '') === 'paid',
        'The receivable did not return to its reviewed zero balance');
    $reversalId = (new AccountingRepository($app))->reverseJournalEntry(4908, [
        'source_type' => 'historical_receivable_resync_reversed',
        'source_reference' => 'BK000038-REV-JE4908',
        'entry_date' => date('Y-m-d'),
        'narration' => 'Reverse the AED 608 receivable and revenue restored by a later edit of cancelled BK-000038',
        'actor_user_id' => $actor,
    ]);
    $check($reversalId !== null && $reversalId > 0
        && $near($controlBalance('AR_CONTROL'), 0)
        && $near($controlBalance('SERVICE_REVENUE'), -580),
        'The reversing journal did not reconcile customer AR and revenue');
    $check($receipt === $query('SELECT id, receipt_no, booking_reference, branch_id, currency, received_amount,
            allocated_amount, unallocated_amount, returned_amount, status, treasury_account_id
        FROM customer_receipts WHERE id = ?', [38])
        && $events === $query('SELECT id, event_type, event_status, currency, customer_refund_amount,
            supplier_refund_amount, journal_entry_id, payload_json FROM booking_service_events
        WHERE booking_service_id = ? ORDER BY id', [38])
        && $obligation === $query('SELECT id, currency, gross_amount, advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = ?', [38])
        && $supplierAllocations === $query('SELECT id, supplier_payment_id, supplier_obligation_id, allocated_amount
        FROM supplier_payment_allocations WHERE supplier_obligation_id = ? ORDER BY id', [38]),
        'A receipt, refund event or supplier settlement changed unexpectedly');
    $check($query('SELECT id FROM customer_receivable_items WHERE id = ? AND due_amount = 580
        AND allocated_amount = 580 AND outstanding_amount = 0 AND status = "paid"', [38]) !== [],
        'The receivable no longer matches the settled customer balance');

    AuditLog::recordRequired($app, 'service.bk000038.restored_receivable.repaired', [
        'user_id' => $actor, 'booking_id' => 38, 'service_id' => 38,
        'booking_reference' => 'BK-000038', 'customer_receivable_item_id' => 38,
        'receipt_id' => 38, 'customer_refund_event_id' => 47,
        'erroneous_journal_entry_id' => 4908, 'reversal_journal_entry_id' => $reversalId,
        'before' => ['currency' => 'AED', 'due' => 1188, 'allocated' => 580, 'outstanding' => 608],
        'after' => ['currency' => 'AED', 'due' => 580, 'allocated' => 580, 'outstanding' => 0],
        'customer_penalty' => 53, 'supplier_penalty_recovery' => 527,
        'customer_receipt_unchanged' => true, 'customer_refund_unchanged' => true,
        'supplier_records_unchanged' => true, 'supplier_refund_cash_posted_by_this_repair' => false,
        'basis' => 'Restore the fully settled cancellation after the 2026-08-31 cost-currency correction reintroduced the original invoice.',
    ]);
    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back',
        'reversal_journal_entry_id' => $reversalId] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
