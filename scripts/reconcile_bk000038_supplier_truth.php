<?php

declare(strict_types=1);

// One-time, guarded correction of the historical PKR supplier-side denomination.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\SupplierRepository;

foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, "Usage: php scripts/reconcile_bk000038_supplier_truth.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n");
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
if (isset($options['rehearse'], $options['apply'])) {
    throw new RuntimeException('Choose --rehearse or --apply, not both.');
}
$mode = isset($options['apply']) ? 'apply' : (isset($options['rehearse']) ? 'rehearse' : 'preview');
$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$read = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$execute = static function (string $sql, array $parameters) use ($db): void {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Guarded update matched an unexpected number of rows.');
    }
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message . ' Nothing was committed.');
    }
};
$is = static fn (mixed $amount, float $expected): bool => abs((float) $amount - $expected) < .005;

$db->beginTransaction();
try {
    $service = $read('SELECT id, supplier_id, cost_currency, purchase_cost FROM booking_services WHERE id = 38 FOR UPDATE');
    $cancel = $read('SELECT id, booking_reference, event_status, currency, payload_json FROM booking_service_events WHERE id = 1 FOR UPDATE');
    $refund = $read('SELECT id, booking_reference, event_status, currency, supplier_refund_amount, payload_json FROM booking_service_events WHERE id = 119 FOR UPDATE');
    $refundDetail = $read('SELECT * FROM booking_service_refund_details WHERE service_event_id = 119 FOR UPDATE');
    $obligation = $read('SELECT * FROM supplier_obligations WHERE id = 38 FOR UPDATE');
    $allocation = $read('SELECT * FROM supplier_payment_allocations WHERE supplier_obligation_id = 38 FOR UPDATE');
    $pkrPayment = $read('SELECT * FROM supplier_payments WHERE id = 180 FOR UPDATE');
    $falsePayment = $read('SELECT * FROM supplier_payments WHERE id = 8 FOR UPDATE');
    $oldAdvance = $read('SELECT * FROM supplier_advances WHERE id = 2 FOR UPDATE');
    $convertedAdvance = $read('SELECT * FROM supplier_advances WHERE id = 48 FOR UPDATE');
    $advanceUse = $read('SELECT * FROM supplier_advance_applications WHERE supplier_advance_id = 48 FOR UPDATE');
    $falseJournal = $read('SELECT je.id, je.booking_reference, je.currency, je.source_reference, coa.code,
            jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = 1938 ORDER BY jel.id FOR UPDATE');
    $priorRepair = $read('SELECT id FROM journal_entries WHERE source_reference = ? FOR UPDATE', ['BK38-PKR-SUPPLIER-REPAIR']);
    $require($priorRepair === [], 'This correction already has a posted journal');
    $require(count($service) === 1 && (int) $service[0]['supplier_id'] === 413
        && $service[0]['cost_currency'] === 'PKR' && $is($service[0]['purchase_cost'], 90942),
        'Booking supplier cost changed');
    $cancelPayload = count($cancel) === 1 ? json_decode((string) $cancel[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
    $refundPayload = count($refund) === 1 ? json_decode((string) $refund[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
    $require(count($cancel) === 1 && $cancel[0]['booking_reference'] === 'BK-000038'
        && $cancel[0]['event_status'] === 'posted' && $cancel[0]['currency'] === 'AED'
        && ($cancelPayload['expected_supplier_refund_currency'] ?? '') === 'PKR'
        && $is($cancelPayload['expected_supplier_refund_source_amount'] ?? 0, 50242)
        && $is($cancelPayload['expected_supplier_refund_equivalent_amount'] ?? 0, 653)
        && ! isset($cancelPayload['supplier_penalty_source_amount']),
        'Cancellation source facts changed');
    $require(count($refund) === 1 && $refund[0]['booking_reference'] === 'BK-000038'
        && $refund[0]['event_status'] === 'posted' && $refund[0]['currency'] === 'AED'
        && $is($refund[0]['supplier_refund_amount'], 653)
        && ($refundPayload['supplier_source_correction']['source_currency'] ?? '') === 'PKR'
        && $is($refundPayload['supplier_source_correction']['source_amount'] ?? 0, 50242),
        'Supplier refund source correction changed');
    $require(count($refundDetail) === 1 && $refundDetail[0]['refund_currency'] === 'AED'
        && $is($refundDetail[0]['refund_source_amount'], 653), 'Structured refund detail changed');
    $require(count($obligation) === 1 && $obligation[0]['booking_reference'] === 'BK-000038'
        && $obligation[0]['currency'] === 'PKR' && $is($obligation[0]['gross_amount'], 90942)
        && $is($obligation[0]['net_payable_amount'], 0), 'Supplier obligation changed');
    $require(count($allocation) === 1 && (int) $allocation[0]['supplier_payment_id'] === 180
        && $is($allocation[0]['allocated_amount'], 90942), 'Original PKR allocation changed');
    $require(count($pkrPayment) === 1 && $pkrPayment[0]['currency'] === 'PKR'
        && $pkrPayment[0]['payment_scope'] === 'global' && $pkrPayment[0]['status'] !== 'void'
        && $is($pkrPayment[0]['paid_amount'], 500000) && $is($pkrPayment[0]['allocated_amount'], 500000)
        && $is($pkrPayment[0]['unallocated_amount'], 0), 'Original PKR payment changed');
    $require(count($falsePayment) === 1 && $falsePayment[0]['booking_reference'] === 'BK-000038'
        && $falsePayment[0]['currency'] === 'AED' && $falsePayment[0]['status'] !== 'void'
        && $is($falsePayment[0]['paid_amount'], 1188)
        && $is($falsePayment[0]['converted_advance_amount'], 1188)
        && $is($falsePayment[0]['allocated_amount'], 0), 'Incorrect AED payment changed');
    $require(count($oldAdvance) === 1 && (int) $oldAdvance[0]['source_supplier_payment_id'] === 8
        && $is($oldAdvance[0]['deposit_amount'], 653) && $is($oldAdvance[0]['available_amount'], 0)
        && count($convertedAdvance) === 1 && (int) $convertedAdvance[0]['source_supplier_payment_id'] === 8
        && $is($convertedAdvance[0]['deposit_amount'], 535) && $is($convertedAdvance[0]['available_amount'], 0)
        && count($advanceUse) === 1 && $is($advanceUse[0]['applied_amount'], 535),
        'Historical supplier advances changed');
    $require(count($falseJournal) === 2 && $falseJournal[0]['source_reference'] === 'DATAFIX-BK000038-SPAY'
        && $falseJournal[0]['code'] === 'SUPPLIER_ADVANCES'
        && $is($falseJournal[0]['debit_amount'], 1188)
        && str_starts_with($falseJournal[1]['code'], 'CASH_')
        && $is($falseJournal[1]['credit_amount'], 1188), 'Incorrect cash journal changed');

    $snapshot = [$service, $cancel, $refund, $refundDetail, $obligation, $allocation,
        $pkrPayment, $falsePayment, $oldAdvance, $convertedAdvance, $advanceUse, $falseJournal];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'fingerprint' => $fingerprint,
        'supplier_cost_pkr' => 90942,
        'supplier_retained_pkr' => 40700,
        'supplier_credit_pkr' => 50242,
        'supplier_credit_available_pkr' => 50242,
        'supplier_fx_posted' => false,
        'separate_historical_aed_advance_source' => 'unverified; no value attributed to BK-000038',
        'false_aed_cash_journal_to_reverse' => 1938,
        'new_cash_movements' => 0,
    ];
    if ($mode === 'preview') {
        $db->rollBack();
        echo json_encode(['mode' => $mode] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $actor = (int) ($options['actor'] ?? 0);
    $require($actor > 0 && $read('SELECT id FROM users WHERE id = ?', [$actor]) !== []
        && hash_equals($fingerprint, strtolower((string) ($options['fingerprint'] ?? ''))),
        'A valid actor and current preview fingerprint are required');

    $suppliers = new SupplierRepository($app);
    $accounting = new AccountingRepository($app);
    $release = $suppliers->releaseSettledCreditForObligation(38, 40700, $actor,
        'Correct supplier cancellation settlement to PKR 40,700 retained');
    $require($is($release['released_payment_amount'] ?? 0, 50242)
        && ($release['affected_payment_ids'] ?? []) === [180], 'PKR payment release was incomplete');
    $accounting->postSupplierSettlementRelease([
        'branch_id' => 2, 'booking_reference' => 'BK-000038', 'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => 38, 'released_amount' => 50242,
        'source_reference' => 'BK38-PKR-SUPPLIER-REPAIR', 'entry_date' => date('Y-m-d'),
        'currency' => 'PKR', 'actor_user_id' => $actor,
        'narration' => 'Historical correction: release refundable PKR supplier payment to advance pool',
    ]);
    $sync = $suppliers->syncObligation([
        'supplier_id' => 413, 'branch_id' => 2, 'booking_reference' => 'BK-000038',
        'service_line_reference' => 'SV-001', 'obligation_group' => 'service_cost',
        'currency' => 'PKR', 'gross_amount' => 40700, 'actor_user_id' => $actor,
        'remarks' => 'Supplier retained PKR 40,700 after cancellation; PKR 50,242 returned as supplier credit',
    ]);
    $require($is($sync['delta_amount'] ?? 0, -50242), 'Supplier cost adjustment was not PKR 50,242');
    $accounting->postPayableAdjusted([
        'branch_id' => 2, 'booking_reference' => 'BK-000038', 'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => 38, 'adjustment_amount' => -50242,
        'entry_date' => date('Y-m-d'), 'currency' => 'PKR', 'actor_user_id' => $actor,
        'narration' => 'Cancellation supplier penalty adjustment: correct retained supplier cost in PKR',
    ]);
    $conversion = $suppliers->convertRefundablePaymentCreditToAdvance(
        413, 2, 'BK-000038', 'PKR', 50242, date('Y-m-d'),
        'SUPPLIER-POOL-PKR-REFUND-' . (string) $refund[0]['id'], $actor,
        'Historical PKR supplier refund retained as reusable supplier credit', [180], true
    );
    $require($is($conversion['converted_payment_credit_amount'] ?? 0, 50242)
        && count($conversion['supplier_advance_ids'] ?? []) === 1, 'PKR supplier advance was not fully backed');
    $newAdvanceId = (int) $conversion['supplier_advance_ids'][0];
    $rate = round(50242 / 653, 8);
    $reversalId = $accounting->reverseJournalEntry(1938, [
        'source_type' => 'data_integrity_reversal', 'source_reference' => 'VOID-FALSE-BK38-AED-SUPPLIER-CASH',
        'entry_date' => date('Y-m-d'), 'actor_user_id' => $actor,
        'narration' => 'Reverse historical supplier cash entry that did not occur',
    ]);
    $require($reversalId !== null, 'False cash journal could not be reversed');
    $execute('UPDATE supplier_payments SET status = "void", void_reason = ?, voided_by_user_id = ?,
        voided_at = NOW(), reversal_reference = ?, reversal_journal_entry_id = ?
        WHERE id = 8 AND status = "fully_allocated"',
        ['Historical AED supplier cash entry was incorrect; actual supplier payment was PKR', $actor,
            'VOID-FALSE-BK38-AED-SUPPLIER-CASH', $reversalId]);
    $execute('UPDATE supplier_advances SET deposit_amount = 0, remarks = ?, updated_by_user_id = ?
        WHERE id = 2 AND deposit_amount = 653 AND available_amount = 0',
        ['Voided unused AED advance from incorrect historical supplier cash entry', $actor]);
    $execute('UPDATE supplier_advances SET source_supplier_payment_id = NULL, reference_no = ?,
        remarks = ?, updated_by_user_id = ? WHERE id = 48 AND source_supplier_payment_id = 8
        AND deposit_amount = 535 AND available_amount = 0',
        ['UNVERIFIED-HISTORICAL-AED-ADV-48',
            'Historical supplier advance use retained for separate source review; not funded by BK-000038 customer equivalent', $actor]);

    $cancelPayload['supplier_cost_basis'] = 90942;
    $cancelPayload['supplier_penalty_currency'] = 'PKR';
    $cancelPayload['supplier_penalty_source_amount'] = 40700;
    $cancelPayload['supplier_truth_reconciliation'] = [
        'source_currency' => 'PKR', 'supplier_cost' => 90942,
        'supplier_retained' => 40700, 'supplier_credit' => 50242,
        'customer_equivalent_aed' => 653, 'corrected_at' => date('c'), 'actor_user_id' => $actor,
    ];
    $execute('UPDATE booking_service_events SET payload_json = ? WHERE id = 1 AND payload_json = ?',
        [json_encode($cancelPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $cancel[0]['payload_json']]);
    $execute('UPDATE booking_service_refund_details SET refund_currency = "PKR", refund_source_amount = 50242,
        refund_exchange_rate_to_booking = ?, updated_by_user_id = ?
        WHERE id = ? AND refund_currency = "AED" AND refund_source_amount = 653',
        [$rate, $actor, $refundDetail[0]['id']]);
    AuditLog::recordRequired($app, 'supplier.bk000038.full_source_reconciliation', [
        'user_id' => $actor, 'booking_reference' => 'BK-000038', 'fingerprint' => $fingerprint,
        'supplier_cost_currency' => 'PKR', 'supplier_cost_amount' => 90942,
        'supplier_retained_amount' => 40700, 'supplier_credit_amount' => 50242,
        'supplier_advance_id' => $newAdvanceId,
        'supplier_pool_fx_posted' => false,
        'separate_historical_aed_advance_id' => 48,
        'separate_historical_aed_advance_source' => 'unverified; not attributed to BK-000038',
        'void_supplier_payment_id' => 8, 'reversed_cash_journal_id' => 1938,
        'cash_reversal_journal_id' => $reversalId,
        'customer_refund_unchanged' => true,
    ]);
    $require($is($suppliers->findObligationById(38)['gross_amount'] ?? 0, 40700)
        && $is($suppliers->findAdvanceById($newAdvanceId)['available_amount'] ?? 0, 50242),
        'Post-repair supplier balances do not reconcile');

    if ($mode === 'apply') {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $mode, 'new_supplier_advance_id' => $newAdvanceId] + $plan,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'No changes committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
