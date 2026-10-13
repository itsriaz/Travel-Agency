<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$service = new \App\Services\ReportService(\App\Core\App::bootstrap(BASE_PATH));
$report = new ReflectionMethod($service, 'cashBankPositionReport');

[, $cards] = $report->invoke($service, [
    ['currency' => 'PKR', 'account_group' => 'Cash', 'account_code' => 'CASH_ON_HAND', 'balance' => -100],
    ['currency' => 'AED', 'account_group' => 'Cash', 'account_code' => 'CASH_ON_HAND', 'balance' => 50],
    ['currency' => 'PKR', 'account_group' => 'Bank Account', 'account_code' => 'BANK_CLEARING', 'balance' => 200],
    ['currency' => 'AED', 'account_group' => 'Bank Account', 'account_code' => 'BANK_CLEARING', 'balance' => 80],
    ['currency' => 'PKR', 'account_group' => 'Card / Clearing', 'account_code' => 'CARD_CLEARING', 'balance' => 5],
]);

$expected = [
    'Cash on Hand / PKR' => ['PKR -100', 'cash_position_cash', 'Cash on Hand'],
    'Cash on Hand / AED' => ['AED 50', 'cash_position_cash', 'Cash on Hand'],
    'Bank and Wallet Balances / PKR' => ['PKR 200', 'cash_position_bank', 'Bank and Wallet Balances'],
    'Bank and Wallet Balances / AED' => ['AED 80', 'cash_position_bank', 'Bank and Wallet Balances'],
    'Card Clearing / PKR' => ['PKR 5', 'cash_position_card', 'Card Clearing'],
    'Net Liquid Position / PKR' => ['PKR 105', 'cash_position_net', 'Net Liquid Position'],
    'Net Liquid Position / AED' => ['AED 130', 'cash_position_net', 'Net Liquid Position'],
];

$actual = [];
foreach ($cards as $card) {
    $actual[(string) $card['label']] = [
        (string) $card['value'],
        (string) ($card['group'] ?? ''),
        (string) ($card['group_title'] ?? ''),
    ];
}

if ($actual !== $expected) {
    throw new RuntimeException('Cash and bank card grouping or amounts changed: ' . json_encode($actual, JSON_THROW_ON_ERROR));
}

echo '[PASS] Cash, bank, card, and net balances retain their amounts in separate groups.' . PHP_EOL;
