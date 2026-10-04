<?php

declare(strict_types=1);

use App\Core\App;
use App\Repositories\TreasuryRepository;
use App\Repositories\ReportRepository;
use App\Services\SupplierSettlementWorkspaceService;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

echo 'Supplier payment cross-currency/cross-branch regression (rollback only)' . PHP_EOL;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$treasury = new TreasuryRepository($app);
$reports = new ReportRepository($app);
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

$branchStatement = $db->query('SELECT id, name FROM branches ORDER BY id');
$branches = $branchStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$swatBranchId = 0;
$nobleBranchId = 0;
foreach ($branches as $branch) {
    $name = mb_strtolower(trim((string) ($branch['name'] ?? '')));
    if (str_contains($name, 'imdad international')) {
        $swatBranchId = (int) ($branch['id'] ?? 0);
    }
    if (str_contains($name, 'noble route')) {
        $nobleBranchId = (int) ($branch['id'] ?? 0);
    }
}

$supplierStatement = $db->prepare(
    'SELECT id FROM suppliers WHERE code = "SUP-00012" AND name = "AL SAUDIA AED" LIMIT 1'
);
$supplierStatement->execute();
$supplierId = (int) ($supplierStatement->fetchColumn() ?: 0);

$accountStatement = $db->prepare(
    'SELECT id FROM treasury_accounts
     WHERE branch_id = :branch_id
       AND account_name = "Cash-SWT-PKR"
       AND currency = "PKR"
       AND is_active = 1
     LIMIT 1'
);
$accountStatement->execute(['branch_id' => $swatBranchId]);
$treasuryAccountId = (int) ($accountStatement->fetchColumn() ?: 0);
$actorId = (int) ($db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

$check('Swat and Noble Route branches are available', $swatBranchId > 0 && $nobleBranchId > 0);
$check('AL SAUDIA AED supplier is available', $supplierId > 0);
$check('Swat PKR cash account is available', $treasuryAccountId > 0);
$check('An actor user is available', $actorId > 0);

if ($failures === []) {
    $token = date('His') . random_int(1000, 9999);
    $bookingReference = 'XFX-' . $token;
    $paymentRate = 0.01298701; // 1 PKR = approximately 0.01298701 AED (77 PKR per AED).
    $expectedAedPayment = 100.0;
    $expectedPkrOutflow = round($expectedAedPayment / $paymentRate, 2);
    $balanceBefore = $treasury->currentBalanceForAccountId($treasuryAccountId);
    $cashOut = static function (int $branchId, string $currency) use ($reports): float {
        $total = 0.0;
        foreach ($reports->cashFlow([$branchId], date('Y-m-d'), date('Y-m-d'))['supplierPayments'] as $row) {
            if ((string) ($row['currency'] ?? '') === $currency) {
                $total += (float) ($row['cash_out_amount'] ?? 0);
            }
        }
        return $total;
    };
    $swatPkrCashOutBefore = $cashOut($swatBranchId, 'PKR');
    $nobleAedCashOutBefore = $cashOut($nobleBranchId, 'AED');
    $paymentId = 0;
    $obligationId = 0;

    $db->beginTransaction();
    try {
        $obligationStatement = $db->prepare(
            'INSERT INTO supplier_obligations (
                supplier_id, branch_id, booking_reference, service_line_reference,
                obligation_group, currency, gross_amount, advance_applied_amount,
                net_payable_amount, due_date, status, remarks, created_by_user_id
             ) VALUES (
                :supplier_id, :branch_id, :booking_reference, "SV-001",
                "service_cost", "AED", :gross_amount, 0,
                :net_payable_amount, "1900-01-01", "open", :remarks, :actor_user_id
             )'
        );
        $obligationStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $nobleBranchId,
            'booking_reference' => $bookingReference,
            'gross_amount' => $expectedAedPayment,
            'net_payable_amount' => $expectedAedPayment,
            'remarks' => 'Rollback-only cross-currency/cross-branch regression fixture',
            'actor_user_id' => $actorId,
        ]);
        $obligationId = (int) $db->lastInsertId();

        $result = $service->recordSupplierAccountPayment([
            'branch_id' => $nobleBranchId,
            'supplier_id' => $supplierId,
            'supplier_payment_currency' => 'AED',
            'supplier_paid_amount' => $expectedAedPayment,
            'supplier_payment_date' => date('Y-m-d'),
            'supplier_payment_method' => 'cash',
            'supplier_treasury_account_id' => $treasuryAccountId,
            'supplier_exchange_rate_to_booking' => $paymentRate,
            'supplier_payment_remarks' => 'Rollback-only AED payable funded from Swat PKR cash',
        ], $actorId, [$swatBranchId, $nobleBranchId]);
        $paymentId = (int) ($result['payment']['id'] ?? 0);

        $paymentStatement = $db->prepare(
            'SELECT branch_id, currency, paid_amount, allocated_amount, unallocated_amount,
                    treasury_account_id, exchange_rate_to_booking, status
             FROM supplier_payments
             WHERE id = :payment_id'
        );
        $paymentStatement->execute(['payment_id' => $paymentId]);
        $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $allocationStatement = $db->prepare(
            'SELECT spa.supplier_obligation_id, spa.allocated_amount, spa.exchange_rate_used,
                    so.branch_id AS obligation_branch_id, so.currency AS obligation_currency,
                    so.net_payable_amount, so.status
             FROM supplier_payment_allocations spa
             INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
             WHERE spa.supplier_payment_id = :payment_id'
        );
        $allocationStatement->execute(['payment_id' => $paymentId]);
        $allocation = $allocationStatement->fetch(PDO::FETCH_ASSOC) ?: [];

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
        $actualPkrOutflow = round($balanceBefore - $balanceAfter, 2);

        $check(
            'Noble Route payment remains AED while using the Swat PKR treasury account',
            (int) ($payment['branch_id'] ?? 0) === $nobleBranchId
                && (string) ($payment['currency'] ?? '') === 'AED'
                && abs((float) ($payment['paid_amount'] ?? 0) - $expectedAedPayment) <= 0.005
                && (int) ($payment['treasury_account_id'] ?? 0) === $treasuryAccountId
                && abs((float) ($payment['exchange_rate_to_booking'] ?? 0) - $paymentRate) <= 0.00000001
        );
        $check(
            'AED payable is allocated in Noble Route without changing its currency',
            (int) ($allocation['supplier_obligation_id'] ?? 0) === $obligationId
                && (int) ($allocation['obligation_branch_id'] ?? 0) === $nobleBranchId
                && (string) ($allocation['obligation_currency'] ?? '') === 'AED'
                && abs((float) ($allocation['allocated_amount'] ?? 0) - $expectedAedPayment) <= 0.005
                && abs((float) ($allocation['net_payable_amount'] ?? 0)) <= 0.005
                && (string) ($allocation['status'] ?? '') === 'paid'
        );
        $check(
            'Swat PKR cash balance is reduced by the converted PKR amount',
            abs($actualPkrOutflow - $expectedPkrOutflow) <= 0.005,
            'expected ' . number_format($expectedPkrOutflow, 2)
                . ' PKR, actual ' . number_format($actualPkrOutflow, 2) . ' PKR'
        );
        $check('Cash Flow shows converted PKR outflow in Swat, not AED outflow in Noble Route',
            abs(($cashOut($swatBranchId, 'PKR') - $swatPkrCashOutBefore) - $expectedPkrOutflow) <= 0.005
                && abs($cashOut($nobleBranchId, 'AED') - $nobleAedCashOutBefore) <= 0.005);

        $recorded = $journalsByType['supplier_payment_recorded'] ?? [];
        $funded = $journalsByType['supplier_payment_treasury_funded'] ?? [];
        $allocated = $journalsByType['supplier_payment_allocated'] ?? [];
        $check(
            'AED payment journal is balanced in the Noble Route supplier branch',
            (int) ($recorded['branch_id'] ?? 0) === $nobleBranchId
                && (string) ($recorded['currency'] ?? '') === 'AED'
                && abs((float) ($recorded['debits'] ?? 0) - $expectedAedPayment) <= 0.005
                && abs((float) ($recorded['credits'] ?? 0) - $expectedAedPayment) <= 0.005
        );
        $check(
            'PKR funding journal is balanced and credits the selected Swat cash account',
            (int) ($funded['branch_id'] ?? 0) === $swatBranchId
                && (string) ($funded['currency'] ?? '') === 'PKR'
                && abs((float) ($funded['debits'] ?? 0) - $expectedPkrOutflow) <= 0.005
                && abs((float) ($funded['credits'] ?? 0) - $expectedPkrOutflow) <= 0.005
                && abs((float) ($funded['treasury_credit'] ?? 0) - $expectedPkrOutflow) <= 0.005
        );
        $check(
            'AED allocation journal is balanced in the Noble Route obligation branch',
            (int) ($allocated['branch_id'] ?? 0) === $nobleBranchId
                && (string) ($allocated['currency'] ?? '') === 'AED'
                && abs((float) ($allocated['debits'] ?? 0) - $expectedAedPayment) <= 0.005
                && abs((float) ($allocated['credits'] ?? 0) - $expectedAedPayment) <= 0.005
        );
        $check(
            'Payment is fully allocated with no supplier advance created',
            abs((float) ($payment['allocated_amount'] ?? 0) - $expectedAedPayment) <= 0.005
                && abs((float) ($payment['unallocated_amount'] ?? 0)) <= 0.005
                && (int) ($result['advance_id'] ?? 0) === 0
        );
    } catch (Throwable $exception) {
        $check('Cross-currency/cross-branch payment probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $paymentRetained = 0;
    if ($paymentId > 0) {
        $statement = $db->prepare('SELECT COUNT(*) FROM supplier_payments WHERE id = :id');
        $statement->execute(['id' => $paymentId]);
        $paymentRetained = (int) $statement->fetchColumn();
    }
    $obligationRetained = 0;
    if ($obligationId > 0) {
        $statement = $db->prepare('SELECT COUNT(*) FROM supplier_obligations WHERE id = :id');
        $statement->execute(['id' => $obligationId]);
        $obligationRetained = (int) $statement->fetchColumn();
    }
    $check(
        'Rollback removed the test payment, allocation, journals, and payable',
        $paymentRetained === 0 && $obligationRetained === 0
    );
}

if ($failures !== []) {
    echo PHP_EOL . 'Cross-currency/cross-branch supplier payment regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Cross-currency/cross-branch supplier payment regression passed; no test data was retained.' . PHP_EOL;
