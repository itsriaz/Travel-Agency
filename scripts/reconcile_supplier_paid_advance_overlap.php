<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\SupplierRepository;

$supplierId = 0;
$currency = '';
$obligationIds = [];
$expectedRelease = null;
$apply = false;
$actorUserId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--supplier-id=(\d+)$/', $argument, $matches) === 1) {
        $supplierId = (int) $matches[1];
    } elseif (preg_match('/^--currency=([A-Z]{3})$/', $argument, $matches) === 1) {
        $currency = $matches[1];
    } elseif (preg_match('/^--obligation-ids=([\d,]+)$/', $argument, $matches) === 1) {
        $obligationIds = array_map('intval', explode(',', $matches[1]));
    } elseif (preg_match('/^--expected-release=(\d+(?:\.\d{1,2})?)$/', $argument, $matches) === 1) {
        $expectedRelease = round((float) $matches[1], 2);
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--actor-id=(\d+)$/', $argument, $matches) === 1) {
        $actorUserId = (int) $matches[1];
    } else {
        fwrite(STDERR, "Usage: php scripts/reconcile_supplier_paid_advance_overlap.php --supplier-id=N --currency=PKR --obligation-ids=1,2 --expected-release=100.00 [--apply --actor-id=N]\n");
        exit(2);
    }
}
$obligationIds = array_values(array_unique($obligationIds));
if ($supplierId <= 0 || $currency === '' || $obligationIds === [] || in_array(0, $obligationIds, true)
    || $expectedRelease === null || $expectedRelease <= 0) {
    fwrite(STDERR, "A specific supplier, currency, obligation list and positive expected release are required.\n");
    exit(2);
}
if ($apply && $actorUserId <= 0) {
    fwrite(STDERR, "Apply requires an active actor user ID for the audit trail.\n");
    exit(2);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
if ($apply) {
    $actorStatement = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $actorStatement->execute([$actorUserId]);
    if ($actorStatement->fetchColumn() === false) {
        fwrite(STDERR, "The actor user ID must be active.\n");
        exit(2);
    }
}
$repository = new SupplierRepository($app);
$accounting = new AccountingRepository($app);
$db->beginTransaction();
try {
    $getObligation = $db->prepare(
        'SELECT id, supplier_id, branch_id, booking_reference, service_line_reference, currency,
                gross_amount, advance_applied_amount, net_payable_amount, status
         FROM supplier_obligations WHERE id = ? FOR UPDATE'
    );
    $getCoverage = $db->prepare(
        'SELECT COALESCE(SUM(allocation.allocated_amount), 0)
         FROM supplier_payment_allocations allocation
         INNER JOIN supplier_payments payment ON payment.id = allocation.supplier_payment_id
            AND payment.status <> "void"
         WHERE allocation.supplier_obligation_id = ?'
    );
    $getApplications = $db->prepare(
        'SELECT x.id, x.supplier_advance_id, x.applied_amount, x.advance_amount_consumed,
                x.obligation_amount_applied, a.supplier_id, a.currency AS advance_currency
         FROM supplier_advance_applications x
         INNER JOIN supplier_advances a ON a.id = x.supplier_advance_id
         WHERE x.supplier_obligation_id = ? FOR UPDATE'
    );
    $getJournalCoverage = $db->prepare(
        'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
         FROM journal_entry_lines jel
         INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.code = "AP_CONTROL"
         WHERE jel.supplier_obligation_id = ? AND je.source_type IN
               ("supplier_advance_applied", "supplier_advance_adjusted")'
    );
    $getPaymentTotals = $db->prepare(
        'SELECT COALESCE(SUM(paid_amount), 0), COALESCE(SUM(allocated_amount), 0),
                COALESCE(SUM(unallocated_amount), 0), COALESCE(SUM(converted_advance_amount), 0),
                COALESCE(SUM(returned_amount), 0)
         FROM supplier_payments WHERE supplier_id = ? AND currency = ? AND status <> "void"'
    );
    $getPaymentTotals->execute([$supplierId, $currency]);
    $paymentsBefore = $getPaymentTotals->fetch(PDO::FETCH_NUM);

    $candidates = [];
    $totalRelease = 0.0;
    foreach ($obligationIds as $obligationId) {
        $getObligation->execute([$obligationId]);
        $obligation = $getObligation->fetch(PDO::FETCH_ASSOC);
        if (! is_array($obligation) || (int) $obligation['supplier_id'] !== $supplierId
            || strtoupper((string) $obligation['currency']) !== $currency
            || (float) $obligation['gross_amount'] <= 0
            || abs((float) $obligation['net_payable_amount']) > 0.005) {
            throw new RuntimeException('Obligation #' . $obligationId . ' does not match the expected paid supplier payable.');
        }

        $getCoverage->execute([$obligationId]);
        $directPaid = round((float) $getCoverage->fetchColumn(), 2);
        $gross = round((float) $obligation['gross_amount'], 2);
        $release = round((float) $obligation['advance_applied_amount'], 2);
        $getApplications->execute([$obligationId]);
        $applications = $getApplications->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $applicationTotal = 0.0;
        foreach ($applications as $application) {
            if ((int) $application['supplier_id'] !== $supplierId
                || strtoupper((string) $application['advance_currency']) !== $currency
                || abs((float) $application['applied_amount'] - (float) $application['advance_amount_consumed']) > 0.005
                || abs((float) $application['applied_amount'] - (float) $application['obligation_amount_applied']) > 0.005) {
                throw new RuntimeException('Obligation #' . $obligationId . ' has an unsupported advance application.');
            }
            $applicationTotal += (float) $application['applied_amount'];
        }
        $getJournalCoverage->execute([$obligationId]);
        $journalApplied = round((float) $getJournalCoverage->fetchColumn(), 2);
        if ($release <= 0.005 || abs($directPaid - $gross) > 0.005
            || abs($applicationTotal - $release) > 0.005
            || abs($journalApplied - $release) > 0.005) {
            throw new RuntimeException('Obligation #' . $obligationId . ' lacks exact payment, advance and journal evidence.');
        }

        $candidates[] = [
            'obligation_id' => $obligationId,
            'booking_reference' => (string) $obligation['booking_reference'],
            'gross' => $gross,
            'direct_paid' => $directPaid,
            'advance_to_release' => $release,
            'application_ids' => array_map(static fn (array $row): int => (int) $row['id'], $applications),
            'journal_applied' => $journalApplied,
            'branch_id' => (int) $obligation['branch_id'],
            'service_line_reference' => (string) ($obligation['service_line_reference'] ?? ''),
        ];
        $totalRelease = round($totalRelease + $release, 2);
    }
    if (abs($totalRelease - $expectedRelease) > 0.005) {
        throw new RuntimeException('Expected release differs from verified applications. Nothing was changed.');
    }

    $journalIds = [];
    if ($apply) {
        foreach ($candidates as $candidate) {
            $result = $repository->autoApplyAvailableAdvanceToObligation($candidate['obligation_id'], $actorUserId);
            $delta = round((float) ($result['applied_amount_delta'] ?? 0), 2);
            if (abs($delta + $candidate['advance_to_release']) > 0.005
                || abs((float) ($result['obligation']['advance_applied_amount'] ?? -1)) > 0.005
                || abs((float) ($result['obligation']['net_payable_amount'] ?? -1)) > 0.005) {
                throw new RuntimeException('Obligation #' . $candidate['obligation_id'] . ' did not release exactly as previewed.');
            }
            $journalIds[] = $accounting->postSupplierAdvanceApplicationAdjusted([
                'branch_id' => $candidate['branch_id'],
                'booking_reference' => $candidate['booking_reference'],
                'source_reference' => 'SADV-OVERLAP-REPAIR-' . $candidate['obligation_id'],
                'service_line_reference' => $candidate['service_line_reference'] !== ''
                    ? $candidate['service_line_reference'] : null,
                'supplier_obligation_id' => $candidate['obligation_id'],
                'adjustment_amount' => $delta,
                'entry_date' => date('Y-m-d'),
                'currency' => $currency,
                'narration' => 'Release duplicate supplier advance application after verified direct payment',
                'actor_user_id' => $actorUserId,
            ]);
        }
        $getPaymentTotals->execute([$supplierId, $currency]);
        if ($getPaymentTotals->fetch(PDO::FETCH_NUM) !== $paymentsBefore) {
            throw new RuntimeException('Supplier payment totals changed unexpectedly. Nothing was committed.');
        }
        AuditLog::recordRequired($app, 'supplier.advance.paid_overlap_repaired', [
            'user_id' => $actorUserId,
            'supplier_id' => $supplierId,
            'currency' => $currency,
            'released_amount' => $totalRelease,
            'obligations' => $candidates,
            'journal_ids' => $journalIds,
            'cash_movement' => false,
        ]);
        $db->commit();
    } else {
        $db->rollBack();
    }

    echo ($apply ? 'APPLIED' : 'DRY RUN') . ': ' . $currency . ' ' . number_format($totalRelease, 2, '.', '')
        . ' across ' . count($candidates) . ' directly paid obligations.' . PHP_EOL;
    foreach ($candidates as $candidate) {
        echo json_encode($candidate, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    if ($apply) {
        echo 'Adjustment journal IDs: ' . implode(', ', $journalIds) . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
