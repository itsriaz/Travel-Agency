<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\TreasuryRepository;
use App\Services\SupplierSettlementWorkspaceService;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

echo 'Supplier payment same-currency/cross-branch funding regression (rollback only)' . PHP_EOL;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new TreasuryRepository($app);
$service = new SupplierSettlementWorkspaceService($app);
$failures = [];

$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};

$branches = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sourceBranchId = 0;
$paymentBranchId = 0;
foreach ($branches as $branch) {
    $name = mb_strtolower(trim((string) ($branch['name'] ?? '')));
    if (str_contains($name, 'imdad international')) {
        $sourceBranchId = (int) ($branch['id'] ?? 0);
    }
    if (str_contains($name, 'noble route')) {
        $paymentBranchId = (int) ($branch['id'] ?? 0);
    }
}

$accountStatement = $db->prepare(
    'SELECT id FROM treasury_accounts
     WHERE branch_id = :branch_id
       AND currency = "PKR"
       AND account_type = "cash"
       AND is_active = 1
     ORDER BY is_default DESC, id
     LIMIT 1'
);
$accountStatement->execute(['branch_id' => $sourceBranchId]);
$treasuryAccountId = (int) ($accountStatement->fetchColumn() ?: 0);
$actorId = (int) ($db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin"
     ORDER BY u.id
     LIMIT 1'
)->fetchColumn() ?: 0);

$check('Source and payment branches are available', $sourceBranchId > 0 && $paymentBranchId > 0);
$check('A Swat PKR cash account is available', $treasuryAccountId > 0);
$check('An actor user is available', $actorId > 0);

$paymentId = 0;
$obligationId = 0;
$supplierId = 0;

if ($failures === []) {
    $token = date('His') . random_int(1000, 9999);
    $amount = 300.00;
    $balanceBefore = $treasury->currentBalanceForAccountId($treasuryAccountId);

    $db->beginTransaction();
    try {
        $supplierStatement = $db->prepare(
            'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
             VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
        );
        $supplierStatement->execute([
            'branch_id' => $paymentBranchId,
            'code' => 'XSCB-' . $token,
            'name' => 'Same Currency Cross Branch ' . $token,
        ]);
        $supplierId = (int) $db->lastInsertId();

        $bookingReference = 'XSCB-' . $token;
        $obligationStatement = $db->prepare(
            'INSERT INTO supplier_obligations (
                supplier_id, branch_id, booking_reference, service_line_reference,
                obligation_group, currency, gross_amount, advance_applied_amount,
                net_payable_amount, due_date, status, remarks, created_by_user_id
             ) VALUES (
                :supplier_id, :branch_id, :booking_reference, "SV-001",
                "service_cost", "PKR", :gross_amount, 0,
                :net_payable_amount, :due_date, "open", :remarks, :actor_user_id
             )'
        );
        $obligationStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $paymentBranchId,
            'booking_reference' => $bookingReference,
            'gross_amount' => $amount,
            'net_payable_amount' => $amount,
            'due_date' => date('Y-m-d'),
            'remarks' => 'Rollback-only same-currency/cross-branch funding fixture',
            'actor_user_id' => $actorId,
        ]);
        $obligationId = (int) $db->lastInsertId();

        $result = $service->recordSupplierAccountPayment([
            'branch_id' => $paymentBranchId,
            'supplier_id' => $supplierId,
            'supplier_payment_currency' => 'PKR',
            'supplier_paid_amount' => $amount,
            'supplier_payment_date' => date('Y-m-d'),
            'supplier_payment_method' => 'cash',
            'supplier_treasury_account_id' => $treasuryAccountId,
            'supplier_payment_remarks' => 'Rollback-only Noble payable funded from Swat PKR cash',
        ], $actorId, [$sourceBranchId, $paymentBranchId]);
        $paymentId = (int) ($result['payment']['id'] ?? 0);

        $paymentStatement = $db->prepare(
            'SELECT branch_id, currency, paid_amount, allocated_amount, treasury_account_id, status
             FROM supplier_payments WHERE id = :payment_id'
        );
        $paymentStatement->execute(['payment_id' => $paymentId]);
        $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $obligationStatement = $db->prepare(
            'SELECT net_payable_amount, status FROM supplier_obligations WHERE id = :id'
        );
        $obligationStatement->execute(['id' => $obligationId]);
        $obligation = $obligationStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $journalStatement = $db->prepare(
            'SELECT je.source_type, je.branch_id, je.currency,
                    ROUND(SUM(jel.debit_amount), 2) AS debits,
                    ROUND(SUM(jel.credit_amount), 2) AS credits,
                    ROUND(SUM(CASE WHEN jel.account_id = ta.linked_account_id THEN jel.credit_amount ELSE 0 END), 2) AS treasury_credit
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             LEFT JOIN treasury_accounts ta ON ta.id = :treasury_account_id
             WHERE jel.supplier_payment_id = :payment_id
               AND je.source_type IN (
                    "supplier_payment_recorded",
                    "supplier_payment_treasury_funded",
                    "supplier_payment_allocated"
               )
             GROUP BY je.id, je.source_type, je.branch_id, je.currency
             ORDER BY je.id'
        );
        $journalStatement->execute([
            'treasury_account_id' => $treasuryAccountId,
            'payment_id' => $paymentId,
        ]);
        $journals = $journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $journalsByType = [];
        foreach ($journals as $journal) {
            $journalsByType[(string) ($journal['source_type'] ?? '')] = $journal;
        }

        $balanceAfter = $treasury->currentBalanceForAccountId($treasuryAccountId);
        $actualOutflow = round($balanceBefore - $balanceAfter, 2);
        $recorded = $journalsByType['supplier_payment_recorded'] ?? [];
        $funded = $journalsByType['supplier_payment_treasury_funded'] ?? [];

        $check(
            'Supplier payment remains in the Noble Route payable branch',
            (int) ($payment['branch_id'] ?? 0) === $paymentBranchId
                && (string) ($payment['currency'] ?? '') === 'PKR'
                && abs((float) ($payment['paid_amount'] ?? 0) - $amount) <= 0.005
                && (int) ($payment['treasury_account_id'] ?? 0) === $treasuryAccountId
        );
        $check(
            'Supplier payable is fully reduced by the payment',
            abs((float) ($payment['allocated_amount'] ?? 0) - $amount) <= 0.005
                && abs((float) ($obligation['net_payable_amount'] ?? 0)) <= 0.005
                && (string) ($obligation['status'] ?? '') === 'paid'
        );
        $check(
            'Noble Route supplier-side journal remains balanced',
            (int) ($recorded['branch_id'] ?? 0) === $paymentBranchId
                && (string) ($recorded['currency'] ?? '') === 'PKR'
                && abs((float) ($recorded['debits'] ?? 0) - $amount) <= 0.005
                && abs((float) ($recorded['credits'] ?? 0) - $amount) <= 0.005
                && abs((float) ($recorded['treasury_credit'] ?? 0)) <= 0.005
        );
        $check(
            'Swat funding journal credits the selected PKR cash account',
            (int) ($funded['branch_id'] ?? 0) === $sourceBranchId
                && (string) ($funded['currency'] ?? '') === 'PKR'
                && abs((float) ($funded['debits'] ?? 0) - $amount) <= 0.005
                && abs((float) ($funded['credits'] ?? 0) - $amount) <= 0.005
                && abs((float) ($funded['treasury_credit'] ?? 0) - $amount) <= 0.005
        );
        $check(
            'Swat PKR cash balance is reduced by the funded amount',
            abs($actualOutflow - $amount) <= 0.005,
            'expected ' . number_format($amount, 2) . ', actual ' . number_format($actualOutflow, 2)
        );

        $service->voidGlobalSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'void_reason' => 'Rollback-only verification of cross-branch funding reversal',
        ], $actorId, [$sourceBranchId, $paymentBranchId]);

        $voidedPaymentStatement = $db->prepare(
            'SELECT status, allocated_amount FROM supplier_payments WHERE id = :payment_id'
        );
        $voidedPaymentStatement->execute(['payment_id' => $paymentId]);
        $voidedPayment = $voidedPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $restoredObligationStatement = $db->prepare(
            'SELECT net_payable_amount, status FROM supplier_obligations WHERE id = :id'
        );
        $restoredObligationStatement->execute(['id' => $obligationId]);
        $restoredObligation = $restoredObligationStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $balanceAfterVoid = $treasury->currentBalanceForAccountId($treasuryAccountId);
        $voidRestoredBothSides = (string) ($voidedPayment['status'] ?? '') === 'void'
            && abs((float) ($restoredObligation['net_payable_amount'] ?? 0) - $amount) <= 0.005
            && (string) ($restoredObligation['status'] ?? '') === 'open'
            && abs($balanceAfterVoid - $balanceBefore) <= 0.005;

        $check(
            'Voiding restores both the supplier payable and Swat PKR cash',
            $voidRestoredBothSides,
            $voidRestoredBothSides ? '' : json_encode([
                'payment_status' => $voidedPayment['status'] ?? null,
                'obligation' => $restoredObligation,
                'balance_before' => $balanceBefore,
                'balance_after_void' => $balanceAfterVoid,
            ], JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $exception) {
        $check('Same-currency/cross-branch payment probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $retainedRows = 0;
    foreach ([
        ['supplier_payments', $paymentId],
        ['supplier_obligations', $obligationId],
        ['suppliers', $supplierId],
    ] as [$table, $id]) {
        if ($id <= 0) {
            continue;
        }
        $statement = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $retainedRows += (int) $statement->fetchColumn();
    }
    $check('Rollback removed all regression records', $retainedRows === 0);
}

if ($failures !== []) {
    echo PHP_EOL . 'Same-currency/cross-branch supplier funding regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Same-currency/cross-branch supplier funding regression passed; no test data was retained.' . PHP_EOL;
