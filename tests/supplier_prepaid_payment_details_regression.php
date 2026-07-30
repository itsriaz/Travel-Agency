<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Supplier prepaid payment details regression' . PHP_EOL;

$fixture = $db->query(
    'SELECT ta.id AS treasury_account_id, ta.branch_id, ta.currency, ta.account_type,
            s.id AS supplier_id, s.name AS supplier_name,
            u.id AS actor_user_id
     FROM treasury_accounts ta
     INNER JOIN suppliers s ON s.is_active = 1
     INNER JOIN users u ON u.is_active = 1
     WHERE ta.is_active = 1
       AND ta.account_type IN ("cash", "bank")
     ORDER BY ta.is_default DESC, ta.id ASC, s.id ASC, u.id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($fixture === false) {
    $check('Fixture is available', false, 'No active treasury account, supplier, or user exists.');
} else {
    $service = new \App\Services\SupplierSettlementWorkspaceService($app);
    $method = (string) $fixture['account_type'] === 'bank' ? 'bank_transfer' : 'cash';
    $branchId = (int) $fixture['branch_id'];
    $treasuryAccountId = (int) $fixture['treasury_account_id'];
    $actorUserId = (int) $fixture['actor_user_id'];
    $balance = (new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId($treasuryAccountId);
    $amount = round(min(max($balance / 10, 1), 10), 2);
    $replacementSupplierStatement = $db->prepare(
        'SELECT id
         FROM suppliers
         WHERE is_active = 1
           AND id <> :supplier_id
         ORDER BY id ASC
         LIMIT 1'
    );
    $replacementSupplierStatement->execute(['supplier_id' => (int) $fixture['supplier_id']]);
    $replacementSupplierId = (int) ($replacementSupplierStatement->fetchColumn() ?: 0);

    if ($balance + 0.005 < $amount) {
        $check('Fixture source account has an available balance', false, 'Available=' . number_format($balance, 2));
    } else {
        $db->beginTransaction();
        try {
            $created = $service->recordGlobalSupplierAdvance([
                'branch_id' => $branchId,
                'supplier_name' => (string) $fixture['supplier_name'],
                'advance_currency' => (string) $fixture['currency'],
                'advance_date' => date('Y-m-d'),
                'advance_amount' => $amount,
                'advance_payment_method' => $method,
                'advance_treasury_account_id' => $treasuryAccountId,
                'advance_reference_number' => 'PREPAID-DETAIL-TEST',
                'advance_remarks' => 'Rollback-only prepaid supplier regression',
            ], $actorUserId, [$branchId]);

            $advanceId = (int) ($created['advance']['id'] ?? 0);
            $rowStatement = $db->prepare('SELECT * FROM supplier_advances WHERE id = :id');
            $rowStatement->execute(['id' => $advanceId]);
            $row = $rowStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $check('Payment method is stored', (string) ($row['payment_method'] ?? '') === $method);
            $check('Source treasury account is stored', (int) ($row['treasury_account_id'] ?? 0) === $treasuryAccountId);
            $check('Posted journal is linked', (int) ($row['journal_entry_id'] ?? 0) > 0);

            $journalStatement = $db->prepare(
                'SELECT COALESCE(SUM(debit_amount), 0) AS debit_total,
                        COALESCE(SUM(credit_amount), 0) AS credit_total
                 FROM journal_entry_lines
                 WHERE journal_entry_id = :journal_entry_id'
            );
            $journalStatement->execute(['journal_entry_id' => (int) ($row['journal_entry_id'] ?? 0)]);
            $journal = $journalStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $check(
                'Advance journal remains balanced',
                abs((float) ($journal['debit_total'] ?? 0) - (float) ($journal['credit_total'] ?? 0)) <= 0.005
            );

            $correctedAmount = round($amount + 1, 2);
            $service->correctGlobalSupplierAdvance([
                'supplier_advance_id' => $advanceId,
                'supplier_id' => $replacementSupplierId > 0 ? $replacementSupplierId : (int) $fixture['supplier_id'],
                'advance_date' => date('Y-m-d'),
                'advance_amount' => $correctedAmount,
                'advance_payment_method' => $method,
                'advance_treasury_account_id' => $treasuryAccountId,
                'advance_reference_number' => 'PREPAID-DETAIL-EDIT',
                'advance_remarks' => 'Edited in rollback-only regression',
                'correction_reason' => 'Automated edit regression',
            ], $actorUserId, [$branchId]);

            $rowStatement->execute(['id' => $advanceId]);
            $corrected = $rowStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $check('One-step edit updates paid and available amounts', abs((float) ($corrected['deposit_amount'] ?? 0) - $correctedAmount) <= 0.005
                && abs((float) ($corrected['available_amount'] ?? 0) - $correctedAmount) <= 0.005);
            $check(
                'Unused prepaid payment can move to the correct supplier',
                $replacementSupplierId <= 0 || (int) ($corrected['supplier_id'] ?? 0) === $replacementSupplierId
            );
            $check('Correction audit row is retained', (int) $db->query(
                'SELECT COUNT(*) FROM supplier_advance_corrections WHERE supplier_advance_id = ' . $advanceId
            )->fetchColumn() === 1);
            $correctionStatement = $db->prepare(
                'SELECT old_supplier_id, new_supplier_id
                 FROM supplier_advance_corrections
                 WHERE supplier_advance_id = :advance_id
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $correctionStatement->execute(['advance_id' => $advanceId]);
            $correction = $correctionStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $check(
                'Supplier correction keeps old and new supplier audit evidence',
                (int) ($correction['old_supplier_id'] ?? 0) === (int) $fixture['supplier_id']
                    && (int) ($correction['new_supplier_id'] ?? 0) === ($replacementSupplierId > 0 ? $replacementSupplierId : (int) $fixture['supplier_id'])
            );

            if ($replacementSupplierId > 0) {
                $db->prepare(
                    'UPDATE supplier_advances
                     SET available_amount = deposit_amount - 1
                     WHERE id = :advance_id'
                )->execute(['advance_id' => $advanceId]);
                $supplierChangeBlocked = false;
                try {
                    $service->correctGlobalSupplierAdvance([
                        'supplier_advance_id' => $advanceId,
                        'supplier_id' => (int) $fixture['supplier_id'],
                        'advance_date' => date('Y-m-d'),
                        'advance_amount' => $correctedAmount,
                        'advance_payment_method' => $method,
                        'advance_treasury_account_id' => $treasuryAccountId,
                        'correction_reason' => 'Supplier change should be blocked after advance use',
                    ], $actorUserId, [$branchId]);
                } catch (RuntimeException $exception) {
                    $supplierChangeBlocked = str_contains($exception->getMessage(), 'already been applied');
                }
                $check('Supplier is locked after any prepaid amount is used', $supplierChangeBlocked);
            }

            $reportRows = (new \App\Repositories\ReportRepository($app))->supplierPrepaidPayments(
                [$branchId],
                null,
                null,
                (string) $fixture['currency']
            );
            $reportRow = array_values(array_filter(
                $reportRows,
                static fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $advanceId
            ))[0] ?? [];
            $check('Report exposes method and source account', (string) ($reportRow['payment_method'] ?? '') === $method
                && (int) ($reportRow['treasury_account_id'] ?? 0) === $treasuryAccountId);
            $finderRows = (new \App\Repositories\SupplierRepository($app))->searchGlobalSupplierAdvances(
                [$branchId],
                ['query' => 'PREPAID-DETAIL-EDIT'],
                10
            );
            $check(
                'Workspace finder can search the corrected prepaid payment',
                count(array_filter(
                    $finderRows,
                    static fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $advanceId
                )) === 1
            );
        } catch (Throwable $exception) {
            $check('Transactional prepaid supplier workflow completed', false, $exception->getMessage());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}

if ($failures !== []) {
    echo 'Supplier prepaid payment details regression failed.' . PHP_EOL;
    exit(1);
}

echo 'Supplier prepaid payment details regression passed.' . PHP_EOL;
