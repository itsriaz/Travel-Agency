<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;

$app = App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new SupplierRepository($app);
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo '[PASS] ', $message, PHP_EOL;
};
$snapshot = static function () use ($db): array {
    $rows = [];
    foreach (['supplier_payments' => 'paid_amount', 'supplier_advances' => 'available_amount',
        'supplier_obligations' => 'net_payable_amount', 'customer_receivable_items' => 'outstanding_amount',
        'journal_entries' => 'id', 'journal_entry_lines' => 'debit_amount'] as $table => $amount) {
        $rows[$table] = $db->query("SELECT COUNT(*) AS row_count, COALESCE(SUM({$amount}), 0) AS amount FROM {$table}")
            ->fetch(PDO::FETCH_ASSOC);
    }
    return $rows;
};

$before = $snapshot();
$supplier = $db->query('SELECT id FROM suppliers WHERE name = "mysupplier" ORDER BY id DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
$assert(is_array($supplier), 'The refreshed local database has the mysupplier example.');
$rows = $repository->searchGlobalSupplierAdvances([1, 2], ['supplier_id' => (int) $supplier['id']], 200);
$byPayment = [];
foreach ($rows as $row) {
    $byPayment[(string) $row['source_supplier_payment_no']] = $row;
}
foreach ([
    'SPAY-000269' => [10000, 3500, 6500, 'IMDAD BANK ACC'],
    'SPAY-000271' => [10000, 2500, 7500, 'BILAL BANK ACC'],
] as $paymentNo => [$paid, $used, $available, $sourceAccount]) {
    $row = $byPayment[$paymentNo] ?? null;
    $assert(is_array($row), $paymentNo . ' appears once in the advance manager.');
    $assert(abs((float) $row['display_paid_amount'] - $paid) < 0.01
        && abs((float) $row['display_used_amount'] - $used) < 0.01
        && abs((float) $row['display_available_amount'] - $available) < 0.01,
        $paymentNo . ' shows full paid, all ticket use, and remaining credit.');
    $assert((string) $row['treasury_account_name'] === $sourceAccount,
        $paymentNo . ' shows its original payment source account.');
}

$multi = $db->query('SELECT source_supplier_payment_id, supplier_id, COUNT(*) AS credit_count
    FROM supplier_advances WHERE source_supplier_payment_id IS NOT NULL AND deposit_amount > 0.005
    GROUP BY source_supplier_payment_id, supplier_id HAVING COUNT(*) > 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert(is_array($multi), 'A historical payment with multiple credit records exists.');
$multiRows = $repository->searchGlobalSupplierAdvances([1, 2], ['supplier_id' => (int) $multi['supplier_id']], 200);
$matches = array_values(array_filter($multiRows, static fn (array $row): bool =>
    (int) $row['source_supplier_payment_id'] === (int) $multi['source_supplier_payment_id']));
$assert(count($matches) === 1 && (int) $matches[0]['credit_count'] === (int) $multi['credit_count'],
    'Multiple credit records are grouped under one original payment, without double-counting Paid.');
$assert(abs((float) $matches[0]['display_paid_amount'] - (float) $matches[0]['display_used_amount']
    - (float) $matches[0]['display_available_amount'] - (float) $matches[0]['returned_amount']) < 0.01,
    'The grouped payment display reconciles Paid to Used, Available, and Returned.');
$partiallyUsed = $repository->searchGlobalSupplierAdvances([1, 2], [
    'supplier_id' => (int) $supplier['id'],
    'status' => 'partially_used',
    'date_from' => '2026-10-16',
    'date_to' => '2026-10-16',
], 200);
$assert(count($partiallyUsed) === 2, 'Status and displayed payment-date filters find both partially used payments.');
$allRows = $repository->searchGlobalSupplierAdvances([1, 2], [], 200);
foreach ($allRows as $row) {
    if (abs((float) $row['display_paid_amount'] - (float) $row['display_used_amount']
        - (float) $row['display_available_amount'] - (float) $row['returned_amount']) >= 0.01) {
        throw new RuntimeException('Payment ' . ($row['source_supplier_payment_no'] ?: 'SADV-' . $row['id']) . ' display does not reconcile.');
    }
}
$assert($allRows !== [], 'All ' . count($allRows) . ' displayed payments reconcile, including split credits and unallocated funds.');
$assert($before === $snapshot(), 'Display search did not change payments, advances, ledgers, or journals.');
