<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;

$supplierId = 0;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--supplier-id=(\d+)$/', $argument, $matches) === 1) {
        $supplierId = (int) $matches[1];
    } elseif ($argument === '--apply') {
        $apply = true;
    } else {
        fwrite(STDERR, "Usage: php scripts/reconcile_supplier_advance_metadata.php --supplier-id=349 [--apply]\n");
        exit(2);
    }
}
if ($supplierId <= 0) {
    fwrite(STDERR, "A specific supplier ID is required. No data was changed.\n");
    exit(2);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$db->beginTransaction();
try {
    $statement = $db->prepare(
        'SELECT x.id, x.supplier_advance_id, x.supplier_obligation_id, x.applied_amount,
                x.advance_amount_consumed, x.obligation_amount_applied,
                a.currency AS advance_currency, o.currency AS obligation_currency,
                x.exchange_rate
         FROM supplier_advance_applications x
         INNER JOIN supplier_advances a ON a.id = x.supplier_advance_id
         INNER JOIN supplier_obligations o ON o.id = x.supplier_obligation_id
         WHERE a.supplier_id = :supplier_id
           AND (ABS(x.applied_amount - COALESCE(x.advance_amount_consumed, x.applied_amount)) > 0.005
                OR ABS(x.applied_amount - COALESCE(x.obligation_amount_applied, x.applied_amount)) > 0.005)
         ORDER BY x.id FOR UPDATE'
    );
    $statement->execute(['supplier_id' => $supplierId]);
    $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $advanceCheck = $db->prepare(
        'SELECT a.deposit_amount, a.available_amount,
                COALESCE(SUM(x.applied_amount), 0) AS applied_total,
                SUM(CASE WHEN o.currency <> a.currency THEN 1 ELSE 0 END) AS cross_currency_count
         FROM supplier_advances a
         LEFT JOIN supplier_advance_applications x ON x.supplier_advance_id = a.id
         LEFT JOIN supplier_obligations o ON o.id = x.supplier_obligation_id
         WHERE a.id = :id
         GROUP BY a.id, a.deposit_amount, a.available_amount'
    );
    $obligationCheck = $db->prepare(
        'SELECT o.advance_applied_amount, COALESCE(SUM(x.applied_amount), 0) AS applied_total
         FROM supplier_obligations o
         LEFT JOIN supplier_advance_applications x ON x.supplier_obligation_id = o.id
         WHERE o.id = :id
         GROUP BY o.id, o.advance_applied_amount'
    );
    $update = $db->prepare(
        'UPDATE supplier_advance_applications
         SET advance_amount_consumed = :advance_amount, obligation_amount_applied = :obligation_amount
         WHERE id = :id'
    );
    $reconciled = [];
    foreach ($candidates as $row) {
        $advanceCheck->execute(['id' => (int) $row['supplier_advance_id']]);
        $advance = $advanceCheck->fetch(PDO::FETCH_ASSOC);
        $obligationCheck->execute(['id' => (int) $row['supplier_obligation_id']]);
        $obligation = $obligationCheck->fetch(PDO::FETCH_ASSOC);
        if (! is_array($advance) || ! is_array($obligation)
            || strtoupper((string) $row['advance_currency']) !== strtoupper((string) $row['obligation_currency'])
            || (int) $advance['cross_currency_count'] !== 0
            || ($row['exchange_rate'] !== null && abs((float) $row['exchange_rate'] - 1.0) > 0.00000001)
            || abs((float) $advance['deposit_amount'] - (float) $advance['available_amount'] - (float) $advance['applied_total']) > 0.005
            || abs((float) $obligation['advance_applied_amount'] - (float) $obligation['applied_total']) > 0.005) {
            throw new RuntimeException('Application #' . $row['id'] . ' lacks matching source/obligation evidence. Nothing was changed.');
        }
        $reconciled[] = [
            'application_id' => (int) $row['id'],
            'advance_id' => (int) $row['supplier_advance_id'],
            'obligation_id' => (int) $row['supplier_obligation_id'],
            'old_advance_consumed' => (float) $row['advance_amount_consumed'],
            'old_obligation_applied' => (float) $row['obligation_amount_applied'],
            'reconciled_amount' => (float) $row['applied_amount'],
            'currency' => (string) $row['advance_currency'],
        ];
        if ($apply) {
            $update->execute([
                'id' => (int) $row['id'],
                'advance_amount' => $row['applied_amount'],
                'obligation_amount' => $row['applied_amount'],
            ]);
        }
    }

    if ($apply && $reconciled !== []) {
        AuditLog::recordRequired($app, 'supplier.advance.metadata_reconciled', [
            'supplier_id' => $supplierId,
            'reason' => 'Same-currency application detail realigned to conserved advance and obligation amounts',
            'applications' => $reconciled,
            'money_movement' => false,
        ]);
    }
    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo ($apply ? 'APPLIED' : 'DRY RUN') . ': ' . count($reconciled) . ' same-currency application detail rows.' . PHP_EOL;
    foreach ($reconciled as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
