<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$column = $db->query('SHOW COLUMNS FROM journal_entry_lines LIKE "supplier_id_snapshot"')->fetch();
if ($column === false) {
    throw new RuntimeException('Run migration 20261018_000081 before the supplier snapshot regression.');
}

$fixture = $db->query('SELECT sp.id AS payment_id, sp.supplier_id AS current_supplier_id,
        so.id AS obligation_id, so.supplier_id AS original_supplier_id,
        so.branch_id, so.booking_reference, so.service_line_reference, so.currency
    FROM supplier_payments sp
    INNER JOIN supplier_obligations so ON so.booking_reference = "BK-000716"
    WHERE sp.payment_no = "SPAY-000114" AND so.supplier_id <> sp.supplier_id
    LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (! is_array($fixture)) {
    echo '[SKIP] Supplier-change fixture is not present in this database.' . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $accounting = new \App\Repositories\AccountingRepository($app);
    $insertAllocation = $db->prepare('INSERT INTO supplier_payment_allocations
        (supplier_payment_id, supplier_obligation_id, allocated_amount, allocation_note, exchange_rate_used)
        VALUES (:payment_id, :obligation_id, 1, "Rollback-only supplier snapshot test", 1.00000000)');
    $insertAllocation->execute([
        'payment_id' => (int) $fixture['payment_id'],
        'obligation_id' => (int) $fixture['obligation_id'],
    ]);
    $allocationId = (int) $db->lastInsertId();
    if ($allocationId <= 0) {
        throw new RuntimeException('Rollback-only test allocation ID was not recorded.');
    }
    $paymentJournalId = $accounting->postJournalEntry([
        'branch_id' => (int) $fixture['branch_id'],
        'booking_reference' => (string) $fixture['booking_reference'],
        'source_type' => 'supplier_payment_recorded',
        'source_reference' => 'SPAY-000114-SNAPSHOT-TEST-PAYMENT',
        'entry_date' => '2026-10-18',
        'currency' => (string) $fixture['currency'],
        'narration' => 'Rollback-only payment supplier snapshot test',
    ], [
        ['account_code' => 'SUPPLIER_ADVANCES', 'supplier_payment_id' => (int) $fixture['payment_id'],
            'debit_amount' => 1, 'credit_amount' => 0],
        ['account_code' => 'AP_CONTROL', 'supplier_payment_id' => (int) $fixture['payment_id'],
            'debit_amount' => 0, 'credit_amount' => 1],
    ]);
    $paymentSnapshotStatement = $db->prepare('SELECT DISTINCT supplier_id_snapshot
        FROM journal_entry_lines WHERE journal_entry_id = :journal_id');
    $paymentSnapshotStatement->execute(['journal_id' => $paymentJournalId]);
    if (array_map('intval', $paymentSnapshotStatement->fetchAll(PDO::FETCH_COLUMN))
        !== [(int) $fixture['current_supplier_id']]) {
        throw new RuntimeException('New payment-only journal lines did not preserve the payment supplier.');
    }
    $reference = 'SPAY-000114-ALLOC-' . $allocationId;
    $allocationJournalId = $accounting->postSupplierPaymentAllocation([
        'branch_id' => (int) $fixture['branch_id'],
        'booking_reference' => (string) $fixture['booking_reference'],
        'source_reference' => $reference,
        'service_line_reference' => (string) $fixture['service_line_reference'],
        'supplier_obligation_id' => (int) $fixture['obligation_id'],
        'supplier_payment_id' => (int) $fixture['payment_id'],
        'allocated_amount' => 1,
        'entry_date' => '2026-10-18',
        'currency' => (string) $fixture['currency'],
    ]);
    $releaseJournalId = $accounting->postSupplierSettlementRelease([
        'branch_id' => (int) $fixture['branch_id'],
        'booking_reference' => (string) $fixture['booking_reference'],
        'source_reference' => 'SUPPLIER-CORRECTION-SPAY-000114-RELEASE-SNAPSHOT-TEST',
        'service_line_reference' => (string) $fixture['service_line_reference'],
        'supplier_obligation_id' => (int) $fixture['obligation_id'],
        'released_amount' => 1,
        'entry_date' => '2026-10-18',
        'currency' => (string) $fixture['currency'],
    ]);

    $snapshotStatement = $db->prepare('SELECT DISTINCT supplier_id_snapshot
        FROM journal_entry_lines
        WHERE journal_entry_id IN (:allocation_journal_id, :release_journal_id)');
    $snapshotStatement->execute([
        'allocation_journal_id' => $allocationJournalId,
        'release_journal_id' => $releaseJournalId,
    ]);
    $snapshots = array_map('intval', $snapshotStatement->fetchAll(PDO::FETCH_COLUMN));
    if ($snapshots !== [(int) $fixture['original_supplier_id']]) {
        throw new RuntimeException('New allocation and release lines did not preserve the obligation supplier.');
    }

    $repository = new \App\Repositories\ReportRepository($app);
    $branchIds = [(int) $fixture['branch_id']];
    $oldRows = $repository->supplierLedger($branchIds, null, null, (string) $fixture['currency'], '', (int) $fixture['original_supplier_id']);
    $newRows = $repository->supplierLedger($branchIds, null, null, (string) $fixture['currency'], '', (int) $fixture['current_supplier_id']);
    $journalIds = [$allocationJournalId, $releaseJournalId];
    $oldMatched = array_values(array_filter($oldRows, static fn (array $row): bool => in_array((int) ($row['journal_entry_id'] ?? 0), $journalIds, true)));
    $newMatched = array_values(array_filter($newRows, static fn (array $row): bool => in_array((int) ($row['journal_entry_id'] ?? 0), $journalIds, true)));
    if (count($oldMatched) !== 2 || $newMatched !== []) {
        throw new RuntimeException('The allocation and its release must both remain with the original supplier.');
    }
    $debit = array_sum(array_map(static fn (array $row): float => (float) $row['debit_amount'], $oldMatched));
    $credit = array_sum(array_map(static fn (array $row): float => (float) $row['credit_amount'], $oldMatched));
    if (abs($debit - $credit) > 0.005) {
        throw new RuntimeException('The future allocation and correction release must cancel in the original supplier ledger.');
    }

    $voidJournalId = $accounting->postSupplierPaymentVoidReversal([
        'supplier_payment_id' => (int) $fixture['payment_id'],
        'payment_no' => 'SPAY-000114',
        'branch_id' => (int) $fixture['branch_id'],
        'booking_reference' => (string) $fixture['booking_reference'],
        'source_reference' => 'SPAY-000114-SNAPSHOT-TEST-VOID',
        'entry_date' => '2026-10-18',
        'currency' => (string) $fixture['currency'],
    ]);
    if ($voidJournalId === null) {
        throw new RuntimeException('The test payment reversal did not post.');
    }
    $voidSnapshotStatement = $db->prepare('SELECT supplier_id_snapshot
        FROM journal_entry_lines
        WHERE journal_entry_id = :journal_id
          AND supplier_obligation_id = :obligation_id
          AND (ABS(debit_amount - 1) < 0.005 OR ABS(credit_amount - 1) < 0.005)');
    $voidSnapshotStatement->execute([
        'journal_id' => $voidJournalId,
        'obligation_id' => (int) $fixture['obligation_id'],
    ]);
    $voidSnapshots = array_map('intval', $voidSnapshotStatement->fetchAll(PDO::FETCH_COLUMN));
    if (count($voidSnapshots) !== 2
        || array_values(array_unique($voidSnapshots)) !== [(int) $fixture['original_supplier_id']]) {
        throw new RuntimeException('The void reversal did not preserve the original allocation supplier.');
    }

    echo '[PASS] New journal lines retain their posting-time supplier.' . PHP_EOL;
    echo '[PASS] Payment-only journal lines retain their posting-time supplier.' . PHP_EOL;
    echo '[PASS] A changed payment header cannot move old allocation or release rows to the new supplier.' . PHP_EOL;
    echo '[PASS] Allocation and release cancel without changing the original supplier closing balance.' . PHP_EOL;
    echo '[PASS] Voiding a payment retains the supplier snapshot of its original journal lines.' . PHP_EOL;
} finally {
    $db->rollBack();
}
