<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\ReportService($app);
$buildReport = new ReflectionMethod($service, 'lossReport');
$buildReport->setAccessible(true);
$buildFooter = new ReflectionMethod($service, 'lossReportFooterRows');
$buildFooter->setAccessible(true);
$failures = [];

$check = static function (bool $passed, string $label, mixed $detail = null) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label;
    if ($detail !== null) {
        echo ' - ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Loss report regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$sourceRows = [
    [
        'branch_name' => 'Imdad International Travel Agency',
        'booking_reference' => 'BK-LOSS-PKR',
        'booking_date' => '2026-08-03',
        'business_source_name' => 'Riaz',
        'customer_name' => 'ABC1',
        'service_type' => 'air ticket',
        'supplier_name' => 'XYZ',
        'currency' => 'PKR',
        'payable_currency' => 'PKR',
        'receivable_amount' => 900.00,
        'payable_original_amount' => 1000.00,
        'payable_amount' => 1000.00,
        'loss_reason' => 'Approved promotional loss',
    ],
    [
        'branch_name' => 'Noble Route',
        'booking_reference' => 'BK-LOSS-AED',
        'booking_date' => '2026-08-03',
        'business_source_name' => 'Dubai Sales',
        'customer_name' => 'ABC2',
        'service_type' => 'air ticket',
        'supplier_name' => 'XYZ UAE',
        'currency' => 'AED',
        'payable_currency' => 'AED',
        'receivable_amount' => 90.00,
        'payable_original_amount' => 100.00,
        'payable_amount' => 100.00,
        'loss_reason' => '',
    ],
    [
        'branch_name' => 'Noble Route',
        'booking_reference' => 'BK-LOSS-MIXED',
        'booking_date' => '2026-08-03',
        'business_source_name' => 'Mixed Sales',
        'customer_name' => 'ABC3',
        'service_type' => 'air ticket',
        'supplier_name' => 'PKR Supplier',
        'currency' => 'AED',
        'payable_currency' => 'PKR',
        'receivable_amount' => 100.00,
        'payable_original_amount' => 8500.00,
        'payable_amount' => round(8500 / 77, 2),
        'pricing_exchange_rate' => round(1 / 77, 8),
        'loss_reason' => 'Mixed-currency loss',
    ],
    [
        'branch_name' => 'Imdad International Travel Agency',
        'booking_reference' => 'BK-PROFIT',
        'booking_date' => '2026-08-03',
        'business_source_name' => 'Riaz',
        'customer_name' => 'ABC4',
        'service_type' => 'air ticket',
        'supplier_name' => 'XYZ',
        'currency' => 'PKR',
        'payable_currency' => 'PKR',
        'receivable_amount' => 1200.00,
        'payable_original_amount' => 1000.00,
        'payable_amount' => 1000.00,
        'loss_reason' => '',
    ],
    [
        'branch_name' => 'Noble Route',
        'booking_reference' => 'BK-CANCEL-NO-LOSS',
        'booking_date' => '2026-08-12',
        'business_source_name' => 'IMDAD AED SALES',
        'customer_name' => 'ADNAN SHIND NOOR',
        'service_type' => 'air ticket',
        'supplier_name' => 'AL SAUDIA AED',
        'service_status' => 'Cancelled',
        'currency' => 'AED',
        'payable_currency' => 'PKR',
        'receivable_amount' => 515.72,
        'payable_original_amount' => 91526.00,
        'payable_amount' => 1188.65,
        'pricing_exchange_rate' => 77.00,
        'cancellation_event_id' => 36,
        'cancellation_customer_final_charge_amount' => 515.72,
        'cancellation_customer_refund_basis' => 1230.00,
        'cancellation_supplier_penalty_source_amount' => 35700.00,
        'cancellation_supplier_penalty_amount' => 0.00,
        'cancellation_supplier_penalty_currency' => 'PKR',
        'loss_reason' => '',
    ],
    [
        'branch_name' => 'Imdad International Travel Agency',
        'booking_reference' => 'BK-CANCEL-LOSS',
        'booking_date' => '2026-08-12',
        'business_source_name' => 'Walking Client',
        'customer_name' => 'ABC5',
        'service_type' => 'air ticket',
        'supplier_name' => 'Test Supplier',
        'service_status' => 'Cancelled',
        'currency' => 'AED',
        'payable_currency' => 'PKR',
        'receivable_amount' => 200.00,
        'payable_original_amount' => 40000.00,
        'payable_amount' => 519.48,
        'pricing_exchange_rate' => 77.00,
        'cancellation_event_id' => 99,
        'cancellation_customer_final_charge_amount' => 200.00,
        'cancellation_customer_refund_basis' => 900.00,
        'cancellation_supplier_penalty_source_amount' => 40000.00,
        'cancellation_supplier_penalty_amount' => 0.00,
        'cancellation_supplier_penalty_currency' => 'PKR',
        'loss_reason' => '',
    ],
];

[$rows, $summary] = $buildReport->invoke($service, $sourceRows, ['PKR' => 1.0, 'AED' => 77.0]);
$rowsByBooking = [];
foreach ($rows as $row) {
    $rowsByBooking[(string) ($row['booking_reference'] ?? '')] = $row;
}

$check(
    count($rows) === 4
    && ! isset($rowsByBooking['BK-PROFIT'])
    && ! isset($rowsByBooking['BK-CANCEL-NO-LOSS']),
    'Only genuine loss-making services are included',
    array_keys($rowsByBooking)
);
$check(
    ($rowsByBooking['BK-LOSS-PKR']['loss_amount'] ?? '') === '100'
    && ($rowsByBooking['BK-LOSS-PKR']['loss_reason'] ?? '') === 'Approved promotional loss',
    'PKR loss and saved loss reason remain exact',
    $rowsByBooking['BK-LOSS-PKR'] ?? null
);
$check(
    ($rowsByBooking['BK-LOSS-AED']['loss_amount'] ?? '') === '10'
    && ($rowsByBooking['BK-LOSS-AED']['pkr_loss_amount'] ?? '') === '770'
    && ($rowsByBooking['BK-LOSS-AED']['loss_reason'] ?? '') === 'Sold below supplier cost',
    'AED loss is retained in AED and consolidated to PKR separately',
    $rowsByBooking['BK-LOSS-AED'] ?? null
);
$check(
    ($rowsByBooking['BK-LOSS-MIXED']['supplier_currency'] ?? $rowsByBooking['BK-LOSS-MIXED']['payable_currency'] ?? '') === 'PKR'
    && ($rowsByBooking['BK-LOSS-MIXED']['supplier_cost'] ?? '') === '8,500'
    && ($rowsByBooking['BK-LOSS-MIXED']['pkr_loss_amount'] ?? '') === '800',
    'Mixed-currency supplier cost is compared after conversion and never as a raw AED number',
    $rowsByBooking['BK-LOSS-MIXED'] ?? null
);
$check(
    ($rowsByBooking['BK-CANCEL-LOSS']['loss_amount'] ?? '') === '319'
    && ($rowsByBooking['BK-CANCEL-LOSS']['supplier_cost'] ?? '') === '40,000'
    && ($rowsByBooking['BK-CANCEL-LOSS']['loss_reason'] ?? '') === 'Cancellation loss',
    'Cancelled services only remain in loss report when cancellation truth still ends in a real loss',
    $rowsByBooking['BK-CANCEL-LOSS'] ?? null
);

$summaryValues = [];
foreach ($summary as $card) {
    $summaryValues[(string) ($card['label'] ?? '')] = (string) ($card['value'] ?? '');
}
$check(
    ($summaryValues['Loss / PKR'] ?? '') === 'PKR 100'
    && ($summaryValues['Loss / AED'] ?? '') === 'AED 340'
    && ($summaryValues['Loss / Consolidated PKR'] ?? '') === 'PKR 26,270',
    'Summary keeps native PKR/AED losses separate and shows one consolidated PKR total',
    $summaryValues
);

$footerRows = $buildFooter->invoke($service, $rows);
$footerByCurrency = [];
foreach ($footerRows as $row) {
    $footerByCurrency[(string) ($row['currency'] ?? '')] = $row;
}
$check(
    ($footerByCurrency['PKR']['invoice_amount'] ?? '') === '900'
    && ($footerByCurrency['PKR']['loss_amount'] ?? '') === '100'
    && ($footerByCurrency['AED']['invoice_amount'] ?? '') === '390'
    && ($footerByCurrency['AED']['loss_amount'] ?? '') === '339'
    && ($footerByCurrency['PKR']['pkr_loss_amount'] ?? '') === '26,270',
    'Footer totals are currency-safe and do not add AED and PKR together',
    $footerRows
);

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Loss report regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Loss report regression passed.' . PHP_EOL;
