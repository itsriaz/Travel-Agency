<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\AccountLedgerService($app);

$fail = static function (string $message): never {
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
};

$position = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-CANCEL-CURR-001',
    'booking_date' => '2026-08-20',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Cancellation Currency Customer',
    'contact_mobile' => 'N/A',
    'booking_service_id' => 1,
    'service_line_reference' => 'SV-001',
    'service_type' => 'air ticket',
    'service_status' => 'Cancelled',
    'currency' => 'AED',
    'cost_currency' => 'PKR',
    'pricing_exchange_rate' => 77,
    'original_invoice_amount' => 964,
    'original_supplier_cost' => 65000,
    'original_profit_loss' => 120,
    'passenger_name' => 'Cancellation Currency Customer',
    'supplier_name' => 'PIA',
    'pnr' => 'N/A',
    'route' => 'ISB/DXB',
    'current_customer_due' => 0,
    'current_customer_outstanding' => 0,
    'current_customer_unallocated_credit' => 35000,
    'current_supplier_gross' => 65000,
    'current_supplier_payable' => 35000,
    'current_supplier_paid' => 0,
    'cancellation_event_id' => 1,
    'cancellation_date' => '2026-08-20',
    'customer_penalty_amount' => 10000,
    'customer_penalty_currency' => 'PKR',
    'customer_penalty_source_amount' => 10000,
    'supplier_penalty_amount' => 20000,
    'supplier_penalty_currency' => 'PKR',
    'supplier_penalty_source_amount' => 20000,
    'customer_final_charge_amount' => 0,
    'expected_supplier_refund_amount' => 45000,
    'expected_supplier_refund_source_amount' => 45000,
    'available_customer_refund_credit' => 35000,
    'customer_refund_paid' => 35000,
    'supplier_refund_received' => 0,
];

$report = $service->buildReport([
    'positions' => [$position],
    'customerReceipts' => [],
    'refundMovements' => [],
    'customerAdvanceMovements' => [],
    'customerCreditTransfers' => [],
    'reissueAdjustments' => [],
    'accountSupplierSettlements' => [],
]);

$penaltyRow = null;
foreach ((array) ($report['rows'] ?? []) as $row) {
    if ((string) ($row['ledger_entry'] ?? '') === 'Customer penalty / profit charged') {
        $penaltyRow = $row;
        break;
    }
}

if (! is_array($penaltyRow)) {
    $fail('Cancellation penalty row was not generated.');
}
if ((string) ($penaltyRow['currency'] ?? '') !== 'PKR') {
    $fail('Cancellation penalty row must use payload penalty currency PKR.');
}
if (! str_contains((string) ($penaltyRow['transaction_note'] ?? ''), 'PKR 10,000')) {
    $fail('Cancellation penalty note must mention PKR 10,000.');
}

echo '[PASS] Cancellation penalty row respects payload currency instead of service invoice currency.' . PHP_EOL;

$brokenConvertedPayloadPosition = $position;
$brokenConvertedPayloadPosition['booking_reference'] = 'BK-CANCEL-CURR-002';
$brokenConvertedPayloadPosition['customer_penalty_amount'] = 826.00;
$brokenConvertedPayloadPosition['customer_penalty_source_amount'] = 826.00;
$brokenConvertedPayloadPosition['supplier_penalty_amount'] = 35700.00;
$brokenConvertedPayloadPosition['supplier_penalty_source_amount'] = 35700.00;
$brokenConvertedPayloadPosition['customer_final_charge_amount'] = 515.72;
$brokenConvertedPayloadPosition['expected_supplier_refund_amount'] = 55826.00;
$brokenConvertedPayloadPosition['expected_supplier_refund_source_amount'] = 55826.00;
$brokenConvertedPayloadPosition['available_customer_refund_credit'] = 714.28;
$brokenConvertedPayloadPosition['customer_refund_paid'] = 55000.00;

$brokenReport = $service->buildReport([
    'positions' => [$brokenConvertedPayloadPosition],
    'customerReceipts' => [],
    'refundMovements' => [],
    'customerAdvanceMovements' => [],
    'customerCreditTransfers' => [],
    'reissueAdjustments' => [],
    'accountSupplierSettlements' => [],
]);

$brokenPenaltyRow = null;
foreach ((array) ($brokenReport['rows'] ?? []) as $row) {
    if ((string) ($row['ledger_entry'] ?? '') === 'Customer penalty / profit charged') {
        $brokenPenaltyRow = $row;
        break;
    }
}

if (! is_array($brokenPenaltyRow)) {
    $fail('Broken converted payload scenario did not generate a customer penalty row.');
}
if ((float) ($brokenPenaltyRow['raw_debit_amount'] ?? 0) !== 826.0) {
    $fail('Broken converted payload must prefer PKR 826 source penalty amount in ledger rows.');
}

$brokenSummaryRows = (array) ($brokenReport['summaryRows'] ?? []);
$aedSummary = null;
foreach ($brokenSummaryRows as $summaryRow) {
    if ((string) ($summaryRow['currency'] ?? '') === 'AED') {
        $aedSummary = $summaryRow;
        break;
    }
}

if (! is_array($aedSummary)) {
    $fail('Broken converted payload scenario must still produce an AED summary row.');
}
if (str_contains((string) ($aedSummary['recognized_profit_loss'] ?? ''), '-')) {
    $fail('Broken converted payload scenario must not manufacture a negative AED loss from bogus converted supplier penalty amounts.');
}

echo '[PASS] Mixed-currency cancellation ledger prefers source penalty amounts and avoids fake converted AED loss.' . PHP_EOL;
