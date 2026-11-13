<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$supplierId = 0;
$obligationId = 0;
$apply = false;
$dryRun = false;
$actorId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--supplier-id=(\d+)$/', $argument, $matches)) {
        $supplierId = (int) $matches[1];
    } elseif (preg_match('/^--obligation-id=(\d+)$/', $argument, $matches)) {
        $obligationId = (int) $matches[1];
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--actor-id=(\d+)$/', $argument, $matches)) {
        $actorId = (int) $matches[1];
    } else {
        fwrite(STDERR, "Usage: php scripts/release_overcovered_supplier_advance.php --supplier-id=N --obligation-id=N [--dry-run|--apply --actor-id=N]\n");
        exit(2);
    }
}
if ($supplierId <= 0 || $obligationId <= 0) {
    fwrite(STDERR, "Both supplier and obligation IDs are required. Read-only unless --apply is supplied.\n");
    exit(2);
}
if (($apply && $dryRun) || (($apply || $dryRun) && $actorId <= 0)) {
    fwrite(STDERR, "A write rehearsal or apply requires exactly one mode and an actor ID.\n");
    exit(2);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
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
    $statement = $db->prepare('SELECT * FROM supplier_obligations WHERE id = :id AND supplier_id = :supplier_id FOR UPDATE');
    $statement->execute(['id' => $obligationId, 'supplier_id' => $supplierId]);
    $obligation = $statement->fetch(PDO::FETCH_ASSOC);
    if (! $obligation) {
        throw new RuntimeException('The selected supplier obligation does not exist.');
    }
    $statement = $db->prepare('SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payment_allocations WHERE supplier_obligation_id = :id');
    $statement->execute(['id' => $obligationId]);
    $paymentAllocated = (float) $statement->fetchColumn();
    $statement = $db->prepare('SELECT COALESCE(SUM(a.allocated_amount), 0)
        FROM counterparty_offset_payable_allocations a
        JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id AND o.status = "posted"
        WHERE a.supplier_obligation_id = :id');
    $statement->execute(['id' => $obligationId]);
    $linkedOffset = (float) $statement->fetchColumn();
    $statement = $db->prepare('SELECT a.*, sa.supplier_id, sa.currency, sa.deposit_amount, sa.available_amount
        FROM supplier_advance_applications a
        JOIN supplier_advances sa ON sa.id = a.supplier_advance_id
        WHERE a.supplier_obligation_id = :id FOR UPDATE');
    $statement->execute(['id' => $obligationId]);
    $applications = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($applications) !== 1) {
        throw new RuntimeException('A single independently traceable advance application is required.');
    }
    $application = $applications[0];
    $amount = (float) $application['applied_amount'];
    if ($amount <= 0.005
        || abs($paymentAllocated + $linkedOffset - (float) $obligation['gross_amount']) > 0.005
        || abs($amount - (float) $obligation['advance_applied_amount']) > 0.005
        || abs((float) $obligation['net_payable_amount']) > 0.005
        || (int) $application['supplier_id'] !== $supplierId
        || (string) $application['currency'] !== (string) $obligation['currency']
        || (string) $application['application_type'] !== 'same_currency_auto'
        || (float) $application['available_amount'] + $amount > (float) $application['deposit_amount'] + 0.005) {
        throw new RuntimeException('The payable is not safely identifiable as fully paid plus duplicate same-currency advance use.');
    }

    $journalStatement = $db->prepare('SELECT je.id
        FROM journal_entries je
        JOIN journal_entry_lines ap ON ap.journal_entry_id = je.id AND ap.supplier_obligation_id = :obligation_id
        JOIN chart_of_accounts coa_ap ON coa_ap.id = ap.account_id AND coa_ap.code = "AP_CONTROL"
        JOIN journal_entry_lines advance_line ON advance_line.journal_entry_id = je.id
        JOIN chart_of_accounts coa_advance ON coa_advance.id = advance_line.account_id AND coa_advance.code = "SUPPLIER_ADVANCES"
        WHERE je.source_type = "supplier_advance_applied"
          AND je.currency = :currency
          AND ABS(ap.debit_amount - :amount) < 0.005 AND ap.credit_amount = 0
          AND ABS(advance_line.credit_amount - :advance_amount) < 0.005 AND advance_line.debit_amount = 0
        ORDER BY je.id');
    $journalStatement->execute([
        'obligation_id' => $obligationId, 'currency' => $obligation['currency'],
        'amount' => $amount, 'advance_amount' => $amount,
    ]);
    $journalIds = array_map('intval', $journalStatement->fetchAll(PDO::FETCH_COLUMN));
    if (count($journalIds) !== 1) {
        throw new RuntimeException('A unique matching advance-application journal was not found.');
    }
    $originalJournalId = $journalIds[0];
    $statement = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE source_reference = :reference AND source_type = "supplier_advance_overcoverage_reversed"');
    $statement->execute(['reference' => 'REV-JE-' . $originalJournalId]);
    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException('This advance-application journal has already been reversed.');
    }

    echo 'Supplier #' . $supplierId . ' / ' . $obligation['booking_reference']
        . ' / ' . $obligation['currency'] . ' ' . number_format($amount, 2)
        . ' duplicate advance application #' . $application['id']
        . ' / journal #' . $originalJournalId . PHP_EOL;
    if (! $apply && ! $dryRun) {
        $db->rollBack();
        echo "Dry run only; no records changed.\n";
        exit(0);
    }

    $result = (new \App\Repositories\SupplierRepository($app))->autoApplyAvailableAdvanceToObligation($obligationId, $actorId);
    if (abs((float) ($result['applied_amount_delta'] ?? 0) + $amount) > 0.005) {
        throw new RuntimeException('The reconciler did not release exactly the duplicate advance amount.');
    }
    $reversalId = (new \App\Repositories\AccountingRepository($app))->reverseJournalEntry($originalJournalId, [
        'source_type' => 'supplier_advance_overcoverage_reversed',
        'source_reference' => 'REV-JE-' . $originalJournalId,
        'entry_date' => date('Y-m-d'),
        'narration' => 'Reverse duplicate advance coverage on fully paid supplier payable',
        'actor_user_id' => $actorId,
    ]);
    if (! $reversalId) {
        throw new RuntimeException('The original advance journal could not be reversed.');
    }
    \App\Helpers\AuditLog::recordRequired($app, 'supplier.advance.overcoverage.repaired', [
        'user_id' => $actorId,
        'supplier_id' => $supplierId, 'supplier_obligation_id' => $obligationId,
        'supplier_advance_id' => (int) $application['supplier_advance_id'],
        'application_id' => (int) $application['id'], 'released_amount' => $amount,
        'original_journal_id' => $originalJournalId, 'reversal_journal_id' => $reversalId,
    ]);
    if ($dryRun) {
        $db->rollBack();
        echo "DRY RUN rolled back; no records changed.\n";
    } else {
        $db->commit();
        echo 'Released duplicate coverage and posted reversal journal #' . $reversalId . PHP_EOL;
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
