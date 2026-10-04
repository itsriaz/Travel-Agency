<?php

declare(strict_types=1);

// One-time reconciliation of the historical mixed-currency cancellation.
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

$usage = "Usage: php scripts/reconcile_bk000064_currency_truth.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);
if ($apply && $rehearse) {
    fwrite(STDERR, $usage);
    exit(1);
}

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$query = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$check = static function (bool $valid, string $message): void {
    if (! $valid) {
        throw new RuntimeException($message . ' No changes committed; review the local data.');
    }
};
$near = static fn (mixed $value, float $expected): bool => abs((float) $value - $expected) < .005;
$balance = static function (string $currency, string $code) use ($query): float {
    return (float) ($query('SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS balance
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND je.currency = ? AND coa.code = ?',
        ['BK-000064', $currency, $code])[0]['balance'] ?? 0);
};

$db->beginTransaction();
try {
    $booking = $query('SELECT id, branch_id, booking_reference FROM bookings WHERE id = 64 FOR UPDATE');
    $service = $query('SELECT id, booking_id, line_reference, service_status, currency, cost_currency,
        purchase_cost, final_sale_price FROM booking_services WHERE id = 64 FOR UPDATE');
    $receivable = $query('SELECT id, currency, due_amount, allocated_amount, outstanding_amount, status
        FROM customer_receivable_items WHERE id = 64 FOR UPDATE');
    $obligation = $query('SELECT id, supplier_id, branch_id, currency, gross_amount,
        advance_applied_amount, net_payable_amount, status FROM supplier_obligations WHERE id = 64 FOR UPDATE');
    $events = $query('SELECT id, event_type, event_status, currency, customer_refund_amount,
        supplier_refund_amount, supplier_credit_amount, payload_json FROM booking_service_events
        WHERE booking_service_id = 64 ORDER BY id FOR UPDATE');
    $receipts = $query('SELECT id, currency, received_amount, allocated_amount, returned_amount,
        unallocated_amount FROM customer_receipts WHERE booking_reference = ? ORDER BY id FOR UPDATE', ['BK-000064']);
    $allocations = $query('SELECT id, customer_receipt_id, allocated_amount FROM customer_receipt_allocations
        WHERE customer_receivable_item_id = 64 ORDER BY id FOR UPDATE');
    $supplierAllocations = $query('SELECT id FROM supplier_payment_allocations WHERE supplier_obligation_id = 64 FOR UPDATE');
    $advanceApplications = $query('SELECT id FROM supplier_advance_applications WHERE supplier_obligation_id = 64 FOR UPDATE');
    $offsets = $query('SELECT id FROM counterparty_offset_payable_allocations WHERE supplier_obligation_id = 64 FOR UPDATE');
    $journals = $query('SELECT je.id, je.currency, je.source_type, coa.code, jel.debit_amount, jel.credit_amount
        FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id IN (4953, 4954) ORDER BY je.id, jel.id FOR UPDATE');
    $prior = $query('SELECT id FROM journal_entries WHERE booking_reference = ?
        AND source_type = ? AND source_reference = ?', ['BK-000064', 'bk000064_currency_reversal', 'REV-JE-4953']);

    $check(count($booking) === 1 && (int) $booking[0]['branch_id'] === 2
        && $booking[0]['booking_reference'] === 'BK-000064', 'Booking identity changed');
    $check(count($service) === 1 && (int) $service[0]['booking_id'] === 64
        && $service[0]['line_reference'] === 'SV-001'
        && strcasecmp($service[0]['service_status'], 'Cancelled') === 0
        && $service[0]['currency'] === 'AED' && $service[0]['cost_currency'] === 'PKR'
        && $near($service[0]['purchase_cost'], 39745) && $near($service[0]['final_sale_price'], 600),
        'Service financial basis changed');
    $check(count($receipts) === 2 && (int) $receipts[0]['id'] === 65
        && (int) $receipts[1]['id'] === 394 && $receipts[1]['currency'] === 'AED'
        && $near($receipts[1]['received_amount'], 550)
        && $near($receipts[1]['allocated_amount'], 550)
        && $near($receipts[1]['returned_amount'], 0)
        && count($allocations) === 1 && (int) $allocations[0]['customer_receipt_id'] === 394
        && $near($allocations[0]['allocated_amount'], 550), 'Customer receipt evidence changed');
    $check($supplierAllocations === [] && $advanceApplications === [] && $offsets === [],
        'Supplier obligation has a settlement; this repair cannot assume an unpaid payable');
    $check(count($events) === 1 && (int) $events[0]['id'] === 55
        && $events[0]['event_type'] === 'cancel' && $events[0]['event_status'] === 'posted'
        && $events[0]['currency'] === 'AED' && $near($events[0]['customer_refund_amount'], 0)
        && $near($events[0]['supplier_refund_amount'], 0)
        && $near($events[0]['supplier_credit_amount'], 0), 'Cancellation history changed');
    $payload = json_decode((string) $events[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $check(is_array($payload), 'Cancellation payload is invalid');

    if ($prior !== []) {
        $check(count($prior) === 1 && count($receivable) === 1 && count($obligation) === 1
            && $receivable[0]['currency'] === 'AED' && $near($receivable[0]['due_amount'], 600)
            && $near($receivable[0]['outstanding_amount'], 50)
            && $obligation[0]['currency'] === 'PKR' && $near($obligation[0]['gross_amount'], 35700)
            && $near($obligation[0]['net_payable_amount'], 35700)
            && $near($balance('AED', 'AR_CONTROL'), 50)
            && $near($balance('PKR', 'AP_CONTROL'), -35700), 'A prior repair exists but balances differ');
        $db->rollBack();
        echo "ALREADY APPLIED: BK-000064 AED customer and PKR supplier positions reconcile.\n";
        exit(0);
    }

    $check(count($receivable) === 1 && $receivable[0]['currency'] === 'AED'
        && $near($receivable[0]['due_amount'], 35080)
        && $near($receivable[0]['allocated_amount'], 550)
        && $near($receivable[0]['outstanding_amount'], 34530), 'Customer receivable changed');
    $check(count($obligation) === 1 && (int) $obligation[0]['supplier_id'] === 413
        && (int) $obligation[0]['branch_id'] === 2 && $obligation[0]['currency'] === 'AED'
        && $near($obligation[0]['gross_amount'], 35000)
        && $near($obligation[0]['advance_applied_amount'], 0)
        && $near($obligation[0]['net_payable_amount'], 35000), 'Supplier payable changed');
    $check(count($journals) === 4
        && (int) $journals[0]['id'] === 4953 && $journals[0]['currency'] === 'AED'
        && $journals[0]['code'] === 'AR_CONTROL' && $near($journals[0]['debit_amount'], 34480)
        && $journals[1]['code'] === 'SERVICE_REVENUE' && $near($journals[1]['credit_amount'], 34480)
        && (int) $journals[2]['id'] === 4954 && $journals[2]['currency'] === 'AED'
        && $journals[2]['code'] === 'SERVICE_COST' && $near($journals[2]['credit_amount'], 4745)
        && $journals[3]['code'] === 'AP_CONTROL' && $near($journals[3]['debit_amount'], 4745),
        'Historical journals changed');
    $check($near($payload['supplier_cost_basis'] ?? -1, 39745)
        && $near($payload['supplier_penalty_source_amount'] ?? -1, 35000)
        && ($payload['supplier_penalty_currency'] ?? '') === 'PKR'
        && $near($payload['expected_supplier_refund_source_amount'] ?? -1, 4745)
        && ($payload['expected_supplier_refund_currency'] ?? '') === 'PKR'
        && $near($payload['agency_fee_retained_amount'] ?? -1, 80)
        && $near($payload['prior_customer_due_amount'] ?? -1, 600)
        && $near($balance('AED', 'AR_CONTROL'), 34530)
        && $near($balance('AED', 'AP_CONTROL'), 4745)
        && $near($balance('PKR', 'AP_CONTROL'), -39745), 'Payload or control balances changed');

    $snapshot = compact('booking', 'service', 'receivable', 'obligation', 'events', 'receipts',
        'allocations', 'supplierAllocations', 'advanceApplications', 'journals');
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'booking_reference' => 'BK-000064', 'fingerprint' => $fingerprint,
        'customer_before' => 'AED 35,080 due / AED 34,530 outstanding',
        'customer_after' => 'AED 600 original invoice / AED 50 outstanding until customer cancellation settlement is confirmed',
        'supplier_before' => 'incorrect AED 35,000 payable; PKR 39,745 control',
        'supplier_after' => 'PKR 35,700 payable after confirmed PKR 4,045 reduction',
        'customer_cash_changed' => false, 'supplier_cash_created' => false,
    ];
    if (! $apply && ! $rehearse) {
        $db->rollBack();
        echo json_encode(['mode' => 'preview'] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $actor = (int) ($options['actor'] ?? 0);
    $check($actor > 0 && $query('SELECT id FROM users WHERE id = ?', [$actor]) !== []
        && hash_equals($fingerprint, strtolower((string) ($options['fingerprint'] ?? ''))),
        'An active actor and exact preview fingerprint are required');

    $accounting = new AccountingRepository($app);
    $reversals = [];
    foreach ([4953, 4954] as $journalId) {
        $reversals[] = $accounting->reverseJournalEntry($journalId, [
            'source_type' => 'bk000064_currency_reversal',
            'source_reference' => 'REV-JE-' . $journalId,
            'entry_date' => date('Y-m-d'),
            'narration' => 'Reverse mixed-currency cancellation posting on BK-000064',
            'actor_user_id' => $actor,
        ]);
    }
    $check(count(array_filter($reversals)) === 2, 'Wrong-currency journal reversal failed');
    $supplierJournal = $accounting->postJournalEntry([
        'branch_id' => 2, 'booking_reference' => 'BK-000064',
        'source_type' => 'bk000064_supplier_refund_correction',
        'source_reference' => 'EVENT-55-PKR-4045', 'entry_date' => date('Y-m-d'),
        'currency' => 'PKR', 'narration' => 'Supplier payable reduced by confirmed PKR 4,045; no supplier cash recorded',
        'actor_user_id' => $actor,
    ], [
        ['account_code' => 'AP_CONTROL', 'service_line_reference' => 'SV-001', 'supplier_obligation_id' => 64,
            'line_description' => 'Supplier payable reduction in actual cost currency', 'debit_amount' => 4045, 'credit_amount' => 0],
        ['account_code' => 'SERVICE_COST', 'service_line_reference' => 'SV-001', 'supplier_obligation_id' => 64,
            'line_description' => 'Supplier cost recovery in actual cost currency', 'debit_amount' => 0, 'credit_amount' => 4045],
    ]);
    $statement = $db->prepare('UPDATE customer_receivable_items SET due_amount = 600,
        outstanding_amount = 50, status = "partially_paid" WHERE id = 64 AND due_amount = 35080
        AND allocated_amount = 550 AND outstanding_amount = 34530');
    $statement->execute();
    $check($statement->rowCount() === 1, 'Customer due update failed');
    $statement = $db->prepare('UPDATE supplier_obligations SET currency = "PKR", gross_amount = 35700,
        net_payable_amount = 35700, remarks = ? WHERE id = 64 AND currency = "AED"
        AND gross_amount = 35000 AND advance_applied_amount = 0 AND net_payable_amount = 35000');
    $statement->execute(['Historical currency repair: PKR 39,745 cost less confirmed PKR 4,045 supplier reduction; no payment allocated.']);
    $check($statement->rowCount() === 1, 'Supplier payable update failed');

    $beforePayload = $payload;
    $payload['currency_correction'] = [
        'original_cancellation_payload' => $beforePayload,
        'confirmed_supplier_refund_currency' => 'PKR',
        'confirmed_supplier_refund_amount' => 4045,
        'customer_cancellation_settlement_pending' => true,
        'repair_journal_ids' => array_merge($reversals, [$supplierJournal]),
    ];
    $payload['settlement_currency'] = 'AED';
    $payload['customer_refund_basis_currency'] = 'AED';
    $payload['customer_final_charge_amount'] = 600;
    $payload['customer_delta'] = 0;
    $payload['expected_supplier_refund_amount'] = 4045;
    $payload['expected_supplier_refund_source_amount'] = 4045;
    $payload['supplier_penalty_amount'] = 35700;
    $payload['supplier_penalty_source_amount'] = 35700;
    $payload['supplier_delta'] = -4045;
    $statement = $db->prepare('UPDATE booking_service_events SET payload_json = ? WHERE id = 55 AND payload_json = ?');
    $statement->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $events[0]['payload_json']]);
    $check($statement->rowCount() === 1, 'Cancellation event correction failed');
    $check($near($balance('AED', 'AR_CONTROL'), 50)
        && $near($balance('AED', 'AP_CONTROL'), 0)
        && $near($balance('PKR', 'AP_CONTROL'), -35700)
        && $near($balance('PKR', 'SERVICE_COST'), 35700), 'Control accounts do not reconcile');
    $check($receipts === $query('SELECT id, currency, received_amount, allocated_amount, returned_amount,
        unallocated_amount FROM customer_receipts WHERE booking_reference = ? ORDER BY id', ['BK-000064']),
        'Customer cash changed unexpectedly');
    AuditLog::recordRequired($app, 'service.bk000064.currency_truth.reconciled', [
        'user_id' => $actor, 'booking_reference' => 'BK-000064', 'fingerprint' => $fingerprint,
        'before' => ['receivable' => $receivable[0], 'obligation' => $obligation[0], 'event_payload' => $beforePayload],
        'after' => ['customer_due_AED' => 600, 'customer_outstanding_AED' => 50,
            'supplier_payable_PKR' => 35700, 'supplier_reduction_PKR' => 4045],
        'journal_ids' => array_merge($reversals, [$supplierJournal]),
        'no_cash_movement' => true, 'customer_cancellation_settlement_pending' => true,
    ]);
    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back',
        'journal_ids' => array_merge($reversals, [$supplierJournal])] + $plan,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'No changes committed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
