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

$usage = "Usage: php scripts/reconcile_supplier_cost_currency_journals.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
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

// These five reviewed records match the restored dataset; BK-001044 was a different local test booking.
$expected = [
    'BK-000002' => [2, 2, 67626, 0, 67626, 0],
    'BK-000529' => [539, 529, 377483, 377483, 0, 0],
    'BK-000606' => [616, 612, 42277, 0, 42277, 0],
    'BK-000636' => [646, 642, 43782, 0, 43782, 0],
    'BK-000670' => [680, 675, 210317, 210317, 0, 0],
];

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

$db->beginTransaction();
try {
    $snapshots = [];
    $repairs = [];
    foreach ($expected as $reference => [$serviceId, $obligationId, $gross, $net, $paid, $advance]) {
        $bookings = $query('SELECT id, branch_id, booking_reference FROM bookings WHERE booking_reference = ? FOR UPDATE', [$reference]);
        $services = $query('SELECT id, booking_id, line_reference, service_status, cost_currency, purchase_cost
            FROM booking_services WHERE booking_id = ? FOR UPDATE', [(int) ($bookings[0]['id'] ?? 0)]);
        $obligations = $query('SELECT id, branch_id, booking_reference, service_line_reference, obligation_group,
            currency, gross_amount, advance_applied_amount, net_payable_amount, status
            FROM supplier_obligations WHERE booking_reference = ? FOR UPDATE', [$reference]);
        $require(count($bookings) === 1 && count($services) === 1 && count($obligations) === 1,
            $reference . ': booking/service/obligation cardinality changed');
        $booking = $bookings[0];
        $service = $services[0];
        $obligation = $obligations[0];
        $require((int) $service['id'] === $serviceId && (int) $obligation['id'] === $obligationId
            && (int) $service['booking_id'] === (int) $booking['id']
            && (int) $obligation['branch_id'] === (int) $booking['branch_id']
            && $service['line_reference'] === $obligation['service_line_reference']
            && $service['service_status'] === 'Open'
            && $service['cost_currency'] === 'PKR' && $obligation['currency'] === 'PKR'
            && $obligation['obligation_group'] === 'service_cost'
            && $cents($service['purchase_cost']) === $gross * 100
            && $cents($obligation['gross_amount']) === $gross * 100
            && $cents($obligation['net_payable_amount']) === $net * 100
            && $cents($obligation['advance_applied_amount']) === $advance * 100,
            $reference . ': saved supplier truth changed');

        $settled = $query('SELECT
            (SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payment_allocations
                WHERE supplier_obligation_id = ?) AS paid,
            (SELECT COALESCE(SUM(obligation_amount_applied), 0) FROM supplier_advance_applications
                WHERE supplier_obligation_id = ?) AS advance', [$obligationId, $obligationId]);
        $require($cents($settled[0]['paid'] ?? 0) === $paid * 100
            && $cents($settled[0]['advance'] ?? 0) === $advance * 100
            && $gross - $paid - $advance === $net,
            $reference . ': settlement allocations changed');

        $lines = $query('SELECT je.id, je.currency, je.source_type, je.source_reference, jel.id AS line_id,
            coa.code, jel.debit_amount, jel.credit_amount
            FROM journal_entries je
            INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.booking_reference = ? AND coa.code IN ("AP_CONTROL", "SERVICE_COST")
            ORDER BY je.id, jel.id FOR UPDATE', [$reference]);
        $require($lines !== [], $reference . ': supplier journals are missing');
        $balances = [];
        foreach ($lines as $line) {
            $currency = strtoupper((string) $line['currency']);
            $code = (string) $line['code'];
            $require(in_array($currency, ['PKR', 'AED', 'USD'], true), $reference . ': unexpected journal currency');
            $debit = $cents($line['debit_amount']);
            $credit = $cents($line['credit_amount']);
            $require($debit % 100 === 0 && $credit % 100 === 0,
                $reference . ': journal contains a fractional amount; separate review required');
            $balances[$currency][$code] = ($balances[$currency][$code] ?? 0) + $debit - $credit;
        }

        $currencies = array_unique(array_merge(array_keys($balances), ['PKR']));
        sort($currencies);
        foreach ($currencies as $currency) {
            $cost = $balances[$currency]['SERVICE_COST'] ?? 0;
            $ap = $balances[$currency]['AP_CONTROL'] ?? 0;
            $expectedSettled = $currency === 'PKR' ? ($paid + $advance) * 100 : 0;
            $require($cost + $ap === $expectedSettled,
                $reference . ': journal cost/payable pair does not reconcile to actual settlement');
            $targetCost = $currency === 'PKR' ? $gross * 100 : 0;
            $targetAp = $currency === 'PKR' ? -$net * 100 : 0;
            $deltaCost = $targetCost - $cost;
            $require($targetAp - $ap === -$deltaCost && $deltaCost % 100 === 0,
                $reference . ': correction would not be a whole-unit cost/payable transfer');
            if ($deltaCost !== 0) {
                $repairs[] = [
                    'booking_reference' => $reference,
                    'branch_id' => (int) $booking['branch_id'],
                    'line_reference' => (string) $service['line_reference'],
                    'obligation_id' => $obligationId,
                    'currency' => $currency,
                    'cost_delta' => $deltaCost / 100,
                ];
            }
        }
        $snapshots[$reference] = [$booking, $service, $obligation, $settled[0], $lines];
    }

    if ($repairs === []) {
        $db->rollBack();
        echo "ALREADY APPLIED: five supplier cost/payable currency positions reconcile.\n";
        exit(0);
    }

    $fingerprint = hash('sha256', json_encode($snapshots, JSON_THROW_ON_ERROR));
    $summary = ['mode' => 'preview', 'fingerprint' => $fingerprint, 'corrections' => $repairs,
        'cash_movements' => 0, 'supplier_payment_changes' => 0, 'supplier_advance_changes' => 0];
    if (! $apply && ! $rehearse) {
        $db->rollBack();
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $require(hash_equals($fingerprint, strtolower((string) $options['fingerprint'])), 'Fingerprint changed; preview again');
    $actor = $query('SELECT id FROM users WHERE id = ? LIMIT 1', [$actorId]);
    $require(count($actor) === 1, 'Actor not found');

    $accounting = new AccountingRepository($app);
    $journalIds = [];
    foreach ($repairs as $repair) {
        $delta = (int) $repair['cost_delta'];
        $amount = abs($delta);
        $line = ['service_line_reference' => $repair['line_reference'],
            'supplier_obligation_id' => $repair['obligation_id']];
        $journalIds[] = $accounting->postJournalEntry([
            'branch_id' => $repair['branch_id'],
            'booking_reference' => $repair['booking_reference'],
            'source_type' => 'supplier_payable_adjusted',
            'source_reference' => $repair['line_reference'] . '-HIST-CUR-RECON-' . $repair['currency'],
            'entry_date' => date('Y-m-d'),
            'currency' => $repair['currency'],
            'narration' => 'Historical supplier cost currency journal reconciliation',
            'actor_user_id' => $actorId,
        ], [
            $line + ['account_code' => 'SERVICE_COST', 'debit_amount' => $delta > 0 ? $amount : 0,
                'credit_amount' => $delta < 0 ? $amount : 0],
            $line + ['account_code' => 'AP_CONTROL', 'debit_amount' => $delta < 0 ? $amount : 0,
                'credit_amount' => $delta > 0 ? $amount : 0],
        ]);
    }
    foreach ($expected as $reference => [, , $gross, $net]) {
        $balances = $query('SELECT je.currency, coa.code,
            ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS balance
            FROM journal_entries je
            INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.booking_reference = ? AND coa.code IN ("AP_CONTROL", "SERVICE_COST")
            GROUP BY je.currency, coa.code', [$reference]);
        foreach ($balances as $balance) {
            $target = $balance['currency'] === 'PKR'
                ? ($balance['code'] === 'SERVICE_COST' ? $gross : -$net)
                : 0;
            $require($cents($balance['balance']) === $target * 100,
                $reference . ': post-repair journal balance does not match supplier truth');
        }
    }
    AuditLog::record($app, 'supplier.cost_currency_journals_reconciled', [
        'user_id' => $actorId, 'fingerprint' => $fingerprint, 'journal_ids' => $journalIds,
        'bookings' => array_keys($expected),
    ]);
    $summary['mode'] = $rehearse ? 'rehearsed-and-rolled-back' : 'applied';
    $summary['journal_ids'] = $journalIds;
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
