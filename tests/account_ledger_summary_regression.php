<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\AccountLedgerService($app);

$fail = static function (string $message): never {
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
};

$pass = static function (string $message): void {
    echo '[PASS] ' . $message . PHP_EOL;
};

$money = static function (mixed $value): float {
    $text = trim((string) $value);
    $negative = str_contains($text, ' Cr') || str_contains($text, 'Loss');
    $numeric = preg_replace('/[^0-9.\-]/', '', $text) ?? '0';
    $amount = is_numeric($numeric) ? (float) $numeric : 0.0;

    return round($negative ? -abs($amount) : $amount, 2);
};

$basePosition = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-000001',
    'booking_date' => '2026-07-15',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Rizwan chacha',
    'contact_mobile' => 'N/A',
    'booking_service_id' => 1,
    'service_line_reference' => 'SV-001',
    'service_type' => 'air ticket',
    'service_status' => 'Cancelled',
    'currency' => 'PKR',
    'cost_currency' => 'PKR',
    'pricing_exchange_rate' => 1,
    'original_invoice_amount' => 1188,
    'original_supplier_cost' => 1188,
    'original_profit_loss' => 0,
    'passenger_name' => 'Rizwan chacha',
    'supplier_name' => 'PIA',
    'pnr' => 'PNR 123',
    'route' => 'ISB/DXB',
    'current_customer_due' => 580,
    'current_customer_outstanding' => 0,
    'current_supplier_gross' => 535,
    'current_supplier_payable' => 0,
    'current_supplier_paid' => 535,
    'supplier_payment_references' => 'SPAY-000001',
    'supplier_payment_date' => '2026-07-16',
    'cancellation_event_id' => 1,
    'cancellation_date' => '2026-07-15',
    'customer_penalty_amount' => 53,
    'supplier_penalty_amount' => 535,
    'customer_final_charge_amount' => 580,
    'expected_supplier_refund_amount' => 653,
    'available_customer_refund_credit' => 600,
    'released_supplier_payment_credit' => 0,
    'expected_supplier_refund_credit' => 0,
    'customer_refund_paid' => 600,
    'supplier_refund_received' => 653,
];

$customerReceipt = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-000001',
    'booking_date' => '2026-07-15',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Rizwan chacha',
    'contact_mobile' => 'N/A',
    'movement_id' => 1,
    'movement_reference' => 'RCPT-000001',
    'movement_date' => '2026-07-15',
    'currency' => 'PKR',
    'debit_amount' => 1180,
    'payment_method' => 'cash',
    'treasury_account_name' => 'Cash-SWT-PKR',
    'external_reference' => '',
];

$supplierRefund = [
    'booking_id' => 1,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-000001',
    'booking_date' => '2026-07-15',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Rizwan chacha',
    'contact_mobile' => 'N/A',
    'movement_id' => 66,
    'movement_reference' => 'REFUND-EVT-66',
    'movement_date' => '2026-07-16',
    'currency' => 'PKR',
    'customer_refund_amount' => 0,
    'supplier_refund_amount' => 653,
    'service_line_reference' => 'SV-001',
    'passenger_name' => 'Rizwan chacha',
    'supplier_name' => 'PIA',
    'pnr' => 'PNR 123',
    'route' => 'ISB/DXB',
    'payment_method' => 'cash',
    'treasury_account_name' => 'Cash-SWT-PKR',
    'external_reference' => '',
];

$customerRefund = array_merge($supplierRefund, [
    'movement_id' => 65,
    'movement_reference' => 'REFUND-EVT-65',
    'customer_refund_amount' => 600,
    'supplier_refund_amount' => 0,
]);

$settled = $service->buildReport([
    'positions' => [$basePosition],
    'customerReceipts' => [$customerReceipt],
    'refundMovements' => [$customerRefund, $supplierRefund],
]);

$settledEvents = [];
foreach ($settled['rows'] as $row) {
    $settledEvents[(string) ($row['ledger_entry'] ?? '')] = $row;
}

foreach ([
    'Customer payment received',
    'Supplier payment made',
    'Supplier refund received',
    'Customer refund paid',
] as $requiredEvent) {
    if (! isset($settledEvents[$requiredEvent])) {
        $fail('Settled ledger is missing: ' . $requiredEvent);
    }
}

if ($money($settledEvents['Customer payment received']['debit_amount'] ?? 0) !== 1180.0) {
    $fail('Customer payment must be PKR 1,180 money in.');
}
if ($money($settledEvents['Supplier payment made']['credit_amount'] ?? 0) !== 1188.0) {
    $fail('Supplier payment must reconcile to the original PKR 1,188 gross settlement.');
}
if ($money($settledEvents['Supplier refund received']['debit_amount'] ?? 0) !== 653.0) {
    $fail('Supplier refund must be PKR 653 money in.');
}
if ($money($settledEvents['Customer refund paid']['credit_amount'] ?? 0) !== 600.0) {
    $fail('Customer refund must be PKR 600 money out.');
}

$settledSummary = $settled['summaryRows'][0] ?? [];
if ($money($settledSummary['customer_paid'] ?? 0) !== 1180.0) {
    $fail('Summary must show customer paid as PKR 1,180 without adding supplier refunds.');
}
if ($money($settledSummary['supplier_paid'] ?? 0) !== 1188.0) {
    $fail('Summary must show supplier paid as PKR 1,188 without adding customer refunds.');
}
if ($money($settledSummary['total_debit'] ?? 0) !== 1180.0) {
    $fail('Summary Debit must contain customer payment only.');
}
if ($money($settledSummary['total_credit'] ?? 0) !== 1188.0) {
    $fail('Summary Credit must contain supplier payment only.');
}
if ($money($settledSummary['supplier_refund_received'] ?? 0) !== 653.0) {
    $fail('Summary must show supplier refund separately as PKR 653.');
}
if ($money($settledSummary['customer_refund_paid'] ?? 0) !== 600.0) {
    $fail('Summary must show customer refund separately as PKR 600.');
}
if ($money($settledSummary['cash_balance'] ?? 0) !== 45.0) {
    $fail('Fully settled booking must close at PKR 45 Dr.');
}
if ($money($settledSummary['recognized_profit_loss'] ?? 0) !== 45.0) {
    $fail('Fully settled cancellation must recognize PKR 45 profit.');
}
if (str_contains((string) ($settledSummary['recognized_profit_loss'] ?? ''), 'Profit')
    || str_contains((string) ($settledSummary['recognized_profit_loss'] ?? ''), 'Loss')) {
    $fail('Profit column values must be numeric without redundant Profit/Loss suffixes.');
}
$settledProfitCards = array_values(array_filter(
    (array) ($settled['summaryCards'] ?? []),
    static fn (array $card): bool => (string) ($card['label'] ?? '') === 'Total Profit / PKR'
));
if (count($settledProfitCards) !== 1 || $money($settledProfitCards[0]['value'] ?? 0) !== 45.0) {
    $fail('Account Ledger must show a top-level PKR 45 total-profit summary.');
}
$settledCardsByLabel = [];
foreach ((array) ($settled['summaryCards'] ?? []) as $card) {
    $settledCardsByLabel[(string) ($card['label'] ?? '')] = $card;
}
if (
    $money($settledCardsByLabel['Total Sale / PKR']['value'] ?? 0) !== 1188.0
    || $money($settledCardsByLabel['Total Received / PKR']['value'] ?? 0) !== 1180.0
    || $money($settledCardsByLabel['Total Outstanding / PKR']['value'] ?? 0) !== 0.0
) {
    $fail('Account Ledger top summary must show current sale, actual customer receipts, and current outstanding without refund inflation.');
}
foreach ($settledCardsByLabel as $card) {
    if ((string) ($card['group_title'] ?? '') !== 'Account Summary') {
        $fail('Account Ledger commercial totals must render together under Account Summary.');
    }
}
if ((string) ($settledSummary['pending_status'] ?? '') !== 'Settled') {
    $fail('Fully settled cancellation must not show a pending financial position.');
}
$pass('Fully settled cancellation follows 1,180 in, 1,188 out, 653 in, 600 out, closing 45 Dr, with sale, receipt, outstanding, and profit summarized.');

$aedOpenPosition = array_merge($basePosition, [
    'booking_id' => 2,
    'booking_reference' => 'BK-000002',
    'booking_service_id' => 2,
    'service_line_reference' => 'SV-002',
    'currency' => 'AED',
    'cost_currency' => 'AED',
    'original_invoice_amount' => 120,
    'original_supplier_cost' => 100,
    'original_profit_loss' => 20,
    'current_customer_due' => 120,
    'current_customer_outstanding' => 120,
    'current_supplier_gross' => 100,
    'current_supplier_payable' => 100,
    'current_supplier_paid' => 0,
    'supplier_payment_references' => '',
    'supplier_payment_date' => '',
    'cancellation_event_id' => 0,
    'cancellation_date' => '',
    'customer_penalty_amount' => 0,
    'supplier_penalty_amount' => 0,
    'customer_final_charge_amount' => 0,
    'expected_supplier_refund_amount' => 0,
    'available_customer_refund_credit' => 0,
    'released_supplier_payment_credit' => 0,
    'expected_supplier_refund_credit' => 0,
    'customer_refund_paid' => 0,
    'supplier_refund_received' => 0,
]);
$multiCurrency = $service->buildReport([
    'positions' => [$basePosition, $aedOpenPosition],
    'customerReceipts' => [$customerReceipt],
    'refundMovements' => [$customerRefund, $supplierRefund],
]);
$profitCardsByLabel = [];
foreach ((array) ($multiCurrency['summaryCards'] ?? []) as $card) {
    $profitCardsByLabel[(string) ($card['label'] ?? '')] = (string) ($card['value'] ?? '');
}
if (
    $money($profitCardsByLabel['Total Profit / PKR'] ?? 0) !== 45.0
    || $money($profitCardsByLabel['Total Profit / AED'] ?? 0) !== 20.0
) {
    $fail('Account Ledger profit summary must preserve separate native-currency totals.');
}
if (
    $money($profitCardsByLabel['Total Sale / PKR'] ?? 0) !== 1188.0
    || $money($profitCardsByLabel['Total Received / PKR'] ?? 0) !== 1180.0
    || $money($profitCardsByLabel['Total Outstanding / PKR'] ?? 0) !== 0.0
    || $money($profitCardsByLabel['Total Sale / AED'] ?? 0) !== 120.0
    || $money($profitCardsByLabel['Total Received / AED'] ?? 0) !== 0.0
    || $money($profitCardsByLabel['Total Outstanding / AED'] ?? 0) !== 120.0
) {
    $fail('Account Ledger commercial summary must preserve separate native-currency sale, receipt, and outstanding totals.');
}
$pass('Account Ledger summarizes sale, receipts, outstanding, and profit separately for every native currency.');

$unpaidPosition = array_merge($basePosition, [
    'current_customer_outstanding' => 580,
    'current_supplier_payable' => 535,
    'current_supplier_paid' => 0,
    'supplier_payment_references' => '',
    'supplier_payment_date' => '',
    'available_customer_refund_credit' => 0,
    'customer_refund_paid' => 0,
    'supplier_refund_received' => 0,
]);
$unpaid = $service->buildReport([
    'positions' => [$unpaidPosition],
    'customerReceipts' => [],
    'refundMovements' => [],
]);
$unpaidSummary = $unpaid['summaryRows'][0] ?? [];
if ($unpaid['rows'] !== []) {
    $fail('Unpaid booking must not fabricate customer receipts, supplier payments, or refunds.');
}
if (! str_contains((string) ($unpaidSummary['pending_status'] ?? ''), 'Cust. receivable 580.00')) {
    $fail('Unpaid customer charge must remain customer receivable.');
}
if (! str_contains((string) ($unpaidSummary['pending_status'] ?? ''), 'Supp. payable 535.00')) {
    $fail('Unpaid supplier penalty must remain supplier payable.');
}
$pass('Unpaid customer and supplier amounts remain pending without fabricated cash rows.');

$pendingPosition = array_merge($basePosition, [
    'expected_supplier_refund_credit' => 653,
    'customer_refund_paid' => 0,
    'supplier_refund_received' => 0,
]);
$pending = $service->buildReport([
    'positions' => [$pendingPosition],
    'customerReceipts' => [$customerReceipt],
    'refundMovements' => [],
]);
$pendingSummary = $pending['summaryRows'][0] ?? [];
if ($money($pendingSummary['cash_balance'] ?? 0) !== -8.0) {
    $fail('Before refunds are exchanged, cash balance must show the original PKR 8 loss.');
}
if (! str_contains((string) ($pendingSummary['pending_status'] ?? ''), 'Supp. refund due 653.00')) {
    $fail('Unreceived supplier refund must remain supplier refund receivable.');
}
if (! str_contains((string) ($pendingSummary['pending_status'] ?? ''), 'Cust. refund due 600.00')) {
    $fail('Unpaid customer refund must remain customer refund payable.');
}
$pass('Expected but unpaid refunds remain pending while cash balance stays at 8 Cr.');

$partialPosition = array_merge($pendingPosition, [
    'customer_refund_paid' => 300,
    'supplier_refund_received' => 200,
]);
$partialSupplierRefund = array_merge($supplierRefund, ['supplier_refund_amount' => 200]);
$partialCustomerRefund = array_merge($customerRefund, ['customer_refund_amount' => 300]);
$partial = $service->buildReport([
    'positions' => [$partialPosition],
    'customerReceipts' => [$customerReceipt],
    'refundMovements' => [$partialSupplierRefund, $partialCustomerRefund],
]);
$partialSummary = $partial['summaryRows'][0] ?? [];
if ($money($partialSummary['cash_balance'] ?? 0) !== -108.0) {
    $fail('Partial settlement cash balance must be 108 Cr.');
}
if (! str_contains((string) ($partialSummary['pending_status'] ?? ''), 'Supp. refund due 453.00')) {
    $fail('Partial supplier refund must leave PKR 453 receivable.');
}
if (! str_contains((string) ($partialSummary['pending_status'] ?? ''), 'Cust. refund due 300.00')) {
    $fail('Partial customer refund must leave PKR 300 payable.');
}
$pass('Partial refunds update cash and leave exact supplier/customer pending balances.');

$crossBookingPosition = array_merge($basePosition, [
    'booking_id' => 277,
    'booking_reference' => 'BK-000277',
    'service_status' => 'Confirmed',
    'currency' => 'AED',
    'cost_currency' => 'AED',
    'original_invoice_amount' => 1450,
    'original_supplier_cost' => 1450,
    'current_customer_due' => 1450,
    'current_customer_outstanding' => 606,
    'current_supplier_gross' => 1450,
    'current_supplier_payable' => 0,
    'current_supplier_paid' => 1450,
    'supplier_payment_references' => 'SPAY-000277',
    'cancellation_event_id' => 0,
    'cancellation_date' => '',
    'customer_penalty_amount' => 0,
    'supplier_penalty_amount' => 0,
    'customer_final_charge_amount' => 0,
    'expected_supplier_refund_amount' => 0,
    'available_customer_refund_credit' => 0,
    'customer_refund_paid' => 0,
    'supplier_refund_received' => 0,
]);
$crossBookingTransfer = [
    'booking_id' => 277,
    'branch_id' => 1,
    'branch_name' => 'Imdad International Travel Agency',
    'booking_reference' => 'BK-000277',
    'booking_date' => '2026-07-20',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walk-in',
    'customer_name' => 'Rizwan chacha',
    'contact_mobile' => 'N/A',
    'movement_date' => '2026-07-20',
    'movement_reference' => 'CREDIT-XFER-430',
    'currency' => 'AED',
    'debit_amount' => 844,
    'credit_amount' => 0,
    'transfer_direction' => 'target',
    'counterpart_booking_reference' => 'BK-000270',
];
$crossBooking = $service->buildReport([
    'positions' => [$crossBookingPosition],
    'customerReceipts' => [],
    'refundMovements' => [],
    'customerCreditTransfers' => [$crossBookingTransfer],
]);
$crossBookingSummary = $crossBooking['summaryRows'][0] ?? [];
if ($money($crossBookingSummary['total_debit'] ?? 0) !== 844.0) {
    $fail('Cross-booking customer credit must be Debit AED 844 on BK-000277 only.');
}
if ($money($crossBookingSummary['total_credit'] ?? 0) !== 1450.0) {
    $fail('Recorded supplier payment must be Credit AED 1,450 on BK-000277.');
}
if (! str_contains((string) ($crossBookingSummary['pending_status'] ?? ''), 'Cust. receivable 606.00')) {
    $fail('BK-000277 must retain AED 606 as pending customer receivable.');
}
$pass('Cross-booking credit shows 844 Debit, supplier payment shows 1,450 Credit, and 606 remains receivable.');

echo 'Account Ledger summary regression passed.' . PHP_EOL;
