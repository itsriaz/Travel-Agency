<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Helpers\SupplierLedgerDisplay;

$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

$rows = SupplierLedgerDisplay::formatRows([[
    'debit_amount' => '1,234.00',
    'credit_amount' => '0.00',
    'balance_amount' => '432,023.00 Advance',
    'raw_debit_amount' => 1234.25,
    'raw_balance_amount' => -432023.25,
    'trace' => 'Voided: duplicate | Original amount PKR 1,188.00',
], [
    'debit_amount' => '0.00',
    'credit_amount' => '653.50',
    'balance_amount' => '-653.50 Receivable',
]]);
$cards = SupplierLedgerDisplay::formatCards([
    ['label' => 'Supplier Balance / PKR', 'value' => 'PKR 432,023.00 Advance'],
    ['label' => 'Supplier Balance / AED', 'value' => 'AED 0.00'],
    ['label' => 'Unconverted / USD', 'value' => 'Not available'],
]);

$check('Ledger rows show whole amounts and preserve balance labels',
    $rows[0]['debit_amount'] === '1,234'
    && $rows[0]['credit_amount'] === '0'
    && $rows[0]['balance_amount'] === '432,023 Advance'
    && $rows[1]['credit_amount'] === '654'
    && $rows[1]['balance_amount'] === '-654 Receivable');
$check('Ungrouped four-digit amounts receive proper thousands separators',
    SupplierLedgerDisplay::formatAmount('1234.00') === '1,234');
$check('Raw ledger values remain unchanged',
    $rows[0]['raw_debit_amount'] === 1234.25
    && $rows[0]['raw_balance_amount'] === -432023.25);
$check('Voided payment trace uses the same whole-number display',
    $rows[0]['trace'] === 'Voided: duplicate | Original amount PKR 1,188');
$check('Summary cards show whole amounts without changing nonnumeric values',
    $cards[0]['value'] === 'PKR 432,023 Advance'
    && $cards[1]['value'] === 'AED 0'
    && $cards[2]['value'] === 'Not available');

exit($failures === [] ? 0 : 1);
