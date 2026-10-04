<?php

declare(strict_types=1);

// Guarded repair for the pre-credit production state of BK-000038 only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BookingServiceEventRepository;
use App\Repositories\BookingServiceRefundDetailRepository;
use App\Repositories\SupplierRepository;

foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, "Usage: php scripts/reconcile_bk000038_supplier_precredit.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n");
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
$executeOne = static function (string $sql, array $parameters) use ($db): void {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('A guarded update matched an unexpected number of rows.');
    }
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message . ' Nothing was committed.');
    }
};
$near = static fn (mixed $amount, float $expected): bool => abs((float) $amount - $expected) < .005;

$db->beginTransaction();
try {
    $service = $read('SELECT id, booking_id, branch_id, supplier_id, line_reference,
        cost_currency, purchase_cost FROM booking_services WHERE id = 38 FOR UPDATE');
    $cancel = $read('SELECT id, booking_id, booking_reference, event_status, currency, payload_json
        FROM booking_service_events WHERE id = 1 FOR UPDATE');
    $customerRefund = $read('SELECT id, booking_reference, event_status, currency,
        customer_refund_amount FROM booking_service_events WHERE id = 47 FOR UPDATE');
    $postedSupplierRefunds = $read('SELECT id FROM booking_service_events
        WHERE booking_service_id = 38 AND event_type = "refund" AND event_status = "posted"
          AND supplier_refund_amount > 0.005 FOR UPDATE');
    $obligation = $read('SELECT id, supplier_id, branch_id, booking_reference, currency,
        gross_amount, advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = 38 FOR UPDATE');
    $allocation = $read('SELECT id, supplier_payment_id, allocated_amount
        FROM supplier_payment_allocations WHERE supplier_obligation_id = 38 FOR UPDATE');
    $pkrPayment = $read('SELECT id, supplier_id, branch_id, currency, payment_scope, status,
        paid_amount, allocated_amount, unallocated_amount, converted_advance_amount
        FROM supplier_payments WHERE id = 180 FOR UPDATE');
    $falsePayment = $read('SELECT id, supplier_id, branch_id, booking_reference, currency,
        status, paid_amount, allocated_amount, unallocated_amount, returned_amount,
        converted_advance_amount, converted_advance_id, reversal_journal_entry_id
        FROM supplier_payments WHERE id = 8 FOR UPDATE');
    $falseAllocations = $read('SELECT id FROM supplier_payment_allocations
        WHERE supplier_payment_id = 8 FOR UPDATE');
    $falseAdvances = $read('SELECT id, supplier_id, branch_id, currency, deposit_amount,
        available_amount, source_supplier_payment_id, reference_no, remarks
        FROM supplier_advances WHERE source_supplier_payment_id = 8 FOR UPDATE');
    $advanceUse = $read('SELECT id FROM supplier_advance_applications
        WHERE supplier_advance_id = 2 FOR UPDATE');
    $advanceCorrections = $read('SELECT id FROM supplier_advance_corrections
        WHERE supplier_advance_id = 2 FOR UPDATE');
    $falseJournal = $read('SELECT je.id, je.source_reference, je.currency, coa.code,
        jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = 1938 ORDER BY jel.id FOR UPDATE');
    $priorRepair = $read('SELECT id FROM journal_entries
        WHERE source_reference = ? FOR UPDATE', ['BK38-PKR-SUPPLIER-REPAIR']);
    $priorReversal = $read('SELECT id FROM journal_entries
        WHERE source_reference = ? FOR UPDATE', ['VOID-FALSE-BK38-AED-SUPPLIER-CASH']);

    $require($priorRepair === [] && $priorReversal === [], 'The supplier repair or cash reversal already exists');
    $require(count($service) === 1 && (int) $service[0]['id'] === 38
        && (int) $service[0]['supplier_id'] === 413 && (int) $service[0]['branch_id'] === 2
        && $service[0]['line_reference'] === 'SV-001'
        && $service[0]['cost_currency'] === 'PKR' && $near($service[0]['purchase_cost'], 90942),
        'Booking supplier cost differs');
    $cancelPayload = count($cancel) === 1
        ? json_decode((string) $cancel[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR) : [];
    $require(count($cancel) === 1 && (int) $cancel[0]['booking_id'] === (int) $service[0]['booking_id']
        && $cancel[0]['booking_reference'] === 'BK-000038'
        && $cancel[0]['event_status'] === 'posted' && $cancel[0]['currency'] === 'AED'
        && ($cancelPayload['expected_supplier_refund_currency'] ?? '') === 'PKR'
        && $near($cancelPayload['expected_supplier_refund_source_amount'] ?? 0, 50242)
        && $near($cancelPayload['expected_supplier_refund_equivalent_amount'] ?? 0, 653)
        && ! isset($cancelPayload['supplier_penalty_source_amount']),
        'Saved cancellation facts differ');
    $require(count($customerRefund) === 1 && $customerRefund[0]['booking_reference'] === 'BK-000038'
        && $customerRefund[0]['event_status'] === 'posted'
        && $customerRefund[0]['currency'] === 'AED'
        && $near($customerRefund[0]['customer_refund_amount'], 600)
        && $postedSupplierRefunds === [], 'A customer refund or supplier credit event changed');
    $require(count($obligation) === 1 && (int) $obligation[0]['supplier_id'] === 413
        && (int) $obligation[0]['branch_id'] === 2
        && $obligation[0]['booking_reference'] === 'BK-000038'
        && $obligation[0]['currency'] === 'PKR'
        && $near($obligation[0]['gross_amount'], 90942)
        && $near($obligation[0]['advance_applied_amount'], 0)
        && $near($obligation[0]['net_payable_amount'], 0)
        && $obligation[0]['status'] === 'paid', 'PKR supplier obligation changed');
    $require(count($allocation) === 1 && (int) $allocation[0]['supplier_payment_id'] === 180
        && $near($allocation[0]['allocated_amount'], 90942), 'PKR payment allocation changed');
    $require(count($pkrPayment) === 1 && (int) $pkrPayment[0]['supplier_id'] === 413
        && $pkrPayment[0]['currency'] === 'PKR' && $pkrPayment[0]['payment_scope'] === 'global'
        && $pkrPayment[0]['status'] === 'fully_allocated'
        && $near($pkrPayment[0]['paid_amount'], 500000)
        && $near($pkrPayment[0]['allocated_amount'], 500000)
        && $near($pkrPayment[0]['unallocated_amount'], 0)
        && $near($pkrPayment[0]['converted_advance_amount'], 0), 'PKR source payment changed');
    $require(count($falsePayment) === 1 && (int) $falsePayment[0]['supplier_id'] === 413
        && (int) $falsePayment[0]['branch_id'] === 2
        && $falsePayment[0]['booking_reference'] === 'BK-000038'
        && $falsePayment[0]['currency'] === 'AED' && $falsePayment[0]['status'] === 'paid'
        && $near($falsePayment[0]['paid_amount'], 1188)
        && $near($falsePayment[0]['allocated_amount'], 0)
        && $near($falsePayment[0]['unallocated_amount'], 535)
        && $near($falsePayment[0]['returned_amount'], 0)
        && $near($falsePayment[0]['converted_advance_amount'], 653)
        && (int) $falsePayment[0]['converted_advance_id'] === 2
        && $falsePayment[0]['reversal_journal_entry_id'] === null
        && $falseAllocations === [], 'Historical AED supplier payment changed');
    $require(count($falseAdvances) === 1 && (int) $falseAdvances[0]['id'] === 2
        && (int) $falseAdvances[0]['supplier_id'] === 413
        && (int) $falseAdvances[0]['branch_id'] === 2
        && $falseAdvances[0]['currency'] === 'AED'
        && $near($falseAdvances[0]['deposit_amount'], 653)
        && $near($falseAdvances[0]['available_amount'], 0)
        && $advanceUse === [] && $advanceCorrections === [],
        'Historical AED advance has changed or been used');
    $require(count($falseJournal) === 2
        && $falseJournal[0]['source_reference'] === 'DATAFIX-BK000038-SPAY'
        && $falseJournal[0]['currency'] === 'AED'
        && $falseJournal[0]['code'] === 'SUPPLIER_ADVANCES'
        && $near($falseJournal[0]['debit_amount'], 1188)
        && str_starts_with((string) $falseJournal[1]['code'], 'CASH_')
        && $near($falseJournal[1]['credit_amount'], 1188),
        'Historical AED cash journal changed');

    $snapshot = [$service, $cancel, $customerRefund, $postedSupplierRefunds, $obligation,
        $allocation, $pkrPayment, $falsePayment, $falseAllocations, $falseAdvances,
        $advanceUse, $advanceCorrections, $falseJournal];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $plan = [
        'fingerprint' => $fingerprint,
        'supplier_paid_pkr' => 90942,
        'supplier_retained_pkr' => 40700,
        'supplier_credit_pkr' => 50242,
        'customer_equivalent_reference_aed' => 653,
        'customer_refund_unchanged_aed' => 600,
        'incorrect_aed_cash_journal_to_reverse' => 1938,
        'new_cash_movements' => 0,
        'supplier_fx_conversion' => false,
    ];
    if ($mode === 'preview') {
        $db->rollBack();
        echo json_encode(['mode' => $mode] + $plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $actor = (int) ($options['actor'] ?? 0);
    $verifiedActor = $read('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.is_active = 1 AND r.code = "super_admin"', [$actor]);
    $require(count($verifiedActor) === 1
        && hash_equals($fingerprint, strtolower((string) ($options['fingerprint'] ?? ''))),
        'An active super admin and the current preview fingerprint are required');

    $supplierRepository = new SupplierRepository($app);
    $accounting = new AccountingRepository($app);
    $eventRepository = new BookingServiceEventRepository($app);
    $detailRepository = new BookingServiceRefundDetailRepository($app);
    $release = $supplierRepository->releaseSettledCreditForObligation(38, 40700, $actor,
        'Correct BK-000038 supplier settlement to PKR 40,700 retained');
    $require($near($release['released_payment_amount'] ?? 0, 50242)
        && ($release['affected_payment_ids'] ?? []) === [180], 'PKR payment release was incomplete');
    $accounting->postSupplierSettlementRelease([
        'branch_id' => 2, 'booking_reference' => 'BK-000038', 'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => 38, 'released_amount' => 50242,
        'source_reference' => 'BK38-PKR-SUPPLIER-REPAIR', 'entry_date' => date('Y-m-d'),
        'currency' => 'PKR', 'actor_user_id' => $actor,
        'narration' => 'Release refundable PKR supplier payment to supplier credit',
    ]);
    $sync = $supplierRepository->syncObligation([
        'supplier_id' => 413, 'branch_id' => 2, 'booking_reference' => 'BK-000038',
        'service_line_reference' => 'SV-001', 'obligation_group' => 'service_cost',
        'currency' => 'PKR', 'gross_amount' => 40700, 'actor_user_id' => $actor,
        'remarks' => 'Supplier retained PKR 40,700; PKR 50,242 returned as reusable supplier credit',
    ]);
    $require($near($sync['delta_amount'] ?? 0, -50242), 'Supplier cost adjustment was incomplete');
    $accounting->postPayableAdjusted([
        'branch_id' => 2, 'booking_reference' => 'BK-000038', 'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => 38, 'adjustment_amount' => -50242,
        'entry_date' => date('Y-m-d'), 'currency' => 'PKR', 'actor_user_id' => $actor,
        'narration' => 'Cancellation supplier penalty adjustment: correct retained supplier cost in PKR',
    ]);

    $refundDetail = [
        'refund_payment_method' => 'supplier_credit',
        'refund_currency' => 'PKR',
        'refund_source_amount' => 50242,
        'refund_booking_equivalent' => 653,
        'refund_exchange_rate_to_booking' => null,
    ];
    $eventId = $eventRepository->createEvent([
        'branch_id' => 2, 'booking_id' => (int) $service[0]['booking_id'],
        'booking_service_id' => 38, 'booking_reference' => 'BK-000038',
        'service_line_reference' => 'SV-001', 'event_type' => 'refund',
        'event_status' => 'posted', 'event_date' => date('Y-m-d'),
        'currency' => 'AED', 'customer_refund_amount' => 0,
        'supplier_refund_amount' => 653,
        'reason' => 'Historical PKR supplier refund retained as supplier credit',
        'payload_json' => [
            'refund_scope' => 'supplier',
            'supplier_refund_payment_method' => 'supplier_credit',
            'supplier_refund_detail' => $refundDetail,
            'refund_detail' => $refundDetail,
            'customer_equivalent_reference_only' => true,
        ],
        'actor_user_id' => $actor,
    ]);
    $require($eventId > 0, 'Supplier refund event was not created');
    $detailRepository->upsertForServiceEvent($eventId, $refundDetail + ['actor_user_id' => $actor]);
    $conversion = $supplierRepository->convertRefundablePaymentCreditToAdvance(
        413, 2, 'BK-000038', 'PKR', 50242, date('Y-m-d'),
        'SUPPLIER-POOL-PKR-REFUND-' . $eventId, $actor,
        'PKR supplier refund retained as reusable supplier credit', [180], true
    );
    $require($near($conversion['converted_payment_credit_amount'] ?? 0, 50242)
        && count($conversion['supplier_advance_ids'] ?? []) === 1,
        'PKR supplier credit was not fully backed');
    $newAdvanceId = (int) $conversion['supplier_advance_ids'][0];
    $conversion['currency'] = 'PKR';
    $conversion['booking_equivalent_amount'] = 653;
    $conversion['automatic_applications'] = [];
    $conversion['automatic_applied_amount'] = 0;
    $eventRepository->updateRefundFinancials($eventId, [
        'payload_patch' => ['retained_credit_conversion' => $conversion],
    ]);

    $cashReversalId = $accounting->reverseJournalEntry(1938, [
        'source_type' => 'data_integrity_reversal',
        'source_reference' => 'VOID-FALSE-BK38-AED-SUPPLIER-CASH',
        'entry_date' => date('Y-m-d'), 'actor_user_id' => $actor,
        'narration' => 'Reverse historical AED supplier cash entry that did not occur',
    ]);
    $require($cashReversalId !== null, 'False AED cash journal was not reversed');
    $supplierRepository->voidSupplierPayment(8,
        'Historical AED supplier payment was incorrect; actual supplier payment was PKR',
        $actor, 'VOID-FALSE-BK38-AED-SUPPLIER-CASH', $cashReversalId);
    $executeOne('UPDATE supplier_advances SET deposit_amount = 0, remarks = ?, updated_by_user_id = ?
        WHERE id = 2 AND source_supplier_payment_id = 8
          AND deposit_amount = 653 AND available_amount = 0',
        ['Neutralized historical AED advance from the incorrect supplier payment', $actor]);

    $cancelPayload['supplier_cost_basis'] = 90942;
    $cancelPayload['supplier_penalty_currency'] = 'PKR';
    $cancelPayload['supplier_penalty_source_amount'] = 40700;
    $cancelPayload['supplier_truth_reconciliation'] = [
        'source_currency' => 'PKR', 'supplier_cost' => 90942,
        'supplier_retained' => 40700, 'supplier_credit' => 50242,
        'customer_equivalent_reference_aed' => 653,
        'corrected_at' => date('c'), 'actor_user_id' => $actor,
    ];
    $executeOne('UPDATE booking_service_events SET payload_json = ?
        WHERE id = 1 AND payload_json = ?',
        [json_encode($cancelPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $cancel[0]['payload_json']]);

    AuditLog::recordRequired($app, 'supplier.bk000038.precredit_reconciliation', [
        'user_id' => $actor, 'booking_reference' => 'BK-000038',
        'fingerprint' => $fingerprint, 'supplier_cost_currency' => 'PKR',
        'supplier_cost_amount' => 90942, 'supplier_retained_amount' => 40700,
        'supplier_credit_amount' => 50242, 'supplier_refund_event_id' => $eventId,
        'supplier_advance_id' => $newAdvanceId,
        'void_supplier_payment_id' => 8, 'false_cash_journal_id' => 1938,
        'cash_reversal_journal_id' => $cashReversalId,
        'historical_aed_advance_id' => 2,
        'supplier_fx_posted' => false, 'new_cash_movement' => false,
        'customer_refund_event_id_unchanged' => 47,
    ]);
    $require($near($supplierRepository->findObligationById(38)['gross_amount'] ?? 0, 40700)
        && $near($supplierRepository->findAdvanceById($newAdvanceId)['available_amount'] ?? 0, 50242)
        && count($read('SELECT id FROM booking_service_refund_details
            WHERE service_event_id = ? AND refund_currency = "PKR"
              AND refund_source_amount = 50242', [$eventId])) === 1,
        'Post-repair supplier positions do not reconcile');

    if ($mode === 'apply') {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $mode, 'supplier_refund_event_id' => $eventId,
        'supplier_advance_id' => $newAdvanceId] + $plan,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'No changes committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
