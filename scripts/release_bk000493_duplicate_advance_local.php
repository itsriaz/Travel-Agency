<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$apply = false;
$dryRun = false;
$actorId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') $apply = true;
    elseif ($argument === '--dry-run') $dryRun = true;
    elseif (preg_match('/^--actor-id=(\d+)$/', $argument, $match)) $actorId = (int) $match[1];
    else {
        fwrite(STDERR, "Usage: php scripts/release_bk000493_duplicate_advance_local.php [--dry-run|--apply --actor-id=N]\n");
        exit(2);
    }
}
if (($apply && $dryRun) || (($apply || $dryRun) && $actorId <= 0)) {
    fwrite(STDERR, "A write rehearsal or apply requires exactly one mode and an actor ID.\n");
    exit(2);
}
$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
if ($apply || $dryRun) {
    $actor = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $actor->execute([$actorId]);
    if ($actor->fetchColumn() === false) {
        fwrite(STDERR, "The actor ID must belong to an active user.\n");
        exit(2);
    }
}
$db->beginTransaction();
try {
    $obligation = $db->query('SELECT * FROM supplier_obligations WHERE id = 492 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    $applications = $db->query('SELECT a.*, s.supplier_id, s.currency, s.deposit_amount, s.available_amount
        FROM supplier_advance_applications a JOIN supplier_advances s ON s.id = a.supplier_advance_id
        WHERE a.supplier_obligation_id = 492 ORDER BY a.id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $payments = (float) $db->query('SELECT COALESCE(SUM(a.allocated_amount), 0)
        FROM supplier_payment_allocations a JOIN supplier_payments p ON p.id = a.supplier_payment_id
        WHERE a.supplier_obligation_id = 492 AND p.status <> "void"')->fetchColumn();
    $offsets = (float) $db->query('SELECT COALESCE(SUM(a.allocated_amount), 0)
        FROM counterparty_offset_payable_allocations a JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id
        WHERE a.supplier_obligation_id = 492 AND o.status = "posted"')->fetchColumn();
    $journal = $db->query('SELECT je.id, je.source_type, je.branch_id, je.currency, je.source_reference,
        SUM(CASE WHEN coa.code = "AP_CONTROL" THEN l.debit_amount ELSE 0 END) AS ap_debit,
        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN l.credit_amount ELSE 0 END) AS advance_credit,
        COUNT(*) AS line_count
        FROM journal_entries je JOIN journal_entry_lines l ON l.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = l.account_id WHERE je.id = 2945 GROUP BY je.id')->fetch(PDO::FETCH_ASSOC);
    if (! $obligation || (int) $obligation['supplier_id'] !== 371 || (int) $obligation['branch_id'] !== 2
        || $obligation['booking_reference'] !== 'BK-000493' || $obligation['currency'] !== 'AED'
        || abs((float) $obligation['gross_amount'] - 665) > 0.005
        || abs((float) $obligation['advance_applied_amount'] - 665) > 0.005
        || abs((float) $obligation['net_payable_amount']) > 0.005
        || abs($payments + $offsets - 665) > 0.005
        || array_map(static fn (array $a): int => (int) $a['id'], $applications) !== [17, 18]
        || array_map(static fn (array $a): int => (int) $a['supplier_advance_id'], $applications) !== [11, 12]
        || ! $journal || $journal['source_type'] !== 'supplier_advance_applied'
        || (int) $journal['branch_id'] !== 2 || $journal['currency'] !== 'AED'
        || (int) $journal['line_count'] !== 2
        || abs((float) $journal['ap_debit'] - 665) > 0.005
        || abs((float) $journal['advance_credit'] - 665) > 0.005) {
        throw new RuntimeException('BK-000493 source or journal evidence changed; no correction applied.');
    }
    foreach ([388.0, 277.0] as $index => $amount) {
        $a = $applications[$index];
        if ((int) $a['supplier_id'] !== 371 || $a['currency'] !== 'AED'
            || $a['application_type'] !== 'same_currency_auto'
            || abs((float) $a['applied_amount'] - $amount) > 0.005
            || abs((float) $a['advance_amount_consumed'] - $amount) > 0.005
            || abs((float) $a['obligation_amount_applied'] - $amount) > 0.005
            || (float) $a['available_amount'] + $amount > (float) $a['deposit_amount'] + 0.005) {
            throw new RuntimeException('Advance application evidence changed; no correction applied.');
        }
    }
    $already = $db->query('SELECT COUNT(*) FROM journal_entries
        WHERE source_type = "supplier_advance_overcoverage_reversed" AND source_reference = "REV-JE-2945"')->fetchColumn();
    if ((int) $already !== 0) {
        throw new RuntimeException('The advance journal was already reversed.');
    }
    echo "BK-000493: release AED 388 and AED 277 from advances #11 and #12; cash and supplier bill unchanged.\n";
    if (! $apply && ! $dryRun) {
        $db->rollBack();
        exit(0);
    }
    $result = (new \App\Repositories\SupplierRepository($app))->autoApplyAvailableAdvanceToObligation(492, $actorId);
    if (abs((float) ($result['applied_amount_delta'] ?? 0) + 665) > 0.005) {
        throw new RuntimeException('The reconciler did not release exactly AED 665.');
    }
    $reversalId = (new \App\Repositories\AccountingRepository($app))->reverseJournalEntry(2945, [
        'source_type' => 'supplier_advance_overcoverage_reversed',
        'source_reference' => 'REV-JE-2945',
        'entry_date' => date('Y-m-d'),
        'narration' => 'Reverse duplicate supplier advance coverage on fully paid BK-000493',
        'actor_user_id' => $actorId,
    ]);
    if (! $reversalId) {
        throw new RuntimeException('Advance journal reversal failed.');
    }
    \App\Helpers\AuditLog::recordRequired($app, 'supplier.advance.overcoverage.repaired', [
        'user_id' => $actorId,
        'supplier_id' => 371, 'supplier_obligation_id' => 492,
        'application_ids' => [17, 18], 'released_amount' => 665,
        'original_journal_id' => 2945, 'reversal_journal_id' => $reversalId,
        'cash_movement' => false,
    ]);
    if ($dryRun) {
        $db->rollBack();
        echo "DRY RUN rolled back; no records changed.\n";
    } else {
        $db->commit();
        echo 'Released duplicate applications; reversal journal #' . $reversalId . PHP_EOL;
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
