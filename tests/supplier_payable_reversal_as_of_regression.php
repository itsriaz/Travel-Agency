<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$repository = new \App\Repositories\ReportRepository($app);

// Both reversals have an exact inverse AP_CONTROL line against the original
// obligation. The original is valid before its reversal date, not afterward.
foreach ([
    ['booking' => 'BK-000570', 'original' => 5464, 'before' => '2026-10-09'],
    ['booking' => 'BK-000897', 'original' => 6205, 'before' => '2026-10-02'],
] as $case) {
    $before = $repository->supplierLedger($branches, null, $case['before'], 'PKR', '', 349, 0, $case['booking']);
    $current = $repository->supplierLedger($branches, null, null, 'PKR', '', 349, 0, $case['booking']);
    $contains = static fn (array $rows): bool => count(array_filter(
        $rows,
        static fn (array $row): bool => (int) ($row['journal_entry_id'] ?? 0) === $case['original']
    )) === 1;
    if (! $contains($before) || $contains($current)) {
        throw new RuntimeException($case['booking'] . ' payable reversal is not applied at the correct report date.');
    }
}

$source = (float) $db->query('SELECT
    (SELECT COALESCE(SUM(paid_amount), 0) FROM supplier_payments
        WHERE supplier_id = 349 AND currency = "PKR" AND status <> "void")
    - (SELECT COALESCE(SUM(gross_amount), 0) FROM supplier_obligations
        WHERE supplier_id = 349 AND currency = "PKR")')->fetchColumn();
$ledger = (new \App\Services\SupplierLedgerService($app))->report(['supplierId' => 349, 'currency' => 'PKR'], $branches);
$ledgerNet = 0.0;
foreach ($ledger['rows'] as $row) {
    $ledgerNet += (float) ($row['raw_credit_amount'] ?? 0) - (float) ($row['raw_debit_amount'] ?? 0);
}
if (abs($ledgerNet - $source) > 0.005) {
    throw new RuntimeException('Supplier ledger still differs from independent saved payments minus obligations.');
}

echo '[PASS] Exact payable reversals affect only reports at or after their posting dates.' . PHP_EOL;
echo '[PASS] AirBlue supplier ledger equals independent payment and obligation sources: PKR '
    . number_format($ledgerNet, 2, '.', '') . PHP_EOL;
