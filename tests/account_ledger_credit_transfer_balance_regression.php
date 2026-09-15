<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\AccountLedgerService($app);
$failures = [];
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $ok) $failures[] = $label;
};
$party = [
    'booking_id' => 1, 'booking_reference' => 'QA-CREDIT-TARGET',
    'branch_id' => 1, 'branch_name' => 'Test branch',
    'lead_traveler_id' => 1, 'customer_name' => 'Test customer',
    'business_source_name' => 'Test account', 'currency' => 'AED',
    'booking_date' => '2026-08-01', 'service_type' => 'air ticket',
    'service_line_reference' => 'SV-001',
];
$transfer = $party + [
    'movement_date' => '2026-08-02', 'movement_reference' => 'QA-CREDIT-1',
    'transfer_direction' => 'target', 'counterpart_booking_reference' => 'QA-CREDIT-SOURCE',
    'debit_amount' => 844, 'credit_amount' => 0, 'receipt_purpose' => 'booking_payment',
];
$build = static function (array $position, array $transfers, array $receipts = []) use ($service): array {
    return $service->buildReport([
        'positions' => [$position], 'customerCreditTransfers' => $transfers,
        'customerReceipts' => $receipts,
    ]);
};
$lastBalance = static function (array $report): float {
    $rows = $report['rows'];
    return (float) $rows[array_key_last($rows)]['raw_balance_amount'];
};

foreach (['AED', 'PKR', 'USD'] as $currency) {
    foreach ([844 => 0, 1450 => 606] as $invoice => $due) {
        $position = array_replace($party, [
            'currency' => $currency, 'cost_currency' => $currency,
            'original_invoice_amount' => $invoice,
            'current_customer_due' => $invoice, 'current_customer_outstanding' => $due,
        ]);
        $movement = array_replace($transfer, ['currency' => $currency]);
        $report = $build($position, [$movement]);
        $check($currency . ': invoice ' . $invoice . ' minus transferred credit 844 closes at ' . $due,
            $lastBalance($report) === (float) $due);
        $creditRow = $report['rows'][1];
        $check($currency . ': credit stays displayed as Debit 844 without a new customer receipt',
            (float) $creditRow['raw_debit_amount'] === 844.0
            && (float) $report['summaryRows'][0]['customer_paid'] === 0.0
            && (float) $report['summaryRows'][0]['raw_total_debit'] === 0.0);
    }
}

$source = array_replace($party, [
    'booking_reference' => 'QA-CREDIT-SOURCE', 'original_invoice_amount' => 156,
    'current_customer_due' => 156, 'current_customer_outstanding' => 0,
]);
$receipt = $source + ['movement_date' => '2026-08-01', 'movement_reference' => 'QA-RECEIPT', 'debit_amount' => 1000];
$outgoing = array_replace($transfer, [
    'booking_reference' => 'QA-CREDIT-SOURCE', 'transfer_direction' => 'source',
    'counterpart_booking_reference' => 'QA-CREDIT-TARGET', 'debit_amount' => 0, 'credit_amount' => 844,
]);
$sourceReport = $build($source, [$outgoing], [$receipt]);
$check('Source invoice 156 minus receipt 1000 leaves credit 844; sending 844 closes it at zero', $lastBalance($sourceReport) === 0.0);
$check('Source keeps the original cash receipt counted once', (float) $sourceReport['summaryRows'][0]['raw_total_debit'] === 1000.0);

$target = array_replace($party, ['original_invoice_amount' => 844, 'current_customer_due' => 844, 'current_customer_outstanding' => 0]);
$advance = $build($target, [array_replace($transfer, ['receipt_purpose' => 'customer_advance'])]);
$check('An advance applied to the target invoice reduces its balance without becoming a new receipt',
    $lastBalance($advance) === 0.0 && (float) $advance['summaryRows'][0]['raw_total_debit'] === 0.0);
$split = $build($target, [
    array_replace($transfer, ['debit_amount' => 400]),
    array_replace($transfer, ['debit_amount' => 444, 'movement_reference' => 'QA-CREDIT-2', 'movement_date' => '2026-08-03']),
]);
$check('Two partial credits of 400 and 444 settle the 844 invoice exactly once', $lastBalance($split) === 0.0);

// The presentation layer consumes already denominated amounts; it must not convert or combine currencies.
$crossCurrencySource = array_replace($source, ['currency' => 'PKR', 'original_invoice_amount' => 0, 'current_customer_due' => 0]);
$crossCurrencyReceipt = array_replace($receipt, ['currency' => 'PKR', 'debit_amount' => 64988]);
$crossCurrencyOutgoing = array_replace($outgoing, ['currency' => 'PKR', 'credit_amount' => 64988]);
$otherBranchTarget = array_replace($target, ['branch_id' => 2]);
$combined = $service->buildReport([
    'positions' => [$crossCurrencySource, $otherBranchTarget],
    'customerReceipts' => [$crossCurrencyReceipt],
    'customerCreditTransfers' => [$crossCurrencyOutgoing, array_replace($transfer, ['branch_id' => 2])],
]);
$lastByCurrency = [];
foreach ($combined['rows'] as $row) $lastByCurrency[$row['currency']] = (float) $row['raw_balance_amount'];
$check('Pre-denominated PKR 64,988 outgoing and AED 844 incoming close independently across branches',
    $lastByCurrency === ['PKR' => 0.0, 'AED' => 0.0] || $lastByCurrency === ['AED' => 0.0, 'PKR' => 0.0]);

if ($failures !== []) exit(1);
echo 'Account ledger credit transfer balance regression passed. No database records changed.' . PHP_EOL;
