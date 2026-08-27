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

$pass = static function (string $message): void {
    echo '[PASS] ' . $message . PHP_EOL;
};

$position = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-RCPT-001',
    'booking_date' => '2026-08-20',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Ledger Receipt Customer',
    'contact_mobile' => 'N/A',
    'booking_service_id' => 1,
    'service_line_reference' => 'SV-001',
    'service_type' => 'air ticket',
    'service_status' => 'Booked',
    'currency' => 'AED',
    'cost_currency' => 'AED',
    'pricing_exchange_rate' => 1,
    'original_invoice_amount' => 1154,
    'original_supplier_cost' => 0,
    'original_profit_loss' => 0,
    'passenger_name' => 'Ledger Receipt Customer',
    'supplier_name' => 'PIA',
    'pnr' => 'N/A',
    'route' => 'N/A',
    'current_customer_due' => 1154,
    'current_customer_outstanding' => 0,
    'current_supplier_gross' => 0,
    'current_supplier_payable' => 0,
    'current_supplier_paid' => 0,
    'expected_supplier_refund_amount' => 0,
    'available_customer_refund_credit' => 0,
    'customer_refund_paid' => 0,
    'supplier_refund_received' => 0,
];

$receipt = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-RCPT-001',
    'booking_date' => '2026-08-20',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Ledger Receipt Customer',
    'contact_mobile' => 'N/A',
    'movement_id' => 1,
    'movement_reference' => 'RCPT-TEST-001',
    'movement_date' => '2026-08-20',
    'currency' => 'AED',
    'debit_amount' => 1154,
    'payment_method' => 'cash',
    'treasury_account_name' => 'Cash-UAE-AED',
    'external_reference' => '',
];

$report = $service->buildReport([
    'positions' => [$position],
    'customerReceipts' => [$receipt],
    'refundMovements' => [],
    'customerAdvanceMovements' => [],
    'customerCreditTransfers' => [],
    'reissueAdjustments' => [],
    'accountSupplierSettlements' => [],
]);

$rows = array_values((array) ($report['rows'] ?? []));
if (count($rows) !== 2) {
    $fail('Expected exactly two ledger rows for invoice and receipt.');
}

$invoiceRow = $rows[0] ?? null;
$receiptRow = $rows[1] ?? null;
if (! is_array($invoiceRow) || ! is_array($receiptRow)) {
    $fail('Ledger rows were not built as expected.');
}

if ((string) ($invoiceRow['ledger_entry'] ?? '') !== 'Air Ticket invoice') {
    $fail('First row must be the invoice row.');
}
if ((string) ($receiptRow['ledger_entry'] ?? '') !== 'Customer payment received') {
    $fail('Second row must be the receipt row.');
}
if (abs((float) ($receiptRow['raw_debit_amount'] ?? 0) - 1154.0) > 0.005) {
    $fail('Receipt must still display under Debit / Money In.');
}
if (abs((float) ($receiptRow['raw_balance_amount'] ?? 999.0)) > 0.005) {
    $fail('Receipt must close the running balance instead of inflating it.');
}

$pass('Booking receipt remains Debit / Money In while closing the invoice running balance.');
echo 'Account ledger receipt balance regression passed.' . PHP_EOL;
