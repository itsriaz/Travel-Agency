<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;

$apply = false;
$dryRun = false;
$actorId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') $apply = true;
    elseif ($argument === '--dry-run') $dryRun = true;
    elseif (preg_match('/^--actor-id=(\d+)$/', $argument, $match)) $actorId = (int) $match[1];
    else {
        fwrite(STDERR, "Usage: php scripts/reconcile_supplier_overcoverage_local.php [--dry-run|--apply --actor-id=N]\n");
        exit(2);
    }
}
if (($apply && $dryRun) || (($apply || $dryRun) && $actorId <= 0)) {
    fwrite(STDERR, "A write rehearsal or apply requires exactly one mode and an actor ID.\n");
    exit(2);
}
$app = App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
if ($apply || $dryRun) {
    $actor = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $actor->execute([$actorId]);
    if ($actor->fetchColumn() === false) {
        fwrite(STDERR, "The actor ID must belong to an active user.\n");
        exit(2);
    }
}
$accounting = new AccountingRepository($app);
$db->beginTransaction();
try {
    $targets = [
        ['obligation' => 136, 'supplier' => 419, 'booking' => 'BK-000136', 'gross' => 740, 'allocation' => 862,
            'payment' => 230, 'payment_no' => 'SPAY-000218', 'allocated' => 740, 'release' => 740, 'journal' => 6229],
        ['obligation' => 250, 'supplier' => 422, 'booking' => 'BK-000252', 'gross' => 1000, 'allocation' => 200,
            'payment' => 53, 'payment_no' => 'SPAY-000045', 'allocated' => 1000, 'release' => 985, 'journal' => 2498],
    ];
    $readObligation = $db->prepare('SELECT * FROM supplier_obligations WHERE id = ? FOR UPDATE');
    $readAllocation = $db->prepare('SELECT * FROM supplier_payment_allocations WHERE id = ? FOR UPDATE');
    $readPayment = $db->prepare('SELECT * FROM supplier_payments WHERE id = ? FOR UPDATE');
    $activeCoverage = $db->prepare('SELECT COALESCE(SUM(a.allocated_amount), 0) FROM supplier_payment_allocations a
        JOIN supplier_payments p ON p.id = a.supplier_payment_id AND p.status <> "void"
        WHERE a.supplier_obligation_id = ?');
    $journalEvidence = $db->prepare('SELECT COUNT(*) FROM journal_entries je JOIN journal_entry_lines jel
        ON jel.journal_entry_id = je.id JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = ? AND je.source_type = "supplier_payment_allocated" AND je.source_reference = ?
        AND jel.supplier_obligation_id = ? AND coa.code = "AP_CONTROL" AND ABS(jel.debit_amount - ?) < 0.005');
    $changes = [];
    foreach ($targets as $target) {
        $readObligation->execute([$target['obligation']]);
        $obligation = $readObligation->fetch(PDO::FETCH_ASSOC);
        $readAllocation->execute([$target['allocation']]);
        $allocation = $readAllocation->fetch(PDO::FETCH_ASSOC);
        $readPayment->execute([$target['payment']]);
        $payment = $readPayment->fetch(PDO::FETCH_ASSOC);
        $activeCoverage->execute([$target['obligation']]);
        $journalEvidence->execute([$target['journal'], $target['payment_no'] . '-ALLOC-0',
            $target['obligation'], $target['allocated']]);
        if (! $obligation || ! $allocation || ! $payment
            || (int) $obligation['supplier_id'] !== $target['supplier']
            || (string) $obligation['booking_reference'] !== $target['booking']
            || (int) $obligation['branch_id'] !== 2
            || (string) $obligation['service_line_reference'] !== 'SV-001'
            || (string) $obligation['currency'] !== 'AED'
            || abs((float) $obligation['gross_amount'] - $target['gross']) > 0.005
            || abs((float) $obligation['advance_applied_amount']) > 0.005
            || abs((float) $obligation['net_payable_amount']) > 0.005
            || (int) $allocation['supplier_obligation_id'] !== $target['obligation']
            || (int) $allocation['supplier_payment_id'] !== $target['payment']
            || abs((float) $allocation['allocated_amount'] - $target['allocated']) > 0.005
            || (int) $payment['supplier_id'] !== $target['supplier']
            || (string) $payment['payment_no'] !== $target['payment_no']
            || (string) $payment['currency'] !== 'AED'
            || (string) $payment['status'] === 'void'
            || abs((float) $activeCoverage->fetchColumn() - $target['gross'] - $target['release']) > 0.005
            || (int) $journalEvidence->fetchColumn() !== 1
            || abs((float) $payment['paid_amount'] - (float) $payment['allocated_amount']
                - (float) $payment['unallocated_amount'] - (float) $payment['converted_advance_amount']
                - (float) ($payment['returned_amount'] ?? 0)) > 0.005) {
            throw new RuntimeException('Source evidence changed for ' . $target['booking'] . '; no correction applied.');
        }
        $changes[] = $target;
    }

    $readObligation->execute([183]);
    $partialObligation = $readObligation->fetch(PDO::FETCH_ASSOC);
    $readAdvance = $db->prepare('SELECT aa.*, sa.supplier_id, sa.currency, sa.deposit_amount, sa.available_amount
        FROM supplier_advance_applications aa JOIN supplier_advances sa ON sa.id = aa.supplier_advance_id
        WHERE aa.id = 13 FOR UPDATE');
    $readAdvance->execute();
    $application = $readAdvance->fetch(PDO::FETCH_ASSOC);
    $activeCoverage->execute([183]);
    $advanceJournal = $db->prepare('SELECT COUNT(*) FROM journal_entries je JOIN journal_entry_lines jel
        ON jel.journal_entry_id = je.id JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.id = 2824 AND je.source_type = "supplier_advance_applied"
        AND jel.supplier_obligation_id = 183 AND coa.code = "AP_CONTROL"
        AND ABS(jel.debit_amount - 50) < 0.005');
    $advanceJournal->execute();
    if (! $partialObligation || ! $application
        || (int) $partialObligation['supplier_id'] !== 371
        || (string) $partialObligation['booking_reference'] !== 'BK-000184'
        || (int) $partialObligation['branch_id'] !== 2
        || (string) $partialObligation['service_line_reference'] !== 'SV-001'
        || (string) $partialObligation['currency'] !== 'AED'
        || abs((float) $partialObligation['gross_amount'] - 668) > 0.005
        || abs((float) $partialObligation['advance_applied_amount'] - 50) > 0.005
        || abs((float) $partialObligation['net_payable_amount']) > 0.005
        || abs((float) $activeCoverage->fetchColumn() - 665) > 0.005
        || (int) $application['supplier_obligation_id'] !== 183
        || (int) $application['supplier_id'] !== 371
        || (string) $application['currency'] !== 'AED'
        || abs((float) $application['applied_amount'] - 50) > 0.005
        || abs((float) $application['advance_amount_consumed'] - 50) > 0.005
        || abs((float) $application['obligation_amount_applied'] - 50) > 0.005
        || (float) $application['available_amount'] + 50 > (float) $application['deposit_amount'] + 0.005
        || (int) $advanceJournal->fetchColumn() !== 1) {
        throw new RuntimeException('Source evidence changed for BK-000184; no correction applied.');
    }
    echo "Review: release AED 740 from SPAY-000218, AED 985 from SPAY-000045, and AED 50 from advance #4. BK-000184 will have AED 3 open.\n";
    if (! $apply && ! $dryRun) {
        $db->rollBack();
        exit(0);
    }

    $journalIds = [];
    foreach ($changes as $target) {
        $remaining = $target['allocated'] - $target['release'];
        if ($remaining > 0) {
            $db->prepare('UPDATE supplier_payment_allocations SET allocated_amount = ? WHERE id = ?')
                ->execute([$remaining, $target['allocation']]);
        } else {
            $db->prepare('DELETE FROM supplier_payment_allocations WHERE id = ?')->execute([$target['allocation']]);
        }
        $db->prepare('UPDATE supplier_payments SET allocated_amount = allocated_amount - ?,
            unallocated_amount = unallocated_amount + ?, status = "partially_allocated" WHERE id = ?')
            ->execute([$target['release'], $target['release'], $target['payment']]);
        if ($remaining === 0) {
            $journalIds[] = $accounting->reverseJournalEntry($target['journal'], [
                'source_type' => 'supplier_overcoverage_allocation_reversed',
                'source_reference' => 'REV-JE-' . $target['journal'],
                'entry_date' => date('Y-m-d'),
                'narration' => 'Release duplicate supplier payment allocation to unallocated supplier credit',
                'actor_user_id' => $actorId,
            ]);
        } else {
            $journalIds[] = $accounting->postSupplierSettlementRelease([
                'branch_id' => 2,
                'booking_reference' => $target['booking'],
                'source_reference' => 'OVERLAP-REL-' . $target['allocation'],
                'service_line_reference' => 'SV-001',
                'supplier_obligation_id' => $target['obligation'],
                'released_amount' => $target['release'],
                'entry_date' => date('Y-m-d'),
                'currency' => 'AED',
                'narration' => 'Release duplicate supplier payment allocation to unallocated supplier credit',
                'actor_user_id' => $actorId,
            ]);
        }
    }
    $db->prepare('DELETE FROM supplier_advance_applications WHERE id = 13')->execute();
    $db->prepare('UPDATE supplier_advances SET available_amount = available_amount + 50 WHERE id = ?')
        ->execute([(int) $application['supplier_advance_id']]);
    $db->prepare('UPDATE supplier_obligations SET advance_applied_amount = 0, net_payable_amount = 3,
        status = "partially_covered" WHERE id = 183')->execute();
    $journalIds[] = $accounting->reverseJournalEntry(2824, [
        'source_type' => 'supplier_overcoverage_advance_reversed',
        'source_reference' => 'REV-JE-2824',
        'entry_date' => date('Y-m-d'),
        'narration' => 'Release excess supplier advance application; leave remaining AED 3 payable open',
        'actor_user_id' => $actorId,
    ]);
    if (in_array(null, $journalIds, true)) {
        throw new RuntimeException('A required journal reversal failed. No correction applied.');
    }
    AuditLog::recordRequired($app, 'supplier.overcoverage.repaired', [
        'user_id' => $actorId,
        'payment_allocation_ids' => [862, 200],
        'advance_application_id' => 13,
        'released_supplier_payment_credit_aed' => 1725,
        'released_supplier_advance_aed' => 50,
        'reopened_supplier_payable_aed' => 3,
        'journal_ids' => $journalIds,
        'cash_movement' => false,
        'customer_invoice_change' => false,
    ]);
    if ($dryRun) {
        $db->rollBack();
        echo 'DRY RUN rolled back; no records changed.' . PHP_EOL;
    } else {
        $db->commit();
        echo 'Applied correction; journal IDs: ' . implode(', ', $journalIds) . PHP_EOL;
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
