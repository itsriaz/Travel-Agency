<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$service = new \App\Services\LinkedPartyLedgerService(\App\Core\App::bootstrap(BASE_PATH));
$accountRows = [[
    'branch_id' => 1,
    'branch_name' => 'Swat',
    'entry_date_raw' => '2026-01-01',
    'currency' => 'PKR',
    'ledger_entry' => 'Customer invoice',
    'is_financial_position_only' => true,
    'raw_debit_amount' => 1000,
    'raw_credit_amount' => 0,
], [
    'branch_id' => 2,
    'branch_name' => 'Dubai',
    'entry_date_raw' => '2026-01-02',
    'currency' => 'PKR',
    'ledger_entry' => 'Net payment received',
    'raw_debit_amount' => 10000,
    'raw_credit_amount' => 0,
], [
    'branch_id' => 2,
    'branch_name' => 'Dubai',
    'entry_date_raw' => '2026-01-03',
    'currency' => 'AED',
    'ledger_entry' => 'Customer invoice',
    'is_financial_position_only' => true,
    'raw_debit_amount' => 500,
    'raw_credit_amount' => 0,
]];

$assert = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo '[PASS] ' . $label . PHP_EOL;
};

$beforeCash = $service->buildSupplierReport([$accountRows[0]], [], null, null, 'PKR', 'AJWA');
$assert((float) ($beforeCash['footerRows'][0]['raw_balance_amount'] ?? 0) === -1000.0,
    'PKR 1,000 owed by the account holder is PKR 1,000 supplier receivable');

$accountState = $service->buildReport($accountRows, [], null, null);
$footers = array_column($accountState['footerRows'], null, 'currency');
$assert(count($footers) === 2 && (float) ($footers['PKR']['raw_balance_amount'] ?? 0) === -9000.0,
    'PKR 10,000 direct cash crosses branches once and leaves PKR 9,000 due to the party');
$assert((float) ($footers['AED']['raw_balance_amount'] ?? 0) === 500.0,
    'AED remains separate from the PKR pool');
$assert(array_column($accountState['rows'], 'branch_id') === [1, 2, 2],
    'Each transaction retains its real branch for audit');

$supplierState = $service->buildSupplierReport($accountRows, [], null, null, 'PKR', 'AJWA');
$assert((float) ($supplierState['footerRows'][0]['raw_balance_amount'] ?? 0) === 9000.0,
    'Supplier-facing view mirrors the same PKR 9,000 payable without duplicating cash');

$datedState = $service->buildReport($accountRows, [], '2026-01-02', '2026-01-02', 'PKR');
$assert((float) ($datedState['footerRows'][0]['raw_balance_amount'] ?? 0) === -9000.0
    && count(array_filter($datedState['rows'], static fn (array $row): bool => ($row['ledger_entry'] ?? '') === 'Opening balance')) === 1,
    'Date-filtered opening balance also pools branches by currency');
