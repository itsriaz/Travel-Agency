<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$payment = $db->query(
    "SELECT id, supplier_id, currency, paid_amount, status
     FROM supplier_payments WHERE payment_no = 'SPAY-000235'"
)->fetch(PDO::FETCH_ASSOC);
if (! is_array($payment) || (float) $payment['paid_amount'] !== 500000.0 || $payment['status'] === 'void') {
    throw new RuntimeException('SPAY-000235 source payment is missing or changed.');
}

$ledger = new \App\Services\SupplierLedgerService($app);
$shownAmount = static function (array $branches, ?string $asOf = null) use ($ledger, $payment): float {
    $report = $ledger->report([
        'supplierId' => (int) $payment['supplier_id'],
        'currency' => (string) $payment['currency'],
        'dateTo' => $asOf,
    ], $branches);
    $amount = 0.0;
    foreach ($report['rows'] as $row) {
        if ((int) ($row['supplier_payment_id'] ?? 0) === (int) $payment['id']
            && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
            $amount += (float) ($row['raw_credit_amount'] ?? 0);
        }
    }
    return round($amount, 2);
};

$branchOne = $shownAmount([1]);
$branchTwo = $shownAmount([2]);
$combined = $shownAmount([1, 2]);
if (abs($branchOne + $branchTwo - 500000.0) > 0.005
    || abs($combined - 500000.0) > 0.005) {
    throw new RuntimeException(sprintf(
        'Branch payments do not conserve PKR 500,000: branch 1 %.2f, branch 2 %.2f, combined %.2f.',
        $branchOne,
        $branchTwo,
        $combined
    ));
}

foreach (['2026-10-05' => 0.0, '2026-10-06' => 500000.0, '2026-10-24' => 500000.0] as $asOf => $expected) {
    if (abs($shownAmount([1, 2], $asOf) - $expected) > 0.005) {
        throw new RuntimeException('The original payment changed in the as-of ledger for ' . $asOf . '.');
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
    throw new RuntimeException('A zero-ID supplier allocation journal was accepted.');
}

$mismatchedIdRejected = false;
try {
    (new \App\Repositories\AccountingRepository($app))->postSupplierPaymentAllocation([
        'source_reference' => 'SPAY-000235-ALLOC-930',
        'supplier_payment_id' => 0,
        'supplier_obligation_id' => 1033,
        'allocated_amount' => 105236,
    ]);
} catch (RuntimeException $exception) {
    $mismatchedIdRejected = $exception->getMessage()
        === 'Supplier payment allocation journal does not match its allocation record.';
}
if (! $mismatchedIdRejected) {
    throw new RuntimeException('A supplier allocation journal was accepted for the wrong payment.');
}

echo '[PASS] SPAY-000235 shows the full original PKR 500,000 in both branches and combined.' . PHP_EOL;
echo '[PASS] Later allocation changes do not rewrite the original payment in as-of ledgers.' . PHP_EOL;
echo '[PASS] Zero or mismatched allocation IDs are rejected before journal posting.' . PHP_EOL;
