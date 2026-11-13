<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});
/** @var PDO $db */
$db = $app->get('db');
$branchIds = array_map('intval', $db->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN));
$ledger = new \App\Services\SupplierLedgerService($app);
$payments = $db->query('SELECT p.id, p.payment_no, p.supplier_id, s.name AS supplier_name,
        p.currency, p.paid_amount
    FROM supplier_payments p
    INNER JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.status <> "void"')->fetchAll(PDO::FETCH_ASSOC);
$report = $ledger->report([], $branchIds);
$shown = [];
foreach ($report['rows'] as $row) {
    if ((string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
        $shown[(int) $row['supplier_payment_id']][] = $row;
    }
}

foreach ($payments as $payment) {
    $rows = $shown[(int) $payment['id']] ?? [];
    if (count($rows) !== 1
        || abs((float) $rows[0]['raw_credit_amount'] - (float) $payment['paid_amount']) > 0.005
        || strcasecmp((string) $rows[0]['supplier_name'], (string) $payment['supplier_name']) !== 0
        || strtoupper((string) $rows[0]['currency']) !== strtoupper((string) $payment['currency'])) {
        throw new RuntimeException($payment['payment_no'] . ' is duplicated, misattributed, or differs from its saved amount.');
    }
}

$recycled = array_values(array_filter(
    $payments,
    static fn (array $payment): bool => $payment['payment_no'] === 'SPAY-000168'
));
if ($recycled !== []) {
    $payment = $recycled[0];
    $branchTotal = 0.0;
    foreach ($branchIds as $branchId) {
        $branchReport = $ledger->report([
            'supplierId' => (int) $payment['supplier_id'],
            'currency' => (string) $payment['currency'],
        ], [$branchId]);
        foreach ($branchReport['rows'] as $row) {
            if ((int) ($row['supplier_payment_id'] ?? 0) === (int) $payment['id']
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
                $branchTotal += (float) $row['raw_credit_amount'];
            }
        }
    }
    if (abs($branchTotal - (float) $payment['paid_amount']) > 0.005) {
        throw new RuntimeException('SPAY-000168 branch payment rows do not conserve its saved amount.');
    }
}

$corrected = array_values(array_filter(
    $payments,
    static fn (array $payment): bool => $payment['payment_no'] === 'SPAY-000235'
));
if ($corrected !== []) {
    $payment = $corrected[0];
    $branchSum = 0.0;
    foreach ($branchIds as $branchId) {
        $branchReport = $ledger->report([
            'supplierId' => (int) $payment['supplier_id'],
            'currency' => (string) $payment['currency'],
        ], [$branchId]);
        foreach ($branchReport['rows'] as $row) {
            if ((int) ($row['supplier_payment_id'] ?? 0) === (int) $payment['id']
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
                $branchSum += (float) ($row['raw_credit_amount'] ?? 0);
            }
        }
    }
    if (abs($branchSum - (float) $payment['paid_amount']) > 0.005) {
        throw new RuntimeException('SPAY-000235 branch rows do not conserve its original payment.');
    }
    foreach (['2026-10-05' => 0.0, '2026-10-06' => 500000.0, '2026-10-23' => 500000.0] as $asOf => $expected) {
        $datedReport = $ledger->report([
            'supplierId' => (int) $payment['supplier_id'],
            'currency' => (string) $payment['currency'],
            'dateTo' => $asOf,
        ], $branchIds);
        $shownAmount = 0.0;
        foreach ($datedReport['rows'] as $row) {
            if ((int) ($row['supplier_payment_id'] ?? 0) === (int) $payment['id']
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
                $shownAmount += (float) ($row['raw_credit_amount'] ?? 0);
            }
        }
        if (abs($shownAmount - $expected) > 0.005) {
            throw new RuntimeException('SPAY-000235 does not retain its original amount as of ' . $asOf . '.');
        }
    }
}

$zeroIdRejected = false;
try {
    (new \App\Repositories\AccountingRepository($app))->postSupplierPaymentAllocation([
        'source_reference' => 'SPAY-TEST-ALLOC-0',
    ]);
} catch (RuntimeException $exception) {
    $zeroIdRejected = $exception->getMessage() === 'Supplier payment allocation must have a recorded allocation ID.';
}
if (! $zeroIdRejected) {
    throw new RuntimeException('A zero-ID supplier allocation journal was not rejected.');
}

echo '[PASS] Every active supplier payment appears once at its saved amount, supplier, and currency.' . PHP_EOL;
echo '[PASS] Recycled supplier credit does not duplicate SPAY-000168 across branches.' . PHP_EOL;
echo '[PASS] SPAY-000235 remains one full payment before and after later credit reuse.' . PHP_EOL;
echo '[PASS] Allocation journals reject a missing allocation ID before posting.' . PHP_EOL;
