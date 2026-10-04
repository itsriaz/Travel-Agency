<?php

declare(strict_types=1);

// Reviewed historical commercial-resync repair; never invent a customer receipt.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$options = getopt('', ['apply', 'rehearse', 'actor:']);
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);
if ($apply && $rehearse) { fwrite(STDERR, 'Choose apply or rehearse, not both.' . PHP_EOL); exit(1); }
$actor = (int) ($options['actor'] ?? 0);
$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$query = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql); $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message . ' Stop for review.');
};
$eventName = 'service.bk000897.cancellation_resync.repaired';
$reason = 'Restore reviewed BK-000897 cancellation overwritten by 2026-09-21 commercial save; no customer payment reassigned.';
$db->beginTransaction();
try {
    $services = $query('SELECT bs.*,b.booking_reference FROM booking_services bs JOIN bookings b ON b.id=bs.booking_id
        WHERE b.booking_reference=? AND bs.line_reference=? FOR UPDATE', ['BK-000897', 'SV-001']);
    $assert(count($services) === 1, 'Expected one service');
    $service = $services[0];
    $assert((int) $service['id'] === 907 && $service['currency'] === 'PKR' && $service['cost_currency'] === 'PKR'
        && strtolower($service['service_status']) === 'cancelled'
        && (float) $service['purchase_cost'] === 68444.0 && (float) $service['final_sale_price'] === 70000.0, 'Service differs from reviewed snapshot');
    $events = $query('SELECT * FROM booking_service_events WHERE booking_service_id=? FOR UPDATE', [907]);
    $cancel = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'cancel' && $e['event_status'] === 'posted'));
    $assert(count($cancel) === 1 && (int) $cancel[0]['id'] === 57, 'Cancellation event changed');
    $assert(count(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'refund')) === 0, 'Refund history now exists');
    $payload = json_decode($cancel[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    foreach (['customer_final_charge_amount' => 30000, 'customer_received_amount' => 0,
        'available_customer_refund_credit' => 0, 'customer_penalty_amount' => 4444,
        'expected_supplier_refund_source_amount' => 44444, 'supplier_penalty_source_amount' => 24000] as $key => $value) {
        $assert(isset($payload[$key]) && (float) $payload[$key] === (float) $value, 'Cancellation ' . $key . ' changed');
    }
    $receivables = $query('SELECT * FROM customer_receivable_items WHERE id=? AND booking_reference=? FOR UPDATE', [912, 'BK-000897']);
    $obligations = $query('SELECT * FROM supplier_obligations WHERE id=? AND booking_reference=? FOR UPDATE', [898, 'BK-000897']);
    $assert(count($receivables) === 1 && count($obligations) === 1, 'Reviewed receivable/obligation not found');
    $receivable = $receivables[0]; $obligation = $obligations[0];
    $allocations = $query('SELECT * FROM supplier_payment_allocations WHERE supplier_obligation_id=? ORDER BY id FOR UPDATE', [898]);
    $payments = $query('SELECT * FROM supplier_payments WHERE id=? FOR UPDATE', [213]);
    $assert(count($payments) === 1, 'Reviewed supplier payment not found');
    $payment = $payments[0];
    $assert($query('SELECT id FROM customer_receipt_allocations WHERE customer_receivable_item_id=? FOR UPDATE', [912]) === [], 'Customer allocations now exist');
    $assert((float) $receivable['allocated_amount'] === 0.0 && $receivable['currency'] === 'PKR'
        && (float) $obligation['advance_applied_amount'] === 0.0 && $obligation['currency'] === 'PKR', 'Settlement currency or allocation state changed');
    $assert($query('SELECT id FROM supplier_advance_applications WHERE supplier_obligation_id=? FOR UPDATE', [898]) === [], 'Supplier advances now exist');
    $assert($query('SELECT a.id FROM counterparty_offset_payable_allocations a JOIN counterparty_offsets o ON o.id=a.counterparty_offset_id
        WHERE a.supplier_obligation_id=? AND o.status=? FOR UPDATE', [898, 'posted']) === [], 'Linked adjustments now exist');
    $audit = $query('SELECT id FROM audit_logs WHERE event_name=?', [$eventName]);
    $reversals = $query('SELECT id FROM journal_entries WHERE booking_reference=? AND source_type=? ORDER BY id', ['BK-000897', 'bk000897_resync_reversal']);
    $restored = (float) $receivable['due_amount'] === 30000.0 && (float) $receivable['outstanding_amount'] === 30000.0
        && (float) $obligation['gross_amount'] === 24000.0 && (float) $obligation['net_payable_amount'] === 0.0
        && count($allocations) === 1 && (int) $allocations[0]['id'] === 809 && (float) $allocations[0]['allocated_amount'] === 24000.0;
    if ($restored && count($audit) === 1 && count($reversals) === 3) {
        $db->rollBack(); echo json_encode(['mode' => 'already-applied', 'booking_reference' => 'BK-000897'], JSON_PRETTY_PRINT), PHP_EOL; exit;
    }
    $assert($audit === [] && $reversals === [], 'Partial/duplicate repair history exists');
    $assert((float) $receivable['due_amount'] === 70000.0 && (float) $receivable['outstanding_amount'] === 70000.0
        && (float) $obligation['gross_amount'] === 68444.0 && (float) $obligation['net_payable_amount'] === 0.0, 'Live balances changed');
    $assert(count($allocations) === 2 && (int) $allocations[0]['id'] === 809 && (int) $allocations[1]['id'] === 855
        && (int) $allocations[0]['supplier_payment_id'] === 213 && (int) $allocations[1]['supplier_payment_id'] === 213
        && (float) $allocations[0]['allocated_amount'] === 24000.0 && (float) $allocations[1]['allocated_amount'] === 44444.0, 'Supplier allocation history changed');
    $journals = $query('SELECT j.id,j.source_type,j.currency,j.booking_reference,SUM(l.debit_amount) debit_total,SUM(l.credit_amount) credit_total
        FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id
        WHERE j.id IN (?,?,?) GROUP BY j.id ORDER BY j.id', [6204, 6205, 6206]);
    $assert(count($journals) === 3, 'Reviewed erroneous journals missing');
    foreach ($journals as $index => $journal) {
        $types = ['service_receivable_adjusted', 'supplier_payable_adjusted', 'supplier_advance_applied'];
        $totals = [40000.0, 44444.0, 44444.0];
        $assert($journal['booking_reference'] === 'BK-000897' && $journal['currency'] === 'PKR'
            && $journal['source_type'] === $types[$index] && (float) $journal['debit_total'] === $totals[$index]
            && (float) $journal['credit_total'] === $totals[$index], 'Erroneous journal differs from review');
    }
    $ledger = static function () use ($query): array {
        $balances = [];
        foreach ($query('SELECT c.code,SUM(l.debit_amount-l.credit_amount) balance
            FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id
            JOIN chart_of_accounts c ON c.id=l.account_id WHERE j.booking_reference=? GROUP BY c.code', ['BK-000897']) as $row) {
            $balances[$row['code']] = (float) $row['balance'];
        }
        ksort($balances); return $balances;
    };
    $expectedBeforeLedger = ['AP_CONTROL' => 0.0, 'AR_CONTROL' => 70000.0, 'SERVICE_COST' => 68444.0,
        'SERVICE_REVENUE' => -70000.0, 'SUPPLIER_ADVANCES' => -68444.0];
    $assert($ledger() === $expectedBeforeLedger, 'Booking ledger no longer matches the reviewed resync');
    $report = ['mode' => 'validated-no-writes', 'booking_reference' => 'BK-000897',
        'customer_due_before_PKR' => 70000, 'customer_due_after_PKR' => 30000,
        'customer_refund_paid_PKR' => 0, 'customer_refund_remaining_PKR' => 0,
        'customer_payment_confirmation_required' => true, 'customer_money_reassigned' => false,
        'supplier_obligation_before_PKR' => 68444, 'supplier_obligation_after_PKR' => 24000,
        'supplier_credit_to_restore_PKR' => 44444, 'journals_to_reverse' => [6204, 6205, 6206]];
    if (! $apply && ! $rehearse) {
        $db->rollBack(); echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL; exit;
    }
    $assert($actor > 0 && count($query('SELECT id FROM users WHERE id=?', [$actor])) === 1, 'Valid --actor is required for writes/rehearsal');
    $protectedTables = ['customer_receipts', 'customer_receipt_allocations', 'supplier_advances', 'supplier_advance_applications',
        'treasury_accounts', 'treasury_transactions', 'bookings', 'booking_services', 'booking_service_events'];
    $snapshot = static function () use ($protectedTables, $query): array {
        $hashes = [];
        foreach ($protectedTables as $table) $hashes[$table] = hash('sha256', json_encode($query('SELECT * FROM ' . $table . ' ORDER BY id'), JSON_THROW_ON_ERROR));
        foreach (['supplier_obligations' => 898, 'supplier_payments' => 213, 'customer_receivable_items' => 912] as $table => $id) {
            $hashes['other_' . $table] = hash('sha256', json_encode($query('SELECT * FROM ' . $table . ' WHERE id<>? ORDER BY id', [$id]), JSON_THROW_ON_ERROR));
        }
        $hashes['other_supplier_allocations'] = hash('sha256', json_encode($query('SELECT * FROM supplier_payment_allocations WHERE supplier_obligation_id<>? ORDER BY id', [898]), JSON_THROW_ON_ERROR));
        return $hashes;
    };
    $before = $snapshot();
    $suppliers = new \App\Repositories\SupplierRepository($app);
    $release = $suppliers->releaseSettledCreditForObligation(898, 24000, $actor, $reason);
    $assert((float) $release['released_payment_amount'] === 44444.0 && (float) $release['released_amount'] === 44444.0, 'Unexpected supplier credit release');
    (new \App\Repositories\CustomerPaymentRepository($app))->syncReceivableItem([
        'branch_id' => 1, 'booking_reference' => 'BK-000897', 'service_line_reference' => 'SV-001',
        'due_group' => 'service_sale', 'currency' => 'PKR', 'due_amount' => 30000,
        'due_date' => $receivable['due_date'], 'remarks' => 'Reviewed cancellation customer charge [SV-001]', 'actor_user_id' => $actor,
    ]);
    $suppliers->syncObligation([
        'supplier_id' => (int) $obligation['supplier_id'], 'branch_id' => 1, 'booking_reference' => 'BK-000897',
        'service_line_reference' => 'SV-001', 'obligation_group' => 'service_cost', 'currency' => 'PKR',
        'gross_amount' => 24000, 'due_date' => $obligation['due_date'],
        'remarks' => 'Reviewed cancellation supplier penalty [SV-001]', 'actor_user_id' => $actor,
    ]);
    $accounting = new \App\Repositories\AccountingRepository($app);
    $newJournalIds = [];
    foreach ([6204, 6205, 6206] as $id) {
        $reversal = $accounting->reverseJournalEntry($id, ['source_type' => 'bk000897_resync_reversal',
            'source_reference' => 'BK897-RESYNC-REV-' . $id, 'narration' => $reason,
            'entry_date' => date('Y-m-d'), 'actor_user_id' => $actor]);
        $assert($reversal !== null, 'Journal reversal failed'); $newJournalIds[] = $reversal;
    }
    $assert($before === $snapshot(), 'Protected or unrelated data changed');
    $afterPayment = $query('SELECT * FROM supplier_payments WHERE id=?', [213])[0];
    $assert((float) $afterPayment['allocated_amount'] === (float) $payment['allocated_amount'] - 44444
        && (float) $afterPayment['unallocated_amount'] === (float) $payment['unallocated_amount'] + 44444
        && $afterPayment['paid_amount'] === $payment['paid_amount'], 'Supplier payment header not reconciled');
    $paymentInvariant = array_flip(['allocated_amount', 'unallocated_amount', 'status', 'updated_at']);
    $assert(array_diff_key($payment, $paymentInvariant) === array_diff_key($afterPayment, $paymentInvariant), 'Supplier payment cash/header evidence changed');
    $afterAllocations = $query('SELECT * FROM supplier_payment_allocations WHERE supplier_obligation_id=? ORDER BY id', [898]);
    $assert(count($afterAllocations) === 1 && $afterAllocations[0] === $allocations[0], 'Remaining allocation changed');
    $afterReceivable = $query('SELECT * FROM customer_receivable_items WHERE id=?', [912])[0];
    $afterObligation = $query('SELECT * FROM supplier_obligations WHERE id=?', [898])[0];
    $assert((float) $afterReceivable['due_amount'] === 30000.0 && (float) $afterReceivable['allocated_amount'] === 0.0
        && (float) $afterReceivable['outstanding_amount'] === 30000.0
        && (float) $afterObligation['gross_amount'] === 24000.0 && (float) $afterObligation['net_payable_amount'] === 0.0, 'Repaired balances inconsistent');
    $afterLedger = $ledger();
    $assert($afterLedger === ['AP_CONTROL' => 0.0, 'AR_CONTROL' => 30000.0, 'SERVICE_COST' => 24000.0,
        'SERVICE_REVENUE' => -30000.0, 'SUPPLIER_ADVANCES' => -24000.0], 'Repaired journals do not match restored cancellation balances');
    \App\Helpers\AuditLog::record($app, $eventName, ['user_id' => $actor, 'booking_reference' => 'BK-000897',
        'reason' => $reason, 'receivable_before' => $receivable, 'receivable_after' => $afterReceivable,
        'supplier_obligation_before' => $obligation, 'supplier_obligation_after' => $afterObligation,
        'supplier_payment_before' => $payment, 'supplier_payment_after' => $afterPayment,
        'supplier_allocations_before' => $allocations, 'supplier_allocations_after' => $afterAllocations,
        'original_journal_ids' => [6204, 6205, 6206], 'reversal_journal_ids' => $newJournalIds,
        'customer_payment_confirmation_required' => true, 'protected_data_checks' => array_keys($before)]);
    $report['mode'] = $apply ? 'applied' : 'rehearsed-and-rolled-back';
    $report['reversal_journal_ids'] = $newJournalIds; $report['protected_data_checks'] = array_keys($before);
    $report['ledger_after'] = $afterLedger;
    if ($apply) $db->commit(); else $db->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1);
}
