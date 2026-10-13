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

$usage = "Usage: php scripts/reconcile_bk000527_supplier_currency_journals.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
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
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$cents = static fn (mixed $amount): int => (int) round((float) $amount * 100);
$balances = static function () use ($query, $cents): array {
    $rows = $query('SELECT je.currency, coa.code, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS balance
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.booking_reference = ? AND coa.code IN ("AP_CONTROL", "SERVICE_COST")
        GROUP BY je.currency, coa.code', ['BK-000527']);
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['currency']][(string) $row['code']] = $cents($row['balance']);
    }
    return $result;
};

$db->beginTransaction();
try {
    $booking = $query('SELECT id, branch_id, booking_reference FROM bookings WHERE booking_reference = ? FOR UPDATE', ['BK-000527']);
    $service = $query('SELECT id, booking_id, line_reference, service_status, purchase_cost, cost_currency
        FROM booking_services WHERE id = 537 FOR UPDATE');
    $obligation = $query('SELECT id, branch_id, booking_reference, service_line_reference, currency,
        gross_amount, advance_applied_amount, net_payable_amount, status
        FROM supplier_obligations WHERE id = 527 FOR UPDATE');
    $event = $query('SELECT id, booking_reference, event_type, event_status, payload_json
        FROM booking_service_events WHERE id = 36 FOR UPDATE');
    $allocation = $query('SELECT COALESCE(SUM(allocated_amount), 0) AS amount
        FROM supplier_payment_allocations WHERE supplier_obligation_id = 527');
    $advance = $query('SELECT COALESCE(SUM(obligation_amount_applied), 0) AS amount
        FROM supplier_advance_applications WHERE supplier_obligation_id = 527');
    $wrongJournal = $query('SELECT je.id, je.booking_reference, je.currency, je.source_type,
        jel.id AS line_id, jel.service_line_reference, jel.supplier_obligation_id,
        coa.code, jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = 3655 ORDER BY jel.id FOR UPDATE');
    $priorReversal = $query('SELECT id FROM journal_entries
        WHERE booking_reference = ? AND source_type = ? AND source_reference = ?',
        ['BK-000527', 'journal_reversal', 'REV-JE-3655']);

    $check(count($booking) === 1 && count($service) === 1 && count($obligation) === 1
        && count($event) === 1 && count($wrongJournal) === 2, 'BK-000527 source records changed');
    $check((int) $service[0]['booking_id'] === (int) $booking[0]['id']
        && (int) $obligation[0]['branch_id'] === (int) $booking[0]['branch_id']
        && $obligation[0]['booking_reference'] === 'BK-000527'
        && $service[0]['line_reference'] === $obligation[0]['service_line_reference']
        && $service[0]['service_status'] === 'Cancelled'
        && $service[0]['cost_currency'] === 'PKR'
        && $obligation[0]['currency'] === 'PKR'
        && $cents($service[0]['purchase_cost']) === 9152600
        && $cents($obligation[0]['gross_amount']) === 3570000
        && $cents($obligation[0]['advance_applied_amount']) === 0
        && $cents($obligation[0]['net_payable_amount']) === 0
        && $obligation[0]['status'] === 'paid'
        && $cents($allocation[0]['amount'] ?? 0) === 3570000
        && $cents($advance[0]['amount'] ?? 0) === 0,
        'BK-000527 supplier amount or payment allocation changed');
    $payload = json_decode((string) $event[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $check($event[0]['booking_reference'] === 'BK-000527'
        && $event[0]['event_type'] === 'cancel' && $event[0]['event_status'] === 'posted'
        && is_array($payload)
        && $cents($payload['supplier_penalty_source_amount'] ?? 0) === 3570000
        && $cents($payload['expected_supplier_refund_source_amount'] ?? 0) === 5582600
        && ($payload['supplier_penalty_currency'] ?? '') === 'PKR'
        && ($payload['expected_supplier_refund_currency'] ?? '') === 'PKR',
        'BK-000527 cancellation basis changed');
    $check($wrongJournal[0]['booking_reference'] === 'BK-000527'
        && $wrongJournal[1]['booking_reference'] === 'BK-000527'
        && (int) $wrongJournal[0]['supplier_obligation_id'] === 527
        && (int) $wrongJournal[1]['supplier_obligation_id'] === 527
        && $wrongJournal[0]['service_line_reference'] === 'SV-001'
        && $wrongJournal[1]['service_line_reference'] === 'SV-001'
        && $wrongJournal[0]['currency'] === 'AED'
        && $wrongJournal[0]['source_type'] === 'supplier_payable_adjusted'
        && $wrongJournal[0]['code'] === 'SERVICE_COST'
        && $cents($wrongJournal[0]['credit_amount']) === 72501
        && $wrongJournal[1]['code'] === 'AP_CONTROL'
        && $cents($wrongJournal[1]['debit_amount']) === 72501,
        'The exact erroneous AED journal changed');

    $current = $balances();
    $target = ['AED' => ['AP_CONTROL' => 0, 'SERVICE_COST' => 0],
        'PKR' => ['AP_CONTROL' => 0, 'SERVICE_COST' => 3570000]];
    if ($priorReversal !== []) {
        $check(count($priorReversal) === 1 && $current == $target,
            'Previous repair exists but supplier balances differ');
        $db->rollBack();
        echo "ALREADY APPLIED: BK-000527 supplier journals reconcile.\n";
        exit(0);
    }
    $check($current == ['AED' => ['AP_CONTROL' => 72501, 'SERVICE_COST' => -72501],
        'PKR' => ['AP_CONTROL' => -5582600, 'SERVICE_COST' => 9152600]],
        'BK-000527 journal balances changed');

    $snapshot = [$booking, $service, $obligation, $event, $allocation, $advance, $wrongJournal, $current];
    $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    $summary = ['mode' => 'preview', 'booking_reference' => 'BK-000527', 'fingerprint' => $fingerprint,
        'reversal' => 'AED 725.01 exact reversal of historical journal 3655',
        'supplier_cost_after' => 'PKR 35,700', 'supplier_payable_after' => 'PKR 0',
        'cash_movements' => 0, 'supplier_payment_changes' => 0, 'supplier_advance_changes' => 0];
    if (! $apply && ! $rehearse) {
        $db->rollBack();
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $check(hash_equals($fingerprint, strtolower((string) $options['fingerprint'])), 'Fingerprint changed; preview again');
    $check(count($query('SELECT id FROM users WHERE id = ? LIMIT 1', [$actorId])) === 1, 'Actor not found');

    $accounting = new AccountingRepository($app);
    $aedReversal = $accounting->reverseJournalEntry(3655, [
        'branch_id' => (int) $booking[0]['branch_id'],
        'booking_reference' => 'BK-000527',
        'source_reference' => 'REV-JE-3655',
        'entry_date' => date('Y-m-d'),
        'actor_user_id' => $actorId,
        'narration' => 'Exact reversal of wrong-currency historical supplier cancellation journal',
    ]);
    $pkrCorrection = $accounting->postPayableAdjusted([
        'branch_id' => (int) $booking[0]['branch_id'],
        'booking_reference' => 'BK-000527',
        'source_reference' => 'SV-001-HIST-CUR-RECON-PKR',
        'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => 527,
        'adjustment_amount' => -55826,
        'entry_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'narration' => 'Historical supplier cost currency journal reconciliation',
        'actor_user_id' => $actorId,
    ]);
    $check($balances() == $target, 'Post-repair supplier journal balances do not reconcile');
    AuditLog::record($app, 'supplier.bk000527_cost_currency_reconciled', [
        'user_id' => $actorId, 'fingerprint' => $fingerprint,
        'journal_ids' => [$aedReversal, $pkrCorrection],
    ]);
    $summary['mode'] = $rehearse ? 'rehearsed-and-rolled-back' : 'applied';
    $summary['journal_ids'] = [$aedReversal, $pkrCorrection];
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
