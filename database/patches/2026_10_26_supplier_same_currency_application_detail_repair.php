<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Helpers\AuditLog;

$mode = strtolower((string) ($argv[1] ?? 'preview'));
$actorId = (int) ($argv[2] ?? 0);
if (! in_array($mode, ['preview', 'dry-run', 'apply'], true)
    || ($mode !== 'preview' && $actorId <= 0)) {
    fwrite(STDERR, "Usage: php database/patches/2026_10_26_supplier_same_currency_application_detail_repair.php [preview|dry-run ACTOR_ID|apply ACTOR_ID]\n");
    exit(2);
}

// These are historical rows whose currently applied value is already verified
// by the source deposit, the obligation, and the net journal evidence.
$expected = [
    // application ID => [advance ID, obligation ID, current amount, stale detail amount]
    2 => [3, 510, 20, 901],
    5 => [4, 537, 390, 440],
    39 => [17, 668, 332, 527],
    43 => [17, 683, 1, 325],
    50 => [19, 701, 941, 2432],
    150 => [27, 1094, 28591, 28619],
    79 => [29, 843, 97444, 98444],
    142 => [29, 849, 34658, 67190],
    86 => [34, 856, 82030, 107533],
];
$reference = 'SADV-SAME-CURRENCY-DETAIL-20261026';
$db = null;
$lockHeld = false;
try {
    $app = App::bootstrap(BASE_PATH);
    /** @var PDO $db */
    $db = $app->get('db');
    if ($mode !== 'preview') {
        $lock = $db->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => $reference]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another supplier application repair is running.');
        }
        $lockHeld = true;
        $db->beginTransaction();
    }
    $one = static function (string $sql, array $args = []) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            throw new RuntimeException('Expected source row is missing.');
        }
        return $row;
    };
    $amount = static function (mixed $value): int {
        $rounded = round((float) $value, 2);
        if (abs($rounded - round($rounded)) > 0.005) {
            throw new RuntimeException('The reviewed same-currency repair requires whole amounts.');
        }
        return (int) round($rounded);
    };
    if ($mode !== 'preview') {
        $actor = $one('SELECT id FROM users WHERE id = :id AND is_active = 1', ['id' => $actorId]);
    }
    $existing = $one('SELECT COUNT(*) AS n FROM audit_logs WHERE event_name = "supplier.advance.same_currency_details_reconciled"
        AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.reference")) = :reference', ['reference' => $reference]);
    if ((int) $existing['n'] !== 0) {
        throw new RuntimeException('This reviewed detail repair has already been recorded.');
    }
    $before = [];
    $advanceIds = [];
    $obligationIds = [];
    foreach ($expected as $id => [$advanceId, $obligationId, $current, $stale]) {
        $row = $one('SELECT aa.*, sa.supplier_id, sa.currency AS source_currency,
            so.supplier_id AS obligation_supplier_id, so.currency AS target_currency
            FROM supplier_advance_applications aa
            JOIN supplier_advances sa ON sa.id = aa.supplier_advance_id
            JOIN supplier_obligations so ON so.id = aa.supplier_obligation_id
            WHERE aa.id = :id' . ($mode === 'preview' ? '' : ' FOR UPDATE'), ['id' => $id]);
        if ((int) $row['supplier_advance_id'] !== $advanceId
            || (int) $row['supplier_obligation_id'] !== $obligationId
            || $amount($row['applied_amount']) !== $current
            || $amount($row['advance_amount_consumed']) !== $stale
            || $amount($row['obligation_amount_applied']) !== $stale
            || $row['source_currency'] !== $row['target_currency']
            || $row['source_currency'] !== $row['advance_currency']
            || $row['target_currency'] !== $row['obligation_currency']
            || (int) $row['supplier_id'] !== (int) $row['obligation_supplier_id']
            || abs((float) $row['exchange_rate'] - 1.0) > 0.00000001
            || ! in_array($row['application_type'], ['same_currency_auto', 'same_currency_manual'], true)) {
            throw new RuntimeException('Application #' . $id . ' no longer matches the verified same-currency correction.');
        }
        $before[] = [
            'application_id' => $id, 'advance_id' => $advanceId, 'obligation_id' => $obligationId,
            'currency' => $row['source_currency'], 'applied' => $current,
            'stale_consumed' => $stale, 'stale_obligation_applied' => $stale,
        ];
        $advanceIds[$advanceId] = true;
        $obligationIds[$obligationId] = true;
    }
    foreach (array_keys($advanceIds) as $advanceId) {
        $row = $one('SELECT sa.deposit_amount, sa.available_amount,
            COALESCE(SUM(aa.applied_amount),0) AS current_used
            FROM supplier_advances sa LEFT JOIN supplier_advance_applications aa
            ON aa.supplier_advance_id = sa.id WHERE sa.id = :id
            GROUP BY sa.id' . ($mode === 'preview' ? '' : ' FOR UPDATE'), ['id' => $advanceId]);
        if ($amount($row['deposit_amount']) !== $amount($row['available_amount']) + $amount($row['current_used'])) {
            throw new RuntimeException('Advance #' . $advanceId . ' does not conserve its current deposit.');
        }
    }
    foreach (array_keys($obligationIds) as $obligationId) {
        $row = $one('SELECT so.advance_applied_amount,
            (SELECT COALESCE(SUM(a.applied_amount),0) FROM supplier_advance_applications a
             WHERE a.supplier_obligation_id = so.id) AS current_applications,
            (SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount),0)
             FROM journal_entry_lines jel JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.supplier_obligation_id = so.id AND coa.code = "SUPPLIER_ADVANCES") AS journal_net
            FROM supplier_obligations so WHERE so.id = :id', ['id' => $obligationId]);
        if ($amount($row['advance_applied_amount']) !== $amount($row['current_applications'])
            || $amount($row['journal_net']) !== $amount($row['current_applications'])) {
            throw new RuntimeException('Obligation #' . $obligationId . ' does not match its current applications and net journals.');
        }
    }
    $result = ['mode' => $mode, 'reference' => $reference, 'rows' => $before,
        'financial_amounts_change' => false, 'journal_posting' => false];
    if ($mode === 'preview') {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $update = $db->prepare('UPDATE supplier_advance_applications
        SET advance_amount_consumed = :consumed, obligation_amount_applied = :applied
        WHERE id = :id');
    foreach ($before as $row) {
        $update->execute([
            'consumed' => $row['applied'], 'applied' => $row['applied'], 'id' => $row['application_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Application #' . $row['application_id'] . ' was not updated exactly once.');
        }
    }
    $auditId = AuditLog::recordRequired($app, 'supplier.advance.same_currency_details_reconciled', [
        'user_id' => $actorId, 'reference' => $reference, 'rows' => $before,
        'financial_amounts_change' => false, 'cash_movement' => false, 'journal_posting' => false,
    ]);
    $result['audit_id'] = $auditId;
    if ($mode === 'dry-run') {
        $db->rollBack();
        $result['rolled_back'] = true;
    } else {
        $db->commit();
        $result['committed'] = true;
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Same-currency detail repair failed; no partial change: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($lockHeld && $db instanceof PDO) {
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name' => $reference]);
    }
}
