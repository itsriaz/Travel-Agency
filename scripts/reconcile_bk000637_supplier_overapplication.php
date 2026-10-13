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

$usage = "Usage: php scripts/reconcile_bk000637_supplier_overapplication.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
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
$balances = static function (int $obligationId) use ($query, $cents): array {
    $rows = $query('SELECT je.currency, coa.code,
            ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS balance
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND jel.supplier_obligation_id = ?
          AND coa.code IN ("AP_CONTROL", "SERVICE_COST", "SUPPLIER_ADVANCES")
        GROUP BY je.currency, coa.code', ['BK-000637', $obligationId]);
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['currency']][(string) $row['code']] = $cents($row['balance']);
    }
    return $result;
};

$db->beginTransaction();
try {
    $booking = $query('SELECT id, branch_id, booking_reference FROM bookings
        WHERE booking_reference = ? FOR UPDATE', ['BK-000637']);
    $require(count($booking) === 1, 'BK-000637 booking is missing or duplicated');
    $service = $query('SELECT id, booking_id, branch_id, line_reference, service_status,
            supplier_id, purchase_cost, cost_currency
        FROM booking_services WHERE booking_id = ? AND line_reference = ? FOR UPDATE',
        [(int) $booking[0]['id'], 'SV-001']);
    $obligation = $query('SELECT id, supplier_id, branch_id, booking_reference,
            service_line_reference, currency, gross_amount, advance_applied_amount,
            net_payable_amount, status
        FROM supplier_obligations WHERE booking_reference = ? AND service_line_reference = ? FOR UPDATE',
        ['BK-000637', 'SV-001']);
    $require(count($service) === 1 && count($obligation) === 1,
        'BK-000637 service or supplier obligation differs');
    $obligationId = (int) $obligation[0]['id'];

    $payments = $query('SELECT spa.id, spa.supplier_obligation_id, spa.allocated_amount,
            sp.id AS payment_id, sp.payment_no, sp.supplier_id, sp.currency
        FROM supplier_payment_allocations spa
        INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
        WHERE spa.supplier_obligation_id = ? ORDER BY spa.id FOR UPDATE', [$obligationId]);
    $applications = $query('SELECT saa.id, saa.supplier_obligation_id, saa.supplier_advance_id,
            saa.applied_amount, saa.application_type, saa.advance_currency,
            saa.advance_amount_consumed, saa.obligation_currency, saa.obligation_amount_applied,
            sa.supplier_id, sa.branch_id AS advance_branch_id, sa.currency AS pool_currency,
            sa.deposit_amount, sa.available_amount, sa.reference_no
        FROM supplier_advance_applications saa
        INNER JOIN supplier_advances sa ON sa.id = saa.supplier_advance_id
        WHERE saa.supplier_obligation_id = ? ORDER BY saa.id FOR UPDATE', [$obligationId]);
    $require(count($payments) === 1 && count($applications) === 1,
        'BK-000637 payment or advance allocation history differs');
    $advanceId = (int) $applications[0]['supplier_advance_id'];
    $poolApplications = $query('SELECT id, supplier_obligation_id, applied_amount,
            advance_amount_consumed, obligation_amount_applied
        FROM supplier_advance_applications WHERE supplier_advance_id = ? ORDER BY id FOR UPDATE',
        [$advanceId]);
    $sourcePayment = $query('SELECT id, payment_no, supplier_id, currency,
            paid_amount, allocated_amount, unallocated_amount
        FROM supplier_payments WHERE payment_no = ? FOR UPDATE', ['SPAY-000154']);
    $journals = $query('SELECT je.id, je.branch_id, je.currency, je.source_type,
            je.source_reference, jel.supplier_obligation_id, jel.service_line_reference,
            coa.code, jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND jel.supplier_obligation_id = ?
          AND coa.code IN ("AP_CONTROL", "SERVICE_COST", "SUPPLIER_ADVANCES")
        ORDER BY je.id, jel.id FOR UPDATE', ['BK-000637', $obligationId]);
    $repair = $query('SELECT id FROM journal_entries WHERE booking_reference = ?
        AND source_type = ? AND source_reference = ?',
        ['BK-000637', 'supplier_advance_adjusted', 'SV-001-HIST-OVERAPPLICATION-525']);

    $require((int) $service[0]['booking_id'] === (int) $booking[0]['id']
        && (int) $service[0]['branch_id'] === (int) $booking[0]['branch_id']
        && (int) $obligation[0]['branch_id'] === (int) $booking[0]['branch_id']
        && (int) $service[0]['supplier_id'] === (int) $obligation[0]['supplier_id']
        && (int) $payments[0]['supplier_id'] === (int) $obligation[0]['supplier_id']
        && (int) $applications[0]['supplier_id'] === (int) $obligation[0]['supplier_id']
        && count($sourcePayment) === 1
        && (int) $sourcePayment[0]['supplier_id'] === (int) $obligation[0]['supplier_id']
        && $service[0]['service_status'] === 'Open'
        && $service[0]['cost_currency'] === 'AED'
        && $obligation[0]['currency'] === 'AED'
        && $payments[0]['currency'] === 'AED'
        && $applications[0]['pool_currency'] === 'AED'
        && $applications[0]['advance_currency'] === 'AED'
        && $applications[0]['obligation_currency'] === 'AED'
        && $sourcePayment[0]['currency'] === 'AED'
        && $payments[0]['payment_no'] === 'SPAY-000152'
        && $applications[0]['reference_no'] === 'SPAY-000154'
        && $sourcePayment[0]['payment_no'] === 'SPAY-000154'
        && $applications[0]['application_type'] === 'same_currency_auto'
        && $cents($service[0]['purchase_cost']) === 54900
        && $cents($obligation[0]['gross_amount']) === 54900
        && $cents($payments[0]['allocated_amount']) === 54500
        && $cents($applications[0]['deposit_amount']) === 52900
        && $cents($sourcePayment[0]['paid_amount']) === 500000
        && $cents($sourcePayment[0]['allocated_amount']) === 447100
        && $cents($sourcePayment[0]['unallocated_amount']) === 0,
        'BK-000637 source amounts, currencies, or identities changed; investigate before repair');

    $current = $balances($obligationId);
    $before = ['AED' => ['AP_CONTROL' => 52500, 'SERVICE_COST' => 54900,
        'SUPPLIER_ADVANCES' => -107400]];
    $after = ['AED' => ['AP_CONTROL' => 0, 'SERVICE_COST' => 54900,
        'SUPPLIER_ADVANCES' => -54900]];
    if ($repair !== []) {
        $poolAppliedCents = array_reduce($poolApplications,
            static fn (int $sum, array $row): int => $sum + $cents($row['advance_amount_consumed']), 0);
        $require(count($repair) === 1
            && $cents($applications[0]['applied_amount']) === 400
            && $cents($applications[0]['advance_amount_consumed']) === 400
            && $cents($applications[0]['obligation_amount_applied']) === 400
            && $cents($applications[0]['available_amount']) >= 0
            && $poolAppliedCents + $cents($applications[0]['available_amount']) === 52900
            && $cents($obligation[0]['advance_applied_amount']) === 400
            && $cents($obligation[0]['net_payable_amount']) === 0
            && $obligation[0]['status'] === 'paid'
            && $current == $after,
            'Previous BK-000637 repair exists but balances no longer reconcile');
        $db->rollBack();
        echo "ALREADY APPLIED: BK-000637 AED 545 payment + AED 4 advance = AED 549 cost; AED 525 was returned to the supplier advance pool.\n";
        exit(0);
    }

    $require($cents($applications[0]['applied_amount']) === 52900
        && count($poolApplications) === 1
        && $cents($applications[0]['advance_amount_consumed']) === 52900
        && $cents($applications[0]['obligation_amount_applied']) === 52900
        && $cents($poolApplications[0]['applied_amount']) === 52900
        && $cents($applications[0]['available_amount']) === 0
        && $cents($obligation[0]['advance_applied_amount']) === 52900
        && $cents($obligation[0]['net_payable_amount']) === 0
        && $obligation[0]['status'] === 'paid'
        && $current == $before,
        'BK-000637 no longer has the audited over-application');

    $expectedJournalLines = [
        'supplier_payable_created|SERVICE_COST|54500|0',
        'supplier_payable_created|AP_CONTROL|0|54500',
        'supplier_payment_allocated|AP_CONTROL|54500|0',
        'supplier_payment_allocated|SUPPLIER_ADVANCES|0|54500',
        'supplier_payable_adjusted|SERVICE_COST|400|0',
        'supplier_payable_adjusted|AP_CONTROL|0|400',
        'supplier_advance_applied|AP_CONTROL|52900|0',
        'supplier_advance_applied|SUPPLIER_ADVANCES|0|52900',
    ];
    $actualJournalLines = [];
    foreach ($journals as $line) {
        $require($line['currency'] === 'AED'
            && (int) $line['supplier_obligation_id'] === $obligationId
            && $line['service_line_reference'] === 'SV-001',
            'BK-000637 supplier journal currency or service linkage differs');
        $actualJournalLines[] = $line['source_type'] . '|' . $line['code'] . '|'
            . $cents($line['debit_amount']) . '|' . $cents($line['credit_amount']);
    }
    sort($expectedJournalLines);
    sort($actualJournalLines);
    $require($actualJournalLines === $expectedJournalLines,
        'BK-000637 supplier journal history differs; no automatic repair is safe');

    $snapshot = [$booking, $service, $obligation, $payments, $applications,
        $poolApplications, $sourcePayment, $journals, $current];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    $summary = [
        'mode' => 'preview',
        'inspection_only' => true,
        'booking_reference' => 'BK-000637',
        'fingerprint' => $fingerprint,
        'source_ids' => [
            'booking_id' => (int) $booking[0]['id'],
            'service_id' => (int) $service[0]['id'],
            'supplier_obligation_id' => $obligationId,
            'payment_allocation_id' => (int) $payments[0]['id'],
            'advance_application_id' => (int) $applications[0]['id'],
            'supplier_advance_id' => $advanceId,
            'journal_entry_ids' => array_values(array_unique(array_map(
                static fn (array $line): int => (int) $line['id'], $journals
            ))),
        ],
        'timeline' => [
            'original_supplier_cost_aed' => 545,
            'supplier_payment_allocated_aed' => 545,
            'supplier_cost_after_invoice_edit_aed' => 549,
            'additional_amount_actually_due_aed' => 4,
            'advance_wrongly_applied_aed' => 529,
            'advance_overapplied_aed' => 525,
        ],
        'source_payment' => 'SPAY-000152',
        'advance_from_excess_payment' => 'SPAY-000154',
        'current_supplier_advance_available_aed' => 0,
        'current_supplier_payable_aed' => 0,
        'current_supplier_control_overapplied_aed' => 525,
        'after_repair' => 'AED 545 payment + AED 4 advance = AED 549 cost; AED 525 restored to reusable supplier advance',
        'cash_or_bank_movements' => 0,
        'supplier_payment_changes' => 0,
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

    $supplier = new SupplierRepository($app);
    $result = $supplier->autoApplyAvailableAdvanceToObligation($obligationId, $actorId);
    $require($cents($result['applied_amount_delta'] ?? 0) === -52500
        && $cents($result['supplier_payment_credit_applied_amount'] ?? 0) === 0,
        'Supplier reconciliation did not release exactly AED 525');

    $accounting = new AccountingRepository($app);
    $journalId = $accounting->postSupplierAdvanceApplicationAdjusted([
        'branch_id' => (int) $booking[0]['branch_id'],
        'booking_reference' => 'BK-000637',
        'source_reference' => 'SV-001-HIST-OVERAPPLICATION-525',
        'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => $obligationId,
        'adjustment_amount' => -525,
        'entry_date' => date('Y-m-d'),
        'currency' => 'AED',
        'narration' => 'Release supplier advance over-applied after paid invoice cost edit',
        'actor_user_id' => $actorId,
    ]);
    $updatedApplication = $query('SELECT applied_amount, advance_amount_consumed,
            obligation_amount_applied FROM supplier_advance_applications WHERE id = ?',
        [(int) $applications[0]['id']]);
    $updatedAdvance = $query('SELECT available_amount FROM supplier_advances WHERE id = ?', [$advanceId]);
    $updatedObligation = $query('SELECT advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = ?', [$obligationId]);
    $require(count($updatedApplication) === 1
        && $cents($updatedApplication[0]['applied_amount']) === 400
        && $cents($updatedApplication[0]['advance_amount_consumed']) === 400
        && $cents($updatedApplication[0]['obligation_amount_applied']) === 400
        && $cents($updatedAdvance[0]['available_amount'] ?? 0) === 52500
        && $cents($updatedObligation[0]['advance_applied_amount'] ?? 0) === 400
        && $cents($updatedObligation[0]['net_payable_amount'] ?? 0) === 0
        && ($updatedObligation[0]['status'] ?? '') === 'paid'
        && $balances($obligationId) == $after,
        'Post-repair BK-000637 source and journal balances do not reconcile');

    AuditLog::record($app, 'supplier.bk000637_advance_overapplication_reconciled', [
        'user_id' => $actorId,
        'booking_reference' => 'BK-000637',
        'supplier_obligation_id' => $obligationId,
        'supplier_advance_id' => $advanceId,
        'released_amount' => 525,
        'journal_entry_id' => $journalId,
        'fingerprint' => $fingerprint,
    ]);
    $summary['mode'] = $rehearse ? 'rehearsed-and-rolled-back' : 'applied';
    $summary['inspection_only'] = false;
    $summary['journal_entry_id'] = $journalId;
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
