<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$mode = $argv[1] ?? 'dry-run';
if (! in_array($mode, ['dry-run', 'apply'], true)) {
    fwrite(STDERR, "Usage: php database/patches/2026_10_28_bk001185_supplier_payable_transfer.php [dry-run|apply] [actor-id]\n");
    exit(2);
}
$actorId = (int) ($argv[2] ?? 0);
if ($actorId <= 0) {
    fwrite(STDERR, "An active actor ID is required.\n");
    exit(2);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$actor = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
$actor->execute([$actorId]);
if (! $actor->fetchColumn()) {
    throw new RuntimeException('The actor is not active.');
}

$db->beginTransaction();
try {
    $source = $db->query('SELECT bs.id AS service_id, bs.supplier_id AS service_supplier_id,
            so.id AS obligation_id, so.supplier_id AS obligation_supplier_id,
            so.branch_id, so.currency, so.gross_amount, so.advance_applied_amount,
            so.net_payable_amount, so.service_line_reference
        FROM supplier_obligations so
        JOIN bookings b ON b.booking_reference = so.booking_reference
        JOIN booking_services bs ON bs.booking_id = b.id
            AND bs.line_reference = so.service_line_reference
        WHERE so.booking_reference = "BK-001185" AND so.obligation_group = "service_cost"
        FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if (count($source) !== 1) {
        throw new RuntimeException('Expected one BK-001185 service payable.');
    }
    $row = $source[0];
    if ((int) $row['service_supplier_id'] !== 426
        || (int) $row['obligation_supplier_id'] !== 426
        || (int) $row['branch_id'] !== 2
        || (string) $row['currency'] !== 'PKR'
        || abs((float) $row['gross_amount'] - 49982.0) > 0.005
        || abs((float) $row['advance_applied_amount'] - 49982.0) > 0.005
        || abs((float) $row['net_payable_amount']) > 0.005) {
        throw new RuntimeException('BK-001185 differs from the reviewed source snapshot; no correction was posted.');
    }
    $position = $db->prepare('SELECT jel.supplier_id_snapshot,
            ROUND(SUM(jel.credit_amount - jel.debit_amount), 2) AS payable
        FROM journal_entry_lines jel
        JOIN journal_entries je ON je.id = jel.journal_entry_id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE jel.supplier_obligation_id = ? AND coa.code = "AP_CONTROL"
            AND je.source_type IN ("supplier_payable_created", "supplier_payable_adjusted", "supplier_payable_transferred")
        GROUP BY jel.supplier_id_snapshot');
    $position->execute([(int) $row['obligation_id']]);
    $balances = [];
    foreach ($position->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $balances[(int) $item['supplier_id_snapshot']] = (float) $item['payable'];
    }
    if (count($balances) !== 1 || abs(($balances[371] ?? 0) - 49982.0) > 0.005) {
        throw new RuntimeException('Supplier payable journals differ from the reviewed old-supplier balance; no correction was posted.');
    }

    $journalId = (new \App\Repositories\AccountingRepository($app))->postSupplierPayableTransfer([
        'branch_id' => 2,
        'booking_reference' => 'BK-001185',
        'source_reference' => 'BK-001185-SUPPLIER-TRANSFER-20261028',
        'service_line_reference' => (string) $row['service_line_reference'],
        'supplier_obligation_id' => (int) $row['obligation_id'],
        'prior_supplier_id' => 371,
        'new_supplier_id' => 426,
        'amount' => 49982.0,
        'currency' => 'PKR',
        'entry_date' => date('Y-m-d'),
        'actor_user_id' => $actorId,
    ]);
    $position->execute([(int) $row['obligation_id']]);
    $after = [];
    foreach ($position->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $after[(int) $item['supplier_id_snapshot']] = (float) $item['payable'];
    }
    if (abs($after[371] ?? 0) > 0.005 || abs(($after[426] ?? 0) - 49982.0) > 0.005) {
        throw new RuntimeException('Supplier transfer did not reconcile; no correction was committed.');
    }
    if ($mode === 'apply') {
        $db->commit();
    } else {
        $db->rollBack();
    }
    echo json_encode([
        'mode' => $mode,
        'booking' => 'BK-001185',
        'currency' => 'PKR',
        'amount' => 49982,
        'old_supplier_id' => 371,
        'new_supplier_id' => 426,
        'journal_id' => $journalId,
        'before' => $balances,
        'after' => $after,
        'committed' => $mode === 'apply',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
