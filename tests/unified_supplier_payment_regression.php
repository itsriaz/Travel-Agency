<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$service = new \App\Services\SupplierSettlementWorkspaceService($app);
$ledgerService = new \App\Services\SupplierLedgerService($app);
$treasury = new \App\Repositories\TreasuryRepository($app);
$failures = [];
$pass = static function (string $label): void {
    echo '[PASS] ' . $label . PHP_EOL;
};
$fail = static function (string $label, string $detail = '') use (&$failures): void {
    $failures[] = $label;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
};

echo 'Unified supplier account payment regression (rollback only)' . PHP_EOL;

$actorId = (int) $db->query(
    'SELECT u.id FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();

$groups = $db->query(
    'SELECT branch_id, supplier_id, currency, COUNT(*) AS row_count
     FROM supplier_obligations
     WHERE status IN ("open", "partially_covered") AND net_payable_amount > 0.005
     GROUP BY branch_id, supplier_id, currency
     HAVING COUNT(*) >= 2
     ORDER BY COUNT(*) DESC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$multiFixture = null;
foreach ($groups as $group) {
    $obligationStatement = $db->prepare(
        'SELECT id, net_payable_amount
         FROM supplier_obligations
         WHERE branch_id = :branch_id AND supplier_id = :supplier_id AND currency = :currency
           AND status IN ("open", "partially_covered") AND net_payable_amount > 0.005
         ORDER BY due_date IS NULL, due_date, booking_reference, service_line_reference, id
         LIMIT 2'
    );
    $obligationStatement->execute([
        'branch_id' => (int) $group['branch_id'],
        'supplier_id' => (int) $group['supplier_id'],
        'currency' => (string) $group['currency'],
    ]);
    $obligations = $obligationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($obligations) < 2) {
        continue;
    }
    $amount = round((float) $obligations[0]['net_payable_amount'] + min((float) $obligations[1]['net_payable_amount'], 1.00), 2);
    foreach (['cash', 'bank_transfer'] as $method) {
        foreach ($treasury->eligiblePaymentTreasuryAccounts((int) $group['branch_id'], (string) $group['currency'], $method) as $account) {
            if ($treasury->currentBalanceForAccountId((int) $account['id']) + 0.005 < $amount) {
                continue;
            }
            $multiFixture = compact('group', 'obligations', 'amount', 'method', 'account');
            break 3;
        }
    }
}

if ($actorId <= 0 || $multiFixture === null) {
    echo '[INFO] No funded supplier with two open invoices; multi-invoice rollback probe skipped.' . PHP_EOL;
} else {
    $group = $multiFixture['group'];
    $db->beginTransaction();
    try {
        $result = $service->recordSupplierAccountPayment([
            'branch_id' => (int) $group['branch_id'],
            'supplier_id' => (int) $group['supplier_id'],
            'supplier_payment_currency' => (string) $group['currency'],
            'supplier_paid_amount' => (float) $multiFixture['amount'],
            'supplier_payment_date' => date('Y-m-d'),
            'supplier_payment_method' => (string) $multiFixture['method'],
            'supplier_treasury_account_id' => (int) $multiFixture['account']['id'],
            'supplier_payment_remarks' => 'Rollback-only unified supplier payment regression',
        ], $actorId, [(int) $group['branch_id']]);
        $paymentId = (int) ($result['payment']['id'] ?? 0);
        $allocationStatement = $db->prepare(
            'SELECT COUNT(*) AS row_count, COALESCE(SUM(allocated_amount), 0) AS amount
             FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
        );
        $allocationStatement->execute(['payment_id' => $paymentId]);
        $allocation = $allocationStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($allocation['row_count'] ?? 0) >= 2
            && abs((float) ($allocation['amount'] ?? 0) - (float) $multiFixture['amount']) <= 0.005) {
            $pass('One supplier-account payment reconciles multiple invoices internally');
        } else {
            $fail('One supplier-account payment reconciles multiple invoices internally', json_encode($allocation));
        }

        $ledger = $ledgerService->report([
            'dateFrom' => date('Y-m-d'),
            'dateTo' => date('Y-m-d'),
            'currency' => (string) $group['currency'],
            'airline' => '',
            'supplierId' => (int) $group['supplier_id'],
            'businessSourceId' => 0,
            'bookingReference' => '',
        ], [(int) $group['branch_id']]);
        $paymentRows = array_values(array_filter(
            (array) ($ledger['rows'] ?? []),
            static fn (array $row): bool => (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
        ));
        if (count($paymentRows) === 1
            && abs((float) ($paymentRows[0]['raw_credit_amount'] ?? 0) - (float) $multiFixture['amount']) <= 0.005) {
            $pass('Client supplier ledger shows the lump-sum payment exactly once');
        } else {
            $fail('Client supplier ledger shows the lump-sum payment exactly once', 'rows=' . count($paymentRows));
        }

        $journalStatement = $db->prepare(
            'SELECT je.id, SUM(jel.debit_amount) AS debit_total, SUM(jel.credit_amount) AS credit_total
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE EXISTS (
                 SELECT 1 FROM journal_entry_lines linked
                 WHERE linked.journal_entry_id = je.id AND linked.supplier_payment_id = :payment_id
             )
             GROUP BY je.id'
        );
        $journalStatement->execute(['payment_id' => $paymentId]);
        $balanced = true;
        foreach ($journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $journal) {
            $balanced = $balanced && abs((float) $journal['debit_total'] - (float) $journal['credit_total']) <= 0.005;
        }
        $balanced ? $pass('Every journal generated by the lump-sum payment remains balanced') : $fail('Every journal generated by the lump-sum payment remains balanced');
    } catch (Throwable $exception) {
        $fail('Multi-invoice supplier-account payment completed', $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$suppliers = $db->query('SELECT id, default_currency FROM suppliers WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$branches = $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$advanceFixture = null;
foreach ($branches as $branch) {
    foreach ($suppliers as $supplier) {
        foreach (['PKR', 'AED', 'USD'] as $currency) {
            $countStatement = $db->prepare(
                'SELECT COUNT(*) FROM supplier_obligations
                 WHERE branch_id = :branch_id AND supplier_id = :supplier_id AND currency = :currency
                   AND status IN ("open", "partially_covered") AND net_payable_amount > 0.005'
            );
            $countStatement->execute(['branch_id' => (int) $branch['id'], 'supplier_id' => (int) $supplier['id'], 'currency' => $currency]);
            if ((int) $countStatement->fetchColumn() !== 0) {
                continue;
            }
            foreach (['cash', 'bank_transfer'] as $method) {
                $accounts = $treasury->eligiblePaymentTreasuryAccounts((int) $branch['id'], $currency, $method);
                foreach ($accounts as $account) {
                    if ($treasury->currentBalanceForAccountId((int) $account['id']) >= 1.00) {
                        $advanceFixture = compact('branch', 'supplier', 'currency', 'method', 'account');
                        break 4;
                    }
                }
            }
        }
    }
}

if ($actorId <= 0 || $advanceFixture === null) {
    echo '[INFO] No funded supplier/currency without open invoices; advance-only rollback probe skipped.' . PHP_EOL;
} else {
    $db->beginTransaction();
    try {
        $result = $service->recordSupplierAccountPayment([
            'branch_id' => (int) $advanceFixture['branch']['id'],
            'supplier_id' => (int) $advanceFixture['supplier']['id'],
            'supplier_payment_currency' => (string) $advanceFixture['currency'],
            'supplier_paid_amount' => 1.00,
            'supplier_payment_date' => date('Y-m-d'),
            'supplier_payment_method' => (string) $advanceFixture['method'],
            'supplier_treasury_account_id' => (int) $advanceFixture['account']['id'],
            'supplier_payment_remarks' => 'Rollback-only advance-only regression',
        ], $actorId, [(int) $advanceFixture['branch']['id']]);
        if ((int) ($result['allocation_count'] ?? -1) === 0
            && abs((float) ($result['advance_amount'] ?? 0) - 1.00) <= 0.005
            && (int) ($result['advance_id'] ?? 0) > 0) {
            $pass('Payment with no open invoice becomes supplier advance automatically');
        } else {
            $fail('Payment with no open invoice becomes supplier advance automatically', json_encode($result));
        }
    } catch (Throwable $exception) {
        $fail('Advance-only supplier payment completed', $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Unified supplier account payment regression failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Unified supplier account payment regression passed; no fixture data was retained.' . PHP_EOL;
