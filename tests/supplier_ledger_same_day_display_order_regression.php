<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

try {
    $app = \App\Core\App::bootstrap(BASE_PATH);
    $db = $app->get('db');
    $branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $actor = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = "super_admin" AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
    $supplier = $db->query('SELECT id FROM suppliers WHERE name = "AirBlue imdad int" ORDER BY id LIMIT 1')->fetchColumn();
    $filters = ['report' => 'supplier_ledger', 'supplier_id' => (int) $supplier];
    $display = (new \App\Services\ReportService($app))->reportState($filters, $branches, $actor, 'screen');
    $source = (new \App\Services\SupplierLedgerService($app))->report($display['filters'], $branches);

    $rowKey = static fn (array $row): string => json_encode([
        (string) ($row['ledger_date'] ?? ''),
        (string) ($row['booking_reference'] ?? ''),
        (string) ($row['entry_type'] ?? ''),
        (int) ($row['journal_entry_id'] ?? 0),
        round((float) ($row['raw_debit_amount'] ?? 0), 2),
        round((float) ($row['raw_credit_amount'] ?? 0), 2),
        round((float) ($row['raw_balance_amount'] ?? 0), 2),
    ], JSON_THROW_ON_ERROR);
    $sourceByDate = [];
    $displayByDate = [];
    foreach ($source['rows'] as $row) {
        $sourceByDate[(string) $row['ledger_date']][] = $rowKey($row);
    }
    foreach ($display['rows'] as $row) {
        $displayByDate[(string) $row['ledger_date']][] = $rowKey($row);
    }
    foreach ($sourceByDate as $date => $rows) {
        if (($displayByDate[$date] ?? []) !== array_reverse($rows)) {
            throw new RuntimeException('Same-day closing row is not first on ' . $date);
        }
    }
    $sourceDates = array_keys($sourceByDate);
    rsort($sourceDates);
    if (array_keys($displayByDate) !== $sourceDates) {
        throw new RuntimeException('Dates are not newest first.');
    }
    if (! isset($displayByDate['2026-10-22']) || count($displayByDate['2026-10-22']) < 2) {
        throw new RuntimeException('The AirBlue same-day example is incomplete.');
    }
    echo '[PASS] AirBlue supplier ledger displays each day newest transaction first and preserves every row amount and running balance.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
