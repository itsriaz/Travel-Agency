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

$usage = "Usage: php scripts/reconcile_spay000115_duplicate_releases.php [--rehearse|--apply] [--actor=ID] [--fingerprint=SHA256]\n";
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

$expected = [
    4386 => [4113, 'BK-000449', 440, 20006],
    4387 => [4114, 'BK-000304', 300, 64097],
    4388 => [4115, 'BK-000298', 294, 24000],
    4389 => [4116, 'BK-000310', 306, 88000],
    4390 => [4117, 'BK-000312', 308, 10000],
    4391 => [4118, 'BK-000422', 415, 216562],
    4392 => [4119, 'BK-000431', 423, 318681],
    4393 => [4120, 'BK-000453', 444, 81353],
    4394 => [4121, 'BK-000468', 460, 59977],
    4395 => [4122, 'BK-000498', 496, 86174],
    4396 => [4123, 'BK-000501', 499, 133235],
    4397 => [4124, 'BK-000559', 567, 63363],
];

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
$sourceIds = array_merge([4112], array_column($expected, 0), array_keys($expected), [4398]);
$references = array_map(static fn (int $id): string => 'DATA-REPAIR-REVERSE-JE-' . $id, array_keys($expected));
sort($references);

$db->beginTransaction();
try {
    $payment = $query('SELECT id, branch_id, payment_no, currency, paid_amount, allocated_amount,
            unallocated_amount, status, payment_scope, booking_reference
        FROM supplier_payments WHERE id = 127 FOR UPDATE');
    $check(count($payment) === 1 && $payment[0]['payment_no'] === 'SPAY-000115'
        && (int) $payment[0]['branch_id'] === 1 && $payment[0]['currency'] === 'PKR'
        && $payment[0]['status'] === 'void' && $payment[0]['payment_scope'] === 'global'
        && $payment[0]['booking_reference'] === 'GLOBAL'
        && $cents($payment[0]['paid_amount']) === 116544800
        && $cents($payment[0]['allocated_amount']) === 0
        && $cents($payment[0]['unallocated_amount']) === 0,
        'SPAY-000115 no longer matches the reviewed void payment.');

    $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
    $source = $query('SELECT je.id, je.branch_id, je.booking_reference, je.source_type,
            je.source_reference, je.currency, jel.id AS line_id, jel.supplier_payment_id,
            jel.supplier_obligation_id, jel.service_line_reference, coa.code,
            jel.debit_amount, jel.credit_amount
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id IN (' . $placeholders . ') ORDER BY je.id, jel.id FOR UPDATE', $sourceIds);
    $byJournal = [];
    foreach ($source as $row) {
        $byJournal[(int) $row['id']][] = $row;
    }
    $check(count($byJournal) === count($sourceIds), 'An original SPAY-000115 journal is missing.');

    $releaseIds = array_map('intval', array_column($query(
        'SELECT id FROM journal_entries WHERE source_type = "supplier_settlement_released"
         AND source_reference LIKE "SUPPLIER-PAYMENT-CORRECTION-SPAY-000115-RELEASE-%" ORDER BY id'
    ), 'id'));
    $check($releaseIds === array_keys($expected), 'The SPAY-000115 release set changed.');

    $validatePair = static function (array $rows, string $type, string $reference, string $booking,
        int $obligationId, int $amount, bool $release) use ($check, $cents): void {
        $first = $rows[0] ?? [];
        $check(count($rows) === 2 && (int) ($first['branch_id'] ?? 0) === 1
            && $first['booking_reference'] === $booking && $first['source_type'] === $type
            && $first['source_reference'] === $reference && $first['currency'] === 'PKR',
            'SPAY-000115 journal header differs from the reviewed source.');
        $accounts = [];
        foreach ($rows as $row) {
            $check((int) $row['supplier_obligation_id'] === $obligationId
                && (int) $row['branch_id'] === 1 && $row['currency'] === 'PKR',
                'SPAY-000115 journal obligation or branch differs.');
            $accounts[$row['code']] = [$cents($row['debit_amount']), $cents($row['credit_amount'])];
        }
        $positive = $amount * 100;
        $check(count($accounts) === 2
            && ($accounts['AP_CONTROL'] ?? null) === ($release ? [0, $positive] : [$positive, 0])
            && ($accounts['SUPPLIER_ADVANCES'] ?? null) === ($release ? [$positive, 0] : [0, $positive]),
            'SPAY-000115 journal lines differ from the reviewed amounts.');
    };

    foreach ($expected as $releaseId => [$originalId, $booking, $obligationId, $amount]) {
        $validatePair($byJournal[$originalId], 'supplier_payment_allocated',
            'SPAY-000115-ALLOC-0', $booking, $obligationId, $amount, false);
        $validatePair($byJournal[$releaseId], 'supplier_settlement_released',
            'SUPPLIER-PAYMENT-CORRECTION-SPAY-000115-RELEASE-' . ($releaseId - 4385),
            $booking, $obligationId, $amount, true);
        $check((int) $byJournal[$originalId][0]['supplier_payment_id'] === 127,
            'Original allocation journal is not linked to SPAY-000115.');
    }

    $recorded = $byJournal[4112];
    $void = $byJournal[4398];
    $check(count($recorded) === 2 && count($void) === 26
        && $recorded[0]['source_type'] === 'supplier_payment_recorded'
        && $recorded[0]['source_reference'] === 'SPAY-000115'
        && $void[0]['source_type'] === 'supplier_payment_void_reversed'
        && $void[0]['source_reference'] === 'VOID-SPAY-000115',
        'SPAY-000115 payment or void journal differs from the reviewed source.');
    foreach ([$recorded, $void] as $rows) {
        foreach ($rows as $row) {
            $check((int) $row['branch_id'] === 1 && $row['currency'] === 'PKR'
                && (int) $row['supplier_payment_id'] === 127,
                'SPAY-000115 payment or void journal dimension differs.');
        }
    }

    $repairRows = $query('SELECT id, source_reference FROM journal_entries
        WHERE source_reference IN (' . implode(',', array_fill(0, count($references), '?')) . ')
        ORDER BY source_reference FOR UPDATE', $references);
    $check(count($repairRows) === 0 || count($repairRows) === count($expected),
        'Partial SPAY-000115 repair exists; manual review required.');
    $check($repairRows === [] || array_column($repairRows, 'source_reference') === $references,
        'SPAY-000115 repair references differ from the reviewed set.');

    $fingerprint = hash('sha256', json_encode([$payment, $source, $releaseIds, $repairRows], JSON_THROW_ON_ERROR));
    $summary = [
        'payment_no' => 'SPAY-000115',
        'fingerprint' => $fingerprint,
        'duplicate_release_journal_ids' => array_keys($expected),
        'duplicate_release_total_pkr' => 1165448,
        'cash_or_bank_movements' => 0,
        'supplier_payment_or_allocation_changes' => 0,
    ];
    $net = static function (array $ids) use ($query, $check, $cents): void {
        $rows = $query('SELECT coa.code, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS amount
            FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON coa.id = jel.account_id
            WHERE je.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            GROUP BY coa.code', $ids);
        foreach ($rows as $row) {
            $check($cents($row['amount']) === 0,
                'SPAY-000115 chain is not zero in ' . $row['code'] . '.');
        }
    };

    if ($repairRows !== []) {
        $net(array_merge($sourceIds, array_map('intval', array_column($repairRows, 'id'))));
        $db->rollBack();
        echo 'ALREADY APPLIED: SPAY-000115 payment and void net to zero; duplicate releases are reversed.' . PHP_EOL;
        exit(0);
    }
    if (! $apply && ! $rehearse) {
        $db->rollBack();
        echo json_encode(['mode' => 'preview', 'inspection_only' => true] + $summary,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }

    $actorId = (int) ($options['actor'] ?? 0);
    $actor = $query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.is_active = 1 AND r.code = "super_admin"', [$actorId]);
    $check($actor !== [] && hash_equals($fingerprint, strtolower((string) ($options['fingerprint'] ?? ''))),
        'An active Super Admin and the exact preview fingerprint are required.');

    $accounting = new AccountingRepository($app);
    $journalIds = [];
    foreach ($expected as $releaseId => [, $booking]) {
        $rows = $byJournal[$releaseId];
        $lines = array_map(static fn (array $row): array => [
            'account_code' => $row['code'],
            'service_line_reference' => $row['service_line_reference'],
            'supplier_obligation_id' => (int) $row['supplier_obligation_id'],
            'line_description' => 'Reverse duplicate SPAY-000115 correction release #' . $releaseId,
            'debit_amount' => (float) $row['credit_amount'],
            'credit_amount' => (float) $row['debit_amount'],
        ], $rows);
        $journalIds[] = $accounting->postJournalEntry([
            'branch_id' => 1,
            'booking_reference' => $booking,
            'source_type' => 'data_repair_journal_reversal',
            'source_reference' => 'DATA-REPAIR-REVERSE-JE-' . $releaseId,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'narration' => 'Neutralize duplicate supplier correction release for SPAY-000115',
            'actor_user_id' => $actorId,
        ], $lines);
    }
    $net(array_merge($sourceIds, $journalIds));
    AuditLog::record($app, 'supplier.payment.duplicate_releases_reconciled', [
        'user_id' => $actorId,
        'supplier_payment_id' => 127,
        'payment_no' => 'SPAY-000115',
        'reversed_journal_ids' => array_keys($expected),
        'new_journal_ids' => $journalIds,
        'cash_changed' => false,
    ]);

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode(['mode' => $apply ? 'applied' : 'rehearsed-and-rolled-back',
        'journal_ids' => $journalIds] + $summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
