<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actor = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = "super_admin" AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
$reports = new \App\Services\ReportService($app);

$date = static function (array $row, string $key): string {
    $value = (string) ($row[$key] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
};
$assertNewestFirst = static function (string $label, array $rows, string $key) use ($date): void {
    $previous = '9999-12-31';
    foreach ($rows as $row) {
        $current = $date($row, $key);
        if (strcmp($previous, $current) < 0) {
            throw new RuntimeException($label . ' is not globally newest-first across branches.');
        }
        $previous = $current;
    }
};
$rowTruth = static function (array $rows, string $dateKey): array {
    $fingerprints = [];
    foreach ($rows as $row) {
        $fingerprints[] = json_encode([
            (string) ($row[$dateKey] ?? ''),
            (int) ($row['branch_id'] ?? 0),
            (int) ($row['booking_id'] ?? 0),
            (string) ($row['booking_reference'] ?? ''),
            (string) ($row['transaction_reference'] ?? $row['entry_type'] ?? ''),
            round((float) ($row['raw_debit_amount'] ?? 0), 2),
            round((float) ($row['raw_credit_amount'] ?? 0), 2),
            round((float) ($row['raw_balance_amount'] ?? 0), 2),
        ]);
    }
    sort($fingerprints);
    return $fingerprints;
};

foreach (['customer_ledger' => 'entry_date_raw', 'supplier_ledger' => 'ledger_date'] as $report => $dateKey) {
    $state = $reports->reportState(['report' => $report], $branches, $actor, 'screen');
    $assertNewestFirst($report, (array) ($state['rows'] ?? []), $dateKey);
    $source = $report === 'customer_ledger'
        ? (new \App\Services\AccountLedgerService($app))->report($state['filters'], $branches)
        : (new \App\Services\SupplierLedgerService($app))->report($state['filters'], $branches);
    if ($rowTruth((array) ($state['rows'] ?? []), $dateKey) !== $rowTruth((array) ($source['rows'] ?? []), $dateKey)) {
        throw new RuntimeException($report . ' changed a movement or running balance while sorting.');
    }
}

$link = $db->query('SELECT business_source_id, supplier_id FROM business_source_supplier_links ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($link !== false) {
    foreach ([
        ['customer_ledger', 'business_source_id', (int) $link['business_source_id'], 'entry_date_raw'],
        ['supplier_ledger', 'supplier_id', (int) $link['supplier_id'], 'ledger_date'],
    ] as [$report, $filter, $id, $dateKey]) {
        $state = $reports->reportState(['report' => $report, $filter => $id], $branches, $actor, 'screen');
        if (! ($state['isLinkedPartyLedger'] ?? false)) {
            throw new RuntimeException($report . ' did not use the linked view.');
        }
        $assertNewestFirst('linked ' . $report, (array) ($state['rows'] ?? []), $dateKey);
    }
}

echo '[PASS] Account and supplier reports show newest dates first across branches; row balances remain unchanged.' . PHP_EOL;
