<?php

declare(strict_types=1);

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
use App\Repositories\SupplierRepository;

$usage = "Usage: php scripts/reconcile_bk000570_cancellation_resync.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--(?:rehearse|apply|actor=\d+|fingerprint=[a-fA-F0-9]{64})$/', $argument)) {
        fwrite(STDERR, $usage);
        exit(1);
    }
}
$options = getopt('', ['rehearse', 'apply', 'actor:', 'fingerprint:']);
$apply = array_key_exists('apply', $options);
$rehearse = array_key_exists('rehearse', $options);
$actorId = (int) ($options['actor'] ?? 0);
if (($apply && $rehearse) || (($apply || $rehearse) && ($actorId <= 0 || ! isset($options['fingerprint'])))) {
    fwrite(STDERR, $usage);
    exit(1);
}

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$query = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$cents = static fn (mixed $amount): int => (int) round((float) $amount * 100);
$journalBalances = static function () use ($query, $cents): array {
    $rows = $query('SELECT je.currency, coa.code,
            ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_debit
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ?
        GROUP BY je.currency, coa.code ORDER BY je.currency, coa.code', ['BK-000570']);
    $result = [];
    foreach ($rows as $row) {
        $result[$row['currency'] . '|' . $row['code']] = $cents($row['net_debit']);
    }
    return array_filter($result, static fn (int $balance): bool => $balance !== 0);
};
$beforeBalances = [
    'PKR|AP_CONTROL' => 34200,
    'PKR|AR_CONTROL' => 10900000,
    'PKR|SERVICE_COST' => 10282900,
    'PKR|SERVICE_REVENUE' => -10900000,
    'PKR|SUPPLIER_ADVANCES' => -10317100,
];
$afterBalances = [
    'PKR|AR_CONTROL' => 34200,
    'PKR|SERVICE_COST' => 34200,
    'PKR|SERVICE_REVENUE' => -34200,
    'PKR|SUPPLIER_ADVANCES' => -34200,
];
$repairType = 'historical_cancellation_resync_reversed';

$db->beginTransaction();
try {
    $booking = $query('SELECT id, branch_id, booking_reference FROM bookings
        WHERE booking_reference = ? FOR UPDATE', ['BK-000570']);
    $require(count($booking) === 1 && (int) $booking[0]['branch_id'] === 1,
        'BK-000570 booking identity or branch differs');
    $service = $query('SELECT id, booking_id, branch_id, line_reference, service_status,
            supplier_id, currency, cost_currency, final_sale_price, purchase_cost
        FROM booking_services WHERE booking_id = ? AND line_reference = ? FOR UPDATE',
        [(int) $booking[0]['id'], 'SV-001']);
    $event = $query('SELECT id, booking_service_id, event_type, event_status, currency,
            payload_json, created_at, updated_at
        FROM booking_service_events WHERE booking_reference = ? AND service_line_reference = ?
        ORDER BY id FOR UPDATE', ['BK-000570', 'SV-001']);
    $receivable = $query('SELECT * FROM customer_receivable_items
        WHERE booking_reference = ? AND service_line_reference = ? FOR UPDATE', ['BK-000570', 'SV-001']);
    $obligation = $query('SELECT * FROM supplier_obligations
        WHERE booking_reference = ? AND service_line_reference = ? FOR UPDATE', ['BK-000570', 'SV-001']);
    $require(count($service) === 1 && count($event) === 1
        && count($receivable) === 1 && count($obligation) === 1,
        'BK-000570 service, settlement, or financial item count differs');
    $serviceId = (int) $service[0]['id'];
    $receivableId = (int) $receivable[0]['id'];
    $obligationId = (int) $obligation[0]['id'];
    $payload = json_decode((string) $event[0]['payload_json'], true);
    $require(is_array($payload)
        && $service[0]['service_status'] === 'Cancelled'
        && $service[0]['currency'] === 'PKR' && $service[0]['cost_currency'] === 'PKR'
        && (int) $service[0]['booking_id'] === (int) $booking[0]['id']
        && (int) $service[0]['branch_id'] === 1
        && (int) $event[0]['booking_service_id'] === $serviceId
        && $event[0]['event_type'] === 'cancel' && $event[0]['event_status'] === 'posted'
        && $event[0]['currency'] === 'PKR'
        && $receivable[0]['currency'] === 'PKR' && $obligation[0]['currency'] === 'PKR'
        && (int) $obligation[0]['supplier_id'] === (int) $service[0]['supplier_id']
        && $cents($service[0]['final_sale_price']) === 10900000
        && $cents($service[0]['purchase_cost']) === 10282900
        && $cents($payload['customer_final_charge_amount'] ?? null) === 34200
        && $cents($payload['prior_customer_due_amount'] ?? null) === 10900000
        && $cents($payload['prior_supplier_obligation_gross_amount'] ?? null) === 10282900
        && $cents($payload['released_supplier_payment_credit'] ?? null) === 10248700
        && $cents($payload['customer_received_amount'] ?? null) === 0
        && ($payload['mode'] ?? '') === 'cancellation_financial_adjustment',
        'BK-000570 saved cancellation or original service facts differ');

    $customerReceipts = $query('SELECT id FROM customer_receipts
        WHERE booking_reference = ? AND status <> "void" FOR UPDATE', ['BK-000570']);
    $customerAllocations = $query('SELECT id FROM customer_receipt_allocations
        WHERE customer_receivable_item_id = ? FOR UPDATE', [$receivableId]);
    $payments = $query('SELECT spa.id, spa.supplier_payment_id, spa.allocated_amount,
            sp.payment_no, sp.supplier_id, sp.currency, sp.paid_amount, sp.allocated_amount AS payment_allocated,
            sp.unallocated_amount, sp.returned_amount, sp.treasury_account_id
        FROM supplier_payment_allocations spa
        INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
        WHERE spa.supplier_obligation_id = ? ORDER BY spa.id FOR UPDATE', [$obligationId]);
    $applications = $query('SELECT saa.id, saa.supplier_advance_id, saa.applied_amount,
            saa.application_type, saa.advance_currency, saa.advance_amount_consumed,
            saa.obligation_currency, saa.obligation_amount_applied, sa.reference_no,
            sa.supplier_id, sa.currency AS pool_currency, sa.deposit_amount, sa.available_amount
        FROM supplier_advance_applications saa
        INNER JOIN supplier_advances sa ON sa.id = saa.supplier_advance_id
        WHERE saa.supplier_obligation_id = ? ORDER BY saa.id FOR UPDATE', [$obligationId]);
    $poolApplications = [];
    foreach ($applications as $application) {
        $poolApplications[(int) $application['supplier_advance_id']] = $query(
            'SELECT id, supplier_obligation_id, applied_amount, advance_amount_consumed
             FROM supplier_advance_applications WHERE supplier_advance_id = ? ORDER BY id FOR UPDATE',
            [(int) $application['supplier_advance_id']]
        );
    }
    $require($customerReceipts === [] && $customerAllocations === []
        && count($payments) === 1
        && $payments[0]['payment_no'] === 'SPAY-000124'
        && (int) $payments[0]['treasury_account_id'] === 33
        && (int) $payments[0]['supplier_id'] === (int) $service[0]['supplier_id']
        && $payments[0]['currency'] === 'PKR'
        && $cents($payments[0]['allocated_amount']) === 34200
        && count($applications) <= 2,
        'BK-000570 customer cash or supplier payment/allocation history differs');
    $sourcePaymentJournal = $query('SELECT je.id, je.branch_id, je.currency, coa.code,
            jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.source_type = "supplier_payment_recorded"
          AND je.source_reference = ? ORDER BY je.id, jel.id', ['SPAY-000124']);
    $sourceSignatures = array_map(static fn (array $line): string =>
        $line['code'] . '|' . $cents($line['debit_amount']) . '|'
        . $cents($line['credit_amount']), $sourcePaymentJournal);
    sort($sourceSignatures);
    $require(count($sourcePaymentJournal) === 2
        && count(array_unique(array_column($sourcePaymentJournal, 'id'))) === 1
        && (int) $sourcePaymentJournal[0]['branch_id'] === 1
        && $sourcePaymentJournal[0]['currency'] === 'PKR'
        && $sourceSignatures === [
            'CASH_CASH_SWT_PKR_PKR|0|100000000',
            'SUPPLIER_ADVANCES|100000000|0',
        ], 'BK-000570 original supplier cash journal differs');
    $poolBalances = $query('SELECT sa.id, sa.reference_no, sa.deposit_amount,
            sa.available_amount, COALESCE(SUM(saa.advance_amount_consumed), 0) AS applied_amount
        FROM supplier_advances sa
        LEFT JOIN supplier_advance_applications saa ON saa.supplier_advance_id = sa.id
        WHERE sa.reference_no IN ("SPAY-000130", "SPAY-000138")
          AND sa.supplier_id = ? AND sa.currency = "PKR"
        GROUP BY sa.id ORDER BY sa.id', [(int) $service[0]['supplier_id']]);
    $require(count($poolBalances) === 2
        && array_column($poolBalances, 'reference_no') === ['SPAY-000130', 'SPAY-000138'],
        'BK-000570 supplier advance pool identities differ');
    foreach ($poolBalances as $poolBalance) {
        $require($cents($poolBalance['available_amount']) + $cents($poolBalance['applied_amount'])
            === $cents($poolBalance['deposit_amount']),
            'BK-000570 supplier advance pool does not balance');
    }
    $require(count($query('SELECT id FROM audit_logs WHERE event_name = ?
        AND payload_json LIKE ? LIMIT 1', ['commercial.obligations.synced', '%"booking_reference":"BK-000570"%'])) >= 1,
        'BK-000570 historical resync audit is missing');

    $originalJournalIds = $payload['journal_entry_ids'] ?? [];
    $require(count($originalJournalIds) === 3 && count(array_unique($originalJournalIds)) === 3,
        'BK-000570 cancellation journal references differ');
    $originalJournals = $query('SELECT id, source_type, currency, created_at FROM journal_entries
        WHERE id IN (?, ?, ?) AND booking_reference = ? ORDER BY id',
        [...$originalJournalIds, 'BK-000570']);
    $require(count($originalJournals) === 3
        && array_column($originalJournals, 'source_type') === [
            'service_receivable_adjusted', 'supplier_settlement_released', 'supplier_payable_adjusted'
        ]
        && count(array_unique(array_column($originalJournals, 'currency'))) === 1
        && $originalJournals[0]['currency'] === 'PKR',
        'BK-000570 cancellation journals differ');

    $resyncTime = '2026-09-05 14:46:00';
    $badJournals = $query('SELECT id, source_type, source_reference, currency, entry_date, created_at
        FROM journal_entries WHERE booking_reference = ? AND created_at = ? ORDER BY id FOR UPDATE',
        ['BK-000570', $resyncTime]);
    $require(count($badJournals) === 3
        && array_column($badJournals, 'source_type') === [
            'service_receivable_adjusted', 'supplier_payable_adjusted', 'supplier_advance_applied'
        ]
        && count(array_unique(array_column($badJournals, 'currency'))) === 1
        && $badJournals[0]['currency'] === 'PKR'
        && count(array_unique(array_column($badJournals, 'entry_date'))) === 1
        && $badJournals[0]['entry_date'] === '2026-07-31',
        'BK-000570 post-cancellation resync journal group differs');
    $badIds = array_map(static fn (array $row): int => (int) $row['id'], $badJournals);
    $badLines = $query('SELECT jel.journal_entry_id, jel.customer_receivable_item_id,
            jel.supplier_obligation_id, coa.code, jel.debit_amount, jel.credit_amount
        FROM journal_entry_lines jel INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE jel.journal_entry_id IN (?, ?, ?) ORDER BY jel.journal_entry_id, jel.id FOR UPDATE', $badIds);
    $actualLines = [];
    foreach ($badLines as $line) {
        $actualLines[] = $line['journal_entry_id'] . '|' . $line['code'] . '|'
            . $cents($line['debit_amount']) . '|' . $cents($line['credit_amount']) . '|'
            . (int) $line['customer_receivable_item_id'] . '|' . (int) $line['supplier_obligation_id'];
    }
    $expectedLines = [
        "$badIds[0]|AR_CONTROL|10865800|0|$receivableId|0",
        "$badIds[0]|SERVICE_REVENUE|0|10865800|$receivableId|0",
        "$badIds[1]|SERVICE_COST|10248700|0|0|$obligationId",
        "$badIds[1]|AP_CONTROL|0|10248700|0|$obligationId",
        "$badIds[2]|AP_CONTROL|10282900|0|0|$obligationId",
        "$badIds[2]|SUPPLIER_ADVANCES|0|10282900|0|$obligationId",
    ];
    sort($actualLines);
    sort($expectedLines);
    $require($actualLines === $expectedLines,
        'BK-000570 resync journal lines or financial item links differ');

    $repairJournals = $query('SELECT id, source_reference FROM journal_entries
        WHERE booking_reference = ? AND source_type = ? ORDER BY id FOR UPDATE',
        ['BK-000570', $repairType]);
    $currentBalances = $journalBalances();
    if ($repairJournals !== []) {
        $expectedReferences = array_map(static fn (int $id): string => 'BK-000570-REV-JE-' . $id, $badIds);
        $repairAudit = $query('SELECT id FROM audit_logs WHERE event_name = ?
            AND payload_json LIKE ? ORDER BY id',
            ['historical.bk000570_cancellation_resync_reversed', '%"booking_reference":"BK-000570"%']);
        $require(count($repairJournals) === 3
            && array_column($repairJournals, 'source_reference') === $expectedReferences
            && count($repairAudit) === 1
            && $cents($receivable[0]['due_amount']) === 34200
            && $cents($receivable[0]['outstanding_amount']) === 34200
            && $cents($receivable[0]['allocated_amount']) === 0
            && $cents($obligation[0]['gross_amount']) === 34200
            && $cents($obligation[0]['advance_applied_amount']) === 0
            && $cents($obligation[0]['net_payable_amount']) === 0
            && $obligation[0]['status'] === 'paid'
            && $applications === [] && $currentBalances === $afterBalances,
            'Prior BK-000570 repair exists but no longer reconciles');
        $db->rollBack();
        echo "ALREADY APPLIED: BK-000570 customer PKR 342 due; supplier PKR 342 paid; no cash changed.\n";
        exit(0);
    }

    $require($cents($receivable[0]['due_amount']) === 10900000
        && $cents($receivable[0]['allocated_amount']) === 0
        && $cents($receivable[0]['outstanding_amount']) === 10900000
        && $receivable[0]['status'] === 'open'
        && $cents($obligation[0]['gross_amount']) === 10282900
        && $cents($obligation[0]['advance_applied_amount']) === 10282900
        && $cents($obligation[0]['net_payable_amount']) === 0
        && $obligation[0]['status'] === 'paid'
        && count($applications) === 2
        && $currentBalances === $beforeBalances,
        'BK-000570 no longer has the audited cancellation resync discrepancy');
    $expectedPools = ['SPAY-000138' => 6719000, 'SPAY-000130' => 3563900];
    foreach ($applications as $application) {
        $reference = (string) $application['reference_no'];
        $require(isset($expectedPools[$reference])
            && $cents($application['applied_amount']) === $expectedPools[$reference]
            && $cents($application['advance_amount_consumed']) === $expectedPools[$reference]
            && $cents($application['obligation_amount_applied']) === $expectedPools[$reference]
            && $application['application_type'] === 'same_currency_auto'
            && $application['advance_currency'] === 'PKR'
            && $application['obligation_currency'] === 'PKR'
            && $application['pool_currency'] === 'PKR'
            && (int) $application['supplier_id'] === (int) $service[0]['supplier_id'],
            'BK-000570 supplier advance source or amount differs');
        $poolId = (int) $application['supplier_advance_id'];
        $poolConsumed = array_sum(array_map($cents,
            array_column($poolApplications[$poolId], 'advance_amount_consumed')));
        $require($poolConsumed + $cents($application['available_amount'])
            === $cents($application['deposit_amount']),
            'BK-000570 shared supplier advance pool does not reconcile');
        unset($expectedPools[$reference]);
    }
    $require($expectedPools === [], 'BK-000570 expected supplier advance pools are missing');

    $snapshot = [$booking, $service, $event, $receivable, $obligation,
        $payments, $sourcePaymentJournal, $applications, $poolApplications, $poolBalances,
        $originalJournals, $badJournals, $badLines, $currentBalances];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    $summary = [
        'mode' => 'preview',
        'inspection_only' => true,
        'booking_reference' => 'BK-000570',
        'fingerprint' => $fingerprint,
        'cancellation_event_id' => (int) $event[0]['id'],
        'wrong_journal_ids' => $badIds,
        'customer_before' => 'PKR 109,000 due / PKR 109,000 outstanding',
        'customer_after' => 'PKR 342 due / PKR 342 outstanding; no customer receipt',
        'supplier_after' => 'PKR 342 cost covered by existing PKR 342 supplier payment allocation',
        'supplier_advance_release' => 'PKR 67,190 to SPAY-000138 and PKR 35,639 to SPAY-000130',
        'cash_movements' => 0,
        'other_booking_allocations_changed' => 0,
    ];
    if (! $apply && ! $rehearse) {
        $db->rollBack();
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $require(hash_equals($fingerprint, strtolower((string) $options['fingerprint'])),
        'Fingerprint changed; preview again');
    $require(count($query('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [$actorId])) === 1,
        'Active actor not found');

    $db->prepare('UPDATE customer_receivable_items
        SET due_amount = 342, outstanding_amount = 342, status = "open"
        WHERE id = ?')->execute([$receivableId]);
    $db->prepare('UPDATE supplier_obligations SET gross_amount = 342 WHERE id = ?')
        ->execute([$obligationId]);
    $advanceResult = (new SupplierRepository($app))->autoApplyAvailableAdvanceToObligation($obligationId, $actorId);
    $require($cents($advanceResult['applied_amount_delta'] ?? null) === -10282900
        && $cents($advanceResult['supplier_payment_credit_applied_amount'] ?? null) === 0,
        'Supplier reconciliation did not release exactly PKR 102,829');

    $accounting = new AccountingRepository($app);
    $reversalIds = [];
    foreach ($badIds as $journalId) {
        $reversalId = $accounting->reverseJournalEntry($journalId, [
            'source_type' => $repairType,
            'source_reference' => 'BK-000570-REV-JE-' . $journalId,
            'entry_date' => date('Y-m-d'),
            'narration' => 'Reverse post-cancellation commercial resync for BK-000570',
            'actor_user_id' => $actorId,
        ]);
        $require($reversalId !== null, 'Unable to reverse BK-000570 journal ' . $journalId);
        $reversalIds[] = $reversalId;
    }

    $afterReceivable = $query('SELECT due_amount, allocated_amount, outstanding_amount, status
        FROM customer_receivable_items WHERE id = ?', [$receivableId]);
    $afterObligation = $query('SELECT gross_amount, advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = ?', [$obligationId]);
    $afterApplications = $query('SELECT id FROM supplier_advance_applications
        WHERE supplier_obligation_id = ?', [$obligationId]);
    $afterPayment = $query('SELECT id, allocated_amount FROM supplier_payment_allocations
        WHERE supplier_obligation_id = ?', [$obligationId]);
    $require($cents($afterReceivable[0]['due_amount'] ?? null) === 34200
        && $cents($afterReceivable[0]['outstanding_amount'] ?? null) === 34200
        && $cents($afterReceivable[0]['allocated_amount'] ?? null) === 0
        && ($afterReceivable[0]['status'] ?? '') === 'open'
        && $cents($afterObligation[0]['gross_amount'] ?? null) === 34200
        && $cents($afterObligation[0]['advance_applied_amount'] ?? null) === 0
        && $cents($afterObligation[0]['net_payable_amount'] ?? null) === 0
        && ($afterObligation[0]['status'] ?? '') === 'paid'
        && $afterApplications === []
        && count($afterPayment) === 1
        && (int) $afterPayment[0]['id'] === (int) $payments[0]['id']
        && $cents($afterPayment[0]['allocated_amount']) === 34200
        && $journalBalances() === $afterBalances,
        'BK-000570 post-repair customer, supplier, or journal balances do not reconcile');
    foreach ($applications as $application) {
        $poolId = (int) $application['supplier_advance_id'];
        $pool = $query('SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = ?', [$poolId]);
        $otherApplications = $query('SELECT id, supplier_obligation_id, applied_amount, advance_amount_consumed
            FROM supplier_advance_applications WHERE supplier_advance_id = ? ORDER BY id', [$poolId]);
        $expectedOther = array_values(array_filter($poolApplications[$poolId],
            static fn (array $row): bool => (int) $row['supplier_obligation_id'] !== $obligationId));
        $require($otherApplications === $expectedOther
            && $cents($pool[0]['available_amount'] ?? null)
                === $cents($application['available_amount']) + $cents($application['advance_amount_consumed'])
            && $cents($pool[0]['deposit_amount'] ?? null) === $cents($application['deposit_amount']),
            'BK-000570 shared supplier advance pool or other applications changed unexpectedly');
    }
    $require($query('SELECT id FROM customer_receipts WHERE booking_reference = ? AND status <> "void"',
        ['BK-000570']) === [], 'BK-000570 customer cash changed unexpectedly');

    AuditLog::record($app, 'historical.bk000570_cancellation_resync_reversed', [
        'user_id' => $actorId,
        'booking_reference' => 'BK-000570',
        'cancellation_event_id' => (int) $event[0]['id'],
        'wrong_journal_ids' => $badIds,
        'reversal_journal_ids' => $reversalIds,
        'released_supplier_advance_amount' => 102829,
        'customer_due_after' => 342,
        'supplier_gross_after' => 342,
        'fingerprint' => $fingerprint,
    ]);
    $summary['mode'] = $rehearse ? 'rehearsed-and-rolled-back' : 'applied';
    $summary['inspection_only'] = false;
    $summary['reversal_journal_ids'] = $reversalIds;
    if ($rehearse) {
        $db->rollBack();
    } else {
        $db->commit();
    }
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
