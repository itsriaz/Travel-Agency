<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\AccountLedgerService($app);
$position = [
    'booking_id' => 1,
    'branch_id' => 2,
    'branch_name' => 'Noble Route',
    'booking_reference' => 'BK-MIXED-TEST',
    'booking_date' => '2026-10-07',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walking Client',
    'customer_name' => 'Mixed Currency Test',
    'contact_mobile' => 'N/A',
    'booking_service_id' => 1,
    'service_line_reference' => 'SV-001',
    'service_type' => 'air ticket',
    'service_status' => 'Open',
    'currency' => 'AED',
    'cost_currency' => 'PKR',
    'pricing_exchange_rate' => 0.013,
    'original_invoice_amount' => 23,
    'original_supplier_cost' => 1000,
    'original_profit_loss' => 10,
    'passenger_name' => 'Mixed Currency Test',
    'supplier_name' => 'Test Supplier',
    'pnr' => 'N/A',
    'route' => 'N/A',
    'current_customer_due' => 23,
    'current_customer_outstanding' => 0,
    'current_supplier_gross' => 1000,
    'current_supplier_payable' => 1000,
    'current_supplier_paid' => 0,
];
$receipt = [
    'booking_id' => 1,
    'branch_id' => 2,
    'branch_name' => 'Noble Route',
    'booking_reference' => 'BK-MIXED-TEST',
    'booking_date' => '2026-10-07',
    'lead_traveler_id' => 1,
    'business_source_name' => 'Walking Client',
    'customer_name' => 'Mixed Currency Test',
    'contact_mobile' => 'N/A',
    'movement_id' => 1,
    'movement_reference' => 'RCPT-MIXED-TEST',
    'movement_date' => '2026-10-07',
    'currency' => 'AED',
    'debit_amount' => 23,
    'payment_method' => 'cash',
];

$report = $service->buildReport(['positions' => [$position], 'customerReceipts' => [$receipt]]);
if (count($report['summaryRows']) !== 1
    || ($report['summaryRows'][0]['currency'] ?? '') !== 'AED'
    || (float) ($report['summaryRows'][0]['invoice_amount'] ?? 0) !== 23.0
    || (int) ($report['summaryRows'][0]['invoice_count'] ?? 0) !== 1
) {
    throw new RuntimeException('Supplier PKR payable created a false PKR customer invoice summary.');
}
foreach ($report['summaryCards'] as $card) {
    if (str_ends_with((string) ($card['label'] ?? ''), '/ PKR')) {
        throw new RuntimeException('Supplier-only PKR created empty customer account summary cards.');
    }
}
echo '[PASS] AED customer invoice with PKR supplier payable has one AED customer summary.', PHP_EOL;

$paidPosition = $position;
$paidPosition['current_supplier_payable'] = 0;
$paidPosition['current_supplier_paid'] = 1000;
$paidPosition['supplier_payment_references'] = 'SPAY-MIXED-TEST';
$paidPosition['supplier_payment_date'] = '2026-10-07';
$paid = $service->buildReport(['positions' => [$paidPosition], 'customerReceipts' => [$receipt]]);
$pkrSummary = null;
foreach ($paid['summaryRows'] as $row) {
    if (($row['currency'] ?? '') === 'PKR') {
        $pkrSummary = $row;
    }
}
if (! is_array($pkrSummary)
    || (float) ($pkrSummary['raw_total_credit'] ?? 0) !== 1000.0
    || (int) ($pkrSummary['invoice_count'] ?? -1) !== 0
    || (float) ($paid['summaryRows'][0]['raw_total_credit'] ?? -1) !== 0.0
) {
    throw new RuntimeException('Real PKR supplier payment summary differs: ' . json_encode($pkrSummary));
}
echo '[PASS] Real PKR supplier payment stays visible without inventing a PKR customer invoice.', PHP_EOL;

/** @var PDO $db */
$db = $app->get('db');
$booking = $db->query('SELECT id FROM bookings WHERE booking_reference = "BK-001045" LIMIT 1')->fetchColumn();
if ($booking !== false) {
    $actual = $service->report(['bookingReference' => 'BK-001045'], [1, 2]);
    $obligation = $db->query('SELECT currency, net_payable_amount FROM supplier_obligations WHERE booking_reference = "BK-001045" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (count($actual['summaryRows']) !== 1
        || ($actual['summaryRows'][0]['currency'] ?? '') !== 'AED'
        || ($obligation['currency'] ?? '') !== 'PKR'
        || (float) ($obligation['net_payable_amount'] ?? 0) !== 1000.0
    ) {
        throw new RuntimeException('BK-001045 customer summary or PKR supplier payable differs from the reviewed local state.');
    }
    echo '[PASS] BK-001045 has only an AED customer summary; its PKR 1,000 supplier payable is unchanged.', PHP_EOL;
}
